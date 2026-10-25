<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Location;
use App\Models\WorkType;

/**
 * «Ажлын хөлсний төсөв» хуудсыг системийн ажлын мөр рүү задлах.
 *
 * ФАЙЛЫН БҮТЭЦ (хоёр давхар толгой):
 *
 *   мөр 2 │ № │ Дугаар │ Код │ Ажлын төрөл │ … │ Ажлын тоо хэмжээ ─────────┐
 *   мөр 3 │   │        │     │             │   │ Суурь │ Зоорь │ 1-р │ …  │
 *   мөр 4+│ өгөгдөл                                                        │
 *
 * ХОЛБОХ ТҮЛХҮҮР НЬ «КОД» (1.2, 2.6.1, 4.1.7…), нэр БИШ. Төсөвт «Блокон
 * хана» гэж бичигдсэн нь системд «блокон өрлөг» — нэрээр холбох оролдлого
 * 64 мөрийн 43-д нь бүдэрсэн. Код нь тогтвортой.
 *
 * НЭГ КОД ОЛОН МӨРТЭЙ байж болно: «17.1.6 Плитаны ажил» нь төсөвт таван
 * мөрөнд задардаг (айлын 00 өрөө шал, үүд, тагт…). Тэр үед «код + бүрдлийн
 * нэр» хослолоор ялгана.
 *
 * ТООГ ХААШАА БУУЛГАХ НЬ АЖЛЫН ТӨРЛИЙН ТҮВШНЭЭС хамаарна, баганын
 * толгойноос биш:
 *
 *   block │ бүх баганын нийлбэр → барилгын үндэс байршил
 *   floor │ багана бүр → тухайн давхар
 *   unit  │ давхрын тоо ÷ тэр давхрын айлын тоо → айл бүрд
 *
 * Сүүлийнх нь чухал: төсөвт айлын багана БАЙХГҮЙ. «Цонх суулгалт 25 ш»
 * гэдэг нь давхрын дүн бөгөөд 9 айлд хуваагдана.
 *
 * ЯАГААД ЗАДЛАГЧ НЬ БИЧДЭГГҮЙ ВЭ: энэ класс зөвхөн УНШИЖ, ШАЛГАЖ, төлөвлөгөө
 * буцаана. Бичих эсэхийг команд шийднэ — ингэснээр «урьдчилан харах» горим
 * жинхэнэ бичилттэй ЯГ ижил логикоор ажиллана.
 */
class WorkBudgetImport
{
    public const SHEET = 'Ажлын хөлсний төсөв';

    /** Толгойн хоёр дахь мөр — давхрын нэрс эндээс. */
    private const HEADER_ROW = 3;

    private const FIRST_DATA_ROW = 4;

    /** 0-оос эхлэх баганын индекс. */
    private const COL_CODE = 2;

    private const COL_NAME = 4;

    private const COL_PART = 5;

    private const COL_INSPECTOR = 6;

    private const COL_UNIT = 8;

    /**
     * Нэгж үнэ — AD багана.
     *
     * Давхруудын дараа, «Нийт» (AC) баганын хажууд. Үүнийг уншихгүй бол
     * систем дэх мөнгөний цорын гагц эх сурвалж байхгүй болно.
     */
    private const COL_UNIT_PRICE = 29;

    private const COL_FIRST_FLOOR = 9;

    private const COL_LAST_FLOOR = 27;

    /**
     * Хүлээн зөвшөөрөх нэгжүүд — даалгаврын лавлахаас.
     *
     * Танигдахгүй нэгжтэй мөрийг импортлохгүй: «м2» гэж бичих ёстой газар
     * «кв.м» гэж бичигдсэн бол тоо хэмжээ хаашаа ч орж болно.
     */
    public const UNITS = ['м', 'м2', 'м3', 'ш', 'тн', 'кг', 'багц', 'боодол', 'шуудай'];

    /** @var array<int, array<string, mixed>> */
    private array $plan = [];

    /** @var array<int, array{row: int, code: string, name: string, reason: string}> */
    private array $errors = [];

    /** @var array<int, string> Файлд байгаа ч блокт олдоогүй давхрууд. */
    private array $missingLocations = [];

    /** @var array<string, int> төсөв дээрх нэр → хэрэглэгчийн id */
    private array $inspectorsByName = [];

    /** @var array<string, int> таних боломжгүй байсан нэр → хэдэн мөрөнд */
    private array $unknownInspectors = [];

