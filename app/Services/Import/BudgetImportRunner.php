<?php

namespace App\Services\Import;

use App\Models\Block;
use App\Models\MaterialBudgetItem;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Services\MaterialBudgetImport;
use App\Services\WorkBudgetImport;
use App\Services\XlsxReader;
use RuntimeException;

/**
 * Төсвийн импортын НЭГДСЭН гүйцэтгэгч.
 *
 * Команд ба вэбийн дэлгэц ХОЁУЛАА эндээс дуудна. Хоёр тусдаа зам бичвэл
 * «командаар оруулахад өөр, дэлгэцээр оруулахад өөр» гэсэн тайлагдашгүй
 * зөрүү гарна — төсөв бол системийн суурь тул тийм зөрүү хамгийн үнэтэй.
 *
 * УРЬДЧИЛАН ХАРАХ БА БИЧИХ НЬ ИЖИЛ КОД. `preview()` нь `apply()`-тай яг
 * ижил задаргаа хийж, яг ижил тайлан буцаана. Ялгаа нь зөвхөн бичих эсэх.
 *
 * ТРАНЗАКЦ ЭНД НЭЭГДЭХГҮЙ. Дуудагч тал хариуцна: урьдчилан харахад
 * `rollBack`, батлахад `commit`. Ингэснээр урьдчилан харалт нь бодитоор
 * бичилт хийгээд буцаадаг тул «харсан тоо» ба «орох тоо» зөрөх боломжгүй.
 */
class BudgetImportRunner
{
    public const TYPES = ['quantities', 'materials'];

    public const LABELS = [
        'quantities' => 'Ажлын хөлсний төсөв',
        'materials' => 'Материалын төсөв',
    ];

    public const SHEETS = [
        'quantities' => WorkBudgetImport::SHEET,
        'materials' => MaterialBudgetImport::SHEET,
    ];

    /**
     * Файлд ямар төсөв байгааг ХУУДСААР нь таньж олно.
     *
     * ЯАГААД ХЭРЭГТЭЙ ВЭ: захиалагчийн нэг л файлд «Ажлын хөлсний төсөв»
     * ба «Материалын төсөв» ХОЁУЛАА байдаг (29 хуудасны хоёр нь). Хүнээс
     * «энэ файл ямар төсөв вэ» гэж асуух нь утгагүй — файл өөрөө хэлж
     * байна.
     *
     * Дараалал нь ТОГТМОЛ: ажлын төсөв эхэлнэ. Материал нь ажлын төрөлд
     * холбогддог тул ажлын мөрүүд ӨМНӨ нь байх ёстой.
     *
     * @return array<int, string>
     */
    public function detect(string $path): array
    {
        $sheets = (new XlsxReader($path))->sheetNames();

        return array_values(array_filter(
            self::TYPES,
            fn ($type) => in_array(self::SHEETS[$type], $sheets, true),
        ));
    }

    /** @return array<int, string> */
    public function sheetNames(string $path): array
    {
        return (new XlsxReader($path))->sheetNames();
    }

    /**
     * Файлыг задалж тайлан гаргана. ЮУ Ч БИЧИХГҮЙ.
     *
     * @return array<string, mixed>
     */
    public function preview(Block $block, string $path, string $type): array
    {
        $reader = $this->reader($path, $type);

        return $type === 'materials'
            ? $this->materialReport($block, $reader)
            : $this->quantityReport($block, $reader);
    }

    /**
     * Хэд хэдэн төсвийг НЭГ файлаас.
     *
     * @param  array<int, string>  $types
     * @return array<int, array<string, mixed>>
     */
    public function previewAll(Block $block, string $path, array $types): array
    {
        return array_map(
            fn ($type) => $this->preview($block, $path, $type),
            $this->ordered($types),
        );
    }

