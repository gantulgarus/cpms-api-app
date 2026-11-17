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
        $w->widths([6, 8, 18, 12, 28, 48, 12, 12, 12, ...array_fill(0, 19, 10), 16, 18]);
        $w->row([[$block->name.' — АЖЛЫН ХӨЛСНИЙ ҮНЭ', XlsxWriter::TITLE]]);
        $header = array_fill(0, 30, '');
        foreach ([0 => '№', 2 => 'Ажлын код', 4 => 'Бүлэг', 5 => 'Хийгдэх ажлын нэр', 8 => 'Хэмжих нэгж', 28 => 'Одоогийн тоо хэмжээ', 29 => 'Нэгж үнэ'] as $column => $label) $header[$column] = [$label, XlsxWriter::HEADER];
        $w->row($header);
        $w->row(['Зөвхөн нэгж үнийг бөглөнө. Код, нэгжийг хадгална. Тоо хэмжээг импортоор өөрчлөхгүй.']);
        $groups = $block->workItems()->with('workType.catalogEntry')->get()->groupBy('work_type_id');
        $i = 0;
        foreach ($groups as $items) {
            $type = $items->first()->workType;
            $row = array_fill(0, 30, '');
            $row[0] = ++$i;
            $row[2] = \App\Services\Import\WorkPriceImport::codeFor($type);
            $row[4] = $type->catalogEntry?->group_name ?? $type->group?->name;
            $row[5] = $type->name;
            $row[8] = $type->unit;
            $row[28] = [$items->sum('planned_qty'), XlsxWriter::NUMBER];
            $prices = $items->pluck('budget_unit_price')->unique();
            $row[29] = [$prices->count() === 1 ? $prices->first() : null, XlsxWriter::MONEY];
            $w->row($row);
        }
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
        $w->widths([35, 100]);
        $w->row([['ТӨСВИЙН ҮНЭ ОРУУЛАХ', XlsxWriter::TITLE]]);
        $w->row(['Ажлын хөлсний төсөв', 'Барилгад үүссэн ажлууд бэлэн байна. Зөвхөн AD баганад нэгж үнийг бөглөнө.']);
        $w->row(['Ажлын код', 'C баганын WT код, I баганын хэмжих нэгжийг өөрчлөхгүй. Нэрээр ойролцоолж тааруулахгүй.']);
        $w->row(['Тоо хэмжээ', 'AC багана нь одоогийн хэмжээг харуулна. Импорт тоо хэмжээ, гүйцэтгэл, инженер, огноог өөрчлөхгүй.']);
        $w->row(['Хоосон үнэ', 'Хоосон үнийг алгасана. 0 бол үнэгүй ажил гэсэн утгаар оруулна.']);
        $w->row(['Ажил олдохгүй бол', 'Эхлээд загвараас эсвэл гараар тухайн барилгад ажил нэмнэ. Үнийн импорт шинэ ажил үүсгэхгүй.']);
        $w->row(['Материалын төсөв', 'Тусдаа материалын төсвийн хуудас. Жишээ мөрүүдийг бодит өгөгдлөөр солино.']);
        $w->row(['Давхардсан код', 'Ижил ажилд нэг удаа үнэ өгнө. Алдаатай мөр байвал файлыг засаад дахин оруулна.']);
    }
}
