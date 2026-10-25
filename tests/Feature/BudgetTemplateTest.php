<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use App\Services\BudgetTemplateSheet;
use App\Services\MaterialBudgetImport;
use App\Services\WorkBudgetImport;
use App\Services\XlsxReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Загвар файл — БИЧЭЭД УНШИНА.
 *
 * ЯАГААД ЭНЭ ТЕСТ ХЭРЭГТЭЙ ВЭ: ажлын хөлсний төсвийн задлагч багануудыг
 * НЭРЭЭР биш ДУГААРААР уншдаг (`COL_CODE = 2`, `COL_UNIT_PRICE = 29`…).
 * Загвар дээр нэг багана зөрвөл хүн юу ч анзаарахгүй — файл нь хэвийн
 * харагдах атлаа импорт бүхэлдээ унана. Загварыг үүсгээд ТЭР ЯГ ТЭР
 * задлагчаар нь буцааж уншиж байж л энэ зөрүү илэрнэ.
 *
 * Хоёр талыг нь өөр өөрөөр шалгана:
 *   · ажлын хөлс — жишээ кодоор нь ажлын төрөл бүртгээд БҮРЭН импортлоно
 *   · материал   — «ЖИШЭЭ» хамгаалалт нь хамгийн сүүлд зогсдог тул
 *                  «цорын ганц алдаа нь жишээ мөр» гэдэг нь бусад бүх
 *                  шалгуур давсан гэсэн үг
 */
class BudgetTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Ланкастер', 'status' => 'active']);

        $this->block = $project->blocks()->create([
            'name' => 'А1 блок',
            'building_no' => 'A1',
            'floors' => 3,
            'units_per_floor' => 2,
            'start_date' => '2026-10-01',
        ]);

        app(\App\Services\BlockLocationBuilder::class)->build($this->block, 3, 2);

        $this->path = tempnam(sys_get_temp_dir(), 'zagvar').'.xlsx';

        app(BudgetTemplateSheet::class)->build($this->block, $this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    // ───────────────────────── бүтэц ─────────────────────────

    /** Задлагчийн хайдаг гурван хуудас бүгд байна. */
    public function test_it_contains_the_sheets_the_importers_look_for(): void
    {
        $sheets = (new XlsxReader($this->path))->sheetNames();

        $this->assertContains(WorkBudgetImport::SHEET, $sheets);
        $this->assertContains(MaterialBudgetImport::SHEET, $sheets);
        $this->assertContains('Тайлбар', $sheets);
    }

    /** Давхрын баганын толгой нь блокийн байршлын нэртэй ЯГ таарна. */
    public function test_the_floor_headers_match_the_blocks_own_locations(): void
    {
        $names = Location::where('block_id', $this->block->id)
            ->where('level', 'floor')
            ->orderBy('sequence_number')
            ->pluck('name')
            ->all();

        $rows = (new XlsxReader($this->path))->rows(WorkBudgetImport::SHEET);
        $header = $rows[3] ?? [];

        $written = [];

        for ($column = 9; $column <= 27; $column++) {
            if (isset($header[$column]) && trim((string) $header[$column]) !== '') {
                $written[] = trim((string) $header[$column]);
            }
        }

        $this->assertSame($names, $written);
    }

    /**
     * «Нийт» ба «Нэгж үнэ» нь давхрын тооноос ҮЛ ХАМААРАН AC, AD дээр.
     *
     * Задлагч нэгж үнийг 29-р баганаас хатуу уншина. Давхар цөөн болоход
     * тэднийг зүүн тийш шахвал үнэ огт уншигдахгүй болно — файл нь
     * хэвийн харагдах атлаа бүх ажил үнэгүй орно.
     */
    public function test_the_total_and_price_columns_stay_at_ac_and_ad(): void
    {
        $rows = (new XlsxReader($this->path))->rows(WorkBudgetImport::SHEET);

        $this->assertSame('Нийт', trim((string) ($rows[2][28] ?? '')));
        $this->assertSame('Нэгж үнэ', trim((string) ($rows[2][29] ?? '')));
    }

    // ───────────────────── ажлын хөлсний төсөв ─────────────────────

    /**
     * Загварын ажлын хуудас АЛДААГҮЙ импортлогдоно.
     *
     * Энэ бол гол тест: баганын байрлал нэг ч зөрвөл унана.
     */
    public function test_the_work_sheet_imports_without_a_single_error(): void
    {
        $import = (new WorkBudgetImport($this->block))
            ->parse(new XlsxReader($this->path), $this->exampleWorkTypes());

        $this->assertSame([], $import->errors(), $this->dump($import->errors()));
        $this->assertSame([], $import->missingLocations());

        // Төлөвлөгөө нь МӨР тус бүрээр биш, БАЙРШИЛ тус бүрээр гарна.
        $this->assertCount(3 * $this->floorCount(), $import->plan());
    }

    /** Нэгж үнэ нь AD баганаас зөв уншигдана. */
    public function test_it_reads_the_unit_price_column(): void
    {
        $import = (new WorkBudgetImport($this->block))
            ->parse(new XlsxReader($this->path), $this->exampleWorkTypes());

        $prices = array_values(array_unique(array_map(
            fn ($line) => $line['unitPrice'],
            $import->plan(),
        )));

        $this->assertEqualsCanonicalizing([18500.0, 950000.0, 23000.0], $prices);
    }

    /** Ажил бүр давхар бүрт буусан байна. */
    public function test_quantities_land_on_every_floor(): void
    {
        $import = (new WorkBudgetImport($this->block))
            ->parse(new XlsxReader($this->path), $this->exampleWorkTypes());

        $byType = [];

        foreach ($import->plan() as $line) {
            $byType[$line['workTypeId']][] = $line['locationId'];
        }

        $this->assertCount(3, $byType, 'Гурван жишээ ажил бүгд уншигдах ёстой.');

        foreach ($byType as $locationIds) {
            $this->assertCount($this->floorCount(), array_unique($locationIds));
        }
    }

    // ───────────────────── материалын төсөв ─────────────────────

    /**
     * Материалын хуудасны ЦОРЫН ГАНЦ алдаа нь «жишээ мөр» байна.
     *
     * «ЖИШЭЭ» хамгаалалт нь бүх форматын шалгуурын ДАРАА зогсдог тул
     * энэ нь нэгж, байршил, нийлбэр, код бүгд зөв гэсэн баталгаа.
     */
    public function test_the_material_sheet_passes_every_check_except_the_example_guard(): void
    {
        $import = (new MaterialBudgetImport($this->block))
            ->parse(new XlsxReader($this->path));

        $this->assertCount(3, $import->errors(), $this->dump($import->errors()));

        foreach ($import->errors() as $error) {
            $this->assertSame('Загварын жишээ мөр — устгана уу.', $error['reason']);
        }

        $this->assertSame([], $import->missingLocations());
        $this->assertSame(3, $import->sourceRowCount());
    }

    /** Жишээ мөр нь төсөвт ОРОХГҮЙ — устгахаа мартсан ч аюулгүй. */
    public function test_example_rows_never_reach_the_budget(): void
    {
        $import = (new MaterialBudgetImport($this->block))
            ->parse(new XlsxReader($this->path));

        $this->assertSame([], $import->plan());
    }

    // ───────────────────────── эндпойнт ─────────────────────────

    /** Татаж авахад .xlsx ирнэ. */
    public function test_an_engineer_can_download_the_template(): void
    {
        $admin = User::create([
            'name' => 'Админ', 'email' => 'admin@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'admin',
        ]);

        $this->actingAs($admin)
            ->get("/api/v1/blocks/{$this->block->id}/budget-template.xlsx")
            ->assertOk()
            ->assertHeader(
                'content-type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );
    }

    /** Төсөв оруулах эрхгүй хүн загвар ч татахгүй. */
    public function test_an_inspector_cannot_download_the_template(): void
    {
        $inspector = User::create([
            'name' => 'Д.Хулан', 'email' => 'inspector@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'inspector',
        ]);

        $this->actingAs($inspector)
            ->get("/api/v1/blocks/{$this->block->id}/budget-template.xlsx")
            ->assertForbidden();
    }

    // ───────────────────────── туслах ─────────────────────────

    /**
     * Загварын жишээ кодуудаар ажлын төрөл бүртгэнэ.
     *
     * Жинхэнэ систем дээр эдгээр код лавлахад БАЙХГҮЙ тул жишээ мөр
     * татгалзана — энэ нь зориуд. Тестэд бүртгэж өгснөөр баганын
     * байрлалыг эцэс хүртэл шалгах боломж гарна.
     *
     * @return array<string, WorkType>
     */
    private function exampleWorkTypes(): array
    {
        $group = WorkTypeGroup::create(['name' => 'Угсралт', 'sequence_number' => 1]);
        $prefix = BudgetTemplateSheet::EXAMPLE_PREFIX.'-';

        $types = [];

        foreach ([['1.2', 'м2'], ['2.6.1', 'тн'], ['17.1.6', 'м2']] as $i => [$code, $unit]) {
            $type = WorkType::create([
                'work_type_group_id' => $group->id,
                'name' => 'Жишээ ажил '.($i + 1),
                'code' => $prefix.$code,
                'unit' => $unit,
                'level' => 'floor',
                'sequence_number' => $i + 1,
            ]);

            $types[$type->code] = $type;
        }

        return $types;
    }

    private function floorCount(): int
    {
        return Location::where('block_id', $this->block->id)->where('level', 'floor')->count();
    }

    /** @param  array<int, array{row: int, code: string, name: string, reason: string}>  $errors */
    private function dump(array $errors): string
    {
        return implode("\n", array_map(
            fn ($e) => "{$e['row']}-р мөр · {$e['code']} · {$e['reason']}",
            $errors,
        ));
    }
}