    /**
     * Задалж, бичнэ. Тайлан дээр нь бичилтийн үр дүн нэмэгдэнэ.
     *
     * @return array<string, mixed>
     */
    public function apply(
        Block $block,
        string $path,
        string $type,
        bool $prune = false,
        string $mode = WorkBudgetWriter::MODE_REPLACE,
    ): array {
        $report = $this->preview($block, $path, $type);

        if (! $report['canApply']) {
            throw new RuntimeException('Оруулах зүйл алга — бичих юм байхгүй.');
        }

        /*
         * ГОРИМ нь зөвхөн АЖЛЫН төсөвт хамаарна.
         *
         * Материалын төсөв дээр «гүйцэтгэл» гэж байхгүй тул устгах
         * зүйл ч байхгүй — тэр нь үргэлж бүхэлдээ солигдоно.
         */
        $report['result'] = $type === 'materials'
            ? app(MaterialBudgetWriter::class)->write($block, $report['plan'])
            : app(WorkBudgetWriter::class)->write($block, $report['plan'], $prune, $mode);

        return $report;
    }

    /**
     * Хэд хэдэн төсвийг НЭГ дарааллаар бичнэ.
     *
     * АЖЛЫН ТӨСӨВ ЭХЭЛНЭ. Материалын мөр нь ажлын төрөлд холбогддог ба
     * ажлын импорт нь гүйцэтгэлийг устгадаг — урвуу дарааллаар явбал
     * материал бичигдээд дараа нь ажлын импорт түүний холбоосыг
     * утгагүй болгоно.
     *
     * Транзакц ЭНД нээгдэхгүй: дуудагч тал бүгдийг НЭГ транзакцаар
     * боох ёстой. Хагас орсон төсөв нь оруулаагүйгээс дор.
     *
     * @param  array<int, string>  $types
     * @return array<int, array<string, mixed>>
     */
    public function applyAll(
        Block $block,
        string $path,
        array $types,
        bool $prune = false,
        string $mode = WorkBudgetWriter::MODE_REPLACE,
    ): array {
        return array_map(
            fn ($type) => $this->apply($block, $path, $type, $prune, $mode),
            $this->ordered($types),
        );
    }

    /**
     * Төрлүүдийг БИЧИХ дараалалд оруулна.
     *
     * @param  array<int, string>  $types
     * @return array<int, string>
     */
    private function ordered(array $types): array
    {
        $unknown = array_diff($types, self::TYPES);

        if ($unknown !== []) {
            throw new RuntimeException('Танигдахгүй импортын төрөл «'.implode(', ', $unknown).'».');
        }

        return array_values(array_filter(self::TYPES, fn ($t) => in_array($t, $types, true)));
    }

