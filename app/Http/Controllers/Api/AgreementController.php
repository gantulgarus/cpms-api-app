<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgreementResource;
use App\Models\Agreement;
use App\Models\Block;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\WorkType;
use App\Services\AgreementService;
use App\Services\AgreementSheet;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Ажил гүйцэтгэх тохиролцооны хуудас.
 *
 * Мөнгө системд ЭНДЭЭС орно: акт нь тохиролцооны нэгж үнэ дээр тогтоно.
 * Тиймээс эрх нь хатуу — бичих нь гэрээний шийдвэр, батлах нь ерөнхий
 * инженерийн.
 */
class AgreementController extends Controller
{
    private const RELATIONS = ['block', 'contractor', 'lines.workType', 'createdBy', 'approvedBy'];

    /** GET /projects/{project}/agreements */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $this->authorizeRead($request);

        $query = Agreement::query()
            ->with(self::RELATIONS)
            ->whereIn('block_id', $project->blocks()->select('id'))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('contractorId'), fn ($q, $v) => $q->where('contractor_id', $v))
            ->orderByDesc('created_at');

        return AgreementResource::collection($query->get());
    }

    public function show(Request $request, Agreement $agreement): JsonResponse
    {
        $this->authorizeRead($request);

        return response()->json([
            'data' => new AgreementResource($agreement->load(self::RELATIONS)),
        ]);
    }

    /**
     * GET /blocks/{block}/agreement-draft?groupIds[]=…
     *
     * Төсвөөс мөрүүдийг татаж БЭЛТГЭНЭ — хадгалахгүй.
     *
     * ЯАГААД: гэрээний мэргэжилтэн 64 мөрийг гараар бичих нь алдаа гаргах
     * цорын гагц зам. Тоо хэмжээ төсөвтэй зөрвөл акт, төлбөр бүхэлдээ
     * буруу болно.
     */
    public function draft(Request $request, Block $block): JsonResponse
    {
        $this->authorizeWrite($request);

        /*
         * `groupIds` нь ТАСЛАЛААР салгасан мөр.
         *
         * Массив хэлбэрээр (`groupIds[]=…`) авч болох ч клиентийн query
         * бүтээгч массив дэмждэггүй. Хоёр тал нэг хэлбэртэй байх нь
         * зөрөх зайг арилгана.
         */
        $validated = $request->validate([
            'groupIds' => ['nullable', 'string', 'max:2000'],
        ]);

        $ids = array_values(array_filter(array_map(
            'trim',
            explode(',', $validated['groupIds'] ?? ''),
        )));

        $lines = app(AgreementService::class)->draftLines($block, $ids);

        return response()->json([
            'data' => $lines,
            'meta' => [
                /*
                 * Үнэгүй мөрийг ТЭР ДОР НЬ хэлнэ.
                 *
                 * Төсөвт үнэ тавигдаагүй мөр бий. Батлах үед татгалзахаас,
                 * бэлтгэх үед сануулах нь дээр.
                 */
                'withoutPrice' => count(array_filter(
                    $lines,
                    fn ($l) => ($l['budgetUnitPrice'] ?? null) === null,
                )),
                'priceConflicts' => count(array_filter($lines, fn ($l) => $l['priceConflict'])),
            ],
        ]);
    }

    /** POST /blocks/{block}/agreements */
    public function store(Request $request, Block $block): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $this->validatePayload($request);
        $contractor = Contractor::findOrFail($validated['contractorId']);

        $agreement = app(AgreementService::class)->create(
            $block,
            $contractor,
            $request->user(),
            $this->header($validated),
            $validated['lines'],
        );

        return response()->json([
            'data' => new AgreementResource($agreement->load(self::RELATIONS)),
        ], 201);
    }

    /** PATCH /agreements/{agreement} — зөвхөн драфт. */
    public function update(Request $request, Agreement $agreement): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $this->validatePayload($request, $agreement);

        $agreement->update($this->header($validated));
        app(AgreementService::class)->replaceLines($agreement, $validated['lines']);

        return response()->json([
            'data' => new AgreementResource($agreement->refresh()->load(self::RELATIONS)),
        ]);
    }

    /**
     * POST /agreements/{agreement}/approve
     *
     * Батлагдсаны дараа мөр ТҮГЖИГДЭНЭ — акт нь эдгээр үнэ дээр тогтдог.
     */
    public function approve(Request $request, Agreement $agreement): JsonResponse
    {
        abort_unless(
            in_array($request->user()?->role, Agreement::APPROVER_ROLES, true),
            403,
            'Тохиролцоог зөвхөн ерөнхий инженер, админ батална.'
        );

        $approved = app(AgreementService::class)->approve($agreement, $request->user());

        return response()->json([
            'data' => new AgreementResource($approved->load(self::RELATIONS)),
        ]);
    }

    /**
     * GET /agreements/{agreement}/sheet.xlsx
     *
     * Захиалагчийн бланкны хэлбэрээр татна — хэвлээд гарын үсэг зуруулна.
     *
     * Драфт ч татагдана: гарын үсэг зурахаас өмнө хэвлээд уншуулах нь
     * бодит практик. Хуудсан дээр «драфт — батлагдаагүй» гэж тэмдэглэгдэнэ.
     */
    public function sheet(Request $request, Agreement $agreement): BinaryFileResponse
    {
        $this->authorizeRead($request);

        $name = 'tohirolcoo-'.$agreement->number.'.xlsx';

        // `local` диск нь `storage/app/private` — вэбээс шууд хандахгүй.
        Storage::disk('local')->makeDirectory('agreements');
        $path = Storage::disk('local')->path('agreements/'.$name);

        app(AgreementSheet::class)->build($agreement, $path);

        return response()
            ->download($path, $name, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, ?Agreement $existing = null): array
    {
        return $request->validate([
            'contractorId' => [
                $existing ? 'prohibited' : 'required',
                'uuid', 'exists:contractors,id',
            ],
            'scope' => ['nullable', 'string', 'max:255'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],

            'retentionPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'barterPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'cashPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'barterAmount' => ['nullable', 'numeric', 'min:0'],
            'barterNote' => ['nullable', 'string', 'max:255'],

            'warrantyYears' => ['nullable', 'integer', 'min:0', 'max:50'],
            'maintenanceMonths' => ['nullable', 'integer', 'min:0', 'max:600'],
            'workDaysPerWeek' => ['nullable', 'integer', 'min:1', 'max:7'],
            'restDay' => ['nullable', 'string', 'max:20'],
            'headcount' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:4000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.workTypeId' => ['required', 'uuid', 'exists:work_types,id'],
            'lines.*.label' => ['nullable', 'string', 'max:255'],
            'lines.*.unit' => ['required', Rule::in(WorkType::query()->distinct()->pluck('unit')->all())],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unitPrice' => ['required', 'numeric', 'gt:0'],
            'lines.*.budgetUnitPrice' => ['nullable', 'numeric', 'min:0'],
            'lines.*.isExtra' => ['nullable', 'boolean'],
        ], [
            'contractorId.prohibited' => 'Гүйцэтгэгчийг солих боломжгүй — шинэ тохиролцоо бичнэ үү.',
            'lines.required' => 'Ажлын мөр заавал — төлбөр бодогдох зүйл байх ёстой.',
            'lines.*.unitPrice.gt' => 'Нэгж үнэ 0-ээс их байх ёстой.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private function header(array $v): array
    {
        return [
            'scope' => $v['scope'] ?? null,
            'start_date' => $v['startDate'] ?? null,
            'end_date' => $v['endDate'] ?? null,
            'retention_percent' => $v['retentionPercent'] ?? 0,
            'barter_percent' => $v['barterPercent'],
            'cash_percent' => $v['cashPercent'],
            'barter_amount' => $v['barterAmount'] ?? null,
            'barter_note' => $v['barterNote'] ?? null,
            'warranty_years' => $v['warrantyYears'] ?? 3,
            'maintenance_months' => $v['maintenanceMonths'] ?? 36,
            'work_days_per_week' => $v['workDaysPerWeek'] ?? null,
            'rest_day' => $v['restDay'] ?? null,
            'headcount' => $v['headcount'] ?? null,
            'notes' => $v['notes'] ?? null,
        ];
    }

    /**
     * Харах эрх.
     *
     * Тохиролцоонд МӨНГӨ байна — талбайн инженер, гүйцэтгэгчид хэрэггүй.
     * Гүйцэтгэгч өөрийн гэрээгээ харах нь зөв боловч тэр нь мобайл аппын
     * ажил (R2-25-ийн дараа).
     */
    private function authorizeRead(Request $request): void
    {
        /*
         * ХАРАХ нь БАТЛАХААС өргөн.
         *
         * Захирал тохиролцоо батладаггүй ч зардлаа мэдэх ёстой. Эрхийг
         * батлагчдын жагсаалтаар хэмжвэл захирал мөнгөний мэдээллээс
         * бүрэн тасарна.
         */
        abort_unless(
            (bool) $request->user()?->canSeeMoney(),
            403,
            'Танд тохиролцоо харах эрх байхгүй.'
        );
    }

    private function authorizeWrite(Request $request): void
    {
        abort_unless(
            $request->user()?->canManageContractors(),
            403,
            'Танд тохиролцоо бичих эрх байхгүй.'
        );
    }
}
