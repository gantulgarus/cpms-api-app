<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Material;
use App\Models\MaterialBudgetItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Материалын төсвийн мөрийг ГАРААР нэмэх, хасах.
 *
 * ЯАГААД ХЭРЭГТЭЙ ВЭ: импорт эвдэрсэн нүдтэй мөрийг УНАГААДАГ (А1-д
 * 109 эвдэрсэн нүд, 148 дутуу тоо хэмжээ). Тэднийг нөхөх зам байхгүй
 * бол төсөв бүрэн болохгүй, үлдэгдлийн тооцоо худал болно.
 *
 * ХОЁР ДҮРЭМ энд голлоно:
 *   1. НИЙТ нь задаргааны НИЙЛБЭР — хоёрыг тусад нь авбал зөрнө
 *   2. ЗАХИАЛГАТАЙ мөр хасагдахгүй — үлдэгдэл төсөв дээр тогтдог
 */
class MaterialBudgetEditTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;

    private Project $project;

    private User $admin;

    private User $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Инэл ХХК']);
        $this->project = $company->projects()->create(['name' => 'Ланкастер', 'status' => 'active']);

        $this->block = $this->project->blocks()->create([
            'name' => 'А1 блок',
            'building_no' => 'A1',
            'floors' => 2,
            'units_per_floor' => 2,
            'start_date' => '2026-10-01',
        ]);

        app(\App\Services\BlockLocationBuilder::class)->build($this->block, 2, 2);

        $this->admin = User::create([
            'name' => 'Админ', 'email' => 'admin@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'admin',
        ]);

        $this->inspector = User::create([
            'name' => 'Д.Хулан', 'email' => 'inspector@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'inspector',
        ]);
    }

    // ───────────────────────── нэмэх ─────────────────────────

    /** Нийт тоо хэмжээ нь байршлын задаргааны нийлбэр байна. */
    public function test_the_total_quantity_is_the_sum_of_the_location_breakdown(): void
    {
        $floors = $this->floors();

        $id = $this->actingAs($this->admin)
            ->postJson("/api/v1/blocks/{$this->block->id}/material-budget-items", [
                'code' => '2.9.9',
                'name' => 'Шатны тор',
                'unit' => 'м2',
                'actualUnitPrice' => 12000,
                'quantities' => [
                    ['locationId' => $floors[0]->id, 'quantity' => 40],
                    ['locationId' => $floors[1]->id, 'quantity' => 60.5],
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $item = MaterialBudgetItem::findOrFail($id);

        $this->assertEqualsWithDelta(100.5, (float) $item->total_qty, 0.001);
        $this->assertSame(2, $item->quantities()->count());
        $this->assertEqualsWithDelta(12000, (float) $item->actual_unit_price, 0.001);
    }

    /**
     * Лавлах нь ТӨСЛИЙН хэмжээнд — ижил кодыг хоёр блокт нэмэхэд нэг
     * материал үүснэ.
     *
     * «Ф8» гэдэг арматур 75 барилгад ижил зүйл. Блок бүрт шинээр
     * үүсгэвэл захиалгын жагсаалт давхардаж уншигдахгүй болно.
     */
    public function test_the_same_code_in_two_blocks_reuses_one_material(): void
    {
        $other = $this->project->blocks()->create([
            'name' => 'А2 блок',
            'building_no' => 'A2',
            'floors' => 1,
            'units_per_floor' => 1,
            'start_date' => '2026-10-01',
        ]);

        app(\App\Services\BlockLocationBuilder::class)->build($other, 1, 1);

        $this->add(['code' => '2.1.3', 'name' => 'ф12'])->assertCreated();

        $this->actingAs($this->admin)
            ->postJson("/api/v1/blocks/{$other->id}/material-budget-items", [
                'code' => '2.1.3',
                'name' => 'ф12',
                'unit' => 'ш',
                'quantities' => [[
                    'locationId' => $other->locations()->where('level', 'floor')->first()->id,
                    'quantity' => 10,
                ]],
            ])
            ->assertCreated();

        $this->assertSame(1, Material::where('code', '2.1.3')->count());
    }

    /** Нэг блокт нэг код хоёр удаа орохгүй. */
    public function test_a_duplicate_code_in_the_same_block_is_refused(): void
    {
        $this->add()->assertCreated();

        $this->add()->assertStatus(422);
    }

    /** Танигдахгүй хэмжих нэгж татгалзагдана. */
    public function test_an_unknown_unit_is_refused(): void
    {
        $this->add(['unit' => 'тонн'])->assertStatus(422)->assertJsonValidationErrors('unit');
    }

    /** Задаргаагүй мөр нь төсөв биш. */
    public function test_a_row_without_quantities_is_refused(): void
    {
        $this->add(['quantities' => []])->assertStatus(422)->assertJsonValidationErrors('quantities');
    }

    /** Өөр блокийн байршил руу тоо хэмжээ бичих боломжгүй. */
    public function test_a_location_from_another_block_is_refused(): void
    {
        $other = $this->project->blocks()->create([
            'name' => 'А2 блок',
            'building_no' => 'A2',
            'floors' => 1,
            'units_per_floor' => 1,
            'start_date' => '2026-10-01',
        ]);

        app(\App\Services\BlockLocationBuilder::class)->build($other, 1, 1);

        $this->add([
            'quantities' => [[
                'locationId' => $other->locations()->first()->id,
                'quantity' => 5,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('quantities.0.locationId');
    }

    /** 0 үнэ зөвшөөрөгдөхгүй — «үнэгүй» ба «мэдэгдэхгүй» хоёр өөр зүйл. */
    public function test_a_zero_price_is_refused(): void
    {
        $this->add(['actualUnitPrice' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('actualUnitPrice');
    }

    // ───────────────────────── эрх ─────────────────────────

    /** Засах эрх нь ХАРАХААС нарийн: хяналтын инженер нэмэхгүй. */
    public function test_an_inspector_cannot_add_a_budget_row(): void
    {
        $this->actingAs($this->inspector)
            ->postJson("/api/v1/blocks/{$this->block->id}/material-budget-items", [
                'code' => '2.9.9',
                'name' => 'Шатны тор',
                'unit' => 'м2',
                'quantities' => [['locationId' => $this->floors()[0]->id, 'quantity' => 1]],
            ])
            ->assertForbidden();
    }

    // ───────────────────────── хасах ─────────────────────────

    /** Захиалгагүй мөр хасагдана. */
    public function test_a_row_without_orders_can_be_removed(): void
    {
        $id = $this->add()->assertCreated()->json('data.id');

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/material-budget-items/{$id}")
            ->assertOk();

        $this->assertNull(MaterialBudgetItem::find($id));
        $this->assertDatabaseMissing('material_budget_quantities', ['material_budget_item_id' => $id]);
    }

    /**
     * ЗАХИАЛГАТАЙ мөр хасагдахгүй.
     *
     * Үлдэгдэл нь төсөв дээр тогтдог: төсвийг нь устгавал захиалга
     * «төсөвгүй» болж, хэтэрсэн эсэх нь мэдэгдэхгүй болно.
     */
    public function test_a_row_with_a_committed_order_cannot_be_removed(): void
    {
        $id = $this->add()->assertCreated()->json('data.id');
        $item = MaterialBudgetItem::findOrFail($id);

        $engineer = User::create([
            'name' => 'Талбайн инженер', 'email' => 'site@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'site_engineer',
        ]);

        $orderId = $this->actingAs($engineer)
            ->postJson("/api/v1/blocks/{$this->block->id}/material-orders", [
                'kind' => 'main',
                'lines' => [['materialId' => $item->material_id, 'quantity' => 5]],
            ])
            ->assertCreated()
            ->json('data.id');

        // ДРАФТ захиалга төсөв иддэггүй — тиймээс хасахад саад болохгүй.
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/material-budget-items/{$id}")
            ->assertOk();

        // Дахин нэмээд, энэ удаа ИЛГЭЭНЭ.
        $id = $this->add()->assertCreated()->json('data.id');
        $item = MaterialBudgetItem::findOrFail($id);

        \App\Models\MaterialOrderLine::where('material_order_id', $orderId)
            ->update(['material_id' => $item->material_id]);

        $this->actingAs($engineer)
            ->postJson("/api/v1/material-orders/{$orderId}/submit")
            ->assertOk();

        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/material-budget-items/{$id}")
            ->assertStatus(422);

        $this->assertNotNull(MaterialBudgetItem::find($id));
    }

    /** Хяналтын инженер мөр хасахгүй. */
    public function test_an_inspector_cannot_remove_a_budget_row(): void
    {
        $id = $this->add()->assertCreated()->json('data.id');

        $this->actingAs($this->inspector)
            ->deleteJson("/api/v1/material-budget-items/{$id}")
            ->assertForbidden();
    }

    // ───────────────────────── туслах ─────────────────────────

    /** @return \Illuminate\Support\Collection<int, \App\Models\Location> */
    private function floors(): \Illuminate\Support\Collection
    {
        return $this->block->locations()->where('level', 'floor')->orderBy('name')->get()->values();
    }

    /** @param  array<string, mixed>  $patch */
    private function add(array $patch = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/v1/blocks/{$this->block->id}/material-budget-items", [
                'code' => '2.9.9',
                'name' => 'Шатны тор',
                'unit' => 'м2',
                'quantities' => [['locationId' => $this->floors()[0]->id, 'quantity' => 40]],
                ...$patch,
            ]);
    }
}
