<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Material;
use App\Models\MaterialBudgetItem;
use App\Models\MaterialOrder;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Материалын захиалга — үндсэн, туслах, модон, арматур.
 *
 * ГОЛ НЬ ТӨСВИЙН ҮЛДЭГДЭЛ. Цаасан бланк аль хэдийн байгаа; систем нэмж
 * байгаа зүйл нь «энэ блокт төсвөөр хэд байсан, хэд захиалсан» гэдэг.
 *
 * БЕТОНООС ЯЛГАРАХ ХОЁР ЗҮЙЛ энд тусгайлан шалгагдана: үлдэгдэл нь
 * БЛОКООР бодогдоно (давхраар биш), ба «зөвшөөрсөн тоо» нь захиалсанаас
 * зөрж болно.
 */
class MaterialOrderTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;

    private Project $project;

    private Material $pum;

    private User $engineer;

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

        $this->pum = $this->material('2.3.1', '20 Пум', 'ш', '200x1200мм');

        $this->engineer = User::create([
            'name' => 'Талбайн инженер', 'email' => 'site@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'site_engineer',
        ]);

        $this->inspector = User::create([
            'name' => 'Д.Хулан', 'email' => 'inspector@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'inspector',
        ]);
    }

    // ───────────────────────── үлдэгдэл ─────────────────────────

    /** Үлдэгдэл нь төсвөөс захиалгыг хасна. */
    public function test_the_balance_subtracts_orders_from_the_budget(): void
    {
        $this->budget($this->pum, 508);

        $this->assertEqualsWithDelta(508, $this->balance($this->pum)['remaining'], 0.001);

        $this->submitOrder([['materialId' => $this->pum->id, 'quantity' => 300]]);

        $balance = $this->balance($this->pum);
        $this->assertEqualsWithDelta(300, $balance['ordered'], 0.001);
        $this->assertEqualsWithDelta(208, $balance['remaining'], 0.001);
    }

    /** Драфт нь төсөв иддэггүй. */
    public function test_a_draft_does_not_consume_the_budget(): void
    {
        $this->budget($this->pum, 508);
        $this->createOrder([['materialId' => $this->pum->id, 'quantity' => 300]]);

        $this->assertEqualsWithDelta(0, $this->balance($this->pum)['ordered'], 0.001);
    }

    /**
     * ЗӨВШӨӨРСӨН тоо нь төсвөөс хасагдана, захиалсан нь БИШ.
     *
     * Бланк дээр хоёр багана тусдаа байгаа нь санамсаргүй биш:
     * баталгаажуулагч 300 хүссэнийг 180 болгон бууруулдаг. Захиалсанаар
     * бодвол төсөв 120-оор худал иддэг.
     */
    public function test_the_approved_quantity_is_what_consumes_the_budget(): void
    {
        $this->budget($this->pum, 508);
        $id = $this->submitOrder([['materialId' => $this->pum->id, 'quantity' => 300]]);

        $lineId = MaterialOrder::findOrFail($id)->lines->first()->id;

        $this->actingAs($this->inspector)
            ->postJson("/api/v1/material-orders/{$id}/confirm", [
                'approved' => [$lineId => 180],
            ])
            ->assertOk();

        $balance = $this->balance($this->pum);
        $this->assertEqualsWithDelta(180, $balance['ordered'], 0.001);
        $this->assertEqualsWithDelta(328, $balance['remaining'], 0.001);
    }

    /** Нийлүүлэгдсэн бол БОДИТ хэмжээгээр иднэ. */
    public function test_delivery_overrides_the_approved_quantity(): void
    {
        $this->budget($this->pum, 508);
        $id = $this->submitOrder([['materialId' => $this->pum->id, 'quantity' => 300]]);
        $lineId = MaterialOrder::findOrFail($id)->lines->first()->id;

        $this->actingAs($this->inspector)
            ->postJson("/api/v1/material-orders/{$id}/confirm", ['approved' => [$lineId => 180]])
            ->assertOk();

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/material-orders/{$id}/deliver", ['delivered' => [$lineId => 200]])
            ->assertOk();

        $this->assertEqualsWithDelta(200, $this->balance($this->pum)['ordered'], 0.001);
    }

    /**
     * Үлдэгдэл нь БЛОКООР бодогдоно, давхраар БИШ.
     *
     * Пум, хадаас нь тодорхой давхарт очдоггүй — агуулахад ирээд
     * тарагддаг. Давхраар бодох нь байхгүй нарийвчлал зохиож, тоог
     * худал болгоно.
     */
    public function test_the_balance_is_per_block_not_per_location(): void
    {
        $this->budget($this->pum, 500);

        $floor = $this->block->locations()->where('name', '1-р давхар')->firstOrFail();

        $this->submitOrder(
            [['materialId' => $this->pum->id, 'quantity' => 200]],
            ['locationId' => $floor->id],
        );

        // Байршил зааж захиалсан ч үлдэгдэл БЛОКИЙН хэмжээнд хасагдана.
        $this->assertEqualsWithDelta(300, $this->balance($this->pum)['remaining'], 0.001);
    }

    /** Хэтрэлтийг мөр бүр дээр тоогоор хэлнэ, хориглохгүй. */
    public function test_an_over_budget_line_is_reported_but_allowed(): void
    {
        $this->budget($this->pum, 100);

        $order = $this->actingAs($this->engineer)
            ->postJson("/api/v1/blocks/{$this->block->id}/material-orders", [
                'kind' => 'main',
                'lines' => [['materialId' => $this->pum->id, 'quantity' => 130]],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(1, $order['overLineCount']);
        $this->assertEqualsWithDelta(30, $order['lines'][0]['overBy'], 0.001);
    }

    /** Бетоныг энэ хуудсаар захиалж болохгүй. */
    public function test_concrete_cannot_be_ordered_here(): void
    {
        $concrete = $this->material('2.2.4', 'Давхрын бетон', 'м3', 'М250');

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/blocks/{$this->block->id}/material-orders", [
                'kind' => 'main',
                'lines' => [['materialId' => $concrete->id, 'quantity' => 10]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'бетон захиалгын хуудсаар'));
    }

    /** Бетон нь үлдэгдлийн жагсаалтад ОРОХГҮЙ. */
    public function test_the_balance_list_excludes_concrete(): void
    {
        $this->budget($this->pum, 500);
        $this->budget($this->material('2.2.4', 'Давхрын бетон', 'м3', 'М250'), 900);

        $rows = $this->actingAs($this->engineer)
            ->getJson("/api/v1/blocks/{$this->block->id}/material-balance")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('2.3.1', $rows[0]['code']);
    }

    // ───────────────────── олон мөр, гараар бичсэн ─────────────────────

    /** Олон мөртэй захиалга — бланк дээрх шиг. */
    public function test_an_order_holds_many_lines(): void
    {
        $utas = $this->material('2.5.1', 'Бэхэлгээний утас', 'боодол');
        $this->budget($this->pum, 500);
        $this->budget($utas, 215);

        $order = $this->actingAs($this->engineer)
            ->postJson("/api/v1/blocks/{$this->block->id}/material-orders", [
                'kind' => 'aux',
                'lines' => [
                    ['materialId' => $this->pum->id, 'quantity' => 100, 'stockQty' => 20],
                    ['materialId' => $utas->id, 'quantity' => 15],
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(2, $order['lineCount']);
        $this->assertEqualsWithDelta(20, $order['lines'][0]['stockQty'], 0.001);
        $this->assertSame('Угсралтын туслах материал', $order['kindLabel']);
    }

    /**
     * ТӨСӨВТ БАЙХГҮЙ зүйлийг гараар захиалж болно.
     *
     * Бланк дээр урьдчилан хэвлэгдсэн зарим мөр («Шатны тор») төсөвт
     * байдаггүй. Хориглох нь талбайн ажлыг зогсооно — захиалагдана,
     * зөвхөн үлдэгдэл харагдахгүй.
     */
    public function test_a_free_text_line_without_a_budget_is_allowed(): void
    {
        $order = $this->actingAs($this->engineer)
            ->postJson("/api/v1/blocks/{$this->block->id}/material-orders", [
                'kind' => 'main',
                'lines' => [['name' => 'Шатны тор', 'unit' => 'ш', 'quantity' => 12]],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('Шатны тор', $order['lines'][0]['name']);
        $this->assertNull($order['lines'][0]['budgetQty']);
        $this->assertSame(0, $order['overLineCount']);
    }

    /** Нэр, нэгжгүй, лавлахгүй мөрийг татгалзана. */
    public function test_a_line_without_a_material_or_a_name_is_rejected(): void
    {
        $this->actingAs($this->engineer)
            ->postJson("/api/v1/blocks/{$this->block->id}/material-orders", [
                'kind' => 'main',
                'lines' => [['quantity' => 5]],
            ])
            ->assertStatus(422);
    }

    /** Арматурын хуудсанд нийт жин бодогдоно. */
    public function test_rebar_lines_carry_a_total_weight(): void
    {
        $f12 = $this->material('2.1.3', 'ф12', 'ш');

        $order = $this->actingAs($this->engineer)
            ->postJson("/api/v1/blocks/{$this->block->id}/material-orders", [
                'kind' => 'rebar',
                'lines' => [[
                    'materialId' => $f12->id,
                    'quantity' => 2000,
                    'unitWeightKg' => 10.656,
                ]],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertTrue($order['isRebar']);
        $this->assertEqualsWithDelta(21312, $order['lines'][0]['totalWeightKg'], 0.01);
    }

    // ───────────────────────── урсгал, эрх ─────────────────────────

    /** Захиалсан хүн ӨӨРӨӨ баталгаажуулахгүй. */
    public function test_the_requester_cannot_confirm_their_own_order(): void
    {
        $id = $this->submitOrder([['materialId' => $this->pum->id, 'quantity' => 10]]);

        $this->engineer->update(['role' => 'general_engineer']);

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/material-orders/{$id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'өөрөө баталгаажуулах'));

        $this->actingAs($this->inspector)
            ->postJson("/api/v1/material-orders/{$id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
    }

    /** Баталгаажуулахдаа тоо өгөөгүй мөр захиалсанаараа батлагдана. */
    public function test_lines_without_an_explicit_approval_keep_their_quantity(): void
    {
        $this->budget($this->pum, 500);
        $id = $this->submitOrder([['materialId' => $this->pum->id, 'quantity' => 120]]);

        $this->actingAs($this->inspector)
            ->postJson("/api/v1/material-orders/{$id}/confirm")
            ->assertOk();

        $this->assertEqualsWithDelta(
            120,
            MaterialOrder::findOrFail($id)->lines->first()->approved_qty,
            0.001,
        );
    }

    /** Илгээсэн захиалгыг засах боломжгүй. */
    public function test_a_submitted_order_cannot_be_edited(): void
    {
        $id = $this->submitOrder([['materialId' => $this->pum->id, 'quantity' => 10]]);

        $this->actingAs($this->engineer)
            ->patchJson("/api/v1/material-orders/{$id}", [
                'lines' => [['materialId' => $this->pum->id, 'quantity' => 20]],
            ])
            ->assertStatus(422);
    }

    /** Цуцлах нь төсвийг суллана. */
    public function test_cancelling_releases_the_budget(): void
    {
        $this->budget($this->pum, 500);
        $id = $this->submitOrder([['materialId' => $this->pum->id, 'quantity' => 300]]);

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/material-orders/{$id}/cancel", ['reason' => 'Шаардлагагүй болсон.'])
            ->assertOk();

        $this->assertEqualsWithDelta(500, $this->balance($this->pum)['remaining'], 0.001);
    }

    /** Гүйцэтгэгч харж ч, бичиж ч чадахгүй. */
    public function test_a_contractor_has_no_access(): void
    {
        $contractor = User::create([
            'name' => 'Гүйцэтгэгч', 'email' => 'ctr@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'contractor',
        ]);

        $this->actingAs($contractor)
            ->getJson("/api/v1/projects/{$this->project->id}/material-orders")
            ->assertForbidden();
    }

    /** Захирал харна ч бичихгүй — юу ч батладаггүй. */
    public function test_the_director_can_read_but_not_write(): void
    {
        $director = User::create([
            'name' => 'Б.Батбаяр', 'email' => 'dir@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'director',
        ]);

        $this->actingAs($director)
            ->getJson("/api/v1/projects/{$this->project->id}/material-orders")
            ->assertOk();

        $this->actingAs($director)
            ->postJson("/api/v1/blocks/{$this->block->id}/material-orders", [
                'kind' => 'main',
                'lines' => [['materialId' => $this->pum->id, 'quantity' => 5]],
            ])
            ->assertForbidden();
    }

    /** Бланкны хэлбэрийн xlsx татагдана. */
    public function test_the_sheet_downloads(): void
    {
        $this->budget($this->pum, 500);
        $id = $this->submitOrder([['materialId' => $this->pum->id, 'quantity' => 100]]);

        $this->actingAs($this->engineer)
            ->get("/api/v1/material-orders/{$id}/sheet.xlsx")
            ->assertOk();
    }

    /** Дугаар дараалан өснө. */
    public function test_numbers_increment(): void
    {
        $a = $this->createOrder([['materialId' => $this->pum->id, 'quantity' => 1]]);
        $b = $this->createOrder([['materialId' => $this->pum->id, 'quantity' => 1]]);

        $year = now()->year;
        $this->assertSame("МЗ-{$year}-001", MaterialOrder::find($a)->number);
        $this->assertSame("МЗ-{$year}-002", MaterialOrder::find($b)->number);
    }

    // ───────────────────────── туслахууд ─────────────────────────

    private function material(string $code, string $name, string $unit, ?string $grade = null): Material
    {
        return Material::create([
            'project_id' => $this->project->id,
            'code' => $code,
            'name' => $name,
            'grade' => $grade,
            'unit' => $unit,
        ]);
    }

    private function budget(Material $material, float $qty): void
    {
        MaterialBudgetItem::create([
            'block_id' => $this->block->id,
            'material_id' => $material->id,
            'total_qty' => $qty,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function createOrder(array $lines, array $extra = []): string
    {
        return $this->actingAs($this->engineer)
            ->postJson("/api/v1/blocks/{$this->block->id}/material-orders", [
                'kind' => 'main',
                'lines' => $lines,
                ...$extra,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function submitOrder(array $lines, array $extra = []): string
    {
        $id = $this->createOrder($lines, $extra);

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/material-orders/{$id}/submit")
            ->assertOk();

        return $id;
    }

    /**
     * @return array{budget: float, ordered: float, remaining: float, overBy: float}
     */
    private function balance(Material $material): array
    {
        return app(\App\Services\MaterialOrderBalance::class)
            ->forMaterial($this->block, $material->id);
    }
}