    /** @var array<string, int> нэг нүдэнд хоёр нэр бичигдсэн тохиолдол */
    private array $sharedInspectors = [];

    private int $sourceRows = 0;

    /** @var array<string, string> байршлын id → нэр (мэдээлэлд) */
    private array $locationNames = [];

    public function __construct(private readonly Block $block) {}

    /**
     * Файлыг задалж төлөвлөгөө гаргана.
     *
     * @param  array<string, WorkType>  $typesByCode  «код» эсвэл «код|бүрдэл» → төрөл
     * @param  array<string, int>  $inspectorsByName  төсөв дээрх өвөр нэр (жижиг
     *                                                үсгээр) → хэрэглэгчийн id
     */
    public function parse(XlsxReader $reader, array $typesByCode, array $inspectorsByName = []): self
    {
        $this->inspectorsByName = $inspectorsByName;

        $rows = $reader->rows(self::SHEET, self::COL_UNIT_PRICE + 1);

        $floors = $this->floorColumns($rows[self::HEADER_ROW] ?? []);
        $root = $this->rootLocationId();
        $unitsByFloor = $this->unitsByFloor();

        foreach ($rows as $number => $row) {
            if ($number < self::FIRST_DATA_ROW) {
                continue;
            }

            $this->parseRow($number, $row, $floors, $root, $unitsByFloor, $typesByCode);
        }

        return $this;
    }

    /**
     * Давхрын багана → байршлын id.
     *
     * Толгойн нэр блокийн байршлын нэртэй ЯГ таарах ёстой. Таарахгүй бол
     * чимээгүй алгасахгүй — тайланд гаргана. «Суурь», «Техникийн давхар» нь
     * ихэвчлэн энд ордог: төсөвт байдаг ч байршлын модонд үүсдэггүй.
     *
     * @param  array<int, string|float|null>  $header
     * @return array<int, string>
     */
    private function floorColumns(array $header): array
    {
        $locations = Location::where('block_id', $this->block->id)
            ->where('level', 'floor')
            ->pluck('id', 'name')
            ->all();

        $map = [];

        for ($column = self::COL_FIRST_FLOOR; $column <= self::COL_LAST_FLOOR; $column++) {
            $title = trim((string) ($header[$column] ?? ''));

            if ($title === '') {
                continue;
            }

            if (isset($locations[$title])) {
                $map[$column] = $locations[$title];
                $this->locationNames[$locations[$title]] = $title;
            } else {
                $this->missingLocations[$column] = $title;
            }
        }

        return $map;
    }

    private function rootLocationId(): ?string
    {
        $root = Location::where('block_id', $this->block->id)
            ->where('level', 'block')
            ->first();

        if ($root) {
            $this->locationNames[$root->id] = $root->name;
        }

        return $root?->id;
    }

    /**
     * Давхрын id → доорх айлуудын id.
     *
     * @return array<string, array<int, string>>
     */
    private function unitsByFloor(): array
    {
        return Location::where('block_id', $this->block->id)
            ->where('level', 'unit')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id')
            ->map(fn ($rows) => $rows->pluck('id')->all())
            ->all();
    }

