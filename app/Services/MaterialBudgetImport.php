<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Location;
use App\Models\WorkType;

/**
 * «Материалын төсөв» хуудсыг задлах.
 *
 * ФАЙЛЫН БҮТЭЦ (захиалагчийн жинхэнэ файл, хоёр давхар толгой):
 *
 *   мөр 2 │ № │ Ажлын нэр │ … │ Материалын нэр │ … │ Материалын тоо хэмжээ ──┐  Бодит │ Зах зээл
 *   мөр 3 │                                          Суурь │ Зоорь │ 1-р │ … │  Нэгж үнэ │ Нэгж үнэ
 *   мөр 4+│ өгөгдөл
 *
 * ГУРВАН ЗҮЙЛ ЭНЭ ФАЙЛЫГ ОНЦГОЙ БОЛГОНО:
 *
 * 1. БАЙРШЛААР ЗАДАРСАН. 12–31 багана нь «Суурь, Зоорийн давхар, 1-16-р
 *    давхар, Техникийн давхар, Парпет» — системийн байршлын модтой ЯГ ижил.
 *    Инженер аль хэдийн давхраар бодож байна; бид зөвхөн уншина.
 *
 * 2. НЭГТГЭСЭН НҮД. Ажлын нэр зөвхөн бүлгийн ЭХНИЙ мөрөнд бичигдэнэ, доорх
 *    материалууд хоосон. Мөр мөрөөр уншдаг задлагч 273 мөрийн 15-ыг нь л
 *    ажилтай холбоно. Тиймээс дээшээ нөхөж (forward-fill) уншина.
 *
 * 3. «Материалын хэмжээ» ГЭДЭГ БАГАНА ХЭМЖЭЭ БИШ. М250, 200x1200мм — марк.
 *    Толгой нь төөрөгдүүлдэг тул кодод тодорхой нэрлэв: `grade`.
 *
 * БАГАНЫГ БАЙРЛАЛААР БИШ ТОЛГОЙН НЭРЭЭР олно. Инженер багана нэмэхэд
 * импорт эвдрэхгүй, харин нэрийг нь өөрчилбөл олдохгүй — энэ нь зориуд:
 * чимээгүй буруу багана уншихаас олдохгүй гэж хэлэх нь дээр.
 *
 * ЭНЭ КЛАСС БИЧДЭГГҮЙ. Зөвхөн уншиж, шалгаж, төлөвлөгөө буцаана. Бичих
 * эсэхийг команд шийднэ — ингэснээр «урьдчилан харах» горим жинхэнэ
 * бичилттэй ЯГ ижил логикоор ажиллана.
 */
class MaterialBudgetImport
{
    public const SHEET = 'Материалын төсөв';

    /**
     * Хүлээн зөвшөөрөх нэгжүүд.
     *
     * Захиалагчийн файлаас бодитоор уншигдсан 10 нэгж. Танигдахгүй нэгжтэй
     * мөрийг импортлохгүй: «м3» гэж бичих ёстой газар «куб» гэж бичигдсэн
     * бол 920 м³ бетон 920 ширхэг болж захиалагдана.
     */
    public const UNITS = ['м', 'м2', 'м3', 'ш', 'тн', 'кг', 'литр', 'шуудай', 'б', 'ж/х'];

    /**
     * «Нийт» багана ба задаргааны нийлбэрийн зөвшөөрөх зөрүү (хувь).
     *
     * Excel-ийн бутархай дугуйралт 0.1%-аас хэтрэхгүй. Үүнээс их зөрүү нь
     * томьёо буруу муж заасан гэсэн үг — яг тэр алдаа нь чимээгүй өнгөрвөл
     * хамгийн үнэтэй нь.
     */
    private const TOTAL_TOLERANCE = 0.5;

    /** Дэд нийлбэрийн мөрүүд — материал биш. */
    private const SUBTOTAL_LABELS = ['нийт', 'дүн', 'нийт дүн'];

    /** @var array<int, array<string, mixed>> */
    private array $plan = [];

    /** @var array<int, array{row: int, code: string, name: string, reason: string}> */
    private array $errors = [];

    /** @var array<int, string> Файлд багана нь бий ч блокт байхгүй байршлууд. */
    private array $missingLocations = [];

    /** @var array<string, int> код → эхлээд таарсан мөр (давхардал олоход) */
    private array $seenCodes = [];

