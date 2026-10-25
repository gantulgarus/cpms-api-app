<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Location;

/**
 * Импортын ЗАГВАР файл — тухайн блокт тохируулж үүсгэнэ.
 *
 * ЯАГААД БЛОК БҮРТ ТУСАД НЬ ҮҮСГЭДЭГ ВЭ: хоёр задлагч хоёулаа давхрын
 * баганын толгойг блокийн байршлын нэртэй ЯГ тулгадаг. Тогтмол загвар
 * файл тараавал 12 давхрын блокт 16 давхрын багана ирж, зөрүү нь
 * «блокт байхгүй байршил» гэсэн алдаа болон хувирна. Блокоос нь
 * үүсгэвэл энэ ангилалын алдаа БҮРЭН АРИЛНА — инженер зөвхөн тоогоо
 * бөглөнө.
 *
 * ГУРВАН ХУУДАС:
 *   1. Ажлын хөлсний төсөв — баганын байрлал ХАТУУ (доорх тайлбарыг үз)
 *   2. Материалын төсөв    — багана нэрээрээ олддог тул уян хатан
 *   3. Тайлбар             — дүрмүүд, зөвшөөрөгдөх нэгжүүд
 *
 * ЖИШЭЭ МӨРҮҮД нь «ЖИШЭЭ-» гэсэн кодтой. Задлагч тэднийг нэрээр нь
 * таниад татгалздаг тул хэрэглэгч устгахаа мартсан ч төсөвт орохгүй.
 * Загварын мөр чимээгүй орох нь хамгийн муу төгсгөл байх байсан.
 */
class BudgetTemplateSheet
{
    /** Жишээ мөрийн кодын угтвар — задлагч үүгээр нь таниад татгалзана. */
    public const EXAMPLE_PREFIX = 'ЖИШЭЭ';

    /**
     * Ажлын хөлсний төсвийн баганын ХАТУУ байрлал.
     *
     * `WorkBudgetImport`-ийн `COL_*` тогтмолуудтай таарах ёстой. Эдгээр
     * нь нэрээр биш ДУГААРААР уншигддаг тул загвар дээр нэг багана
     * нэмэгдэхэд бүх зүйл гулсана.
     */
    private const WORK_COLUMNS = [
        0 => '№',
        1 => 'Дугаар',
        2 => 'Код',
        3 => 'Тайлбар',
        4 => 'Ажлын төрөл',
        5 => 'Ажлын бүрдэл',
        6 => 'Хяналтын инженер',
        7 => 'Тэмдэглэл',
        8 => 'Хэмжих нэгж',
    ];

    /** Давхрын баганууд: J (9) … AB (27) — задлагчийн хатуу муж. */
    private const WORK_FIRST_FLOOR = 9;

    private const WORK_LAST_FLOOR = 27;

    /** «Нийт» AC (28), «Нэгж үнэ» AD (29) — давхрын тооноос үл хамаарна. */
    private const WORK_TOTAL = 28;

    private const WORK_UNIT_PRICE = 29;

    public function build(Block $block, string $path): string
    {
        $floors = $this->floorNames($block);

        $w = new XlsxWriter(WorkBudgetImport::SHEET);

        $this->workSheet($w, $block, $floors);
        $this->materialSheet($w, $block, $floors);
        $this->notesSheet($w, $block, $floors);

        return $w->save($path);
    }

    /**
     * Блокийн давхруудын нэр — байгаа дарааллаараа.
     *
     * @return array<int, string>
     */
    private function floorNames(Block $block): array
    {
        return Location::where('block_id', $block->id)
            ->where('level', 'floor')
            ->orderBy('sequence_number')
            ->pluck('name')
            ->all();
    }

    /**
     * Ажлын хөлсний төсвийн багана хэд нь загварт багтах вэ.
     *
     * Задлагч J..AB (19 багана) л уншина. Үүнээс олон давхартай блок
     * гарвал чимээгүй тайрахгүй — тайлбар хуудсанд бичнэ.
     */
    private function workFloorLimit(): int
    {
        return self::WORK_LAST_FLOOR - self::WORK_FIRST_FLOOR + 1;
    }

    // ───────────────────── 1. Ажлын хөлсний төсөв ─────────────────────