    /**
     * @param  array<int, string|float|null>  $row
     * @param  array<int, string>  $floors
     * @param  array<string, array<int, string>>  $unitsByFloor
     * @param  array<string, WorkType>  $typesByCode
     */
    private function parseRow(
        int $number,
        array $row,
        array $floors,
        ?string $root,
        array $unitsByFloor,
        array $typesByCode,
    ): void {
        // Ажлын нэр нь НЭГТГЭСЭН нүднээс болж хоосон байж болно — «ажлын
        // бүрдэл» нь бодит хэмжигдэх мөр учир түүнийг эхэнд үзнэ.
        $name = trim((string) ($row[self::COL_PART] ?? $row[self::COL_NAME] ?? ''));
        $code = trim((string) ($row[self::COL_CODE] ?? ''));

        if ($name === '' || $code === '') {
            return;   // бүлгийн толгой эсвэл хоосон мөр
        }

        $this->sourceRows++;

        $unit = trim((string) ($row[self::COL_UNIT] ?? ''));

        if (! in_array($unit, self::UNITS, true)) {
            $this->fail($number, $code, $name, $unit === ''
                ? 'Хэмжих нэгж хоосон.'
                : "Танигдахгүй нэгж «{$unit}».");

            return;
        }

        // Эхлээд «код + бүрдэл», дараа нь зөвхөн «код».
        $type = $typesByCode[$code.'|'.mb_strtolower($name)] ?? $typesByCode[$code] ?? null;

        if (! $type) {
            $this->fail($number, $code, $name, 'Энэ кодтой ажлын төрөл системд алга.');

            return;
        }

        if ($type->unit !== $unit) {
            $this->fail($number, $code, $name,
                "Нэгж зөрж байна: төсөвт «{$unit}», системд «{$type->unit}».");

            return;
        }

        // --- Түүхий утгуудыг цуглуулна ---
        $raw = [];
        $broken = [];

        /*
         * `array_merge` — `+` БИШ.
         *
         * PHP-д массивын `+` нь ТҮЛХҮҮРЭЭР нэгтгэдэг: `array_keys()` нь
         * хоёулаа 0,1,2… гэсэн индекстэй буцаадаг тул `+` хийвэл хоёр дахь
         * массив бүхэлдээ алдагдана. Өөрөөр хэлбэл «блокт байхгүй давхрын»
         * баганууд огт уншигдахгүй бөгөөд эвдэрсэн нүд тэнд байвал
         * илрэхгүй өнгөрнө.
         */
        foreach (array_merge(array_keys($floors), array_keys($this->missingLocations)) as $column) {
            $value = $row[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_float($value) && ! is_int($value)) {
                $broken[] = (string) $value;

                continue;
            }

            if ((float) $value > 0) {
                $raw[$column] = (float) $value;
            }
        }

        if ($broken !== []) {
            $this->fail($number, $code, $name,
                'Эвдэрсэн нүд: '.implode(', ', array_unique($broken)));

            return;
        }

        if ($raw === []) {
            $this->fail($number, $code, $name, 'Тоо хэмжээ олдсонгүй.');

            return;
        }

        /*
         * Нэгж үнэ — тоон биш бол ХООСОН, таамаглахгүй.
         *
         * Төсөвт зарим мөр үнэгүй (нэмэлт ажил, хэмжилтгүй). 0 гэж бичвэл
         * «үнэгүй ажил» гэсэн утга гарч, тохиролцоонд 0₮ санал болгоно.
         */
        $priceRaw = $row[self::COL_UNIT_PRICE] ?? null;
        $unitPrice = is_numeric($priceRaw) && (float) $priceRaw > 0 ? round((float) $priceRaw, 2) : null;

        $inspector = trim((string) ($row[self::COL_INSPECTOR] ?? '')) ?: null;
        $inspectorId = $this->resolveInspector($inspector);
        $this->currentUnitPrice = $unitPrice;
        $added = match ($type->level) {
            'block' => $this->planBlock($type, $name, $unit, $raw, $root, $inspector, $inspectorId, $number),
            'unit' => $this->planUnits($type, $name, $unit, $raw, $floors, $unitsByFloor, $inspector, $inspectorId, $number),
            default => $this->planFloors($type, $name, $unit, $raw, $floors, $inspector, $inspectorId, $number),
        };

        if ($added === 0) {
            $this->fail($number, $code, $name, 'Тоо хэмжээ нь блокт байхгүй давхарт байна.');
        }
    }

    /** Барилгын түвшний ажил — бүх баганын НИЙЛБЭР нэг мөр болно. */
    private function planBlock(
        WorkType $type, string $name, string $unit, array $raw,
        ?string $root, ?string $inspector, ?int $inspectorId, int $number,
    ): int {
        if (! $root) {
            return 0;
        }

        $this->add($type, $root, $name, $unit, round(array_sum($raw), 3), $inspector, $inspectorId, $number);

        return 1;
    }

    /** Давхрын түвшний ажил — багана бүр нэг мөр. */
    private function planFloors(
        WorkType $type, string $name, string $unit, array $raw,
        array $floors, ?string $inspector, ?int $inspectorId, int $number,
    ): int {
        $added = 0;

        foreach ($raw as $column => $value) {
            if (! isset($floors[$column])) {
                continue;   // блокт байхгүй давхар — тайланд аль хэдийн орсон
            }

            $this->add($type, $floors[$column], $name, $unit, round($value, 3), $inspector, $inspectorId, $number);
            $added++;
        }

        return $added;
    }