    private int $sourceRows = 0;

    private int $skippedSubtotals = 0;

    /** @var array<string, string> байршлын id → нэр */
    private array $locationNames = [];

    public function __construct(private readonly Block $block) {}

    /**
     * @param  array<string, WorkType>  $typesByCode  «код» → ажлын төрөл
     */
    public function parse(XlsxReader $reader, array $typesByCode = []): self
    {
        $rows = $reader->rows(self::SHEET);

        $labelRow = $this->findLabelRow($rows);

        if ($labelRow === null) {
            $this->errors[] = [
                'row' => 0, 'code' => '', 'name' => '',
                'reason' => 'Толгой мөр олдсонгүй — «Материалын нэр» гэсэн багана байх ёстой.',
            ];

            return $this;
        }

        $continuation = $this->isContinuationRow($rows[$labelRow + 1] ?? []);
        $headers = $this->headers($rows[$labelRow], $continuation ? ($rows[$labelRow + 1] ?? []) : []);
        $map = $this->columnRoles($headers);

        $required = [
            'materialCode' => 'Материалын код',
            'materialName' => 'Материалын нэр',
            'unit' => 'Хэмжих нэгж (материалын нэрний дараа)',
        ];

        $missing = array_values(array_intersect_key(
            $required,
            array_diff_key($required, $map),
        ));

        if ($missing !== []) {
            $this->fail($labelRow, '', '', 'Заавал байх багана дутуу: '.implode(', ', $missing));

            return $this;
        }

        $locations = $this->locationColumns($headers, $map);
        $firstData = $labelRow + ($continuation ? 2 : 1);

        /** @var array{code: ?string, name: ?string, sub: ?string} $carry Нэгтгэсэн нүднээс нөхөх утгууд. */
        $carry = ['code' => null, 'name' => null, 'sub' => null];

        foreach ($rows as $number => $row) {
            if ($number < $firstData) {
                continue;
            }

            $this->carryWork($row, $map, $carry);
            $this->parseRow($number, $row, $map, $locations, $carry, $typesByCode);
        }

        return $this;
    }

    // ───────────────────────────── толгой ─────────────────────────────

    /**
     * «Материалын нэр» гэсэн багана агуулсан мөрийг олно.
     *
     * Толгойн байрлалыг хатуу бичихгүй: захиалагчийн файлд 2-р мөр, бидний
     * загварт 1-р мөр. Нэмэлт лого, огноо дээр нь нэмэгдвэл бас шилжинэ.
     *
     * @param  array<int, array<int, string|float|null>>  $rows
     */
    private function findLabelRow(array $rows): ?int
    {
        foreach ($rows as $number => $row) {
            if ($number > 10) {
                break;
            }

            foreach ($row as $value) {
                if (is_string($value) && trim($value) === 'Материалын нэр') {
                    return $number;
                }
            }
        }

        return null;
    }

    /**
     * Дараагийн мөр нь толгойн ҮРГЭЛЖЛЭЛ үү, эсвэл өгөгдөл үү.
     *
     * Захиалагчийн файлд 3-р мөр нь давхруудын нэр (толгойн хоёр дахь
     * давхар). Бидний загварт 2-р мөр нь жишээ өгөгдөл. Ялгах шалгуур:
     * ТООН УТГА агуулаагүй, гурваас олон текст нүдтэй.
     *
     * @param  array<int, string|float|null>  $row
     */
    private function isContinuationRow(array $row): bool
    {
        $texts = 0;

        foreach ($row as $value) {
            if (is_int($value) || is_float($value)) {
                return false;
            }

            if (is_string($value) && trim($value) !== '') {
                $texts++;
            }
        }

        return $texts >= 3;
    }