    /** @param  array<int, string>  $floors */
    private function workSheet(XlsxWriter $w, Block $block, array $floors): void
    {
        $shown = array_slice($floors, 0, $this->workFloorLimit());
        $first = self::WORK_FIRST_FLOOR;
        $afterFloors = $first + count($shown);
        $total = self::WORK_TOTAL;
        $price = self::WORK_UNIT_PRICE;

        $widths = array_fill(0, $price + 1, 13.0);
        $widths[0] = 5;
        $widths[2] = 12;
        $widths[3] = 14;
        $widths[4] = 28;
        $widths[5] = 28;
        $widths[6] = 18;
        $widths[7] = 14;
        $widths[8] = 9;
        $widths[$total] = 14;
        $widths[$price] = 16;
        $w->widths($widths);

        // --- 1-р мөр: гарчиг ---
        $row = array_fill(0, $price + 1, '');
        $row[0] = [$block->name.' — АЖЛЫН ХӨЛСНИЙ ТӨСӨВ', XlsxWriter::TITLE];
        $w->row($row);

        // --- 2-р мөр: үндсэн толгой ---
        $row = array_fill(0, $price + 1, '');

        foreach (self::WORK_COLUMNS as $column => $label) {
            $row[$column] = [$label, XlsxWriter::HEADER];
        }

        $row[$first] = ['Ажлын тоо хэмжээ', XlsxWriter::HEADER];

        /*
         * Давхрын муж нь ҮРГЭЛЖ J..AB — «Нийт» ба «Нэгж үнэ» нь AC, AD
         * дээр ХАТУУ зогсдог тул блокийн давхар цөөн байвал дунд нь
         * ашиглагдаагүй нүх үлдэнэ. Нүхийг тайлбаргүй орхивол хүн
         * «алдаа гарсан юм байна» гэж бодно.
         */
        if ($afterFloors <= self::WORK_LAST_FLOOR) {
            $row[$afterFloors] = ['Нөөц багана — хоосон үлдээнэ', XlsxWriter::HEADER];
        }

        $row[$total] = ['Нийт', XlsxWriter::HEADER];
        $row[$price] = ['Нэгж үнэ', XlsxWriter::HEADER];
        $w->row($row);

        /*
         * --- 3-р мөр: давхрын нэрс ---
         *
         * Задлагч ЗӨВХӨН энэ мөрнөөс давхар хайна (`HEADER_ROW = 3`).
         * Нэр нь блокоос шууд авагдсан тул тулгалт гарцаагүй таарна.
         *
         * Ашиглагдаагүй нөөц баганыг энд ХООСОН үлдээнэ: ямар нэг
         * текст бичвэл задлагч «блокт байхгүй давхар» гэж бүртгэнэ.
         */
        $row = array_fill(0, $price + 1, '');

        foreach ($shown as $i => $name) {
            $row[$first + $i] = [$name, XlsxWriter::HEADER];
        }

        $w->row($row);

        $this->mergeHeader($w, [
            ...array_keys(self::WORK_COLUMNS),
            $total,
            $price,
        ], $first, count($shown), $afterFloors, self::WORK_LAST_FLOOR);

        // --- 4-р мөрөөс: жишээ ---
        $examples = [
            ['1.2', 'Угсралтын ажил', 'Хэв хашмал угсрах', 'м2', 420, 18500],
            ['2.6.1', 'Угсралтын ажил', 'Арматур холбох', 'тн', 12.5, 950000],
            ['17.1.6', 'Дотор засал', 'Плитаны ажил', 'м2', 96, 23000],
        ];

        foreach ($examples as $i => [$code, $name, $part, $unit, $qty, $unitPrice]) {
            $row = array_fill(0, $price + 1, '');

            $row[0] = $i + 1;
            $row[2] = [self::EXAMPLE_PREFIX.'-'.$code, XlsxWriter::CELL];
            $row[4] = [$name, XlsxWriter::CELL];
            $row[5] = [$part, XlsxWriter::CELL];
            $row[6] = ['', XlsxWriter::CELL];
            $row[8] = [$unit, XlsxWriter::CELL];

            // Тоо хэмжээг давхар бүрт — энэ нь файлын гол санаа.
            foreach ($shown as $j => $unused) {
                $row[$first + $j] = [$qty, XlsxWriter::NUMBER];
            }

            $row[$total] = [$qty * count($shown), XlsxWriter::NUMBER];
            $row[$price] = [$unitPrice, XlsxWriter::MONEY];

            $w->row($row);
        }

        $this->exampleWarning($w, $price);
    }

