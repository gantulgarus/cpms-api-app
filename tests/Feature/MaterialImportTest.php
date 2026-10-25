<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Location;
use App\Models\Material;
use App\Models\MaterialBudgetItem;
use App\Models\MaterialBudgetQuantity;
use App\Services\MaterialBudgetImport;
use App\Services\XlsxReader;
use App\Services\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Материалын төсвийн импорт.
 *
 * ТУРШИЛТЫН ФАЙЛ НЬ ЗАХИАЛАГЧИЙН БҮТЦИЙГ ДУУРАЙНА: хоёр давхар толгой,
 * нэгтгэсэн нүднээс үүдэн хоосон үлдсэн ажлын нэр, байршлаар задарсан
 * тоо хэмжээ, хоёр үнэ. Хялбаршуулсан файл дээр турших нь утгагүй —
 * бодит файлын эдгээр онцлог яг л алдаа үүсгэдэг зүйлс.
 */
class MaterialImportTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Төсөл', 'status' => 'active']);

        $this->block = $project->blocks()->create([
            'name' => 'А1 блок',
            'building_no' => 'A1',
            'floors' => 2,
            'units_per_floor' => 3,
            'start_date' => '2026-10-01',
        ]);

        app(\App\Services\BlockLocationBuilder::class)->build($this->block, 2, 3);
    }

    // ─────────────────────────── уншилт ───────────────────────────

    /**
     * Хоёр давхар толгойтой, байршлаар задарсан файлыг уншина.
     *
     * Энэ бол үндсэн тест: багана нь БАЙРЛАЛААР биш ТОЛГОЙН НЭРЭЭР
     * олдож, тоо хэмжээ нь зөв байршилд буух ёстой.
     */
    public function test_it_reads_the_two_row_header_and_lands_quantities_on_locations(): void
    {
        $import = $this->parse($this->makeWorkbook([
            ['work' => 'Угсралт', 'sub' => 'Бетон', 'code' => '2.2.2', 'name' => 'Суурь бетон',
             'grade' => 'М250', 'unit' => 'м3', 'qty' => ['1-р давхар' => 920.69],
             'actual' => 320000, 'market' => 338000],
        ]));

        $this->assertSame([], $import->errors(), $this->dump($import));
        $this->assertCount(1, $import->plan());

        $line = $import->plan()[0];
        $floor = $this->location('1-р давхар');

        $this->assertSame('2.2.2', $line['code']);
        $this->assertSame('Суурь бетон', $line['name']);
        $this->assertSame('м3', $line['unit']);
        $this->assertEqualsWithDelta(920.69, $line['total'], 0.001);
        $this->assertSame([$floor->id => 920.69], $line['quantities']);
        $this->assertEqualsWithDelta(320000, $line['actualPrice'], 0.01);
        $this->assertEqualsWithDelta(338000, $line['marketPrice'], 0.01);
    }

    /**
     * «Материалын хэмжээ» гэдэг багана нь МАРК, тоо хэмжээ БИШ.
     *
     * Толгойн нэр нь төөрөгдүүлдэг. Хэрэв түүнийг тоо хэмжээ гэж уншвал
     * М250 гэсэн текст эвдэрсэн нүд мэт харагдаж, бүх бетоны мөр
     * татгалзана.
     */
    public function test_the_grade_column_is_kept_as_a_grade_not_a_quantity(): void
    {
        $import = $this->parse($this->makeWorkbook([
            ['work' => 'Угсралт', 'sub' => 'Бетон', 'code' => '2.2.1', 'name' => 'Ул бетон',
             'grade' => 'М150', 'unit' => 'м3', 'qty' => ['1-р давхар' => 77.86],
             'actual' => 300000, 'market' => 315000],
        ]));

        $this->assertSame([], $import->errors(), $this->dump($import));
        $this->assertSame('М150', $import->plan()[0]['grade']);
        $this->assertEqualsWithDelta(77.86, $import->plan()[0]['total'], 0.001);
    }

    /**
     * Нэгтгэсэн нүднээс болж хоосон үлдсэн ажлын нэрийг дээшээ нөхнө.
     *
     * Захиалагчийн файлд 273 материалын 258 нь ийм байдалтай. Нөхөхгүй
     * бол «энэ материал ямар ажилд зарцуулагдав» гэдэг бүхэлдээ алдагдана.
     */
    public function test_it_carries_the_work_name_down_through_merged_cells(): void
    {
        $import = $this->parse($this->makeWorkbook([
            ['work' => 'Угсралт', 'sub' => 'Арматур', 'code' => '2.1.1', 'name' => 'Ф8',
             'unit' => 'тн', 'qty' => ['1-р давхар' => 12]],
            // Ажлын нэр ба дэд нэр ХООСОН — дээрхтэй ижил гэсэн үг.
            ['code' => '2.1.2', 'name' => 'Ф10', 'unit' => 'тн', 'qty' => ['1-р давхар' => 8]],
            // Дэд нэр л солигдлоо, ажлын нэр хэвээр.
            ['sub' => 'Бетон', 'code' => '2.2.1', 'name' => 'Ул бетон',
             'unit' => 'м3', 'qty' => ['2-р давхар' => 50]],
        ]));

        $this->assertSame([], $import->errors(), $this->dump($import));

        $labels = array_column($import->plan(), 'workLabel', 'code');

        $this->assertSame('Угсралт · Арматур', $labels['2.1.1']);
        $this->assertSame('Угсралт · Арматур', $labels['2.1.2'], 'Хоосон мөр дээрхээ өвлөх ёстой.');
        $this->assertSame('Угсралт · Бетон', $labels['2.2.1'], 'Дэд нэр дангаараа солигдоно.');
    }

    /** «нийт» гэсэн дэд дүнгийн мөрийг материал гэж үзэхгүй. */
    public function test_subtotal_rows_are_skipped(): void
    {
        $import = $this->parse($this->makeWorkbook([
            ['work' => 'Угсралт', 'sub' => 'Арматур', 'code' => '2.1.1', 'name' => 'Ф8',
             'unit' => 'тн', 'qty' => ['1-р давхар' => 12]],
            ['name' => 'нийт', 'qty' => ['1-р давхар' => 12]],
        ]));

        $this->assertSame([], $import->errors(), $this->dump($import));
        $this->assertCount(1, $import->plan());
        $this->assertSame(1, $import->skippedSubtotalCount());
    }

    // ─────────────────────────── татгалзал ───────────────────────────

    /**
     * Эвдэрсэн нүд бүхий мөрийг ТАТГАЛЗАНА, чимээгүй алгасахгүй.
     *
     * Захиалагчийн файлын 273 мөрийн 109-д нь ийм нүд бий. Хэрэв эдгээрийг
     * алгасвал «импорт амжилттай» гэж харагдаад материалын төсвийн 40%
     * дутуу орно — хамгийн аюултай төрлийн алдаа.
     */
    public function test_a_broken_cell_rejects_the_row(): void
    {
        $import = $this->parse($this->makeWorkbook([
            ['work' => 'Угсралт', 'code' => '2.1.1', 'name' => 'Ф8', 'unit' => 'тн',
             'qty' => ['1-р давхар' => '#REF!']],
        ]));

        $this->assertCount(0, $import->plan());
        $this->assertCount(1, $import->errors());
        $this->assertStringContainsString('Эвдэрсэн нүд', $import->errors()[0]['reason']);
        $this->assertStringContainsString('#REF!', $import->errors()[0]['reason']);
    }

    /**
     * Блокт байхгүй давхарт тоо хэмжээ байвал мөрийг татгалзана.
     *
     * 16 давхрын файлыг 2 давхрын блокт оруулахыг оролдох нь бодит
     * тохиолдол — 75 барилгын цогцолборт давхрын тоо өөр өөр. Чимээгүй
     * алгасвал нийт дүн дутуу гарч, хэн ч анзаарахгүй.
     */
    public function test_a_quantity_on_a_floor_the_block_does_not_have_is_rejected(): void
    {
        $import = $this->parse($this->makeWorkbook(
            [['work' => 'Угсралт', 'code' => '2.1.1', 'name' => 'Ф8', 'unit' => 'тн',
              'qty' => ['1-р давхар' => 5, '9-р давхар' => 7]]],
            ['1-р давхар', '2-р давхар', '9-р давхар'],
        ));

        $this->assertSame(['9-р давхар'], $import->missingLocations());
        $this->assertCount(0, $import->plan());
        $this->assertStringContainsString('9-р давхар', $import->errors()[0]['reason']);
    }

    /**
     * Блокт байхгүй давхрын багана БАЙГАА ч ХООСОН бол мөр импортлогдоно.
     *
     * Багана байх нь өөрөө асуудал биш — тэнд тоо байх нь асуудал.
     * Эс тэгвээс 16 баганатай загвар файлыг жижиг блокт хэзээ ч
     * хэрэглэж чадахгүй.
     */
    public function test_an_empty_column_for_a_missing_floor_does_not_reject_the_row(): void
    {
        $import = $this->parse($this->makeWorkbook(
            [['work' => 'Угсралт', 'code' => '2.1.1', 'name' => 'Ф8', 'unit' => 'тн',
              'qty' => ['1-р давхар' => 5]]],
            ['1-р давхар', '2-р давхар', '9-р давхар'],
        ));

        $this->assertSame([], $import->errors(), $this->dump($import));
        $this->assertCount(1, $import->plan());
        $this->assertSame(['9-р давхар'], $import->missingLocations(),
            'Багана дутуу байгааг тайлагнасан хэвээр байх ёстой.');
    }

    /**
     * Файлын «Нийт» нь задаргааны нийлбэртэй тохирохгүй бол татгалзана.
     *
     * Энэ нь ХАМГИЙН НУУЦЛАГ алдаа: «Нийт» бол Excel-ийн томьёо бөгөөд
     * буруу муж заасан байж болно (16 давхрын оронд 14). Файл дотроо
     * төгс харагдана — зөвхөн задаргаатай тулгаж байж илэрнэ.
     */
    public function test_a_declared_total_that_disagrees_with_the_breakdown_is_rejected(): void
    {
        $import = $this->parse($this->makeWorkbook([
            ['work' => 'Угсралт', 'code' => '2.1.1', 'name' => 'Ф8', 'unit' => 'тн',
             'qty' => ['1-р давхар' => 10, '2-р давхар' => 10], 'total' => 10],
        ]));

        $this->assertCount(0, $import->plan());
        $this->assertStringContainsString('Нийлбэр зөрж байна', $import->errors()[0]['reason']);
    }

    /** Бутархай дугуйралтын зэрэг зөрүүг алдаа гэж үзэхгүй. */
    public function test_a_rounding_sized_total_difference_is_tolerated(): void
    {
        $import = $this->parse($this->makeWorkbook([
            ['work' => 'Угсралт', 'code' => '2.1.1', 'name' => 'Ф8', 'unit' => 'тн',
             'qty' => ['1-р давхар' => 10.001, '2-р давхар' => 10], 'total' => 20],
        ]));

        $this->assertSame([], $import->errors(), $this->dump($import));
    }

    /**
     * Давхардсан кодыг татгалзана.
     *
     * `unique(block_id, material_id)` тул хоёр дахь мөр эхнийхийг чимээгүй
     * дарж бичих бөгөөд эхний мөрийн тоо хэмжээ ул мөргүй алга болно.
     */
    public function test_a_duplicate_material_code_is_rejected(): void
    {
        $import = $this->parse($this->makeWorkbook([
            ['work' => 'Угсралт', 'code' => '2.1.1', 'name' => 'Ф8', 'unit' => 'тн',
             'qty' => ['1-р давхар' => 5]],
            ['code' => '2.1.1', 'name' => 'Ф8 дахин', 'unit' => 'тн', 'qty' => ['2-р давхар' => 3]],
        ]));

        $this->assertCount(1, $import->plan());
        $this->assertStringContainsString('давхардсан', $import->errors()[0]['reason']);
    }

    /** Танигдахгүй нэгжтэй мөрийг татгалзана. */
    public function test_an_unrecognised_unit_is_rejected(): void
    {
        $import = $this->parse($this->makeWorkbook([
            ['work' => 'Угсралт', 'code' => '2.2.1', 'name' => 'Ул бетон', 'unit' => 'куб',
             'qty' => ['1-р давхар' => 77]],
        ]));

        $this->assertCount(0, $import->plan());
        $this->assertStringContainsString('Танигдахгүй нэгж', $import->errors()[0]['reason']);
    }

    /** Кодгүй мөрийг татгалзана — холбох түлхүүргүй. */
    public function test_a_row_without_a_code_is_rejected(): void
    {
        $import = $this->parse($this->makeWorkbook([
            ['work' => 'Угсралт', 'name' => 'Нэргүй материал', 'unit' => 'ш',
             'qty' => ['1-р давхар' => 5]],
        ]));

        $this->assertCount(0, $import->plan());
        $this->assertStringContainsString('код хоосон', $import->errors()[0]['reason']);
    }

    // ─────────────────────────── бичилт ───────────────────────────

    /** `--apply` байхгүй бол ЮУ Ч бичигдэхгүй. */
    public function test_a_preview_writes_nothing(): void
    {
        $file = $this->makeWorkbook([
            ['work' => 'Угсралт', 'code' => '2.2.2', 'name' => 'Суурь бетон', 'grade' => 'М250',
             'unit' => 'м3', 'qty' => ['1-р давхар' => 920.69], 'actual' => 320000, 'market' => 338000],
        ]);

        $this->artisan('cpms:import-materials', ['--file' => $file, '--block' => 'А1 блок'])
            ->assertSuccessful();

        $this->assertSame(0, Material::count());
        $this->assertSame(0, MaterialBudgetItem::count());
    }

    /** `--apply` нь лавлах, блокийн мөр, байршлын задаргаа гурвыг бичнэ. */
    public function test_apply_writes_material_item_and_quantities(): void
    {
        $file = $this->makeWorkbook([
            ['work' => 'Угсралт', 'sub' => 'Бетон', 'code' => '2.2.2', 'name' => 'Суурь бетон',
             'grade' => 'М250', 'unit' => 'м3',
             'qty' => ['1-р давхар' => 900, '2-р давхар' => 100],
             'actual' => 320000, 'market' => 338000],
        ]);

        $this->artisan('cpms:import-materials', [
            '--file' => $file, '--block' => 'А1 блок', '--apply' => true, '--force' => true,
        ])->assertSuccessful();

        $material = Material::where('code', '2.2.2')->firstOrFail();
        $this->assertSame('М250', $material->grade);
        $this->assertSame('Суурь бетон (М250)', $material->label());

        $item = MaterialBudgetItem::where('material_id', $material->id)->firstOrFail();
        $this->assertEqualsWithDelta(1000, $item->total_qty, 0.001);
        $this->assertEqualsWithDelta(320000, $item->actual_unit_price, 0.01);
        $this->assertEqualsWithDelta(338000, $item->market_unit_price, 0.01);
        $this->assertEqualsWithDelta(320_000_000, $item->actualAmount(), 0.01);
        $this->assertEqualsWithDelta(5.63, $item->variancePercent(), 0.01);
        $this->assertSame('Угсралт · Бетон', $item->work_label);

        $quantities = MaterialBudgetQuantity::where('material_budget_item_id', $item->id)
            ->pluck('quantity', 'location_id');

        $this->assertCount(2, $quantities);
        $this->assertEqualsWithDelta(900, $quantities[$this->location('1-р давхар')->id], 0.001);
        $this->assertEqualsWithDelta(100, $quantities[$this->location('2-р давхар')->id], 0.001);
    }

    /**
     * Дахин импортлоход блокийн хуучин мөр БҮХЭЛДЭЭ солигдоно.
     *
     * Төсвөөс хасагдсан материал үлдвэл захиалгын хуудсанд хэзээ ч
     * хэрэглэгдэхгүй зүйл гарч ирнэ.
     */
    public function test_reimporting_replaces_the_blocks_rows(): void
    {
        $first = $this->makeWorkbook([
            ['work' => 'Угсралт', 'code' => '2.1.1', 'name' => 'Ф8', 'unit' => 'тн',
             'qty' => ['1-р давхар' => 5]],
            ['code' => '2.1.2', 'name' => 'Ф10', 'unit' => 'тн', 'qty' => ['1-р давхар' => 3]],
        ]);

        $this->artisan('cpms:import-materials', [
            '--file' => $first, '--block' => 'А1 блок', '--apply' => true, '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(2, MaterialBudgetItem::count());

        $second = $this->makeWorkbook([
            ['work' => 'Угсралт', 'code' => '2.1.1', 'name' => 'Ф8', 'unit' => 'тн',
             'qty' => ['1-р давхар' => 9]],
        ]);

        $this->artisan('cpms:import-materials', [
            '--file' => $second, '--block' => 'А1 блок', '--apply' => true, '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(1, MaterialBudgetItem::count(), 'Ф10 блокоос хасагдах ёстой.');
        $this->assertEqualsWithDelta(9, MaterialBudgetItem::first()->total_qty, 0.001);

        // Лавлах нь ҮЛДЭНЭ — өөр блок түүнийг хэрэглэж байж болно.
        $this->assertSame(2, Material::count());
    }

    /** Хуудас нь байхгүй файлыг ойлгомжтой алдаагаар татгалзана. */
    public function test_a_file_without_the_sheet_fails_clearly(): void
    {
        $writer = new XlsxWriter('Огт өөр хуудас');
        $writer->row(['а', 'б']);
        $file = $writer->save(tempnam(sys_get_temp_dir(), 'mat').'.xlsx');

        $this->artisan('cpms:import-materials', ['--file' => $file, '--block' => 'А1 блок'])
            ->expectsOutputToContain('хуудас файлд алга')
            ->assertFailed();
    }

    /**
     * Ажлын дэд нэрийн багана НЭРТЭЙ ч байж болно.
     *
     * Захиалагчийн файлд толгойгүй, бидний үүсгэдэг загварт «Ажлын дэд
     * нэр» гэж нэрлэгдсэн — толгойгүй багана нь хүнд ойлгомжгүй.
     * Хоёулаа ижил үр дүн өгөх ёстой.
     */
    public function test_the_sub_name_column_may_also_carry_a_header(): void
    {
        $import = $this->parse($this->makeWorkbook([
            ['work' => 'Угсралт', 'sub' => 'Арматур', 'code' => '2.1.3', 'name' => 'ф12',
                'unit' => 'ш', 'qty' => ['1-р давхар' => 120]],
        ], ['1-р давхар', '2-р давхар'], 'Ажлын дэд нэр'));

        $this->assertSame([], $import->errors(), $this->dump($import));
        $this->assertSame('Угсралт · Арматур', $import->plan()[0]['workLabel']);
    }

    // ─────────────────────────── туслахууд ───────────────────────────

    private function location(string $name): Location
    {
        return Location::where('block_id', $this->block->id)->where('name', $name)->firstOrFail();
    }

    private function parse(string $file): MaterialBudgetImport
    {
        return (new MaterialBudgetImport($this->block))->parse(new XlsxReader($file));
    }

    private function dump(MaterialBudgetImport $import): string
    {
        return json_encode($import->errors(), JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * Захиалагчийн бүтэцтэй туршилтын .xlsx.
     *
     * Багануудыг ЖИНХЭНЭ файлын дарааллаар байрлуулна:
     *
     *   мөр 1 │ (хоосон)
     *   мөр 2 │ … Ажлын нэр … Код │ Материалын нэр │ Материалын хэмжээ │ Хэмжих нэгж │ Бодит │ Зах зээл
     *   мөр 3 │                                      1-р давхар │ 2-р давхар │ Нийт │ Нэгж үнэ │ Нэгж үнэ
     *
     * Ажлын дэд нэрийн багана (2) анхдагчаар ТОЛГОЙГҮЙ — жинхэнэ файлд
     * ч тийм. `$subHeader` өгвөл нэртэй хувилбар үүснэ: бидний үүсгэдэг
     * загвар файл тэр хэлбэрийг хэрэглэдэг тул хоёулаа ажиллах ёстой.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $locations
     */
    private function makeWorkbook(
        array $rows,
        array $locations = ['1-р давхар', '2-р давхар'],
        ?string $subHeader = null,
    ): string {
        $first = 12;
        $colTotal = $first + count($locations);
        $colActual = $colTotal + 1;
        $colMarket = $colTotal + 3;
        $width = $colMarket + 2;

        $blank = fn () => array_fill(0, $width, '');

        $label = $blank();
        $label[1] = 'Ажлын нэр';

        if ($subHeader !== null) {
            $label[2] = $subHeader;
        }

        $label[4] = 'Хэмжих нэгж';          // ажлын нэгж — материалынхаас ӨМНӨ
        $label[5] = 'Ажлын тоо хэмжээ';
        $label[8] = 'Код';
        $label[9] = 'Материалын нэр';
        $label[10] = 'Материалын хэмжээ';   // төөрөгдүүлэх нэр — энэ нь МАРК
        $label[11] = 'Хэмжих нэгж';         // материалын нэгж
        $label[$first] = 'Материалын тоо хэмжээ';
        $label[$colActual] = 'Бодит';
        $label[$colMarket] = 'Зах зээл';

        $second = $blank();
        foreach ($locations as $i => $name) {
            $second[$first + $i] = $name;
        }
        $second[$colTotal] = 'Нийт';
        $second[$colActual] = 'Нэгж үнэ';
        $second[$colActual + 1] = 'Нийт үнэ';
        $second[$colMarket] = 'Нэгж үнэ';
        $second[$colMarket + 1] = 'Нийт үнэ';

        $sheet = [$blank(), $label, $second];

        foreach ($rows as $row) {
            $line = $blank();
            $line[1] = $row['work'] ?? '';
            $line[2] = $row['sub'] ?? '';
            $line[8] = $row['code'] ?? '';
            $line[9] = $row['name'] ?? '';
            $line[10] = $row['grade'] ?? '';
            $line[11] = $row['unit'] ?? '';

            $sum = 0.0;

            foreach ($locations as $i => $name) {
                if (array_key_exists($name, $row['qty'] ?? [])) {
                    $value = $row['qty'][$name];
                    $line[$first + $i] = $value;

                    if (is_numeric($value)) {
                        $sum += (float) $value;
                    }
                }
            }

            $line[$colTotal] = $row['total'] ?? ($sum > 0 ? $sum : '');
            $line[$colActual] = $row['actual'] ?? '';
            $line[$colMarket] = $row['market'] ?? '';

            $sheet[] = $line;
        }

        $writer = new XlsxWriter(MaterialBudgetImport::SHEET);

        foreach ($sheet as $line) {
            $writer->row($line);
        }

        return $writer->save(tempnam(sys_get_temp_dir(), 'material').'.xlsx');
    }
}