    /**
     * Багана бүрийн толгой — бүлгийн нэр ба өөрийн нэр.
     *
     * Хоёр давхар толгойтой файлд нэг багана ХОЁР нэртэй байна:
     * 33-р багана нь дээр «Бодит», доор «Нэгж үнэ». Аль нэгийг нь сонгож
     * авч болохгүй — «Нэгж үнэ» гэдэг нь 35-р баганад бас байна, «Бодит»
     * гэдэг нь 34-т бас хамаарна. Хоёуланг нь хадгалж, хослолоор ялгана.
     *
     * @param  array<int, string|float|null>  $upper
     * @param  array<int, string|float|null>  $lower
     * @return array<int, array{group: string, label: string}>
     */
    private function headers(array $upper, array $lower): array
    {
        $headers = [];

        foreach (array_unique([...array_keys($upper), ...array_keys($lower)]) as $column) {
            $top = trim((string) ($upper[$column] ?? ''));
            $bottom = trim((string) ($lower[$column] ?? ''));

            if ($top === '' && $bottom === '') {
                continue;
            }

            $headers[$column] = [
                'group' => $top,
                // Доод давхар нь илүү ТОДОРХОЙ: «Суурь» нь «Материалын тоо
                // хэмжээ» гэсэн бүлгийн нэрээс илүү хэрэгтэй.
                'label' => $bottom !== '' ? $bottom : $top,
            ];
        }

        ksort($headers);

        return $headers;
    }

    /**
     * Толгойн нэрээс баганын үүргийг тогтооно.
     *
     * @param  array<int, array{group: string, label: string}>  $headers
     * @return array<string, int>
     */
    private function columnRoles(array $headers): array
    {
        $map = [];

        foreach ($headers as $column => $header) {
            $label = $header['label'];
            $both = $header['group'].' '.$header['label'];

            match (true) {
                $label === 'Ажлын код' => $map['workCode'] ??= $column,
                $label === 'Ажлын нэр' => $map['workName'] ??= $column,
                in_array($label, ['Материалын код', 'Код'], true) => $map['materialCode'] ??= $column,
                $label === 'Материалын нэр' => $map['materialName'] ??= $column,
                $label === 'Нийт' => $map['total'] ??= $column,

                /*
                 * Марк. Захиалагчийн файлд «Материалын хэмжээ» гэсэн
                 * ТӨӨРӨГДҮҮЛЭХ нэртэй — тоо хэмжээ биш, М250 гэх мэт анги.
                 */
                in_array($label, ['Материалын хэмжээ', 'Марк', 'Марк / хэмжээс'], true)
                    => $map['grade'] ??= $column,

                /*
                 * Ажлын дэд нэр — НЭРЛЭГДСЭН хувилбар.
                 *
                 * Захиалагчийн файлд энэ багана толгойгүй байдаг (доорх
                 * нөхөх дүрмийг үз). Бидний үүсгэдэг загварт нэртэй:
                 * толгойгүй багана нь хүнд ойлгомжгүй, санамсаргүй
                 * бөглөх эрсдэлтэй.
                 */
                in_array($label, ['Ажлын дэд нэр', 'Бүрдэл', 'Ажлын бүрдэл'], true)
                    => $map['workSub'] ??= $column,

                // Үнэ: бүлэг ба нэрийн ХОСЛОЛООР. Зөвхөн нэгж үнэ хэрэгтэй —
                // нийт үнэ нь томьёо бөгөөд бид өөрсдөө бодно.
                str_contains($both, 'Бодит') && ! str_contains($both, 'Нийт үнэ')
                    => $map['actualPrice'] ??= $column,
                str_contains($both, 'Зах зээл') && ! str_contains($both, 'Нийт үнэ')
                    => $map['marketPrice'] ??= $column,

                default => null,
            };
        }

        /*
         * «Хэмжих нэгж» нь ХОЁР УДАА гарна: ажлын нэгж, материалын нэгж.
         *
         * Материалынх нь материалын нэрний ДАРАА байна. Эхнийхийг нь авбал
         * бетоны м³ биш, ханын м² уншигдана.
         */
        if (isset($map['materialName'])) {
            foreach ($headers as $column => $header) {
                if ($column > $map['materialName'] && $header['label'] === 'Хэмжих нэгж') {
                    $map['unit'] = $column;
                    break;
                }
            }
        }

        /*
         * Ажлын дэд нэр — нэргүй багана ажлын нэрний яг хажууд.
         *
         * Захиалагчийн файлд 2-р багана толгойгүй атлаа «Арматур», «Бетон»,
         * «Хэв хашмал» гэсэн утгатай. Тэр нь материалыг бүлэглэх цорын
         * гагц шинж тул хаяж болохгүй.
         */
        if (! isset($map['workSub'])
            && isset($map['workName'])
            && ! isset($headers[$map['workName'] + 1])
        ) {
            $map['workSub'] = $map['workName'] + 1;
        }

        return $map;
    }