    /**
     * Хоёр давхар толгойг цэгцэлнэ.
     *
     * НЭГ ДАВХАР баганыг 2 ба 3-р мөрөөр нь БОСОО нэгтгэнэ — эс бөгөөс
     * 3-р мөр нь хагас хоосон харагдана. БҮЛГИЙН нэрийг хүүхдүүдийнх нь
     * дээгүүр ХЭВТЭЭ нэгтгэнэ — эс бөгөөс бүлгийн нэр зөвхөн эхний
     * баганын дээр унжсан мэт харагдана.
     *
     * Excel нэгтгэсэн нүдний утгыг зүүн дээд буландаа л хадгалдаг тул
     * задлагчийн уншдаг зүйл ӨӨРЧЛӨГДӨХГҮЙ.
     *
     * @param  array<int, int>  $single  нэг давхар баганын индексүүд
     */
    private function mergeHeader(
        XlsxWriter $w,
        array $single,
        int $groupFrom,
        int $groupCount,
        int $spareFrom,
        int $spareTo,
    ): void {
        foreach ($single as $column) {
            $w->merge($column, 2, $column, 3);
        }

        if ($groupCount > 1) {
            $w->merge($groupFrom, 2, $groupFrom + $groupCount - 1, 2);
        }

        if ($spareFrom <= $spareTo) {
            $w->merge($spareFrom, 2, $spareTo, 3);
        }
    }

    // ───────────────────── 2. Материалын төсөв ─────────────────────

