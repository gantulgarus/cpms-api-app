<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\BudgetImport;
use App\Services\BudgetTemplateSheet;
use App\Services\Import\BudgetImportRunner;
use App\Services\Import\WorkBudgetWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Төсвийн файлыг вэбээс оруулах.
 *
 * ХОЁР АЛХАМ, НЭГ ФАЙЛ:
 *
 *   1. POST  /blocks/{block}/imports   — байршуулж, УРЬДЧИЛАН ХАРНА
 *   2. POST  /imports/{import}/apply   — тэр ЯГ ТЭР файлыг бичнэ
 *
 * Файлыг хадгалж, id-гаар нь буцааж дууддаг нь зориуд: хөтөчөөс хоёр
 * дахин илгээвэл хоёр дахь удаад өөр файл сонгогдож болох бөгөөд хүн
 * «харсан зүйлээ» баталсан гэж итгээд огт өөр зүйл орно.
 *
 * ЭРХ: `canManageReferenceData` — админ, захирал, ерөнхий инженер.
 * Импорт нь блокийн бүх ажлын мөрийг солиод гүйцэтгэлийг устгадаг тул
 * лавлах сан засахтай ижил түвшний шийдвэр.
 */
class BudgetImportController extends Controller
{
    /** 25 МБ — захиалагчийн жинхэнэ файл 1.2 МБ, нөөц хангалттай. */
    private const MAX_KILOBYTES = 25 * 1024;

