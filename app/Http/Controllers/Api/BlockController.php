<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBlockRequest;
use App\Http\Resources\BlockResource;
use App\Http\Resources\LocationResource;
use App\Jobs\ApplyBlockDesign;
use App\Models\Block;
use App\Models\BlockDesign;
use App\Models\BudgetImport;
use App\Models\ConcreteOrder;
use App\Models\Inspection;
use App\Models\MaterialOrder;
use App\Models\Photo;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\WorkItem;
use App\Services\BlockBudgetView;
use App\Services\BlockLocationBuilder;
use App\Services\BlockReport;
use App\Services\BlockReportSheet;
use App\Services\WorkScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    /**
     * GET /blocks/{block}/budget
     *
     * Блокт ОРСОН төсвийг харах — ажлын хөлс ба материал.
     *
     * Импортын дараа «би юу оруулсан бэ» гэдгийг харах газар
     * байхгүй байв: ажлын үнэ зөвхөн тохиролцоо бичих үед,
     * материал зөвхөн үлдэгдэл хэлбэрээр харагддаг байсан.
     */
    public function budget(Request $request, Block $block): JsonResponse
    {
        $this->authorizeReport($request);

        return response()->json(['data' => app(BlockBudgetView::class)->build($block)]);
    }

    /**
     * PATCH /blocks/{block}/budget/work-price
     *
     * Ажлын төрлийн төсвийн нэгж үнийг ГАРААР тавих.
     *
     * ЯАГААД ХЭРЭГТЭЙ ВЭ: төсвийн файлд үнэ тавигдаагүй мөр байдаг
     * (А1-д 68 ажил). Тэднийг засахын тулд Excel-ээ засаад бүх
     * төсвийг дахин импортлох нь хэт том алхам — нэг тоо тавихын
     * тулд 1,991 ажлыг дахин бичих шаардлагагүй.
     *
     * ҮНЭ НЬ БЛОК ДОТОРХ БҮХ АЖИЛД тавигдана: төсөв дээр нэгж үнэ нь
     * ажлын төрөл бүрт НЭГ байдаг.
     */
    public function setWorkPrice(Request $request, Block $block): JsonResponse
    {
        $this->authorizeBudgetEdit($request);

        $validated = $request->validate([
            'workTypeId' => ['required', 'uuid', 'exists:work_types,id'],
            /*
             * `null` нь үнийг АРИЛГАНА, 0 нь ЗӨВШӨӨРӨГДӨХГҮЙ.
             *
             * «Үнэгүй ажил» ба «үнэ нь мэдэгдэхгүй» хоёр өөр зүйл.
             * 0 бичих нь тохиролцоонд 0₮ санал болгоно.
             */
            'unitPrice' => ['present', 'nullable', 'numeric', 'gt:0'],
        ], [
            'unitPrice.gt' => 'Нэгж үнэ 0-ээс их байх ёстой. Арилгах бол хоосон үлдээнэ үү.',
        ]);

        $updated = WorkItem::where('block_id', $block->id)
            ->where('work_type_id', $validated['workTypeId'])
            ->update(['budget_unit_price' => $validated['unitPrice']]);

        return response()->json(['data' => ['updated' => $updated]]);
    }

    /**
     * GET /blocks/{block}/report
     *
     * Блокийн ЕРӨНХИЙ тайлан — гүйцэтгэл, мөнгө, хугацаа, чанар,
     * материал тав нэг хуудсанд.
     *
     * ХЭЗЭЭ Ч ГАРНА, зөвхөн дууссан блокт биш: «одоогоор хаана яваа вэ»
     * гэдэг нь сар бүр асуугддаг асуулт.
     */
    public function report(Request $request, Block $block): JsonResponse
    {
        $this->authorizeReport($request);

        return response()->json(['data' => app(BlockReport::class)->build($block)]);
    }

    /** GET /blocks/{block}/report.xlsx */
    public function reportSheet(Request $request, Block $block): BinaryFileResponse
    {
        $this->authorizeReport($request);

        $name = 'blok-tailan-'.$block->building_no.'.xlsx';

        Storage::disk('local')->makeDirectory('reports');
        $path = Storage::disk('local')->path('reports/'.$name);

        app(BlockReportSheet::class)->build(app(BlockReport::class)->build($block), $path);

        return response()
            ->download($path, $name, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /**
     * Тайлан харах эрх.
     *
     * МӨНГӨ агуулдаг тул гүйцэтгэгчид хаалттай. Захирал НЭЭЛТТЭЙ —
     * тэр юу ч батлахгүй ч барилгынхаа байдлыг мэдэх ёстой.
     */
    /**
     * Төсөв ЗАСАХ эрх.
     *
     * Төсөв ОРУУЛАХтай ижил түвшин (`canManageReferenceData`): нэг
     * тоо гараар тавих нь импортоор тавихаас агуулгын хувьд ялгаагүй.
     * Харах эрх (`canSeeMoney`) илүү өргөн — захирал, төслийн менежер
     * хардаг ч засдаггүй.
     */
    private function authorizeBudgetEdit(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->canManageReferenceData(),
            403,
            'Төсөв засах эрх зөвхөн ерөнхий инженер, админд байна.'
        );
    }

    private function authorizeReport(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->canSeeMoney(),
            403,
            'Танд блокийн тайлан харах эрх байхгүй.'
        );
    }

    /**
     * GET /blocks/{block}/deletion-impact
     *
     * Устгахаас ӨМНӨ юу алга болохыг тоогоор харуулна.
     *
     * ЯАГААД ЗААВАЛ ВЭ: блок устгахад байршил, ажлын мөр, явц, шалгалт,
     * зураг, төсөв, захиалга БҮГД каскадаар дагаж устана. «Устгах уу?»
     * гэсэн асуулт энэ тоонуудгүйгээр утгагүй — хүн юу алдаж байгаагаа
     * мэдэхгүй байж «тийм» гэж хариулна.
     */
    public function deletionImpact(Request $request, Block $block): JsonResponse
    {
        $this->authorizeDelete($request);

        $itemIds = WorkItem::where('block_id', $block->id)->select('id');

        return response()->json([
            'data' => [
                'blockName' => $block->name,
                'locations' => $block->locations()->count(),
                'workItems' => WorkItem::where('block_id', $block->id)->count(),
                'progressEntries' => ProgressEntry::whereIn('work_item_id', $itemIds)->count(),
                'inspections' => Inspection::whereIn('work_item_id', $itemIds)->count(),
                'photos' => Photo::whereIn('work_item_id', $itemIds)->count(),
                'agreements' => $block->agreements()->count(),
                'concreteOrders' => ConcreteOrder::where('block_id', $block->id)->count(),
                'materialOrders' => MaterialOrder::where('block_id', $block->id)->count(),
                'budgetImports' => BudgetImport::where('block_id', $block->id)->count(),

                /*
                 * БАТАЛГААЖСАН ажил — хамгийн чухал тоо.
                 *
                 * Эдгээр нь АКТАД орсон байж болзошгүй. Актыг системээс
                 * гаргасан, гарын үсэг зурсан, төлбөр хийгдсэн байж
                 * мэднэ — тэгвэл устгах нь мөнгөний баримтыг устгана.
                 */
                'acceptedInspections' => Inspection::whereIn('work_item_id', $itemIds)
                    ->where('stage', Inspection::STAGE_FINAL)
                    ->where('accepted_qty', '>', 0)
                    ->count(),
            ],
        ]);
    }

    /**
     * DELETE /blocks/{block}
     *
     * ЗӨВХӨН АДМИН, бөгөөд блокийн НЭРИЙГ бичиж баталгаажуулна.
     *
     * Нэр бичүүлэх нь чирэгдэл биш: энэ үйлдэл буцаагдахгүй бөгөөд
     * 1,991 ажлын мөр, бүх гүйцэтгэл, акт нэг товчоор алга болно.
     * Санамсаргүй дарах боломжийг бүрэн хаана.
     */
    public function destroy(Request $request, Block $block): JsonResponse
    {
        $this->authorizeDelete($request);

        $validated = $request->validate([
            'confirmName' => ['required', 'string'],
        ], [
            'confirmName.required' => 'Блокийн нэрийг бичиж баталгаажуулна уу.',
        ]);

        if (trim($validated['confirmName']) !== $block->name) {
            return response()->json([
                'message' => "Нэр таарахгүй байна. «{$block->name}» гэж яг бичнэ үү.",
            ], 422);
        }

        /*
         * ЗУРГИЙГ ДИСКНЭЭС устгана.
         *
         * Өгөгдлийн сангийн каскад нь мөрийг устгана, ФАЙЛЫГ биш.
         * Замуудыг устгахаас ӨМНӨ цуглуулна: дараа нь ямар файл байсныг
         * мэдэх арга үлдэхгүй бөгөөд дискэн дээр эзэнгүй зураг
         * хуримтлагдана.
         */
        $paths = Photo::whereIn(
            'work_item_id',
            WorkItem::where('block_id', $block->id)->select('id'),
        )->pluck('path');

        $name = $block->name;

        DB::transaction(fn () => $block->delete());

        // Файлыг транзакцийн ДАРАА устгана: буцсан тохиолдолд зураг
        // устчихаад мөр нь үлдэх ёсгүй.
        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }

        return response()->json([
            'data' => ['name' => $name, 'photosRemoved' => $paths->count()],
        ]);
    }

    /**
     * Блок устгах эрх — ЗӨВХӨН АДМИН.
     *
     * Ерөнхий инженер ч орохгүй: блок устгах нь төслийн өгөгдлийг
     * бүхэлд нь арилгах үйлдэл бөгөөд өдөр тутмын ажилд хэрэггүй.
     * Буруу үүсгэсэн блокийг цөөхөн удаа устгана.
     */
    private function authorizeDelete(Request $request): void
    {
        abort_unless(
            $request->user()?->role === 'admin',
            403,
            'Блок устгах эрх зөвхөн админд байна.'
        );
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
