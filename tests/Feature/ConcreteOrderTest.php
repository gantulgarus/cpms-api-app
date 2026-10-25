<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\ConcreteOrder;
use App\Models\Location;
use App\Models\Material;
use App\Models\MaterialBudgetItem;
use App\Models\MaterialBudgetQuantity;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Бетон зуурмагийн захиалга.
 *
 * ГОЛ ЗҮЙЛ НЬ МАЯГТ БИШ, ҮЛДЭГДЭЛ. Цаасан бланк аль хэдийн байгаа —
 * систем нэмж өгч байгаа цорын гагц зүйл нь «энэ давхарт төсвөөр хэд
 * байсан, хэд захиалсан, хэд үлдсэн» гэдэг. Тиймээс эндэх тестүүдийн
 * дийлэнх нь тэр тооцоог шалгана.
 */
class ConcreteOrderTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;

    private Project $project;

    private Material $concrete;

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
            'units_per_floor' => 3,
            'start_date' => '2026-10-01',
        ]);

        app(\App\Services\BlockLocationBuilder::class)->build($this->block, 2, 3);

        $this->concrete = Material::create([
            'project_id' => $this->project->id,
            'code' => '2.2.4',
            'name' => 'Давхрын бетон',
            'grade' => 'М250',
            'unit' => 'м3',
        ]);

        $this->engineer = User::create([
            'name' => 'Талбайн инженер',
            'email' => 'site@inel.mn',
            'password' => bcrypt('secret123'),
            'role' => 'site_engineer',
        ]);

        $this->inspector = User::create([
            'name' => 'Д.Хулан',
            'email' => 'inspector@inel.mn',
            'password' => bcrypt('secret123'),
            'role' => 'inspector',
        ]);
    }

    // ───────────────────────── үлдэгдэл ─────────────────────────

    /**
     * Үлдэгдэл нь төсвөөс захиалгыг хасна.
     *
     * Энэ бол системийн гол амлалт: цаасан хуудас үүнийг хэлж чаддаггүй.
     */
    public function test_the_balance_subtracts_orders_from_the_budget(): void
    {
        $this->budget('1-р давхар', 240);

        $before = $this->balance('1-р давхар');
        $this->assertEqualsWithDelta(240, $before['budget'], 0.001);
        $this->assertEqualsWithDelta(240, $before['remaining'], 0.001);

        $this->submitOrder('1-р давхар', 180);

        $after = $this->balance('1-р давхар');
        $this->assertEqualsWithDelta(180, $after['ordered'], 0.001);
        $this->assertEqualsWithDelta(60, $after['remaining'], 0.001);
    }

    /**
     * ДРАФТ нь төсөв иддэггүй.
     *
     * Бичиж байгаа захиалга нь амлалт биш. Эс тэгвээс хагас бөглөсөн
     * маягт давхрын төсвийг түгжинэ.
     */
    public function test_a_draft_does_not_consume_the_budget(): void
    {
        $this->budget('1-р давхар', 240);
        $this->createOrder('1-р давхар', 180);   // драфт, илгээгээгүй

        $this->assertEqualsWithDelta(0, $this->balance('1-р давхар')['ordered'], 0.001);
        $this->assertEqualsWithDelta(240, $this->balance('1-р давхар')['remaining'], 0.001);
    }

    /** Цуцлагдсан захиалга төсвийг суллана. */
    public function test_cancelling_releases_the_budget(): void
    {
        $this->budget('1-р давхар', 240);
        $id = $this->submitOrder('1-р давхар', 180);

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/concrete-orders/{$id}/cancel", [
                'reason' => 'Цутгалт хойшлогдсон, дахин захиална.',
            ])
            ->assertOk();

        $this->assertEqualsWithDelta(240, $this->balance('1-р давхар')['remaining'], 0.001);
    }

    /**
     * Нийлүүлэгдсэн бол БОДИТ хэмжээгээр иднэ.
     *
     * Машин 7 м³-аар ирдэг тул 18 захиалбал 21 ирнэ. Захиалсан тоогоор
     * бодвол үлдэгдэл аажмаар худал болно.
     */
    public function test_delivery_consumes_the_actual_quantity_not_the_ordered_one(): void
    {
        $this->budget('1-р давхар', 240);
        $id = $this->submitOrder('1-р давхар', 18);

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/concrete-orders/{$id}/deliver", ['deliveredQty' => 21])
            ->assertOk();

        $balance = $this->balance('1-р давхар');
        $this->assertEqualsWithDelta(21, $balance['ordered'], 0.001);
        $this->assertEqualsWithDelta(219, $balance['remaining'], 0.001);
    }

    /**
     * Үлдэгдэл нь ДАВХАР БҮРД тусдаа.
     *
     * Давхруудыг нийлүүлбэл 5-р давхрын хэтрэлт 6-р давхрын нөөцөөр
     * нуугдана — яг тэр нь хяналтыг утгагүй болгоно.
     */
    public function test_the_balance_is_per_location_not_pooled(): void
    {
        $this->budget('1-р давхар', 240);
        $this->budget('2-р давхар', 240);

        $this->submitOrder('1-р давхар', 240);

        $this->assertEqualsWithDelta(0, $this->balance('1-р давхар')['remaining'], 0.001);
        $this->assertEqualsWithDelta(240, $this->balance('2-р давхар')['remaining'], 0.001);
    }

    /**
     * Хэтрэлтийг ТООГООР хэлнэ, зөвхөн тугаар биш.
     *
     * «Хэтэрсэн» гэдэг мэдээлэл хангалтгүй: хэдээр хэтэрснийг мэдэхгүй
     * бол зөвшөөрөх үү, татгалзах уу гэдгийг шийдэх аргагүй.
     */
    public function test_an_over_budget_order_reports_how_much_it_exceeds_by(): void
    {
        $this->budget('1-р давхар', 100);
        $id = $this->submitOrder('1-р давхар', 130);

        $order = $this->actingAs($this->engineer)
            ->getJson("/api/v1/concrete-orders/{$id}")
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(30, $order['overBy'], 0.001);
        $this->assertEqualsWithDelta(-30, $order['remainingQty'], 0.001);
    }

    /**
     * Хэтэрсэн ч ХОРИГЛОХГҮЙ.
     *
     * Бодит амьдралд нэмэлт ажил гардаг. Системийн үүрэг нь зогсоох
     * биш, ХАРУУЛАХ — шийдвэрийг гарын үсэг зурдаг хүн гаргана.
     */
    public function test_an_over_budget_order_is_still_allowed(): void
    {
        $this->budget('1-р давхар', 100);

        $this->assertNotNull($this->submitOrder('1-р давхар', 500));
    }

    /** Төсөвгүй байршилд захиалга хийвэл бүхэлдээ хэтэрсэн гэж харагдана. */
    public function test_an_order_with_no_budget_at_all_shows_as_fully_over(): void
    {
        $id = $this->submitOrder('1-р давхар', 50);

        $order = $this->actingAs($this->engineer)
            ->getJson("/api/v1/concrete-orders/{$id}")->json('data');

        $this->assertEqualsWithDelta(0, $order['budgetQty'], 0.001);
        $this->assertEqualsWithDelta(50, $order['overBy'], 0.001);
    }

    // ───────────────────────── урсгал, эрх ─────────────────────────

    /**
     * Захиалсан хүн ӨӨРӨӨ баталгаажуулахгүй.
     *
     * Бланк дээр хоёр өөр инженерийн гарын үсэг байдаг нь санамсаргүй
     * биш: бетон бол буцаагдахгүй зарлага.
     */
    public function test_the_requester_cannot_confirm_their_own_order(): void
    {
        $this->budget('1-р давхар', 240);
        $id = $this->submitOrder('1-р давхар', 100);

        // Захиалагчид баталгаажуулах эрх олгосон ч өөрийнхийг нь болихгүй.
        $this->engineer->update(['role' => 'general_engineer']);

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/concrete-orders/{$id}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'өөрөө баталгаажуулах'));

        $this->actingAs($this->inspector)
            ->postJson("/api/v1/concrete-orders/{$id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.confirmedBy', 'Д.Хулан');
    }

    /** Талбайн инженер баталгаажуулж чадахгүй. */
    public function test_a_site_engineer_cannot_confirm(): void
    {
        $id = $this->submitOrder('1-р давхар', 100);

        $other = User::create([
            'name' => 'Өөр инженер',
            'email' => 'site2@inel.mn',
            'password' => bcrypt('secret123'),
            'role' => 'site_engineer',
        ]);

        $this->actingAs($other)
            ->postJson("/api/v1/concrete-orders/{$id}/confirm")
            ->assertForbidden();
    }

    /** Гүйцэтгэгч бетон захиалж ч, харж ч чадахгүй. */
    public function test_a_contractor_has_no_access(): void
    {
        $contractor = User::create([
            'name' => 'Гүйцэтгэгч',
            'email' => 'ctr@inel.mn',
            'password' => bcrypt('secret123'),
            'role' => 'contractor',
        ]);

        $this->actingAs($contractor)
            ->getJson("/api/v1/projects/{$this->project->id}/concrete-orders")
            ->assertForbidden();

        $this->actingAs($contractor)
            ->postJson("/api/v1/blocks/{$this->block->id}/concrete-orders", $this->payload('1-р давхар', 10))
            ->assertForbidden();
    }

    /** Илгээсэн захиалгыг засах боломжгүй. */
    public function test_a_submitted_order_cannot_be_edited(): void
    {
        $id = $this->submitOrder('1-р давхар', 100);

        $this->actingAs($this->engineer)
            ->patchJson("/api/v1/concrete-orders/{$id}", $this->payload('1-р давхар', 150))
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'засах боломжгүй'));
    }

    /** Цуцлахад шалтгаан заавал. */
    public function test_cancelling_requires_a_reason(): void
    {
        $id = $this->submitOrder('1-р давхар', 100);

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/concrete-orders/{$id}/cancel", [])
            ->assertStatus(422);
    }

    /** Өнгөрсөн огноонд захиалах боломжгүй. */
    public function test_a_pour_date_in_the_past_is_rejected(): void
    {
        $this->actingAs($this->engineer)
            ->postJson(
                "/api/v1/blocks/{$this->block->id}/concrete-orders",
                [...$this->payload('1-р давхар', 50), 'pourDate' => now()->subDay()->toDateString()],
            )
            ->assertStatus(422);
    }

    /**
     * 3 хоногийн хугацаанаас хожуу захиалгыг ТЭМДЭГЛЭНЭ, хориглохгүй.
     *
     * Бланк дээр «авахаас 3 хоногийн өмнө» гэж бичигдсэн. Яаралтай
     * цутгалт бодитоор гардаг тул зогсоохгүй — харин үйлдвэр ба
     * баталгаажуулагч хоёр мэдэх ёстой.
     */
    public function test_a_rush_order_is_flagged_but_accepted(): void
    {
        $rush = $this->actingAs($this->engineer)
            ->postJson(
                "/api/v1/blocks/{$this->block->id}/concrete-orders",
                [...$this->payload('1-р давхар', 50), 'pourDate' => now()->addDay()->toDateString()],
            )
            ->assertCreated()
            ->json('data');

        $this->assertTrue($rush['isRush']);

        $planned = $this->actingAs($this->engineer)
            ->postJson(
                "/api/v1/blocks/{$this->block->id}/concrete-orders",
                [...$this->payload('1-р давхар', 50), 'pourDate' => now()->addDays(10)->toDateString()],
            )->json('data');

        $this->assertFalse($planned['isRush']);
    }

    /** Өөр блокийн байршилд захиалга бичих боломжгүй. */
    public function test_a_location_from_another_block_is_rejected(): void
    {
        $other = $this->project->blocks()->create([
            'name' => 'Б1 блок',
            'building_no' => 'B1',
            'floors' => 1,
            'units_per_floor' => 1,
            'start_date' => '2026-10-01',
        ]);
        app(\App\Services\BlockLocationBuilder::class)->build($other, 1, 1);

        $foreign = Location::where('block_id', $other->id)->firstOrFail();

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/blocks/{$this->block->id}/concrete-orders", [
                ...$this->payload('1-р давхар', 50),
                'locationId' => $foreign->id,
            ])
            ->assertStatus(422);
    }

    /** Бетон биш материалаар бетон захиалж болохгүй. */
    public function test_a_non_concrete_material_is_rejected(): void
    {
        $rebar = Material::create([
            'project_id' => $this->project->id,
            'code' => '2.1.1',
            'name' => 'Ф8',
            'grade' => 'А1',
            'unit' => 'тн',
        ]);

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/blocks/{$this->block->id}/concrete-orders", [
                ...$this->payload('1-р давхар', 50),
                'materialId' => $rebar->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'бетон биш'));
    }

    /** Дугаар нь дараалан өснө. */
    public function test_numbers_increment(): void
    {
        $a = $this->createOrder('1-р давхар', 10);
        $b = $this->createOrder('1-р давхар', 10);

        $year = now()->year;
        $this->assertSame("БЗ-{$year}-001", ConcreteOrder::find($a)->number);
        $this->assertSame("БЗ-{$year}-002", ConcreteOrder::find($b)->number);
    }

    // ───────────────────────── блокийн үлдэгдэл ─────────────────────────

    /** Блокийн үлдэгдлийн хүснэгт байршил бүрээр гарна. */
    public function test_the_block_balance_lists_every_budgeted_location(): void
    {
        $this->budget('1-р давхар', 240);
        $this->budget('2-р давхар', 300);
        $this->submitOrder('2-р давхар', 100);

        $rows = $this->actingAs($this->engineer)
            ->getJson("/api/v1/blocks/{$this->block->id}/concrete-balance")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);

        $second = collect($rows)->firstWhere('locationName', '2-р давхар');
        $this->assertEqualsWithDelta(300, $second['budget'], 0.001);
        $this->assertEqualsWithDelta(100, $second['ordered'], 0.001);
        $this->assertEqualsWithDelta(200, $second['remaining'], 0.001);
        $this->assertSame('М250', $second['grade']);
    }

    /** Бетон биш материал үлдэгдлийн хүснэгтэд ОРОХГҮЙ. */
    public function test_the_block_balance_ignores_non_concrete_materials(): void
    {
        $this->budget('1-р давхар', 240);

        $rebar = Material::create([
            'project_id' => $this->project->id,
            'code' => '2.1.1', 'name' => 'Ф8', 'grade' => 'А1', 'unit' => 'тн',
        ]);
        $item = MaterialBudgetItem::create([
            'block_id' => $this->block->id,
            'material_id' => $rebar->id,
            'total_qty' => 12,
        ]);
        MaterialBudgetQuantity::create([
            'material_budget_item_id' => $item->id,
            'location_id' => $this->location('1-р давхар')->id,
            'quantity' => 12,
        ]);

        $rows = $this->actingAs($this->engineer)
            ->getJson("/api/v1/blocks/{$this->block->id}/concrete-balance")->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Давхрын бетон (М250)', $rows[0]['materialName']);
    }

    // ───────────────────────── хуудас ─────────────────────────

    /**
     * Бланкны хэлбэрийн xlsx татагдана.
     *
     * ФАЙЛЫН НЭР ХОЁР ХЭЛБЭРТЭЙ. Дугаар нь кирилл («БЗ-2026-001») тул
     * Symfony нь `Content-Disposition`-д хоёр утга бичнэ: RFC 2616-ийн
     * `filename=` талбарт ЛАТИНЧИЛСАН нөөц нэр («BZ-…»), RFC 5987-ийн
     * `filename*=UTF-8''…` талбарт ЖИНХЭНЭ нэр. Орчин үеийн хөтөч
     * сүүлийнхийг нь хэрэглэдэг тул хэрэглэгч кирилл нэртэй файл татна.
     *
     * `assertDownload()` нь зөвхөн латинчилсан нөөц нэрийг хардаг тул
     * хоёуланг нь шалгана — эс бөгөөс жинхэнэ нэр эвдэрсэн ч тест
     * ногоон хэвээр байна.
     */
    public function test_the_sheet_downloads_with_both_filename_forms(): void
    {
        $this->budget('1-р давхар', 240);
        $id = $this->submitOrder('1-р давхар', 100);
        $number = ConcreteOrder::find($id)->number;

        $response = $this->actingAs($this->engineer)
            ->get("/api/v1/concrete-orders/{$id}/sheet.xlsx")
            ->assertOk()
            // Латинчилсан нөөц нэр — «БЗ» → «BZ».
            ->assertDownload('beton-zahialga-'.$this->asciiNumber($number).'.xlsx');

        /*
         * ХОЁР ТАЛЫГ НЬ жижиг үсэг болгоно.
         *
         * `rawurlencode` нь «%D0%91» гэж ТОМ үсгээр, Symfony нь «%d0%91»
         * гэж жижгээр бичдэг. Хоёулаа ижил тэмдэгт — зөвхөн бичиглэл нь
         * өөр тул харьцуулалт регистрээс хамаарах ёсгүй.
         */
        $this->assertStringContainsString(
            mb_strtolower("filename*=utf-8''".rawurlencode("beton-zahialga-{$number}.xlsx")),
            mb_strtolower($response->headers->get('content-disposition') ?? ''),
            'Жинхэнэ кирилл нэр `filename*`-д байх ёстой.',
        );
    }

    /** «БЗ-2026-001» → «BZ-2026-001» — Symfony-гийн латинчлалтай ижил. */
    private function asciiNumber(string $number): string
    {
        return strtr($number, ['Б' => 'B', 'З' => 'Z']);
    }

    // ───────────────────────── туслахууд ─────────────────────────

    private function location(string $name): Location
    {
        return Location::where('block_id', $this->block->id)->where('name', $name)->firstOrFail();
    }

    /** Тухайн байршилд бетоны төсөв тавина. */
    private function budget(string $locationName, float $qty): void
    {
        $item = MaterialBudgetItem::firstOrCreate(
            ['block_id' => $this->block->id, 'material_id' => $this->concrete->id],
            ['total_qty' => 0],
        );

        MaterialBudgetQuantity::create([
            'material_budget_item_id' => $item->id,
            'location_id' => $this->location($locationName)->id,
            'quantity' => $qty,
        ]);

        $item->increment('total_qty', $qty);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $locationName, float $qty): array
    {
        return [
            'locationId' => $this->location($locationName)->id,
            'materialId' => $this->concrete->id,
            'pourDate' => now()->addDays(5)->toDateString(),
            'pourHalf' => 'am',
            'pourTime' => '08:30',
            'structure' => 'Хучилт',
            'concreteClass' => 'B25',
            'slumpCm' => 12,
            'quantity' => $qty,
            'needsPump' => true,
        ];
    }

    private function createOrder(string $locationName, float $qty): string
    {
        return $this->actingAs($this->engineer)
            ->postJson(
                "/api/v1/blocks/{$this->block->id}/concrete-orders",
                $this->payload($locationName, $qty),
            )
            ->assertCreated()
            ->json('data.id');
    }

    private function submitOrder(string $locationName, float $qty): string
    {
        $id = $this->createOrder($locationName, $qty);

        $this->actingAs($this->engineer)
            ->postJson("/api/v1/concrete-orders/{$id}/submit")
            ->assertOk();

        return $id;
    }

    /**
     * @return array{budget: float, ordered: float, remaining: float, overBy: float}
     */
    private function balance(string $locationName): array
    {
        return app(\App\Services\ConcreteBudgetBalance::class)->forLocation(
            $this->location($locationName)->id,
            $this->concrete->id,
        );
    }
}
