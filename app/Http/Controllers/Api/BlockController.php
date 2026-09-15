<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBlockRequest;
use App\Http\Resources\BlockResource;
use App\Http\Resources\LocationResource;
use App\Jobs\ApplyBlockDesign;
use App\Models\Block;
use App\Models\BlockDesign;
use App\Models\Project;
use App\Services\BlockLocationBuilder;
use App\Services\WorkScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BlockController extends Controller
{
    public function __construct(
        private readonly WorkScope $scope,
        private readonly BlockLocationBuilder $locations,
    ) {}

    /**
     * GET /projects/{project}/blocks
     *
     * Хамрах хүрээнээс гадуурх барилгыг жагсаалтад ч гаргахгүй — дарахад 403
     * авах мөр харуулах нь хэрэглэгчийг зөвхөн бухимдуулна.
     */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $query = Block::query()->where('project_id', $project->id)->orderBy('name');

        $this->scope->blocks($query, $request->user());

        return BlockResource::collection($query->get());
    }

    /**
     * POST /projects/{project}/blocks
     *
     * Блокийг шуурхай үүсгээд, ажлын нэгж үүсгэх удаан ажлыг queue руу өгнө.
     */
    public function store(StoreBlockRequest $request, Project $project): JsonResponse
    {
        $designId = $request->validated('designId');
        $design = $designId ? BlockDesign::findOrFail($designId) : null;

        $block = $project->blocks()->create([
            'block_design_id' => $design?->id,
            'name' => $request->validated('name'),
            'building_no' => $request->validated('buildingNo'),
            'purpose' => $design?->purpose ?? $request->validated('purpose') ?? '—',
            'floors' => $design?->floors ?? (int) $request->validated('floors'),
            'units_per_floor' => $design?->units_per_floor
                ?? (int) ($request->validated('unitsPerFloor') ?? 0),
            'start_date' => $request->validated('startDate'),
            'takt_days' => (int) ($request->validated('taktDays') ?? 5),
            'status' => 'not_started',
        ]);

        // Загваргүй бол байршлыг ТЭР ДОР НЬ үүсгэнэ. Эс бөгөөс давхар, айл
        // байхгүй хоосон блок үлдэж, ажил нэмэх газар байхгүй болно.
        if (! $design) {
            $this->locations->build($block, $block->floors, $block->units_per_floor);
        }

        return response()->json(['data' => new BlockResource($block->refresh())], 201);
    }

    /** GET /blocks/{block} */
    public function show(Block $block): JsonResponse
    {
        return response()->json(['data' => new BlockResource($block)]);
    }

    /** GET /blocks/{block}/locations */
    public function locations(Request $request, Block $block): AnonymousResourceCollection
    {
        $query = $block->locations()
            ->when($request->query('level'), fn ($q, $v) => $q->where('level', $v))
            ->when($request->query('parentId'), fn ($q, $v) => $q->where('parent_id', $v))
            ->orderBy('sequence_number');

        return LocationResource::collection($query->get());
    }

    /**
     * POST /block-designs/{blockDesign}/apply
     *
     * Синхрон биш: ~3,290 мөр үүсэх тул batch id буцаагаад дэлгэц хянана.
     *
     * АРГУМЕНТЫН НЭР ЗААВАЛ `$blockDesign` БАЙНА — route дахь `{blockDesign}`
     * тэмдэгттэй ЯГ таарах ёстой. Laravel-ийн implicit binding нь төрлөөр
     * биш НЭРЭЭР холбодог: `$design` гэж нэрлэвэл холболт унтарч, контейнер
     * ХООСОН `BlockDesign` өгнө. Тэр нь 404 биш — `$design->id` нь `null`
     * болж, алдаа нь хамаагүй өөр газар («designId must be of type string»)
     * гарна. Нэр солихоос өмнө энэ тайлбарыг уншина уу.
     */
    public function applyDesign(Request $request, BlockDesign $blockDesign): JsonResponse
    {
        $validated = $request->validate([
            'blockId' => ['required', 'uuid', 'exists:blocks,id'],
            'startDate' => ['nullable', 'date'],
        ]);

        $block = Block::findOrFail($validated['blockId']);

        abort_if(
            $block->workItems()->exists(),
            409,
            'Энэ блокт загвар аль хэдийн буулгасан байна.'
        );

        $jobId = (string) Str::uuid();
        $payload = [
            'id' => $jobId,
            'status' => 'queued',
            'progress' => 0,
            'total' => $blockDesign->estimatedItems(),
            'result' => ['blockId' => $block->id],
            'error' => null,
        ];

        // Queue дээр авахаас өмнө "queued" төлөвийг бичнэ — дэлгэц шууд poll
        // хийж эхлэхэд 404 авахгүй.
        Cache::put(ApplyBlockDesign::progressKey($jobId), $payload, ApplyBlockDesign::PROGRESS_TTL);

        ApplyBlockDesign::dispatch(
            $block->id,
            $blockDesign->id,
            $validated['startDate'] ?? $block->start_date?->toDateString() ?? now()->toDateString(),
            $jobId,
        );

        return response()->json(['data' => $payload], 202);
    }

    /** GET /jobs/{jobId} — загвар буулгах ажлын явц. */
    public function job(string $jobId): JsonResponse
    {
        $state = Cache::get(ApplyBlockDesign::progressKey($jobId));

        abort_if($state === null, 404, 'Ажил олдсонгүй.');

        return response()->json(['data' => $state]);
    }
}
