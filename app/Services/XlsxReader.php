<?php

namespace App\Services;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Номын сангүйгээр .xlsx унших.
 *
 * ЯАГААД ӨӨРСДӨӨ БИЧИВ: энэ орчинд composer/packagist хаалттай тул
 * phpoffice/phpspreadsheet суулгах боломжгүй. Бичих талдаа `XlsxWriter`
 * мөн ийм шалтгаанаар гараар бичигдсэн.
 *
 * .xlsx бол XML файлуудын ZIP. Бидэнд хэрэгтэй нь дөрөв:
 *
 *   xl/workbook.xml            хуудасны нэр → rId
 *   xl/_rels/workbook.xml.rels rId → бодит файлын зам
 *   xl/sharedStrings.xml       давтагдах текстийн сан
 *   xl/worksheets/sheetN.xml   нүднүүд
 *
 * ХЯЗГААР: зөвхөн УНШИНА. Хэв маяг, нэгтгэсэн нүд, огнооны формат
 * хөрвүүлэхгүй — тоо ба текст л хэрэгтэй.
 */
class XlsxReader
{
    private ZipArchive $zip;

    /** @var array<int, string> Давтагдах текстийн сан — индексээр нь. */
    private array $sharedStrings = [];

    public function __construct(private readonly string $path)
    {
        if (! is_readable($this->path)) {
            throw new RuntimeException("Файл уншигдахгүй байна: {$this->path}");
        }

        $this->zip = new ZipArchive;

        if ($this->zip->open($this->path) !== true) {
            throw new RuntimeException('Файлыг нээж чадсангүй — .xlsx мөн эсэхийг шалгана уу.');
        }

        $this->loadSharedStrings();
    }

    public function __destruct()
    {
        // `@` нь нээгдээгүй архив дээр хаахыг оролдох анхааруулгыг дарна.
        @$this->zip->close();
    }

    /** @return array<int, string> Хуудасны нэрс — файл дахь дарааллаар. */
    public function sheetNames(): array
    {
        $book = $this->xml('xl/workbook.xml');
        $names = [];

        foreach ($book->sheets->sheet as $sheet) {
            $names[] = (string) $sheet['name'];
        }

        return $names;
    }

    /**
     * Нэг хуудсыг мөрүүдийн массив болгон уншина.
     *
     * Буцаах бүтэц: `[мөрийн дугаар => [баганын индекс (0-оос) => утга]]`.
     * Хоосон мөр, хоосон нүд ОРОХГҮЙ — задаргаа нь дутуу нүдийг `null` гэж
     * үзэх ёстой. Ингэснээр 1,000 мөр × 113 баганатай хуудас санах ойд
     * багтана.
     *
     * @return array<int, array<int, string|float|null>>
     */
    public function rows(string $sheetName, int $maxColumn = 0): array
    {
        $xml = $this->xml($this->sheetPath($sheetName));
        $rows = [];

        foreach ($xml->sheetData->row as $row) {
            $rowNumber = (int) $row['r'];
            $cells = [];

            foreach ($row->c as $cell) {
                $index = self::columnIndex((string) $cell['r']);

                if ($maxColumn > 0 && $index >= $maxColumn) {
                    continue;
                }

                $value = $this->cellValue($cell);

                if ($value !== null) {
                    $cells[$index] = $value;
                }
            }

            if ($cells !== []) {
                $rows[$rowNumber] = $cells;
            }
        }

        return $rows;
    }

    /**
     * Нэг нүдний утга.
     *
     * Томьёотой нүд нь `<f>` ба КЭШЛЭГДСЭН `<v>` хоёулантай байдаг — бид
     * сүүлийнхийг нь авна. Excel файлыг хадгалахдаа кэшээ бичдэг тул энэ нь
     * бараг үргэлж байдаг; байхгүй бол `null` буцна.
     *
     * Алдаат нүд (`#REF!`, `#DIV/0!`) нь `t="e"` гэж ирнэ — ТЕКСТ болгож
     * буцаана. Задлагч нь тэдгээрийг танин алдааны тайланд бичих ёстой,
     * чимээгүй 0 болгох ёсгүй.
     */
    private function cellValue(SimpleXMLElement $cell): string|float|null
    {
        $type = (string) $cell['t'];

        if ($type === 'inlineStr') {
            $text = $this->plainText($cell->is);

            return $text === '' ? null : $text;
        }

        if (! isset($cell->v)) {
            return null;
        }

        $raw = (string) $cell->v;

        if ($type === 's') {
            $text = $this->sharedStrings[(int) $raw] ?? '';

            return $text === '' ? null : $text;
        }

        // `str` = томьёоны текст үр дүн; `e` = алдаа; `b` = логик утга.
        if ($type === 'str' || $type === 'e') {
            return $raw === '' ? null : $raw;
        }

        if ($type === 'b') {
            return $raw === '1' ? 'TRUE' : 'FALSE';
        }

        return is_numeric($raw) ? (float) $raw : ($raw === '' ? null : $raw);
    }

    private function loadSharedStrings(): void
    {
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return;
        }

        foreach ($this->xml('xl/sharedStrings.xml')->si as $item) {
            $this->sharedStrings[] = $this->plainText($item);
        }
    }

    /**
     * `<si>` эсвэл `<is>` доторх текстийг нэгтгэнэ.
     *
     * Excel нэг нүдний текстийг хэв маягаар нь хэсэглэж `<r><t>` болгон
     * хадгалдаг — зөвхөн эхний хэсгийг авбал «Ажлын тоо хэмжээ» гэснийг
     * «Ажлын» гэж уншина.
     */
    private function plainText(SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            return trim((string) $node->t);
        }

        $text = '';

        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }

        return trim($text);
    }

    /** Хуудасны нэрээс ZIP доторх замыг олно. */
    private function sheetPath(string $sheetName): string
    {
        $book = $this->xml('xl/workbook.xml');
        $relationId = null;

        foreach ($book->sheets->sheet as $sheet) {
            if ((string) $sheet['name'] === $sheetName) {
                $relationId = (string) $sheet->attributes('r', true)['id'];
                break;
            }
        }

        if ($relationId === null) {
            throw new RuntimeException(
                "«{$sheetName}» хуудас олдсонгүй. Байгаа нь: ".implode(', ', $this->sheetNames())
            );
        }

        foreach ($this->xml('xl/_rels/workbook.xml.rels')->Relationship as $relation) {
            if ((string) $relation['Id'] === $relationId) {
                $target = ltrim((string) $relation['Target'], '/');

                // Зам нь `worksheets/sheet1.xml` эсвэл `xl/worksheets/...` байж болно.
                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        throw new RuntimeException("«{$sheetName}» хуудасны зам тодорхойгүй.");
    }

    private function xml(string $entry): SimpleXMLElement
    {
        $content = $this->zip->getFromName($entry);

        if ($content === false) {
            throw new RuntimeException("Архивт {$entry} алга — эвдэрсэн .xlsx байж магадгүй.");
        }

        $xml = simplexml_load_string($content);

        if ($xml === false) {
            throw new RuntimeException("{$entry} задрахгүй байна.");
        }

        return $xml;
    }

    /** «BX12» → 75. Баганын үсгийг 0-оос эхлэх индекс болгоно. */
    public static function columnIndex(string $reference): int
    {
        $index = 0;

        foreach (str_split(strtoupper($reference)) as $char) {
            if ($char < 'A' || $char > 'Z') {
                break;
            }

            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }
}