    /** @param  array<int, string>  $floors */
    private function materialSheet(XlsxWriter $w, Block $block, array $floors): void
    {
        $w->sheet(MaterialBudgetImport::SHEET);

        $first = 9;
        $total = $first + count($floors);
        $last = $total + 4;

        $widths = array_fill(0, $last + 1, 13.0);
        $widths[0] = 5;
        $widths[1] = 11;
        $widths[2] = 26;
        $widths[3] = 18;
        $widths[4] = 11;
        $widths[5] = 15;
        $widths[6] = 30;
        $widths[7] = 16;
        $widths[8] = 9;
        $widths[$total] = 13;

        // Үнийн багана ӨРГӨН байх ёстой: нарийн бол Excel «###» болгоно.
        for ($c = $total + 1; $c <= $last; $c++) {
            $widths[$c] = 17;
        }

        $w->widths($widths);

        // --- 1-р мөр: гарчиг ---
        $row = array_fill(0, $last + 1, '');
        $row[0] = [$block->name.' — МАТЕРИАЛЫН ТӨСӨВ', XlsxWriter::TITLE];
        $w->row($row);

        /*
         * --- 2-р мөр: үндсэн толгой ---
         *
         * «Хэмжих нэгж» ХОЁР УДАА гарахаас зайлсхийв: захиалагчийн
         * файлд хоёулаа ижил нэртэй бөгөөд задлагч нь материалынхыг
         * «материалын нэрний ДАРАА байгаа нь» гэж ялгадаг. Загвар дээр
         * хүн уншихаар ялгаатай бичих нь дээр — задлагч нэгдүгээрийг
         * огт уншдаггүй тул аюулгүй.
         */
        $row = array_fill(0, $last + 1, '');
        $row[0] = ['№', XlsxWriter::HEADER];
        $row[1] = ['Ажлын код', XlsxWriter::HEADER];
        $row[2] = ['Ажлын нэр', XlsxWriter::HEADER];
        $row[3] = ['Ажлын дэд нэр', XlsxWriter::HEADER];
        $row[4] = ['Ажлын хэмжих нэгж', XlsxWriter::HEADER];
        $row[5] = ['Материалын код', XlsxWriter::HEADER];
        $row[6] = ['Материалын нэр', XlsxWriter::HEADER];
        $row[7] = ['Материалын хэмжээ', XlsxWriter::HEADER];
        $row[8] = ['Хэмжих нэгж', XlsxWriter::HEADER];
        $row[$first] = ['Материалын тоо хэмжээ', XlsxWriter::HEADER];
        $row[$total] = ['Нийт', XlsxWriter::HEADER];
        $row[$total + 1] = ['Бодит', XlsxWriter::HEADER];
        $row[$total + 3] = ['Зах зээл', XlsxWriter::HEADER];
        $w->row($row);

        // --- 3-р мөр: толгойн үргэлжлэл ---
        $row = array_fill(0, $last + 1, '');

        foreach ($floors as $i => $name) {
            $row[$first + $i] = [$name, XlsxWriter::HEADER];
        }

        $row[$total + 1] = ['Нэгж үнэ', XlsxWriter::HEADER];
        $row[$total + 2] = ['Нийт үнэ', XlsxWriter::HEADER];
        $row[$total + 3] = ['Нэгж үнэ', XlsxWriter::HEADER];
        $row[$total + 4] = ['Нийт үнэ', XlsxWriter::HEADER];
        $w->row($row);

        // Нэг давхар баганыг босоо, бүлгийн нэрийг хэвтээ нэгтгэнэ.
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, $total] as $column) {
            $w->merge($column, 2, $column, 3);
        }

        if (count($floors) > 1) {
            $w->merge($first, 2, $total - 1, 2);
        }

        $w->merge($total + 1, 2, $total + 2, 2);   // Бодит
        $w->merge($total + 3, 2, $total + 4, 2);   // Зах зээл

        /*
         * --- 4-р мөрөөс: жишээ ---
         *
         * Эхний хоёр мөр НЭГ ажилд харьяалагдана: ажлын нэр зөвхөн
         * эхний мөрөнд бичигдэж, хоёр дахь нь хоосон. Энэ нь
         * нэгтгэсэн нүд хэрхэн ажилладгийг харуулах зориулалттай.
         */
        $examples = [
            ['1.2', 'Угсралтын ажил', 'Хэв хашмал', '2.3.1', '20 Пум', '200x1200мм', 'ш', 32, 27500, 29000],
            [null, null, 'Арматур', '2.1.3', 'ф12', 'А500', 'ш', 120, 2450, 2600],
            ['2.6.1', 'Бетон цутгалт', 'Бетон', '3.1.1', 'Бэлэн бетон', 'М250', 'м3', 18.5, 245000, 260000],
        ];

        foreach ($examples as $i => $e) {
            [$workCode, $workName, $sub, $code, $name, $grade, $unit, $qty, $actual, $market] = $e;

            $row = array_fill(0, $last + 1, '');

            $row[0] = $i + 1;
            $row[1] = [$workCode ?? '', XlsxWriter::CELL];
            $row[2] = [$workName ?? '', XlsxWriter::CELL];
            $row[3] = [$sub, XlsxWriter::CELL];
            $row[5] = [self::EXAMPLE_PREFIX.'-'.$code, XlsxWriter::CELL];
            $row[6] = [$name, XlsxWriter::CELL];
            $row[7] = [$grade, XlsxWriter::CELL];
            $row[8] = [$unit, XlsxWriter::CELL];

            foreach ($floors as $j => $unused) {
                $row[$first + $j] = [$qty, XlsxWriter::NUMBER];
            }

            $sum = $qty * count($floors);

            $row[$total] = [$sum, XlsxWriter::NUMBER];
            $row[$total + 1] = [$actual, XlsxWriter::MONEY];
            $row[$total + 2] = [round($actual * $sum, 2), XlsxWriter::MONEY];
            $row[$total + 3] = [$market, XlsxWriter::MONEY];
            $row[$total + 4] = [round($market * $sum, 2), XlsxWriter::MONEY];

            $w->row($row);
        }

        $this->exampleWarning($w, $last);
    }

    /** Жишээ мөрүүдийн доорх анхааруулга. */
    private function exampleWarning(XlsxWriter $w, int $lastColumn): void
    {
        $w->row();

        $row = array_fill(0, $lastColumn + 1, '');
        $row[0] = [
            '↑ Дээрх мөрүүд нь ЖИШЭЭ. Устгаад өөрийн өгөгдлөө бичнэ үү. '
                .'(Кодын «'.self::EXAMPLE_PREFIX.'-» угтвартай мөрийг систем татгалзана.)',
            XlsxWriter::SUBTITLE,
        ];
        $w->row($row);
        $w->mergeLastRow(0, min($lastColumn, 12));
    }

    // ───────────────────── 3. Тайлбар ─────────────────────

    /** @param  array<int, string>  $floors */
    private function notesSheet(XlsxWriter $w, Block $block, array $floors): void
    {
        $w->sheet('Тайлбар');
        $w->widths([42, 78]);

        $w->row([['ТӨСӨВ ОРУУЛАХ ЗААВАР', XlsxWriter::TITLE]]);
        $w->mergeLastRow(0, 1);
        $w->row([['Блок: '.$block->name.' · давхар: '.count($floors), XlsxWriter::SUBTITLE]]);
        $w->row();

        $limit = $this->workFloorLimit();

        /** @var array<int, array{0: string, 1: array<int, array{0: string, 1: string}>}> $sections */
        $sections = [
            ['Хамгийн чухал гурван зүйл', [
                ['Хуудасны нэрийг битгий өөрчил',
                    '«'.WorkBudgetImport::SHEET.'», «'.MaterialBudgetImport::SHEET
                    .'» — систем яг энэ нэрээр хайна. Өөрчилбөл тэр хуудас огт уншигдахгүй.'],
                ['Давхрын баганыг битгий өөрчил',
                    'Багануудын толгой нь энэ блокийн давхруудын нэрээр аль хэдийн бичигдсэн. '
                    .'Нэрийг нь засвал тэр давхрын тоо хэмжээ «блокт байхгүй байршил» гэж татгалзагдана.'],
                ['Жишээ мөрүүдийг устга',
                    'Код нь «'.self::EXAMPLE_PREFIX.'-» угтвартай тул систем татгалзана — '
                    .'гэхдээ алдааны жагсаалтыг цэвэр байлгахын тулд устгасан нь дээр.'],
            ]],

            ['Ажлын хөлсний төсөв — БАГАНЫН БАЙРЛАЛ ХАТУУ', [
                ['Багана нэмж, хасаж болохгүй',
                    'Энэ хуудсыг систем баганын НЭРЭЭР биш ДУГААРААР уншина. Дунд нь нэг багана '
                    .'нэмбэл бүх зүйл гулсаж, бараг бүх мөр татгалзана.'],
                ['Дээр нь мөр нэмж болохгүй',
                    'Толгой нь 2 ба 3-р мөр, өгөгдөл 4-р мөрнөөс эхэлнэ. Гарчиг, огноо нэмбэл гулсана.'],
                ['«Код» (C багана) заавал',
                    'Систем ажлыг НЭРЭЭР биш КОДООР холбоно: төсөвт «Блокон хана», лавлахад '
                    .'«блокон өрлөг» гэж бичигдсэн байдаг тул нэрээр тааруулах боломжгүй. '
                    .'Ямар код байгааг системийн «Лавлах сан» цэснээс хараарай — «Төсвийн код» '
                    .'багана бий. Код нь тэндхийн аль нэгтэй таарахгүй бол тэр мөр орохгүй.'],
                ['Нэг код хэд хуваагдаж болно',
                    '«17.1.6 Плитаны ажил» нь таван ажилд задардаг. Тэр үед F баганын «Ажлын '
                    .'бүрдэл» («Айлын тагт», «Нийтийн эдэлбэр»…) нь лавлах дахь «Бүрдэл»-тэй '
                    .'таарах ёстой. Систем эхлээд «код + бүрдэл»-ээр, олдохгүй бол зөвхөн '
                    .'кодоор хайна.'],
                ['Давхрын багана дээд тал нь '.$limit,
                    count($floors) > $limit
                        ? 'АНХААР: энэ блокт '.count($floors).' давхар байна. Эхний '.$limit
                          .' нь л загварт багтсан — үлдсэнийг нь гараар нэмэх шаардлагатай.'
                        : 'Энэ блокийн '.count($floors).' давхар бүгд багтсан.'],
                ['«Нөөц багана» гэдэг нь юу вэ',
                    'Давхрын муж нь J..AB дээр тогтмол, «Нийт» AC, «Нэгж үнэ» AD дээр зогсоно. '
                    .'Блокийн давхар цөөн бол дунд нь ашиглагдаагүй багана үлдэнэ — хоосон '
                    .'үлдээгээрэй, устгавал «Нэгж үнэ» гулсана.'],
            ]],

            ['Материалын төсөв — УЯН ХАТАН', [
                ['Багануудыг нэрээр нь олдог',
                    'Дунд нь багана нэмж болно. Харин баганын НЭРИЙГ өөрчилж болохгүй.'],
                ['Нэгтгэсэн нүд ажиллана',
                    'Ажлын нэрийг зөвхөн бүлгийн эхний мөрөнд бичиж болно — доорх материалууд '
                    .'тэр ажилд автоматаар харьяалагдана (жишээ 1 ба 2-р мөрийг үз).'],
                ['«Ажлын дэд нэр» багана',
                    'Материалыг бүлэглэх шинж: «Арматур», «Бетон», «Хэв хашмал». Захиалагчийн '
                    .'файлд энэ багана толгойгүй байдаг — тэр хэлбэрийг ч систем таньдаг.'],
                ['«Нийт» нь давхруудын нийлбэртэй таарна',
                    'Систем хоёрыг тулгана. 0.5%-иас их зөрвөл мөрийг татгалзана — энэ нь Excel-ийн '
                    .'томьёо буруу муж заасныг барих зорилготой.'],
            ]],

            ['Ямар тохиолдолд мөр ТАТГАЛЗАГДАХ вэ', [
                ['Хэмжих нэгж танигдахгүй',
                    'Зөвхөн доорх жагсаалтаас. «куб», «кв.м» гэж бичвэл орохгүй — '
                    .'920 м³ бетон 920 ширхэг болж захиалагдахаас сэргийлнэ.'],
                ['Эвдэрсэн нүд', '#REF!, #DIV/0!, эсвэл тоон баганад текст орсон.'],
                ['Код хоосон, эсвэл давхардсан', 'Код бол холбох цорын ганц түлхүүр.'],
                ['Тоо хэмжээ огт алга', 'Бүх давхрын нүд хоосон бол тэр мөр төсөв биш.'],
                ['Нэг муу нүд бүх импортыг сүйтгэхгүй',
                    'Зөвхөн ТЭР МӨР унана. Батлахын өмнө алдаатай мөрүүдийн жагсаалтыг '
                    .'шалтгаантай нь харуулна.'],
            ]],

            ['Татгалзахгүй, гэхдээ хоосон үлдэх', [
                ['Нэгж үнэ',
                    'Хоосон эсвэл 0 бол мөр ордог, үнэ нь хоосон үлдэнэ. Дараа нь системийн '
                    .'«Орсон төсөв» хуудсан дээр гараар шивж болно.'],
                ['Хяналтын инженер',
                    'Нэр нь системийн хэрэглэгчтэй таарахгүй бол ажил үүснэ, шалгагч нь '
                    .'хоосон үлдэнэ. Дараа нь хуваарилна.'],
            ]],
        ];

        foreach ($sections as [$title, $items]) {
            $w->row([[$title, XlsxWriter::HEADER], ['', XlsxWriter::HEADER]]);

            foreach ($items as [$label, $text]) {
                $w->row([[$label, XlsxWriter::LABEL], [$text, XlsxWriter::CELL]]);
            }

            $w->row();
        }

        $w->row([['ЗӨВШӨӨРӨГДӨХ ХЭМЖИХ НЭГЖ', XlsxWriter::HEADER], ['', XlsxWriter::HEADER]]);
        $w->row([
            [WorkBudgetImport::SHEET, XlsxWriter::LABEL],
            [implode(' · ', WorkBudgetImport::UNITS), XlsxWriter::CELL],
        ]);
        $w->row([
            [MaterialBudgetImport::SHEET, XlsxWriter::LABEL],
            [implode(' · ', MaterialBudgetImport::UNITS), XlsxWriter::CELL],
        ]);
        $w->row();

        $w->row([['ЭНЭ БЛОКИЙН ДАВХРУУД', XlsxWriter::HEADER], ['', XlsxWriter::HEADER]]);

        foreach ($floors as $i => $name) {
            $w->row([[($i + 1).'.', XlsxWriter::LABEL], [$name, XlsxWriter::CELL]]);
        }
    }
}