    /**
     * Айлын түвшний ажил — давхрын дүнг айлуудад ТЭНЦҮҮ хуваана.
     *
     * Төсөвт айлын багана байхгүй тул өөр арга алга. Айлын загвараар (A2,
     * B2…) ялгах нь илүү зөв ч төсөв тэр мэдээллийг агуулаагүй — энэ нь
     * мэдэгдэж буй ойролцоолол.
     */
    private function planUnits(
        WorkType $type, string $name, string $unit, array $raw,
        array $floors, array $unitsByFloor, ?string $inspector, ?int $inspectorId, int $number,
    ): int {
        $added = 0;

        foreach ($raw as $column => $value) {
            $floorId = $floors[$column] ?? null;
            $units = $floorId ? ($unitsByFloor[$floorId] ?? []) : [];

            if ($units === []) {
                continue;
            }

            $each = round($value / count($units), 3);

            foreach ($units as $unitId) {
                $this->add($type, $unitId, $name, $unit, $each, $inspector, $inspectorId, $number);
                $added++;
            }
        }

        return $added;
    }

    private function add(
        WorkType $type, string $locationId, string $name,
        string $unit, float $quantity, ?string $inspector, ?int $inspectorId, int $number,
    ): void {
        $this->plan[] = [
            'workTypeId' => $type->id,
            'locationId' => $locationId,
            'name' => $type->name,
            'budgetName' => $name,
            'unit' => $unit,
            'quantity' => $quantity,
            'inspector' => $inspector,
            'inspectorId' => $inspectorId,
            'unitPrice' => $this->currentUnitPrice,
            'sourceRow' => $number,
        ];
    }

    /**
     * Төсвийн «Хяналт шалгалт хийх инженер» нэрийг хэрэглэгч рүү хөрвүүлнэ.
     *
     * Төсөвт ЗӨВХӨН ӨВӨР НЭР бичигддэг («Хулан», «Тамир»). Тиймээс
     * `database/data/staff.json`-д тэр нэрийг `budgetName` талбараар
     * ТОДОРХОЙ бичиж холбоно — кодод овог хасаж таамаглахгүй. Таамаглавал
     * «Тамир» нь Б.Сайхантамир эсэх шийдвэрийг код гаргаж байна гэсэн үг
     * болно, тэр нь захиалагчийн шийдэх зүйл.
     *
     * НЭГ НҮДЭНД ХОЁР НЭР байж болно («Тамир, Хулан» — 1 мөр). Багана нь нэг
     * хариуцагч гэсэн утгатай тул ЭХНИЙХИЙГ авна, нөгөөг тайланд дурдана —
     * чимээгүй алдах нь хамгийн муу.
     */
    /** Тухайн мөрийн нэгж үнэ — `add()` руу дамжуулах гүүр. */
    private ?float $currentUnitPrice = null;

    private function resolveInspector(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $names = array_values(array_filter(array_map('trim', explode(',', $raw))));

        if ($names === []) {
            return null;
        }

        if (count($names) > 1) {
            $this->sharedInspectors[$raw] = ($this->sharedInspectors[$raw] ?? 0) + 1;
        }

        $id = $this->inspectorsByName[mb_strtolower($names[0])] ?? null;

        if ($id === null) {
            $this->unknownInspectors[$names[0]] = ($this->unknownInspectors[$names[0]] ?? 0) + 1;
        }

        return $id;
    }

    /**
     * Таних боломжгүй байсан хяналтын инженерүүд.
     *
     * @return array<string, int> нэр → хэдэн мөрөнд таарсан
     */
    public function unknownInspectors(): array
    {
        return $this->unknownInspectors;
    }

    /**
     * Хоёр инженер бичигдсэн нүднүүд — зөвхөн эхнийх хэрэглэгдсэн.
     *
     * @return array<string, int>
     */
    public function sharedInspectors(): array
    {
        return $this->sharedInspectors;
    }

    private function fail(int $row, string $code, string $name, string $reason): void
    {
        $this->errors[] = ['row' => $row, 'code' => $code, 'name' => $name, 'reason' => $reason];
    }

    /** @return array<int, array<string, mixed>> */
    public function plan(): array
    {
        return $this->plan;
    }

    /** @return array<int, array{row: int, code: string, name: string, reason: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<int, string> */
    public function missingLocations(): array
    {
        return array_values($this->missingLocations);
    }

    /** Төсвөөс уншсан мөрийн тоо (ажлын мөрийн тоо БИШ). */
    public function sourceRowCount(): int
    {
        return $this->sourceRows;
    }
}
