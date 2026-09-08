<?php

namespace Tests\Feature;

use App\Jobs\ApplyBlockDesign;
use App\Models\Block;
use App\Models\BlockDesign;
use App\Models\Company;
use App\Models\DesignItem;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Төлөвлөгөөт тоо хэмжээ гүйцээх.
 *
 * Захиалагчийн Хавсралт-2-т ~30 ажлын төрлийн тоо хэмжээ хоосон байсан.
 * Тэдгээр нь `planned_qty = 0` болж үүсэх бөгөөд үлдэгдэл 0 тул гүйцэтгэл
 * ОГТ бүртгэх боломжгүй болдог — ажил талбай дээр хийгдэж байхад програм
 * дээр тэмдэглэх арга байхгүй гэсэн үг.
 *
 * Хамгийн чухал тест: `test_progress_works_after_the_quantity_is_set`.
 */
class PlanQuantityTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;
    private User $manager;
    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkTypeSeeder::class);

        $company = Company::create(['name' => 'Инэл ХХК']);
        /** @var Project $project */
        $project = $company->projects()->create(['name' => 'Цогцолбор', 'status' => 'active']);
        $design = BlockDesign::where('floors', 12)->firstOrFail();

        $this->block = $project->blocks()->create([
            'block_design_id' => $design->id,
            'name' => 'А блок',
            'building_no' => '1',
            'floors' => $design->floors,
            'units_per_floor' => $design->units_per_floor,
            'start_date' => '2026-09-01',
        ]);
        (new ApplyBlockDesign($this->block->id, $design->id, '2026-09-01'))->handle();

        $this->manager = User::factory()->create(['role' => 'project_manager']);
        $this->engineer = User::factory()->create(['role' => 'site_engineer']);
    }

    /**
     * Тоо хэмжээгүй ажил үүсгэнэ.
     *
     * Seed бүх төрөлд тоо хэмжээ өгсөн байж болзошгүй тул нөхцөлийг ЭНД
     * баталгаатай үүсгэнэ — жинхэнэ өгөгдөл дээр `qty_per_location` null
     * байхад ингэж үүсдэг.
     */
    private function zeroItems(): \Illuminate\Support\Collection
    {
        $workTypeId = WorkItem::where('block_id', $this->block->id)
            ->select('work_type_id')
            ->groupBy('work_type_id')
            ->havingRaw('count(*) > 1')
            ->value('work_type_id');

        WorkItem::where('block_id', $this->block->id)
            ->where('work_type_id', $workTypeId)
            ->update(['planned_qty' => 0, 'reported_qty' => 0, 'accepted_qty' => 0]);

        // Загварт ч хоосон болгоно — бөөнөөр оруулахад загвар засагдахыг шалгана.
        DesignItem::where('block_design_id', $this->block->block_design_id)
            ->where('work_type_id', $workTypeId)
            ->update(['qty_per_location' => null]);

        return WorkItem::where('block_id', $this->block->id)
            ->where('work_type_id', $workTypeId)
            ->get();
    }

    // -----------------------------------------------------------------
    // Асуудал ба шийдэл
    // -----------------------------------------------------------------

    public function test_progress_cannot_be_reported_without_a_quantity(): void
    {
        $item = $this->zeroItems()->first();

        // Энэ бол хэрэглэгчийн тааралдсан гацаа — үлдэгдэл 0 тул юу ч орохгүй.
        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 1])
            ->assertStatus(422);
    }

    public function test_progress_works_after_the_quantity_is_set(): void
    {
        $item = $this->zeroItems()->first();

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/work-items/{$item->id}", ['plannedQty' => 25])
            ->assertOk()
            ->assertJsonPath('data.plannedQty', 25)
            ->assertJsonPath('data.remainingQty', 25);

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 10])
            ->assertCreated();

        $this->assertSame(10.0, (float) $item->refresh()->reported_qty);
    }

    public function test_missing_quantities_lists_what_needs_filling(): void
    {
        $items = $this->zeroItems();

        $data = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/blocks/{$this->block->id}/missing-quantities")
            ->assertOk()
            ->json('data');

        $row = collect($data)->firstWhere('workTypeId', $items->first()->work_type_id);

        $this->assertNotNull($row, 'Тоо хэмжээгүй төрөл жагсаалтад байх ёстой.');
        $this->assertSame($items->count(), $row['workItems']);
    }

    // -----------------------------------------------------------------
    // Бөөнөөр оруулах
    // -----------------------------------------------------------------

    public function test_bulk_fills_every_empty_row_of_the_work_type(): void
    {
        $items = $this->zeroItems();
        $workTypeId = $items->first()->work_type_id;

        // Айлын түвшний нэг төрөл 16 давхрын барилгад 144 мөр үүсгэдэг —
        // нэг нэгээр нь бөглөх нь бодит ажлын урсгалд боломжгүй.
        $this->assertGreaterThan(1, $items->count());

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$this->block->id}/work-items/set-quantity", [
                'workTypeId' => $workTypeId,
                'plannedQty' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('data.affected', $items->count());

        $this->assertSame(
            0,
            WorkItem::where('block_id', $this->block->id)
                ->where('work_type_id', $workTypeId)
                ->where('planned_qty', 0)
                ->count(),
        );
    }

    public function test_bulk_does_not_overwrite_rows_that_already_have_a_quantity(): void
    {
        $items = $this->zeroItems();
        $workTypeId = $items->first()->work_type_id;

        // Нэг мөрийг гараар зөв бөглөнө.
        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/work-items/{$items->first()->id}", ['plannedQty' => 99])
            ->assertOk();

        $this->postJson("/api/v1/blocks/{$this->block->id}/work-items/set-quantity", [
            'workTypeId' => $workTypeId,
            'plannedQty' => 12,
        ])->assertOk();

        // Гараар оруулсан утга ХЭВЭЭР — засах гэж байгаад зөв өгөгдлийг
        // устгах нь хамгийн муу үр дүн.
        $this->assertSame(99.0, (float) $items->first()->refresh()->planned_qty);
    }

    public function test_bulk_can_overwrite_when_explicitly_asked(): void
    {
        $items = $this->zeroItems();

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/work-items/{$items->first()->id}", ['plannedQty' => 99])
            ->assertOk();

        $this->postJson("/api/v1/blocks/{$this->block->id}/work-items/set-quantity", [
            'workTypeId' => $items->first()->work_type_id,
            'plannedQty' => 12,
            'overwriteExisting' => true,
        ])->assertOk();

        $this->assertSame(12.0, (float) $items->first()->refresh()->planned_qty);
    }

    public function test_bulk_also_fixes_the_design(): void
    {
        $items = $this->zeroItems();
        $workTypeId = $items->first()->work_type_id;

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$this->block->id}/work-items/set-quantity", [
                'workTypeId' => $workTypeId,
                'plannedQty' => 12,
            ])
            ->assertOk()
            ->assertJsonPath('data.designUpdated', true);

        // Загварыг засахгүй бол ДАРААГИЙН блок ижил нүхтэй үүснэ.
        //
        // Тоогоор харьцуулна: `decimal:3` cast нь драйвераас хамаарч "12",
        // "12.000" гэж өөр өөр текст буцаадаг.
        $stored = DesignItem::where('block_design_id', $this->block->block_design_id)
            ->where('work_type_id', $workTypeId)
            ->value('qty_per_location');

        $this->assertEqualsWithDelta(12.0, (float) $stored, 0.001);
    }

    // -----------------------------------------------------------------
    // Хамгаалалт
    // -----------------------------------------------------------------

    public function test_cannot_plan_below_what_is_already_accepted(): void
    {
        $item = $this->zeroItems()->first();

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/work-items/{$item->id}", ['plannedQty' => 20])
            ->assertOk();

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 15])
            ->assertCreated();

        $this->actingAs(User::factory()->create(['role' => 'inspector']), 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'accepted',
                'acceptedQty' => 15,
            ])->assertCreated();

        // 15 батлагдсан байхад 5 гэж төлөвлөвөл гүйцэтгэл 300% болно.
        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/work-items/{$item->id}", ['plannedQty' => 5])
            ->assertStatus(409);
    }

    public function test_site_engineer_cannot_edit_the_plan(): void
    {
        $item = $this->zeroItems()->first();

        // Талбайн инженер төлөвлөгөө буулгаж "100%" гаргах боломжгүй байх ёстой.
        $this->actingAs($this->engineer, 'sanctum')
            ->patchJson("/api/v1/work-items/{$item->id}", ['plannedQty' => 1])
            ->assertForbidden();

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/blocks/{$this->block->id}/work-items/set-quantity", [
                'workTypeId' => $item->work_type_id,
                'plannedQty' => 1,
            ])
            ->assertForbidden();
    }

    public function test_general_engineer_can_edit_the_plan(): void
    {
        $item = $this->zeroItems()->first();

        $this->actingAs(User::factory()->create(['role' => 'general_engineer']), 'sanctum')
            ->patchJson("/api/v1/work-items/{$item->id}", ['plannedQty' => 30])
            ->assertOk();
    }

    public function test_status_is_recalculated_after_the_quantity_changes(): void
    {
        $item = $this->zeroItems()->first();

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/work-items/{$item->id}", ['plannedQty' => 10])
            ->assertOk();

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 10])
            ->assertCreated();

        $this->actingAs(User::factory()->create(['role' => 'inspector']), 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'accepted',
                'acceptedQty' => 10,
            ])->assertCreated();

        $this->assertSame('completed', $item->refresh()->status);

        // Төлөвлөгөө нэмэгдвэл ажил дахин "дуусаагүй" болно.
        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/work-items/{$item->id}", ['plannedQty' => 20])
            ->assertOk()
            ->assertJsonPath('data.remainingQty', 10)
            ->assertJsonPath('data.percentage', 50);

        $this->assertSame('in_progress', $item->refresh()->status);
    }
}