    /**
     * Байршлын багана → байршлын id.
     *
     * Байршлын баганууд нь материалын нэгж ба «Нийт» хоёрын ХООРОНД байна.
     * Энэ нь нэрсийн жагсаалт хатуу бичихээс дээр: давхрын тоо блок бүрт
     * өөр, «Парпет» зэрэг нэр файл бүрт өөр.
     *
     * @param  array<int, array{group: string, label: string}>  $headers
     * @param  array<string, int>  $map
     * @return array<int, string>
     */
    private function locationColumns(array $headers, array $map): array
    {
        $from = $map['unit'];
        $to = $map['total']
            ?? $map['actualPrice']
            ?? $map['marketPrice']
            ?? PHP_INT_MAX;

        $byName = Location::where('block_id', $this->block->id)
            ->pluck('id', 'name')
            ->all();

        $columns = [];

        foreach ($headers as $column => $header) {
            if ($column <= $from || $column >= $to) {
                continue;
            }

            $name = $header['label'];

            if (isset($byName[$name])) {
                $columns[$column] = $byName[$name];
                $this->locationNames[$byName[$name]] = $name;
            } else {
                $this->missingLocations[$column] = $name;
            }
        }

        return $columns;
    }

    // ───────────────────────────── мөрүүд ─────────────────────────────

    /**
     * Нэгтгэсэн нүднээс үлдсэн утгыг нөхнө.
     *
     * Ажлын нэр хоосон мөр нь «энэ ажил дууслаа» гэсэн үг БИШ — «дээрхтэй
     * ижил» гэсэн үг. Хоосон гэж үзвэл 273 материалын 258 нь эзэнгүй болно.
     *
     * @param  array<int, string|float|null>  $row
     * @param  array<string, int>  $map
     * @param  array{code: ?string, name: ?string, sub: ?string}  $carry
     */
    private function carryWork(array $row, array $map, array &$carry): void
    {
        if (isset($map['workCode'])) {
            $code = trim((string) ($row[$map['workCode']] ?? ''));

            if ($code !== '') {
                $carry['code'] = $code;
            }
        }

        $name = isset($map['workName']) ? trim((string) ($row[$map['workName']] ?? '')) : '';
        $sub = isset($map['workSub']) ? trim((string) ($row[$map['workSub']] ?? '')) : '';

        /*
         * Ажлын нэр ба дэд нэр ТУСДАА нөхөгдөнө.
         *
         * «Угсралт» гэсэн ажлын доор «Арматур» → «Бетон» → «Хэв хашмал» гэж
         * дэд нэр солигдоно, ажлын нэр хэвээрээ. Нэг мөрөнд шахаж хадгалвал
         * дэд нэр солигдох бүрт ажлын нэр алдагдана.
         *
         * Шинэ ажлын нэр гарвал хуучин дэд нэр хүчингүй болно — эс тэгвээс
         * «Дотор засал · Арматур» гэсэн худал нэр үүснэ.
         */
        if ($name !== '') {
            $carry['name'] = $name;
            $carry['sub'] = null;
        }

        if ($sub !== '' && ! in_array(mb_strtolower(rtrim($sub)), self::SUBTOTAL_LABELS, true)) {
            $carry['sub'] = $sub;
        }
    }

