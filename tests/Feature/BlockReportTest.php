<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Contractor;
use App\Models\Inspection;
use App\Models\MaterialBudgetItem;
use App\Models\Material;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Блокийн ерөнхий тайлан.
 *
 * ГОЛ УТГА НЬ ХАРЬЦУУЛАЛТ: 95% дууссан барилга төсвөө 40% хэтрүүлсэн,
 * 60 хоног хоцорсон байж болно. Таван хэсэг зэрэг харагдаж байж
 * шийдвэр гарна — тиймээс тус бүрийг нь шалгана.
 */
class BlockReportTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkTypeSeeder::class);

        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Ланкастер', 'status' => 'active']);

        $this->block = $project->blocks()->create([
            'name' => 'А1 блок',
            'building_no' => 'A1',
            'floors' => 1,
            'units_per_floor' => 2,
            'start_date' => '2026-10-01',
        ]);

        app(\App\Services\BlockLocationBuilder::class)->build($this->block, 1, 2);

        $this->engineer = User::create([
            'name' => 'Ерөнхий инженер', 'email' => 'ge@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'general_engineer',
        ]);
    }

    /** Таван хэсэг бүгд буцна. */
    public function test_the_report_covers_all_five_sections(): void
    {
        $data = $this->report();

        foreach (['block', 'progress', 'byGroup', 'money', 'schedule', 'quality', 'materials'] as $key) {
            $this->assertArrayHasKey($key, $data, "«{$key}» хэсэг дутуу.");
        }

        $this->assertSame('А1 блок', $data['block']['name']);
    }

    /**
     * Гүйцэтгэлийн хувь нь МӨРИЙН дунджаар бодогдоно.
     *
     * Нийт тоо хэмжээгээр бодвол м² ба м³ нийлж, 3,000 м³ газар шороо
     * 60 м² шалыг дарна — барилга «бараг дууссан» гэж харагдана.
     */
    public function test_progress_is_averaged_per_item_not_by_quantity(): void
    {
        $this->workItem(3000, 3000);   // бүрэн дууссан, ТОМ тоо
        $this->workItem(60, 0);        // огт эхлээгүй, жижиг тоо

        $progress = $this->report()['progress'];

        $this->assertSame(2, $progress['workItems']);
        // Мөрийн дунджаар 50%, тоо хэмжээгээр бол 98%.
        $this->assertEqualsWithDelta(50, $progress['percentage'], 0.1);
    }

    /** Ажлын бүлгээр задаргаа гарна. */
    public function test_the_report_breaks_down_by_work_group(): void
    {
        $this->workItem(100, 100);

        $groups = $this->report()['byGroup'];

        $this->assertNotEmpty($groups);
        $this->assertArrayHasKey('name', $groups[0]);
        $this->assertArrayHasKey('percentage', $groups[0]);
    }

    /**
     * ГУРВАН ҮНЭ зэрэгцэнэ: төсөв → гэрээ → акт.
     *
     * Төсөв нь жишиг, гэрээ нь амлалт, акт нь бодит төлбөр. Зөрүү
     * гарах нь хэвийн — харагдахгүй байх нь асуудал.
     */
    public function test_money_compares_budget_agreement_and_acceptance(): void
    {
        $item = $this->workItem(100, 40, unitPrice: 10000);

        $contractor = Contractor::create(['name' => 'Нью констракшн']);

        $agreement = \App\Models\Agreement::create([
            'number' => 'ТХ-2026-001',
            'block_id' => $this->block->id,
            'contractor_id' => $contractor->id,
            'status' => 'active',
            'barter_percent' => 0,
            'cash_percent' => 100,
        ]);

        $agreement->lines()->create([
            'work_type_id' => $item->work_type_id,
            'label' => $item->name,
            'unit' => $item->unit,
            'quantity' => 100,
            // Гэрээний үнэ ТӨСВӨӨС өндөр — яг тэр зөрүүг харуулах ёстой.
            // `amount` нь БАГАНА БИШ — `quantity × unit_price`-аас бодогдоно.
            'unit_price' => 12000,
        ]);

        $money = $this->report()['money'];

        $this->assertEqualsWithDelta(1_000_000, $money['budgetAmount'], 0.01);
        $this->assertEqualsWithDelta(1_200_000, $money['agreedAmount'], 0.01);
        // Акт = батлагдсан 40 × гэрээний 12,000.
        $this->assertEqualsWithDelta(480_000, $money['acceptedAmount'], 0.01);
        $this->assertEqualsWithDelta(20, $money['agreedVariancePercent'], 0.01);
    }

    /** Төсөв 0 бол зөрүүний хувь `null` — тэгд хуваахгүй. */
    public function test_variance_is_null_when_there_is_no_budget(): void
    {
        $this->workItem(100, 0);

        $this->assertNull($this->report()['money']['agreedVariancePercent']);
    }

    /**
     * Хугацаа нь ҮНДСЭН амлалттай зэрэгцэнэ.
     *
     * Сунгалтын дараа `planned_end` хөдөлдөг тул зөвхөн түүнийг харвал
     * гулсалт алга болно.
     */
    public function test_schedule_compares_against_the_baseline(): void
    {
        $item = $this->workItem(100, 0);
        $item->update([
            'planned_end_date' => '2026-12-31',
            'baseline_end_date' => '2026-11-01',
        ]);

        $schedule = $this->report()['schedule'];

        $this->assertSame('2026-11-01', substr((string) $schedule['baselineEnd'], 0, 10));
        $this->assertSame(60, $schedule['slippageDays']);
    }

    /** Чанарын хэсэг татгалзлын ХУВЬ гаргана. */
    public function test_quality_reports_a_rejection_rate(): void
    {
        $item = $this->workItem(100, 40);

        foreach (['accepted', 'accepted', 'accepted', 'rejected'] as $result) {
            Inspection::create([
                'work_item_id' => $item->id,
                'inspector_id' => $this->engineer->id,
                'stage' => Inspection::STAGE_FINAL,
                'result' => $result,
                'accepted_qty' => $result === 'accepted' ? 10 : 0,
                'rejected_qty' => $result === 'rejected' ? 10 : 0,
                'inspected_at' => now(),
            ]);
        }

        $quality = $this->report()['quality'];

        $this->assertSame(4, $quality['inspections']);
        $this->assertSame(1, $quality['rejected']);
        $this->assertEqualsWithDelta(25, $quality['rejectionRate'], 0.1);
    }

    /** Шалгалтгүй бол татгалзлын хувь `null` — 0% гэж худал хэлэхгүй. */
    public function test_rejection_rate_is_null_without_inspections(): void
    {
        $this->workItem(100, 0);

        $this->assertNull($this->report()['quality']['rejectionRate']);
    }

    /** Материалын хэсэг хоёр үнийг зэрэгцүүлнэ. */
    public function test_materials_compare_actual_and_market_prices(): void
    {
        $material = Material::create([
            'project_id' => $this->block->project_id,
            'code' => '2.2.2', 'name' => 'Суурь бетон', 'grade' => 'М250', 'unit' => 'м3',
        ]);

        MaterialBudgetItem::create([
            'block_id' => $this->block->id,
            'material_id' => $material->id,
            'total_qty' => 100,
            'actual_unit_price' => 320000,
            'market_unit_price' => 338000,
        ]);

        $materials = $this->report()['materials'];

        $this->assertSame(1, $materials['budgetedItems']);
        $this->assertEqualsWithDelta(32_000_000, $materials['actualAmount'], 0.01);
        $this->assertEqualsWithDelta(33_800_000, $materials['marketAmount'], 0.01);
        $this->assertEqualsWithDelta(5.63, $materials['marketVariancePercent'], 0.01);
    }

    // ───────────────────────── орсон төсөв ─────────────────────────

    /**
     * Орсон төсөв ажлын ТӨРЛӨӨР нэгтгэгдэнэ.
     *
     * А1-д 1,991 ажил бий. Тэднийг жагсаах нь төсөв биш, жагсаалт
     * болно — төсөв нь «шавардлага нийт 4,320 м²» гэсэн түвшинд
     * уншигддаг.
     */
    public function test_the_budget_view_groups_work_by_type(): void
    {
        $item = $this->workItem(100, 40, unitPrice: 12000);

        // ИЖИЛ төрлөөр хоёр дахь ажил — өөр байршилд.
        WorkItem::create([
            'block_id' => $this->block->id,
            'location_id' => $this->block->locations()->where('name', 'Зоорийн давхар')->firstOrFail()->id,
            'work_type_id' => $item->work_type_id,
            'name' => $item->name,
            'unit' => $item->unit,
            'planned_qty' => 50,
            'budget_unit_price' => 12000,
            'status' => 'not_started',
            'review_state' => 'none',
        ]);

        $rows = $this->actingAs($this->engineer)
            ->getJson("/api/v1/blocks/{$this->block->id}/budget")
            ->assertOk()
            ->json('data.work');

        $this->assertCount(1, $rows, 'Хоёр ажил НЭГ төрөл болж нэгтгэгдэнэ.');
        $this->assertSame(2, $rows[0]['works']);
        $this->assertEqualsWithDelta(150, $rows[0]['plannedQty'], 0.001);
        $this->assertEqualsWithDelta(1_800_000, $rows[0]['amount'], 0.01);
        $this->assertFalse($rows[0]['priceConflict']);
    }

    /** Нэг төрөлд хоёр өөр үнэ орсныг ХЭЛНЭ — төсөв дээр гарах ёсгүй. */
    public function test_the_budget_view_flags_a_price_conflict(): void
    {
        $item = $this->workItem(100, 0, unitPrice: 12000);

        WorkItem::create([
            'block_id' => $this->block->id,
            'location_id' => $this->block->locations()->where('name', 'Зоорийн давхар')->firstOrFail()->id,
            'work_type_id' => $item->work_type_id,
            'name' => $item->name,
            'unit' => $item->unit,
            'planned_qty' => 50,
            'budget_unit_price' => 15000,   // ЗӨРСӨН үнэ
            'status' => 'not_started',
            'review_state' => 'none',
        ]);

        $rows = $this->actingAs($this->engineer)
            ->getJson("/api/v1/blocks/{$this->block->id}/budget")->json('data.work');

        $this->assertTrue($rows[0]['priceConflict']);
    }

    /** Материалын төсөв БАЙРШЛЫН задаргаатай гарна. */
    public function test_the_budget_view_breaks_materials_down_by_location(): void
    {
        $material = Material::create([
            'project_id' => $this->block->project_id,
            'code' => '2.2.2', 'name' => 'Суурь бетон', 'grade' => 'М250', 'unit' => 'м3',
        ]);

        $budgetItem = MaterialBudgetItem::create([
            'block_id' => $this->block->id,
            'material_id' => $material->id,
            'total_qty' => 150,
            'actual_unit_price' => 320000,
            'market_unit_price' => 338000,
        ]);

        foreach (['1-р давхар' => 100, 'Зоорийн давхар' => 50] as $name => $qty) {
            \App\Models\MaterialBudgetQuantity::create([
                'material_budget_item_id' => $budgetItem->id,
                'location_id' => $this->block->locations()->where('name', $name)->firstOrFail()->id,
                'quantity' => $qty,
            ]);
        }

        $rows = $this->actingAs($this->engineer)
            ->getJson("/api/v1/blocks/{$this->block->id}/budget")->json('data.materials');

        $this->assertCount(1, $rows);
        $this->assertSame('М250', $rows[0]['grade']);
        $this->assertCount(2, $rows[0]['locations']);
        $this->assertEqualsWithDelta(48_000_000, $rows[0]['actualAmount'], 0.01);
        $this->assertEqualsWithDelta(5.63, $rows[0]['variancePercent'], 0.01);
    }

    /** Гүйцэтгэгч орсон төсөв харахгүй — мөнгө агуулдаг. */
    public function test_a_contractor_cannot_read_the_budget_view(): void
    {
        $contractor = User::create([
            'name' => 'Гүйцэтгэгч', 'email' => 'ctr2@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'contractor',
        ]);

        $this->actingAs($contractor)
            ->getJson("/api/v1/blocks/{$this->block->id}/budget")
            ->assertForbidden();
    }

    // ───────────────────────── эрх, татах ─────────────────────────

    /** Гүйцэтгэгч тайлан харахгүй — мөнгө агуулдаг. */
    public function test_a_contractor_cannot_read_the_report(): void
    {
        $contractor = User::create([
            'name' => 'Гүйцэтгэгч', 'email' => 'ctr@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'contractor',
        ]);

        $this->actingAs($contractor)
            ->getJson("/api/v1/blocks/{$this->block->id}/report")
            ->assertForbidden();
    }

    /** Захирал ХАРНА — юу ч батлахгүй ч барилгынхаа байдлыг мэдэх ёстой. */
    public function test_the_director_can_read_the_report(): void
    {
        $director = User::create([
            'name' => 'Б.Батбаяр', 'email' => 'dir@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'director',
        ]);

        $this->actingAs($director)
            ->getJson("/api/v1/blocks/{$this->block->id}/report")
            ->assertOk();
    }

    /** Дуусаагүй блокт ч тайлан гарна. */
    public function test_the_report_works_on_an_unfinished_block(): void
    {
        $this->workItem(100, 10);

        $this->assertLessThan(100, $this->report()['progress']['percentage']);
    }

    /** xlsx татагдана. */
    public function test_the_sheet_downloads(): void
    {
        $this->workItem(100, 40);

        $this->actingAs($this->engineer)
            ->get("/api/v1/blocks/{$this->block->id}/report.xlsx")
            ->assertOk();
    }

    // ───────────────────────── туслахууд ─────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function report(): array
    {
        return $this->actingAs($this->engineer)
            ->getJson("/api/v1/blocks/{$this->block->id}/report")
            ->assertOk()
            ->json('data');
    }

    private function workItem(float $planned, float $accepted, ?float $unitPrice = null): WorkItem
    {
        $type = WorkType::whereNotNull('code')
            ->where('level', 'floor')
            ->skip(WorkItem::where('block_id', $this->block->id)->count())
            ->firstOrFail();

        $location = $this->block->locations()->where('name', '1-р давхар')->firstOrFail();

        return WorkItem::create([
            'block_id' => $this->block->id,
            'location_id' => $location->id,
            'work_type_id' => $type->id,
            'name' => $type->name,
            'unit' => $type->unit,
            'planned_qty' => $planned,
            'reported_qty' => $accepted,
            'accepted_qty' => $accepted,
            'budget_unit_price' => $unitPrice,
            'status' => $accepted >= $planned ? 'completed' : ($accepted > 0 ? 'in_progress' : 'not_started'),
            'review_state' => 'none',
            'planned_start_date' => '2026-10-01',
            'planned_end_date' => '2026-10-20',
            'baseline_end_date' => '2026-10-20',
        ]);
    }
}