    /**
     * Хуудас нь байгаа эсэхийг ТЭР ДОР НЬ шалгана.
     *
     * Буруу файл оруулах нь хамгийн түгээмэл алдаа. «Хуудас олдсонгүй»
     * гэж шууд хэлэх нь 300 мөрийн алдааны жагсаалт харуулахаас дээр.
     */
    private function reader(string $path, string $type): XlsxReader
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException("Танигдахгүй импортын төрөл «{$type}».");
        }

        $reader = new XlsxReader($path);
        $sheets = $reader->sheetNames();

        if (! in_array(self::SHEETS[$type], $sheets, true)) {
            throw new RuntimeException(sprintf(
                '«%s» хуудас файлд алга. Файлын хуудсууд: %s',
                self::SHEETS[$type],
                implode(', ', array_slice($sheets, 0, 12)).(count($sheets) > 12 ? '…' : ''),
            ));
        }

        return $reader;
    }

    // ──────────────────────── материалын төсөв ────────────────────────

    /** @return array<string, mixed> */
    private function materialReport(Block $block, XlsxReader $reader): array
    {
        $import = (new MaterialBudgetImport($block))->parse($reader, $this->workTypesByCode());

        $plan = $import->plan();
        $totals = $import->totals();
        $existing = MaterialBudgetItem::where('block_id', $block->id)->count();

        $variance = $totals['actual'] > 0
            ? round(($totals['market'] / $totals['actual'] - 1) * 100, 2)
            : null;

        $linked = count(array_filter($plan, fn ($l) => $l['workTypeId'] !== null));

        $sections = [[
            'title' => 'Материалын өртөг',
            'rows' => [
                ['label' => 'Бодит (төсөв)', 'value' => $totals['actual'], 'format' => 'money'],
                ['label' => 'Зах зээл', 'value' => $totals['market'], 'format' => 'money'],
                ['label' => 'Зөрүү', 'value' => $variance, 'format' => 'percent',
                 'tone' => $this->varianceTone($variance)],
                ['label' => 'Бодит үнэгүй мөр', 'value' => $totals['withoutActual'], 'format' => 'count'],
                ['label' => 'Зах зээлийн үнэгүй мөр', 'value' => $totals['withoutMarket'], 'format' => 'count'],
            ],
        ], [
            'title' => 'Холболт',
            'rows' => [
                ['label' => 'Байршлын багана таарсан', 'value' => count($import->locationNames()), 'format' => 'count'],
                ['label' => 'Ажилтай холбогдсон мөр', 'value' => $linked, 'format' => 'count'],
                ['label' => 'Ажилгүй мөр', 'value' => count($plan) - $linked, 'format' => 'count',
                 'note' => 'Импортлогдоно — үнэ, тоо хэмжээ нь үнэн хэвээр.'],
            ],
        ]];

        return $this->envelope($block, 'materials', $plan, $import->errors(), [
            'summary' => [
                'sourceRows' => $import->sourceRowCount(),
                'planned' => count($plan),
                'rejected' => count($import->errors()),
                'skipped' => $import->skippedSubtotalCount(),
                'existing' => $existing,
            ],
            'sections' => $sections,
            'warnings' => $this->materialWarnings($import, $existing, $variance, $totals),
        ]);
    }

    /**
     * @return array<int, array{level: string, title: string, items: array<int, string>}>
     */
    private function materialWarnings(
        MaterialBudgetImport $import,
        int $existing,
        ?float $variance,
        array $totals,
    ): array {
        $warnings = [];

        if ($import->missingLocations() !== []) {
            $warnings[] = [
                'level' => 'warn',
                'title' => 'Файлд байгаа ч блокт БАЙХГҮЙ байршил',
                'items' => [
                    ...$import->missingLocations(),
                    'Эдгээрт тоо хэмжээ байвал тэр мөр импортлогдохгүй.',
                ],
            ];
        }

        if ($variance !== null && $variance > 5) {
            $warnings[] = [
                'level' => 'warn',
                'title' => sprintf('Зах зээлийн үнэ төсвөөс %.2f%% өндөр', $variance),
                'items' => [sprintf(
                    'Зөрүү %s₮. Худалдан авалт хийхээс ӨМНӨ шийдэх асуудал.',
                    number_format($totals['market'] - $totals['actual'], 2),
                )],
            ];
        }

        if ($existing > 0) {
            $warnings[] = [
                'level' => 'warn',
                'title' => "Блокийн одоогийн {$existing} мөр БҮХЭЛДЭЭ солигдоно",
                'items' => ['Материалын төсөв нь түүхэн бичлэг биш — хамгийн сүүлийн төсөв л үнэн.'],
            ];
        }

        return $warnings;
    }

    // ───────────────────── ажлын хөлсний төсөв ─────────────────────

    /** @return array<string, mixed> */
    private function quantityReport(Block $block, XlsxReader $reader): array
    {
        /*
         * Дутуу байршлыг урьдчилан харахад ч үүсгэнэ.
         *
         * Транзакц буцдаг тул ул мөр үлдэхгүй, харин «техникийн давхар
         * блокт алга» гэсэн худал алдаа гарахгүй: бодит бичилтэд тэр
         * байршил үүсэх учиртай.
         */
        app(\App\Services\BlockLocationBuilder::class)->ensureStandardFloors($block);

        $import = (new WorkBudgetImport($block))
            ->parse($reader, $this->workTypesByCode(true), $this->inspectorsByBudgetName());

        $plan = $import->plan();
        $existing = WorkItem::where('block_id', $block->id)->count();

        $withInspector = count(array_filter($plan, fn ($l) => $l['inspectorId'] !== null));
        $named = count(array_filter($plan, fn ($l) => $l['inspector'] !== null));

        $withPrice = array_filter($plan, fn ($l) => ($l['unitPrice'] ?? null) !== null);
        $budget = array_sum(array_map(fn ($l) => $l['unitPrice'] * $l['quantity'], $withPrice));

        $sections = [[
            'title' => 'Хяналт шалгалт хийх инженер',
            'rows' => [
                ['label' => 'Инженер оноогдсон', 'value' => $withInspector, 'format' => 'count'],
                ['label' => 'Нэр бий ч таарсангүй', 'value' => $named - $withInspector, 'format' => 'count'],
                ['label' => 'Нэр БАЙХГҮЙ', 'value' => count($plan) - $named, 'format' => 'count',
                 'note' => 'Эдгээр мөрийн гүйцэтгэл батлахыг хүлээхэд хэн рүү ч явахгүй.'],
            ],
        ], [
            'title' => 'Төсвийн нэгж үнэ',
            'rows' => [
                ['label' => 'Үнэтэй мөр', 'value' => count($withPrice), 'format' => 'count'],
                ['label' => 'Үнэгүй мөр', 'value' => count($plan) - count($withPrice), 'format' => 'count'],
                ['label' => 'Нийт төсөв', 'value' => round($budget, 2), 'format' => 'money'],
            ],
        ]];

        return $this->envelope($block, 'quantities', $plan, $import->errors(), [
            'summary' => [
                'sourceRows' => $import->sourceRowCount(),
                'planned' => count($plan),
                'rejected' => count($import->errors()),
                'skipped' => 0,
                'existing' => $existing,
            ],
            'sections' => $sections,
            'warnings' => $this->quantityWarnings($block, $import, $plan),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     * @return array<int, array{level: string, title: string, items: array<int, string>}>
     */
    private function quantityWarnings(Block $block, WorkBudgetImport $import, array $plan): array
    {
        $warnings = [];

        if ($import->missingLocations() !== []) {
            $warnings[] = [
                'level' => 'warn',
                'title' => 'Төсөвт байгаа ч блокт БАЙХГҮЙ давхрууд',
                'items' => [...$import->missingLocations(), 'Эдгээрийн тоо хэмжээ орохгүй.'],
            ];
        }

        if ($import->unknownInspectors() !== []) {
            $warnings[] = [
                'level' => 'warn',
                'title' => 'Таних боломжгүй хяналтын инженерийн нэр',
                'items' => array_map(
                    fn ($name, $rows) => "{$name} ({$rows} мөр)",
                    array_keys($import->unknownInspectors()),
                    $import->unknownInspectors(),
                ),
            ];
        }

        if ($import->sharedInspectors() !== []) {
            $warnings[] = [
                'level' => 'info',
                'title' => 'Нэг нүдэнд хоёр инженер — ЗӨВХӨН ЭХНИЙХ оногдоно',
                'items' => array_map(
                    fn ($raw, $rows) => "{$raw} ({$rows} мөр)",
                    array_keys($import->sharedInspectors()),
                    $import->sharedInspectors(),
                ),
            ];
        }

        /*
         * ГҮЙЦЭТГЭЛ УСТАНА — энэ бол хамгийн чухал анхааруулга.
         *
         * Тоо хэмжээ солигдоход хуучин явц утгагүй болно. Хэдэн бичлэг
         * устахыг ТООГООР харуулна: «бага зэрэг өөрчлөлт» гэж бодоод
         * хоёр сарын хэмжилт устгах нь бодит эрсдэл.
         */
        $itemIds = WorkItem::where('block_id', $block->id)->select('id');
        $entries = \App\Models\ProgressEntry::whereIn('work_item_id', $itemIds)->count();
        $inspections = \App\Models\Inspection::whereIn('work_item_id', $itemIds)->count();
        $photos = \App\Models\Photo::whereIn('work_item_id', $itemIds)->count();

        if ($entries + $inspections + $photos > 0) {
            $warnings[] = [
                'level' => 'danger',
                'title' => 'ГҮЙЦЭТГЭЛ УСТАНА',
                'items' => [
                    "Явцын бичлэг: {$entries}",
                    "Шалгалт: {$inspections}",
                    "Зураг: {$photos}",
                    'Тоо хэмжээ солигдоход хуучин явц утгагүй болдог тул цэвэрлэгдэнэ.',
                ],
            ];
        }

        $stale = $this->staleCount($block, $plan);

        if ($stale > 0) {
            $warnings[] = [
                'level' => 'info',
                'title' => "{$stale} ажлын мөр төсөвт ТААРААГҮЙ",
                'items' => ['Эдгээр хэвээр үлдэнэ. Устгах бол «Таараагүй мөрийг устгах» сонголтыг асаана.'],
            ];
        }

        return $warnings;
    }

    /** @param array<int, array<string, mixed>> $plan */
    private function staleCount(Block $block, array $plan): int
    {
        if ($plan === []) {
            return 0;
        }

        $keys = collect($plan)->map(fn ($l) => $l['locationId'].'|'.$l['workTypeId'])->flip();

        return WorkItem::where('block_id', $block->id)
            ->get(['location_id', 'work_type_id'])
            ->reject(fn ($i) => $keys->has($i->location_id.'|'.$i->work_type_id))
            ->count();
    }

    // ───────────────────────────── нийтлэг ─────────────────────────────

    /**
     * Тайлангийн нийтлэг бүрхүүл.
     *
     * `plan` нь хариуд БУЦААГДАХГҮЙ (хянагч хасна) — 3,000 мөрийг
     * хөтөч рүү илгээх нь утгагүй. Гэхдээ `apply()` түүнийг хэрэглэнэ.
     *
     * @param  array<int, array<string, mixed>>  $plan
     * @param  array<int, array<string, mixed>>  $errors
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function envelope(Block $block, string $type, array $plan, array $errors, array $extra): array
    {
        return [
            'type' => $type,
            'typeLabel' => self::LABELS[$type],
            'sheet' => self::SHEETS[$type],
            'blockId' => $block->id,
            'blockName' => $block->name,
            'canApply' => $plan !== [],
            'plan' => $plan,
            'errors' => array_map(fn ($e) => [
                'row' => $e['row'],
                'code' => $e['code'],
                'name' => $e['name'],
                'reason' => $e['reason'],
            ], $errors),
            'errorsByReason' => $this->groupErrors($errors),
            ...$extra,
        ];
    }

    /**
     * Алдааг шалтгаанаар нэгтгэнэ.
     *
     * «109 мөр эвдэрсэн нүдтэй» гэдэг нь нэг асуудал, нэг шийдэл. 109
     * мөрийн жагсаалт нь 109 асуудал мэт харагддаг.
     *
     * @param  array<int, array<string, mixed>>  $errors
     * @return array<int, array{reason: string, count: int}>
     */
    private function groupErrors(array $errors): array
    {
        $grouped = [];

        foreach ($errors as $error) {
            // Тодорхой утгыг нь хасч бүлэглэнэ: «Танигдахгүй нэгж «куб».»
            // ба «…«литр».» нь нэг асуудал.
            $key = trim((string) preg_replace('/[:«].*$/u', '', $error['reason'])) ?: $error['reason'];
            $grouped[$key] = ($grouped[$key] ?? 0) + 1;
        }

        arsort($grouped);

        return array_map(
            fn ($reason, $count) => ['reason' => $reason, 'count' => $count],
            array_keys($grouped),
            $grouped,
        );
    }

    private function varianceTone(?float $variance): string
    {
        return match (true) {
            $variance === null => 'gray',
            $variance > 10 => 'red',
            $variance > 0 => 'amber',
            default => 'green',
        };
    }

    /**
     * @return array<string, WorkType>
     */
    private function workTypesByCode(bool $withParts = false): array
    {
        $types = [];

        foreach (WorkType::whereNotNull('code')->get() as $type) {
            $types[$type->code] ??= $type;

            /*
             * Нэг код олон төрөлд хуваагдаж болно: «17.1.6» нь таван
             * өөр плитаны ажил. Тэр үед «код|бүрдэл» гэсэн нарийн
             * түлхүүрээр ялгана.
             */
            if ($withParts && $type->code_part) {
                $types[$type->code.'|'.mb_strtolower($type->code_part)] = $type;
            }
        }

        return $types;
    }

    /**
     * Төсвийн «Хяналт шалгалт хийх инженер» нэрийг хэрэглэгч рүү холбох хүснэгт.
     *
     * Холбоос нь `database/data/staff.json`-ийн `budgetName` талбарт
     * ТОДОРХОЙ бичигдсэн — кодод овог хасаж таамаглахгүй.
     *
     * @return array<string, int>
     */
    private function inspectorsByBudgetName(): array
    {
        $path = database_path('data/staff.json');

        if (! is_file($path)) {
            return [];
        }

        $map = [];

        foreach (json_decode((string) file_get_contents($path), true) ?? [] as $row) {
            if (! isset($row['budgetName'], $row['email'])) {
                continue;
            }

            $user = User::where('email', $row['email'])->first();

            if ($user) {
                $map[mb_strtolower($row['budgetName'])] = $user->id;
            }
        }

        return $map;
    }
}