    /**
     * @param  array{code: ?string, name: ?string, sub: ?string}  $carry
     */
    private function workLabel(array $carry): ?string
    {
        $parts = array_filter([$carry['name'], $carry['sub']]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @param  array<int, string|float|null>  $row
     * @param  array<string, int>  $map
     * @param  array<int, string>  $locations
     * @param  array{code: ?string, name: ?string, sub: ?string}  $carry
     * @param  array<string, WorkType>  $typesByCode
     */
    private function parseRow(
        int $number,
        array $row,
        array $map,
        array $locations,
        array $carry,
        array $typesByCode,
    ): void {
        $name = trim((string) ($row[$map['materialName']] ?? ''));

        if ($name === '') {
            return;   // бүлгийн толгой эсвэл хоосон мөр
        }

        if (in_array(mb_strtolower(rtrim($name)), self::SUBTOTAL_LABELS, true)) {
            $this->skippedSubtotals++;

            return;   // «нийт» мөр — Excel-ийн дэд дүн, материал биш
        }

        $this->sourceRows++;

        $code = trim((string) ($row[$map['materialCode']] ?? ''));

        if ($code === '') {
            $this->fail($number, '', $name, 'Материалын код хоосон — холбох түлхүүр алга.');

            return;
        }

        /*
         * Давхардсан код нь ЧИМЭЭГҮЙ дарж бичигдэх эрсдэлтэй.
         *
         * `unique(block_id, material_id)` тул хоёр дахь мөр эхнийхийг
         * дарна. Тоо хэмжээ нь нэгдэхгүй, алга болно.
         */
        if (isset($this->seenCodes[$code])) {
            $this->fail($number, $code, $name,
                "Код давхардсан — {$this->seenCodes[$code]}-р мөрөнд аль хэдийн байна.");

            return;
        }

        $unit = trim((string) ($row[$map['unit']] ?? ''));

        if (! in_array($unit, self::UNITS, true)) {
            $this->fail($number, $code, $name, $unit === ''
                ? 'Хэмжих нэгж хоосон.'
                : "Танигдахгүй нэгж «{$unit}».");

            return;
        }

        // --- тоо хэмжээ ба эвдэрсэн нүд ---
        $quantities = [];
        $broken = [];
        $outside = [];

        foreach ($locations as $column => $locationId) {
            $value = $row[$column] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_int($value) && ! is_float($value)) {
                $broken[] = (string) $value;

                continue;
            }

            if ((float) $value > 0) {
                $quantities[$locationId] = round((float) $value, 3);
            }
        }

        /*
         * Блокт БАЙХГҮЙ байршилд тоо хэмжээ байвал мөрийг татгалзана.
         *
         * Чимээгүй алгасвал 16 давхрын файлыг 12 давхрын блокт оруулахад
         * 4 давхрын материал алга болох бөгөөд нийт дүн 25% дутуу гарна.
         * Хэн ч анзаарахгүй.
         */
        foreach ($this->missingLocations as $column => $title) {
            $value = $row[$column] ?? null;

            if (is_int($value) || is_float($value)) {
                if ((float) $value > 0) {
                    $outside[] = $title;
                }
            } elseif ($value !== null && $value !== '') {
                $broken[] = (string) $value;
            }
        }

        foreach (['actualPrice', 'marketPrice'] as $role) {
            $value = isset($map[$role]) ? ($row[$map[$role]] ?? null) : null;

            if ($value !== null && $value !== '' && ! is_int($value) && ! is_float($value)) {
                $broken[] = (string) $value;
            }
        }

        if ($broken !== []) {
            $this->fail($number, $code, $name,
                'Эвдэрсэн нүд: '.implode(', ', array_slice(array_unique($broken), 0, 3)));

            return;
        }

        if ($outside !== []) {
            $this->fail($number, $code, $name,
                'Блокт байхгүй байршилд тоо хэмжээ: '.implode(', ', array_unique($outside)));

            return;
        }

        if ($quantities === []) {
            $this->fail($number, $code, $name, 'Тоо хэмжээ олдсонгүй.');

            return;
        }

        $total = round(array_sum($quantities), 3);

        if (! $this->totalMatches($row, $map, $total)) {
            $declared = (float) $row[$map['total']];
            $this->fail($number, $code, $name, sprintf(
                'Нийлбэр зөрж байна: файлд %s, давхруудын нийлбэр %s.',
                rtrim(rtrim(number_format($declared, 3, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format($total, 3, '.', ''), '0'), '.'),
            ));

            return;
        }

        /*
         * Загварын ЖИШЭЭ мөрийг хэзээ ч оруулахгүй.
         *
         * Татаж авсан загвар дээр жишээ мөрүүд байдаг. Хэрэглэгч тэднийг
         * устгахаа мартвал зохиомол материал төсөвт орж, захиалгын
         * жагсаалтад жинхэнэ мөрүүдийн дунд харагдана.
         *
         * ШАЛГАЛТУУДЫН ХАМГИЙН СҮҮЛД зогсож байгаа нь санамсаргүй биш:
         * ингэснээр загварын жишээ мөр нь бүх форматын шалгуурыг давж
         * байж энэ алдаанд хүрнэ. Өөрөөр хэлбэл «цорын ганц алдаа нь
         * жишээ мөр» гэдэг нь загварын бүтэц ЗӨВ гэсэн баталгаа болно
         * (`BudgetTemplateTest` яг үүнийг шалгадаг).
         */
        if (str_starts_with(mb_strtoupper($code), BudgetTemplateSheet::EXAMPLE_PREFIX)) {
            $this->fail($number, $code, $name, 'Загварын жишээ мөр — устгана уу.');

            return;
        }

        $this->seenCodes[$code] = $number;

        $workCode = $carry['code'];

        $this->plan[] = [
            'code' => $code,
            'name' => $name,
            'grade' => isset($map['grade'])
                ? (trim((string) ($row[$map['grade']] ?? '')) ?: null)
                : null,
            'unit' => $unit,
            'workTypeId' => $workCode !== null ? ($typesByCode[$workCode]->id ?? null) : null,
            'workLabel' => $this->workLabel($carry),
            'quantities' => $quantities,
            'total' => $total,
            'actualPrice' => $this->price($row, $map, 'actualPrice'),
            'marketPrice' => $this->price($row, $map, 'marketPrice'),
            'sourceRow' => $number,
        ];
    }

    /**
     * Файлын «Нийт» багана задаргааны нийлбэртэй тохирч байна уу.
     *
     * ЯАГААД ШАЛГАХ ХЭРЭГТЭЙ ВЭ: «Нийт» нь Excel-ийн томьёо. Томьёо нь
     * буруу муж заасан байж болно — жишээ нь 16 давхрын оронд 14-ийг.
     * Тэр үед файл дотроо зөв харагдана: нийт дүн бодогдож, хүн харахад
     * эвгүй зүйл алга. Зөвхөн задаргаатай тулгаж байж илэрнэ.
     *
     * Томьёо тасарсан (`#REF!`) бол шалгах зүйл алга — тэр мөр аль хэдийн
     * эвдэрсэн нүдээр татгалзсан байна.
     *
     * @param  array<int, string|float|null>  $row
     * @param  array<string, int>  $map
     */
    private function totalMatches(array $row, array $map, float $sum): bool
    {
        if (! isset($map['total'])) {
            return true;
        }

        $declared = $row[$map['total']] ?? null;

        if (! is_int($declared) && ! is_float($declared)) {
            return true;
        }

        $declared = (float) $declared;

        if ($declared <= 0) {
            return true;
        }

        return abs($declared - $sum) / $declared * 100 <= self::TOTAL_TOLERANCE;
    }

    /**
     * Нэгж үнэ — тоон биш эсвэл эерэг биш бол ХООСОН, таамаглахгүй.
     *
     * 0 гэж бичвэл «үнэгүй материал» гэсэн утга гарч, захиалгын хуудсанд
     * 0₮-өөр гарна.
     *
     * @param  array<int, string|float|null>  $row
     * @param  array<string, int>  $map
     */
    private function price(array $row, array $map, string $role): ?float
    {
        if (! isset($map[$role])) {
            return null;
        }

        $value = $row[$map[$role]] ?? null;

        return is_numeric($value) && (float) $value > 0 ? round((float) $value, 2) : null;
    }

    private function fail(int $row, string $code, string $name, string $reason): void
    {
        $this->errors[] = ['row' => $row, 'code' => $code, 'name' => $name, 'reason' => $reason];
    }

    // ───────────────────────────── үр дүн ─────────────────────────────

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
        return array_values(array_unique($this->missingLocations));
    }

    /** Материалын мөрийн тоо (дэд нийлбэр, хоосон мөр орохгүй). */
    public function sourceRowCount(): int
    {
        return $this->sourceRows;
    }

    public function skippedSubtotalCount(): int
    {
        return $this->skippedSubtotals;
    }

    /** @return array<string, string> */
    public function locationNames(): array
    {
        return $this->locationNames;
    }

    /**
     * Төлөвлөгөөний нийт өртөг — хоёр үнээр.
     *
     * @return array{actual: float, market: float, withoutActual: int, withoutMarket: int}
     */
    public function totals(): array
    {
        $actual = 0.0;
        $market = 0.0;
        $withoutActual = 0;
        $withoutMarket = 0;

        foreach ($this->plan as $line) {
            if ($line['actualPrice'] === null) {
                $withoutActual++;
            } else {
                $actual += $line['actualPrice'] * $line['total'];
            }

            if ($line['marketPrice'] === null) {
                $withoutMarket++;
            } else {
                $market += $line['marketPrice'] * $line['total'];
            }
        }

        return [
            'actual' => round($actual, 2),
            'market' => round($market, 2),
            'withoutActual' => $withoutActual,
            'withoutMarket' => $withoutMarket,
        ];
    }
}
