<?php

namespace App\Services;

use ZipArchive;

/**
 * Хамааралгүй .xlsx бичигч.
 *
 * ЯАГААД ГАДНЫ САН АШИГЛААГҮЙ ВЭ: төсөл ердөө дөрвөн composer хамааралтай.
 * Нэг тайлангийн төлөө PhpSpreadsheet (болон түүний арав гаруй дэд сан)
 * нэмэх нь суулгалт, шинэчлэлт, аюулгүй байдлын шинэчлэлтийг тус бүрд нь
 * дагуулна. Бидэнд хэрэгтэй нь: хүснэгт, толгой, нийлбэр, хүрээ — тэднийг
 * бичихэд 200 орчим мөр л хэрэгтэй.
 *
 * .xlsx нь XML файлуудын ZIP архив. Мөрийн текстийг `inlineStr` хэлбэрээр
 * бичнэ — `sharedStrings.xml` хэрэггүй болж, код хоёр дахин богиносно.
 *
 * ХЯЗГААР: томьёо, зураг, олон хуудас дэмжихгүй. Хэрэгтэй болбол PhpSpreadsheet
 * рүү шилжинэ.
 */
class XlsxWriter
{
    /** Загварын индекс — `styles.xml` доторх `cellXfs` дарааллаар. */
    public const PLAIN = 0;

    public const TITLE = 1;

    public const SUBTITLE = 2;

    public const HEADER = 3;

    public const CELL = 4;

    public const NUMBER = 5;

    public const TOTAL_TEXT = 6;

    public const TOTAL_NUMBER = 7;

    public const LABEL = 8;

    /** @var array<int, array<int, array{0: string|int|float|null, 1: int}>> */
    private array $rows = [];

    /** @var array<int, float> */
    private array $columnWidths = [];

    /** @var array<int, string> */
    private array $merges = [];

    public function __construct(private readonly string $sheetName = 'Хуудас1') {}

    /**
     * Мөр нэмнэ.
     *
     * @param  array<int, mixed>  $cells  утга эсвэл `[утга, загвар]`
     */
    public function row(array $cells = []): self
    {
        $this->rows[] = array_map(
            fn ($cell) => is_array($cell) ? [$cell[0], $cell[1] ?? self::PLAIN] : [$cell, self::PLAIN],
            $cells,
        );

        return $this;
    }

    /** @param  array<int, float>  $widths  баганын өргөн (тэмдэгтээр) */
    public function widths(array $widths): self
    {
        $this->columnWidths = $widths;

        return $this;
    }

    /** Одоогийн сүүлийн мөрөнд нүд нэгтгэнэ: `merge(0, 5)` = A..F. */
    public function mergeLastRow(int $from, int $to): self
    {
        $r = count($this->rows);
        $this->merges[] = self::cellRef($from, $r).':'.self::cellRef($to, $r);

        return $this;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    /** Файлыг үүсгээд замыг нь буцаана. */
    public function save(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Файл үүсгэж чадсангүй: {$path}");
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());
        $zip->close();

        return $path;
    }

    // -- XML хэсгүүд ------------------------------------------------------

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        $name = self::esc(mb_substr($this->sheetName, 0, 31));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$name.'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /**
     * Загварууд.
     *
     * `cellXfs`-ийн ДАРААЛАЛ нь дээрх тогтмолуудтай яг таарах ёстой —
     * индексээр холбогддог тул дунд нь шинэ загвар оруулбал бүх нүд гулсана.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.000"/></numFmts>'
            .'<fonts count="5">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'                                   // 0 энгийн
            .'<font><b/><sz val="16"/><color rgb="FF22303A"/><name val="Calibri"/></font>'         // 1 гарчиг
            .'<font><sz val="10"/><color rgb="FF5B6B75"/><name val="Calibri"/></font>'             // 2 туслах
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'         // 3 толгой
            .'<font><b/><sz val="11"/><color rgb="FF22303A"/><name val="Calibri"/></font>'         // 4 тод
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF22303A"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF1EFE9"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border>'
            .'<left style="thin"><color rgb="FFC9C4B8"/></left>'
            .'<right style="thin"><color rgb="FFC9C4B8"/></right>'
            .'<top style="thin"><color rgb="FFC9C4B8"/></top>'
            .'<bottom style="thin"><color rgb="FFC9C4B8"/></bottom>'
            .'<diagonal/>'
            .'</border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="9">'
            // 0 PLAIN
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            // 1 TITLE
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            // 2 SUBTITLE
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            // 3 HEADER
            .'<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 4 CELL
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'
            .'<alignment vertical="center" wrapText="1"/></xf>'
            // 5 NUMBER
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="right" vertical="center"/></xf>'
            // 6 TOTAL_TEXT
            .'<xf numFmtId="0" fontId="4" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment vertical="center"/></xf>'
            // 7 TOTAL_NUMBER
            .'<xf numFmtId="164" fontId="4" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            .'<alignment horizontal="right" vertical="center"/></xf>'
            // 8 LABEL
            .'<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function sheet(): string
    {
        $cols = '';
        if ($this->columnWidths !== []) {
            $cols = '<cols>';
            foreach ($this->columnWidths as $i => $w) {
                $n = $i + 1;
                $cols .= '<col min="'.$n.'" max="'.$n.'" width="'.$w.'" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }

        $body = '';
        foreach ($this->rows as $r => $cells) {
            $rowNo = $r + 1;
            $body .= '<row r="'.$rowNo.'">';
            foreach ($cells as $c => [$value, $style]) {
                $ref = self::cellRef($c, $rowNo);

                if ($value === null || $value === '') {
                    $body .= '<c r="'.$ref.'" s="'.$style.'"/>';

                    continue;
                }

                if (is_int($value) || is_float($value)) {
                    $body .= '<c r="'.$ref.'" s="'.$style.'"><v>'.$value.'</v></c>';

                    continue;
                }

                $body .= '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'
                    .self::esc((string) $value).'</t></is></c>';
            }
            $body .= '</row>';
        }

        $merges = '';
        if ($this->merges !== []) {
            $merges = '<mergeCells count="'.count($this->merges).'">';
            foreach ($this->merges as $m) {
                $merges .= '<mergeCell ref="'.$m.'"/>';
            }
            $merges .= '</mergeCells>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            .$cols
            .'<sheetData>'.$body.'</sheetData>'
            .$merges
            .'<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            .'<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            .'</worksheet>';
    }

    // -- Туслах ----------------------------------------------------------

    /** 0-based багана + 1-based мөр → "A1". */
    private static function cellRef(int $col, int $row): string
    {
        $letters = '';
        $n = $col;
        do {
            $letters = chr(65 + $n % 26).$letters;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);

        return $letters.$row;
    }

    private static function esc(string $value): string
    {
        // Excel нь XML-д хориотой хяналтын тэмдэгт агуулсан файлыг нээхээс
        // татгалздаг — талбайгаас ирсэн тайлбарт ийм тэмдэгт таарч болно.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
