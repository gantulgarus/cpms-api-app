<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\BudgetImport;
use App\Models\Company;
use App\Models\Material;
use App\Models\MaterialBudgetItem;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Services\MaterialBudgetImport;
use App\Services\WorkBudgetImport;
use App\Services\XlsxWriter;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Төсвийг ВЭБЭЭС оруулах.
 *
 * ГОЛ БАТАЛГАА: урьдчилан харах нь ЮУ Ч БИЧИХГҮЙ, харин батлах нь ЯГ
 * ТЭР файлыг бичнэ. Хоёр алхмын хооронд файл сервер дээр хадгалагдана —
 * хөтөчөөс дахин илгээвэл өөр файл орох эрсдэлтэй.
 */
class BudgetImportApiTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkTypeSeeder::class);

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

        $this->engineer = User::create([
            'name' => 'Ерөнхий инженер',
            'email' => 'ye@inel.mn',
            'password' => bcrypt('secret123'),
            'role' => 'general_engineer',
        ]);
    }

    // ────────────────────────── урьдчилан харах ──────────────────────────

    /** Байршуулахад тайлан буцна, өгөгдлийн санд ЮУ Ч бичигдэхгүй. */
    public function test_uploading_previews_without_writing(): void
    {
        $response = $this->actingAs($this->engineer)
            ->post("/api/v1/blocks/{$this->block->id}/imports", [
                'file' => $this->materialFile(),
            ]);

        $response->assertCreated();

        $report = $response->json('reports.0');

        $this->assertSame('materials', $report['type']);
        $this->assertSame('Материалын төсөв', $report['typeLabel']);
        $this->assertTrue($report['canApply']);
        $this->assertSame(1, $report['summary']['planned']);
        $this->assertSame(0, $report['summary']['rejected']);

        $this->assertSame(0, Material::count(), 'Урьдчилан харах нь юу ч бичих ёсгүй.');
        $this->assertSame(0, MaterialBudgetItem::count());

        // Бүртгэл үүссэн, файл хадгалагдсан.
        $import = BudgetImport::firstOrFail();
        $this->assertSame('pending', $import->status);
        $this->assertTrue(Storage::disk('local')->exists($import->path));
    }

    /**
     * Тайлан нь `plan`-ыг хөтөч рүү ИЛГЭЭХГҮЙ.
     *
     * 3,000 мөр × 10 талбар нь хэдэн мегабайт — дэлгэц түүнийг
     * харуулдаггүй, зөвхөн сүлжээ дүүргэнэ.
     */
    public function test_the_report_does_not_ship_the_whole_plan(): void
    {
        $report = $this->actingAs($this->engineer)
            ->post("/api/v1/blocks/{$this->block->id}/imports", [
                'file' => $this->materialFile(),
            ])->json('reports.0');

        $this->assertArrayNotHasKey('plan', $report);
    }

    /** Буруу хуудастай файлыг татгалзаж, ХАДГАЛАХГҮЙ. */
    public function test_a_file_without_the_expected_sheet_is_rejected_and_not_kept(): void
    {
        $writer = new XlsxWriter('Огт өөр хуудас');
        $writer->row(['а', 'б']);
        $file = $writer->save(tempnam(sys_get_temp_dir(), 'bad').'.xlsx');

        $this->actingAs($this->engineer)
            ->post("/api/v1/blocks/{$this->block->id}/imports", [
                'file' => new UploadedFile($file, 'buruu.xlsx', null, null, true),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Танигдах төсвийн хуудас олдсонгүй'));

        $this->assertSame(0, BudgetImport::count(), 'Задрахгүй файл түүхэнд үлдэх ёсгүй.');
    }

    /** .xlsx биш файлыг ойлгомжтой татгалзана. */
    public function test_a_non_xlsx_file_is_rejected(): void
    {
        $this->actingAs($this->engineer)
            ->post("/api/v1/blocks/{$this->block->id}/imports", [
                'file' => UploadedFile::fake()->create('tosov.csv', 10),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, '.xlsx'));
    }

    // ──────────────────────────── батлах ────────────────────────────

    /** Батлах нь ЯГ ТЭР файлыг бичнэ. */
    public function test_applying_writes_the_uploaded_file(): void
    {
        $importId = $this->actingAs($this->engineer)
            ->post("/api/v1/blocks/{$this->block->id}/imports", [
                'file' => $this->materialFile(),
            ])->json('data.id');

        $response = $this->actingAs($this->engineer)
            ->postJson("/api/v1/imports/{$importId}/apply");

        $response->assertOk();
        $this->assertSame('applied', $response->json('data.status'));
        $this->assertSame('Ерөнхий инженер', $response->json('data.appliedBy'));

        $item = MaterialBudgetItem::firstOrFail();
        $this->assertEqualsWithDelta(1000, $item->total_qty, 0.001);
        $this->assertEqualsWithDelta(320000, $item->actual_unit_price, 0.01);
        $this->assertEqualsWithDelta(338000, $item->market_unit_price, 0.01);

        // Бичилтийн үр дүн тайланд гарна.
        $this->assertSame(1, $response->json('reports.0.result.items'));
        $this->assertSame(2, $response->json('reports.0.result.quantities'));
    }

    /**
     * Нэг файлыг хоёр удаа батлахыг хориглоно.
     *
     * Үр дүн нь ижил байх ч «хоёр удаа орсон» гэсэн төөрөгдөл нь
     * өөрөө асуудал: хүн тоо хэмжээ хоёр дахин нэмэгдсэн гэж бодно.
     */
    public function test_an_applied_import_cannot_be_applied_again(): void
    {
        $importId = $this->actingAs($this->engineer)
            ->post("/api/v1/blocks/{$this->block->id}/imports", [
                'file' => $this->materialFile(),
            ])->json('data.id');

        $this->actingAs($this->engineer)->postJson("/api/v1/imports/{$importId}/apply")->assertOk();

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/imports/{$importId}/apply")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'аль хэдийн'));
    }

    /** Ажлын хөлсний төсөв мөн адил вэбээс орно. */
    public function test_the_work_budget_imports_through_the_same_endpoint(): void
    {
        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();

        $importId = $this->actingAs($this->engineer)
            ->post("/api/v1/blocks/{$this->block->id}/imports", [
                'file' => $this->quantityFile($type),
            ])->json('data.id');

        $this->assertSame(0, WorkItem::count(), 'Урьдчилан харах нь юу ч бичих ёсгүй.');

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/imports/{$importId}/apply")
            ->assertOk();

        $this->assertSame(1, WorkItem::where('work_type_id', $type->id)->count());
        $this->assertEqualsWithDelta(
            15,
            WorkItem::where('work_type_id', $type->id)->first()->planned_qty,
            0.001,
        );
    }

    /**
     * Гүйцэтгэл устахыг урьдчилан ХЭЛНЭ.
     *
     * Энэ анхааруулга байхгүй бол хоёр сарын хэмжилт сэрэмжгүй
     * дарагдана.
     */
    public function test_the_preview_warns_that_progress_will_be_wiped(): void
    {
        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();
        $location = $this->block->locations()->where('name', '1-р давхар')->firstOrFail();

        $item = WorkItem::create([
            'block_id' => $this->block->id,
            'location_id' => $location->id,
            'work_type_id' => $type->id,
            'name' => $type->name,
            'unit' => $type->unit,
            'planned_qty' => 648,
            'reported_qty' => 600,
            'accepted_qty' => 0,
            'status' => 'in_progress',
            'review_state' => 'none',
        ]);

        \App\Models\ProgressEntry::create([
            'work_item_id' => $item->id,
            'reported_by_id' => $this->engineer->id,
            'completed_qty' => 600,
            'recorded_at' => now(),
        ]);

        $report = $this->actingAs($this->engineer)
            ->post("/api/v1/blocks/{$this->block->id}/imports", [
                'file' => $this->quantityFile($type),
            ])->json('reports.0');

        $titles = array_column($report['warnings'], 'title');

        $this->assertContains('ГҮЙЦЭТГЭЛ УСТАНА', $titles);
        $this->assertSame('danger', collect($report['warnings'])
            ->firstWhere('title', 'ГҮЙЦЭТГЭЛ УСТАНА')['level']);
    }

    /**
     * НЭГ ФАЙЛ, ХОЁР ТӨСӨВ — нэг л байршуулалт.
     *
     * Захиалагчийн жинхэнэ файл 29 хуудастай бөгөөд хоёр нь төсөв.
     * Хүнээс «энэ ямар төсөв вэ» гэж асуух нь буруу хариулт авах гарц
     * нээж байгаа хэрэг — хуудсын нэр эргэлзээгүй.
     */
    public function test_one_file_with_both_sheets_imports_both(): void
    {
        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();

        $created = $this->actingAs($this->engineer)
            ->post("/api/v1/blocks/{$this->block->id}/imports", [
                'file' => $this->combinedFile($type),
            ]);

        $created->assertCreated();

        $this->assertSame(['quantities', 'materials'], $created->json('data.types'));
        $this->assertSame(
            'Ажлын хөлсний төсөв + Материалын төсөв',
            $created->json('data.typeLabel'),
        );
        $this->assertCount(2, $created->json('reports'));

        /*
         * ДАРААЛАЛ ЧУХАЛ: ажлын төсөв эхэлнэ. Материал нь ажлын
         * төрөлд холбогддог тул ажлын мөрүүд өмнө нь байх ёстой.
         */
        $this->assertSame('quantities', $created->json('reports.0.type'));
        $this->assertSame('materials', $created->json('reports.1.type'));

        // Түүхийн мөрөнд НЭГТГЭСЭН дүн.
        $this->assertSame(2, $created->json('data.summary.planned'));

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/imports/{$created->json('data.id')}/apply")
            ->assertOk();

        $this->assertSame(1, WorkItem::count());
        $this->assertSame(1, MaterialBudgetItem::count());
    }

    /**
     * Хоёр төсөвтэй файлаас НЭГИЙГ нь л оруулж болно.
     *
     * Бодит хэрэгцээ: материалын хэсэг нь эвдэрсэн нүдтэй тул ажлын
     * төсвийг эхлээд оруулаад, материалыг засагдсаны дараа.
     */
    public function test_a_single_part_can_be_applied_from_a_combined_file(): void
    {
        $type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();

        $importId = $this->actingAs($this->engineer)
            ->post("/api/v1/blocks/{$this->block->id}/imports", [
                'file' => $this->combinedFile($type),
            ])->json('data.id');

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/imports/{$importId}/apply", ['types' => ['quantities']])
            ->assertOk();

        $this->assertSame(1, WorkItem::count());
        $this->assertSame(0, MaterialBudgetItem::count(), 'Материал орох ёсгүй.');

        // Бүртгэл нь ҮНЭХЭЭР орсон зүйлийг л тэмдэглэнэ.
        $this->assertSame(['quantities'], BudgetImport::findOrFail($importId)->types());
    }

    // ──────────────────────────── эрх, түүх ────────────────────────────

    /**
     * Талбайн инженер төсөв оруулж чадахгүй.
     *
     * Импорт нь блокийн бүх мөрийг солиод гүйцэтгэлийг устгадаг —
     * «100% болгохын тулд төлөвлөгөөг буулгав» гэсэн эрсдэл.
     */
    public function test_a_site_engineer_cannot_import(): void
    {
        $site = User::create([
            'name' => 'Талбайн инженер',
            'email' => 'site@inel.mn',
            'password' => bcrypt('secret123'),
            'role' => 'site_engineer',
        ]);

        $this->actingAs($site)
            ->post("/api/v1/blocks/{$this->block->id}/imports", [
                'file' => $this->materialFile(),
            ])
            ->assertForbidden();

        $this->assertSame(0, BudgetImport::count());
    }

    /** Түүх нь хэн, хэзээ, ямар файлаар оруулсныг харуулна. */
    public function test_the_history_lists_who_imported_what(): void
    {
        $importId = $this->actingAs($this->engineer)
            ->post("/api/v1/blocks/{$this->block->id}/imports", [
                'file' => $this->materialFile('2026-material.xlsx'),
            ])->json('data.id');

        $this->actingAs($this->engineer)->postJson("/api/v1/imports/{$importId}/apply")->assertOk();

        $rows = $this->actingAs($this->engineer)
            ->getJson("/api/v1/blocks/{$this->block->id}/imports")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('2026-material.xlsx', $rows[0]['originalName']);
        $this->assertSame('Орсон', $rows[0]['statusLabel']);
        $this->assertSame('Ерөнхий инженер', $rows[0]['uploadedBy']);
        $this->assertNotNull($rows[0]['appliedAt']);
    }

    /** Цуцлахад файл дискнээс УСТАНА. */
    public function test_discarding_removes_the_stored_file(): void
    {
        $importId = $this->actingAs($this->engineer)
            ->post("/api/v1/blocks/{$this->block->id}/imports", [
                'file' => $this->materialFile(),
            ])->json('data.id');

        $path = BudgetImport::findOrFail($importId)->path;

        $this->actingAs($this->engineer)
            ->deleteJson("/api/v1/imports/{$importId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'discarded');

        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    // ──────────────────────────── туслахууд ────────────────────────────

    /**
     * Хоёр хуудсыг НЭГ номонд — захиалагчийн файлын бүтэц.
     */
    private function combinedFile(WorkType $type): UploadedFile
    {
        $writer = new XlsxWriter(WorkBudgetImport::SHEET);

        foreach ($this->quantityRows($type) as $line) {
            $writer->row($line);
        }

        $writer->sheet(MaterialBudgetImport::SHEET);

        foreach ($this->materialRows() as $line) {
            $writer->row($line);
        }

        $path = $writer->save(tempnam(sys_get_temp_dir(), 'both').'.xlsx');

        return new UploadedFile($path, 'AJLIIN_TOO_HEMJEE.xlsx', null, null, true);
    }

    private function materialFile(string $name = 'tosov.xlsx'): UploadedFile
    {
        $writer = new XlsxWriter(MaterialBudgetImport::SHEET);

        foreach ($this->materialRows() as $line) {
            $writer->row($line);
        }

        $path = $writer->save(tempnam(sys_get_temp_dir(), 'mat').'.xlsx');

        return new UploadedFile($path, $name, null, null, true);
    }

    private function quantityFile(WorkType $type): UploadedFile
    {
        $writer = new XlsxWriter(WorkBudgetImport::SHEET);

        foreach ($this->quantityRows($type) as $line) {
            $writer->row($line);
        }

        $path = $writer->save(tempnam(sys_get_temp_dir(), 'qty').'.xlsx');

        return new UploadedFile($path, 'tosov.xlsx', null, null, true);
    }

    /**
     * «Материалын төсөв» хуудасны мөрүүд — захиалагчийн бүтцээр.
     *
     * Хоёр давхар толгой: дээд нь бүлгийн нэр («Бодит», «Зах зээл»),
     * доод нь баганын нэр («1-р давхар», «Нэгж үнэ»).
     *
     * @return array<int, array<int, mixed>>
     */
    private function materialRows(): array
    {
        $blank = fn () => array_fill(0, 19, '');

        $label = $blank();
        $label[1] = 'Ажлын нэр';
        $label[8] = 'Код';
        $label[9] = 'Материалын нэр';
        $label[10] = 'Материалын хэмжээ';   // төөрөгдүүлэх нэр — энэ нь МАРК
        $label[11] = 'Хэмжих нэгж';
        $label[12] = 'Материалын тоо хэмжээ';
        $label[15] = 'Бодит';
        $label[17] = 'Зах зээл';

        $second = $blank();
        $second[12] = '1-р давхар';
        $second[13] = '2-р давхар';
        $second[14] = 'Нийт';
        $second[15] = 'Нэгж үнэ';
        $second[16] = 'Нийт үнэ';
        $second[17] = 'Нэгж үнэ';
        $second[18] = 'Нийт үнэ';

        $row = $blank();
        $row[1] = 'Угсралт';
        $row[8] = '2.2.2';
        $row[9] = 'Суурь бетон';
        $row[10] = 'М250';
        $row[11] = 'м3';
        $row[12] = 900;
        $row[13] = 100;
        $row[14] = 1000;
        $row[15] = 320000;
        $row[17] = 338000;

        return [$blank(), $label, $second, $row];
    }

    /**
     * «Ажлын хөлсний төсөв» хуудасны мөрүүд.
     *
     * @return array<int, array<int, mixed>>
     */
    private function quantityRows(WorkType $type): array
    {
        $blank = fn () => array_fill(0, 11, '');

        $label = $blank();
        $label[8] = 'Хэмжих нэгж';

        $floors = $blank();
        $floors[9] = '1-р давхар';
        $floors[10] = '2-р давхар';

        $row = $blank();
        $row[2] = $type->code;
        $row[5] = $type->name;
        $row[8] = $type->unit;
        $row[9] = 15;

        return [$blank(), $label, $floors, $row];
    }
}