    /**
     * GET /blocks/{block}/budget-template.xlsx
     *
     * Энэ БЛОКТ тохируулсан загвар файл.
     *
     * Давхрын баганын толгойг блокийн байршлын нэрээр бичиж өгнө —
     * тогтмол загвар тараавал 12 давхрын блокт 16 давхрын багана ирж,
     * зөрүү нь алдаа болон хувирна. Блокоос нь үүсгэвэл тэр ангилалын
     * алдаа бүрэн арилна.
     *
     * ТАТАХ эрх нь ОРУУЛАХ эрхтэй ижил: загвар нь блокийн бүтцийг
     * задруулдаг тул нээлттэй байлгах шалтгаан алга.
     */
    public function template(Request $request, Block $block): BinaryFileResponse
    {
        $this->authorizeImport($request);

        $slug = Str::slug($block->building_no ?: $block->name) ?: 'blok';
        $name = "tosov-zagvar-{$slug}.xlsx";

        Storage::disk('local')->makeDirectory('templates');
        $path = Storage::disk('local')->path('templates/'.$name);

        app(BudgetTemplateSheet::class)->build($block, $path);

        return response()
            ->download($path, $name, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    /** GET /blocks/{block}/imports — түүх. */
    public function index(Request $request, Block $block): JsonResponse
    {
        $this->authorizeImport($request);

        $imports = BudgetImport::with(['uploadedBy', 'appliedBy'])
            ->where('block_id', $block->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $imports->map(fn ($i) => $this->shape($i))->all()]);
    }

    /**
     * POST /blocks/{block}/imports — байршуулж урьдчилан харна.
     *
     * ЮУ Ч БИЧИХГҮЙ. Задаргаа нь бодитоор транзакц дотор гүйцэтгэгдээд
     * буцаана — тиймээс «харсан тоо» ба «орох тоо» зөрөх боломжгүй.
     */
    public function store(Request $request, Block $block): JsonResponse
    {
        $this->authorizeImport($request);

        /*
         * Өргөтгөлөөр шалгана, MIME-ээр БИШ.
         *
         * .xlsx нь zip архив тул систем бүр өөрөөр танина:
         * `application/zip`, `application/octet-stream`, заримдаа
         * `application/vnd.openxmlformats-…`. `mimes:xlsx` дүрэм нь
         * хүчинтэй файлыг татгалзах нь элбэг. Жинхэнэ шалгалт нь
         * задлах оролдлого өөрөө — доорх `run()` нь буруу файлыг
         * тодорхой алдаагаар татгалзана.
         */
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES],

            /*
             * Төрөл нь СОНГОМОЛ. Өгөөгүй бол файлаас өөрөөс нь олно —
             * доорх `detect()`-ийг үзнэ үү.
             */
            'types' => ['nullable', 'array'],
            'types.*' => [Rule::in(BudgetImportRunner::TYPES)],
        ], [
            'file.max' => 'Файл хэт том (дээд хэмжээ 25 МБ).',
        ]);

        $extension = mb_strtolower($request->file('file')->getClientOriginalExtension());

        if ($extension !== 'xlsx') {
            return response()->json([
                'message' => 'Зөвхөн .xlsx файл. Excel дээр «Save As → .xlsx» хийнэ үү.',
            ], 422);
        }

        $import = new BudgetImport([
            'block_id' => $block->id,
            'original_name' => $request->file('file')->getClientOriginalName(),
            'size_bytes' => $request->file('file')->getSize(),
            'status' => 'pending',
            'uploaded_by' => $request->user()?->id,
        ]);

        $import->id = $import->newUniqueId();
        $import->path = $request->file('file')->storeAs('imports', $import->id.'.xlsx', 'local');

        $runner = app(BudgetImportRunner::class);
        $absolute = Storage::disk('local')->path($import->path);

        try {
            /*
             * ФАЙЛ ӨӨРӨӨ ХЭЛНЭ.
             *
             * Захиалагчийн 29 хуудастай нэг файлд «Ажлын хөлсний төсөв»
             * ба «Материалын төсөв» ХОЁУЛАА байдаг. Хүнээс «энэ ямар
             * төсөв вэ» гэж асуух нь буруу хариулт авах гарц нээж
             * байгаа хэрэг — хуудсын нэр нь эргэлзээгүй.
             */
            $detected = $runner->detect($absolute);
            $types = $validated['types'] ?? $detected;
            $types = array_values(array_intersect($types, $detected));

            if ($types === []) {
                $sheets = $runner->sheetNames($absolute);

                throw new RuntimeException(sprintf(
                    'Танигдах төсвийн хуудас олдсонгүй. «%s» эсвэл «%s» нэртэй хуудас байх ёстой. Файлын хуудсууд: %s',
                    BudgetImportRunner::SHEETS['quantities'],
                    BudgetImportRunner::SHEETS['materials'],
                    implode(', ', array_slice($sheets, 0, 12)).(count($sheets) > 12 ? '…' : ''),
                ));
            }

            $import->type = implode(',', $types);

            $reports = $this->run($block, $import, apply: false);
        } catch (Throwable $e) {
            /*
             * Задрахгүй файлыг ХАДГАЛАХГҮЙ. Түүх нь бодит төсвүүдийн
             * жагсаалт байх ёстой; буруу файл тэнд байвал «аль нь
             * орсон юм бол» гэсэн эргэлзээ үүснэ.
             */
            Storage::disk('local')->delete($import->path);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $import->summary = $this->totalSummary($reports);
        $import->save();

        return response()->json([
            'data' => $this->shape($import),
            'reports' => $reports,
        ], 201);
    }

    /** GET /imports/{budgetImport} — тайланг дахин гаргана. */
    public function show(Request $request, BudgetImport $budgetImport): JsonResponse
    {
        $this->authorizeImport($request);

        try {
            $reports = $this->run($budgetImport->block, $budgetImport, apply: false);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $this->shape($budgetImport),
            'reports' => $reports,
        ]);
    }

    /**
     * POST /imports/{budgetImport}/apply — ҮНЭХЭЭР бичнэ.
     *
     * Бүхэлд нь эсвэл огт үгүй: дунд нь алдаа гарвал транзакц буцна.
     */
    public function apply(Request $request, BudgetImport $budgetImport): JsonResponse
    {
        $this->authorizeImport($request);

        abort_unless(
            $budgetImport->isPending(),
            422,
            'Энэ файл аль хэдийн '.mb_strtolower($budgetImport->statusLabel()).'.'
        );

        $validated = $request->validate([
            // Зөвхөн ажлын хөлсний төсөвт утгатай: төсөвт таараагүй
            // ажлын мөрийг устгах эсэх.
            'prune' => ['nullable', 'boolean'],

            /*
             * Файлд хоёр төсөв байхад НЭГИЙГ нь л оруулахыг зөвшөөрнө.
             * Жишээ: материалын хэсэг нь эвдэрсэн тул ажлын төсвийг
             * эхлээд оруулаад, материалыг засагдсаны дараа.
             */
            'types' => ['nullable', 'array'],
            'types.*' => [Rule::in(BudgetImportRunner::TYPES)],

            /*
             * ГОРИМ — ажлын төсвийн хамгийн чухал сонголт.
             *
             *   replace — бүгдийг солино, ГҮЙЦЭТГЭЛ УСТАНА
             *   update  — тоо хэмжээ, үнэ, инженер солигдоно, явц ҮЛДЭНЭ
             *
             * Анхдагч нь `replace`: хуучин зан төлөв хэвээр, дуудагч
             * зориудаар сонгож байж л өөр болно.
             */
            'mode' => ['nullable', Rule::in(WorkBudgetWriter::MODES)],
        ]);

        $types = array_values(array_intersect(
            $validated['types'] ?? $budgetImport->types(),
            $budgetImport->types(),
        ));

        if ($types === []) {
            return response()->json(['message' => 'Оруулах төсөв сонгогдоогүй байна.'], 422);
        }

        try {
            $reports = $this->run(
                $budgetImport->block,
                $budgetImport,
                apply: true,
                prune: (bool) ($validated['prune'] ?? false),
                types: $types,
                mode: $validated['mode'] ?? WorkBudgetWriter::MODE_REPLACE,
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $budgetImport->forceFill([
            'status' => 'applied',
            'type' => implode(',', $types),
            'summary' => $this->totalSummary($reports),
            'applied_by' => $request->user()?->id,
            'applied_at' => now(),
        ])->save();

        return response()->json([
            'data' => $this->shape($budgetImport->fresh(['uploadedBy', 'appliedBy'])),
            'reports' => $reports,
        ]);
    }

    /** DELETE /imports/{budgetImport} — цуцлаад файлыг устгана. */
    public function destroy(Request $request, BudgetImport $budgetImport): JsonResponse
    {
        $this->authorizeImport($request);

        abort_unless($budgetImport->isPending(), 422, 'Орсон импортыг цуцлах боломжгүй.');

        Storage::disk('local')->delete($budgetImport->path);
        $budgetImport->update(['status' => 'discarded']);

        return response()->json(['data' => $this->shape($budgetImport)]);
    }

    // -----------------------------------------------------------------

    /**
     * Задаргааг ТРАНЗАКЦ дотор гүйцэтгэнэ.
     *
     * Урьдчилан харахад буцаана, батлахад батална. Хоёр тохиолдолд ИЖИЛ
     * код ажиллана — ингэснээр урьдчилан харалт нь дайвар үйлдлийг хүртэл
     * (дутуу байршил үүсгэх зэрэг) тооцно.
     *
     * @return array<string, mixed>
     */
    private function run(
        Block $block,
        BudgetImport $import,
        bool $apply,
        bool $prune = false,
        ?array $types = null,
        string $mode = WorkBudgetWriter::MODE_REPLACE,
    ): array {
        $path = Storage::disk('local')->path($import->path);

        abort_unless(is_file($path), 410, 'Байршуулсан файл олдсонгүй — дахин оруулна уу.');

        $types ??= $import->types();

        /*
         * БҮХ ТӨСӨВ НЭГ ТРАНЗАКЦАД.
         *
         * Ажлын төсөв орчихоод материалынх нь унавал блок хагас
         * шинэчлэгдсэн байдалд үлдэнэ: шинэ ажлын мөрүүд, хуучин
         * материал. Тэр байдлыг хүн гараар засахаас өөр арга алга.
         */
        DB::beginTransaction();

        try {
            $runner = app(BudgetImportRunner::class);

            $reports = $apply
                ? $runner->applyAll($block, $path, $types, $prune, $mode)
                : $runner->previewAll($block, $path, $types);
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $apply ? DB::commit() : DB::rollBack();

        /*
         * `plan` нь хөтөч рүү ЯВАХГҮЙ: 3,000 мөр × 10 талбар нь хэдэн
         * мегабайт болох бөгөөд дэлгэц түүнийг харуулдаггүй.
         */
        return array_map(function (array $report) {
            unset($report['plan']);

            return $report;
        }, $reports);
    }

    /**
     * Түүхийн мөрөнд харуулах НЭГТГЭСЭН дүн.
     *
     * Хоёр төсвийн тоог нэмнэ: түүхийн хүснэгтэд «155 мөр» гэж нэг тоо
     * харагдах ёстой, задаргааг нь тайлангаас харна.
     *
     * @param  array<int, array<string, mixed>>  $reports
     * @return array<string, int>
     */
    private function totalSummary(array $reports): array
    {
        $total = ['sourceRows' => 0, 'planned' => 0, 'rejected' => 0, 'skipped' => 0, 'existing' => 0];

        foreach ($reports as $report) {
            foreach ($total as $key => $value) {
                $total[$key] = $value + (int) ($report['summary'][$key] ?? 0);
            }
        }

        return $total;
    }

    /** @return array<string, mixed> */
    private function shape(BudgetImport $import): array
    {
        return [
            'id' => $import->id,
            'blockId' => $import->block_id,
            'type' => $import->type,
            'types' => $import->types(),
            'typeLabel' => $import->typeLabel(),
            'originalName' => $import->original_name,
            'sizeBytes' => (int) $import->size_bytes,
            'status' => $import->status,
            'statusLabel' => $import->statusLabel(),
            'summary' => $import->summary,
            'uploadedBy' => $import->uploadedBy?->name,
            'appliedBy' => $import->appliedBy?->name,
            'appliedAt' => $import->applied_at?->toIso8601String(),
            'createdAt' => $import->created_at?->toIso8601String(),
        ];
    }

    private function authorizeImport(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->canManageReferenceData(),
            403,
            'Төсөв оруулах эрх зөвхөн ерөнхий инженер, админд байна.'
        );
    }
}
