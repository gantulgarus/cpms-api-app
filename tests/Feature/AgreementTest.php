<?php

namespace Tests\Feature;

use App\Models\Agreement;
use App\Models\Block;
use App\Models\Company;
use App\Models\Contractor;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ажил гүйцэтгэх тохиролцооны хуудас.
 *
 * МӨНГӨ системд эндээс орно, тиймээс эндэх дүрмүүд нь төлбөрийн дүрмүүд:
 * нийлбэр зөрвөл акт худал болно, батлагдсаны дараа үнэ хөдөлвөл гарсан
 * акт бүр худал болно.
 */
class AgreementTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;

    private Contractor $contractor;

    private WorkTypeGroup $group;

    private WorkType $type;

    private User $manager;   // гэрээний мэргэжилтэн — бичнэ

    private User $chief;     // ерөнхий инженер — батална

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Төсөл', 'status' => 'active']);
        $this->block = $project->blocks()->create([
            'name' => 'А1 блок', 'building_no' => 'A1',
            'floors' => 2, 'units_per_floor' => 2, 'start_date' => '2026-10-01',
        ]);

        app(\App\Services\BlockLocationBuilder::class)->build($this->block, 2, 2);

        $this->group = WorkTypeGroup::create([
            'name' => 'Засал', 'sequence_number' => 1, 'build_order' => 7,
        ]);
        $this->type = WorkType::create([
            'name' => 'Шавардлага', 'work_type_group_id' => $this->group->id,
            'level' => 'floor', 'unit' => 'м2',
        ]);

        // Хоёр давхарт 600 + 600 = 1,200 м², төсвийн үнэ 12,000₮.
        foreach (Location::where('block_id', $this->block->id)->where('level', 'floor')->take(2)->get() as $floor) {
            WorkItem::create([
                'block_id' => $this->block->id,
                'location_id' => $floor->id,
                'work_type_id' => $this->type->id,
                'name' => $this->type->name, 'unit' => $this->type->unit,
                'planned_qty' => 600,
                'budget_unit_price' => 12000,
                'status' => 'not_started', 'review_state' => 'none',
            ]);
        }

        $this->contractor = Contractor::create([
            'name' => 'Энх Бүрэн Дүүрэн ХХК',
            'registration_no' => '1234567',
            'bank_name' => 'Хаан банк',
            'bank_account' => '5012345678',
            'phone' => '99119911',
            'status' => 'active',
        ]);

        $this->manager = User::factory()->create(['role' => 'project_manager']);
        $this->chief = User::factory()->create(['role' => 'general_engineer']);
    }

    /**
     * ГОЛ ТЕСТ: мөрүүд ТӨСВӨӨС татагдана, тоо хэмжээ нэгтгэгдэнэ.
     *
     * Блокт нэг ажлын төрөл 148 мөр байж болно (айл бүрд). Гэрээнд тэднийг
     * тус тусад нь бичих нь утгагүй — «шавардлага 1,200 м²» гэж нэг мөр.
     */
    public function test_lines_are_drafted_from_the_budget(): void
    {
        Sanctum::actingAs($this->manager);

        $response = $this->getJson("/api/v1/blocks/{$this->block->id}/agreement-draft")
            ->assertOk();

        $lines = $response->json('data');

        $this->assertCount(1, $lines, 'Ажлын төрлөөр нэгтгэгдэх ёстой.');

        /*
         * ТООГООР харьцуулна, `assertSame`-ээр биш.
         *
         * JSON-д 1200.0 нь 1200 болж цувдаг тул PHP тэрийг int гэж уншина.
         * `assertSame(1200.0, …)` нь төрлөөр унах бөгөөд тэр нь дүрмийн
         * алдаа БИШ — зөвхөн тестийн андуурал.
         */
        $this->assertEqualsWithDelta(1200, $lines[0]['quantity'], 0.001, '600 + 600 = 1,200 м².');
        $this->assertEqualsWithDelta(12000, $lines[0]['budgetUnitPrice'], 0.01);
        $this->assertSame('м2', $lines[0]['unit']);
        $this->assertSame(0, $response->json('meta.withoutPrice'));
    }

    /** Тохиролцоо бичигдээд дүн СЕРВЭРЭЭС бодогдоно. */
    public function test_an_agreement_computes_its_own_totals(): void
    {
        Sanctum::actingAs($this->manager);

        $data = $this->create(['unitPrice' => 13500])->assertCreated()->json('data');

        $this->assertSame('ТХ-'.now()->year.'-001', $data['number']);
        $this->assertSame('draft', $data['status']);

        // 1,200 × 13,500 = 16,200,000₮
        $this->assertEqualsWithDelta(16200000, $data['totalAmount'], 0.01);
        // Төсөв: 1,200 × 12,000 = 14,400,000₮
        $this->assertEqualsWithDelta(14400000, $data['budgetAmount'], 0.01);
        // Зөрүү: +12.5% — төсвөөс ДЭЭШ гэрээлсэн.
        $this->assertEqualsWithDelta(12.5, $data['variancePercent'], 0.01);
        // Барьцаа 5%: 810,000₮
        $this->assertEqualsWithDelta(810000, $data['retentionAmount'], 0.01);
    }

    /**
     * Бартер + бэлэн = 100%.
     *
     * Нийлбэр зөрвөл төлбөр тооцоо бүхэлдээ буруу болно: 60% бартер, 50%
     * бэлэн гэвэл 110% олгогдоно.
     */
    public function test_barter_and_cash_must_add_up_to_a_hundred(): void
    {
        Sanctum::actingAs($this->manager);

        $this->create(['barterPercent' => 60, 'cashPercent' => 50])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cashPercent');
    }

    /** Нэгж үнэ 0 бол хүлээж авахгүй — үнэгүй ажил гэсэн утга гарна. */
    public function test_a_zero_unit_price_is_refused(): void
    {
        Sanctum::actingAs($this->manager);

        $this->create(['unitPrice' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines.0.unitPrice');
    }

    /**
     * Профайл ДУТУУ гүйцэтгэгчтэй тохиролцоо бичигдэхгүй.
     *
     * РД, данс, банкгүйгээр хуудас хэвлэгдэхгүй, төлбөр гарахгүй.
     */
    public function test_an_incomplete_contractor_profile_blocks_the_agreement(): void
    {
        $this->contractor->update(['registration_no' => null, 'bank_account' => null]);

        Sanctum::actingAs($this->manager);

        $response = $this->create()->assertStatus(422);

        $this->assertStringContainsString(
            'Регистрийн дугаар',
            $response->json('errors.contractorId.0'),
        );
    }

    /** Гэрээ цуцлагдсан гүйцэтгэгчтэй тохиролцоо бичигдэхгүй. */
    public function test_a_terminated_contractor_blocks_the_agreement(): void
    {
        $this->contractor->update(['status' => 'terminated']);

        Sanctum::actingAs($this->manager);

        $this->create()->assertStatus(422)->assertJsonValidationErrors('contractorId');
    }

    /**
     * ХАМГИЙН ЧУХАЛ ДҮРЭМ: батлагдсаны дараа мөр ТҮГЖИГДЭНЭ.
     *
     * Акт нь тохиролцооны үнэ дээр тогтдог. Үнэ хойшоо хөдөлвөл гарсан акт
     * бүр худал болж, төлбөрийн баримт хүчингүй болно.
     */
    public function test_an_approved_agreement_cannot_be_edited(): void
    {
        Sanctum::actingAs($this->manager);
        $id = $this->create()->assertCreated()->json('data.id');

        Sanctum::actingAs($this->chief);
        $this->postJson("/api/v1/agreements/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.isEditable', false);

        Sanctum::actingAs($this->manager);
        $this->patchJson("/api/v1/agreements/{$id}", $this->payload(['unitPrice' => 99000]))
            ->assertStatus(422);

        $this->assertSame(
            '13000.00',
            (string) Agreement::findOrFail($id)->lines->first()->unit_price,
        );
    }

    /**
     * ТӨСЛИЙН МЕНЕЖЕР өөрийн бичсэн гэрээг батлахгүй.
     *
     * Эс бөгөөс хяналт утгагүй: нэг хүн үнэ тавиад өөрөө баталгаажуулна.
     */
    public function test_the_author_cannot_approve_their_own_agreement(): void
    {
        Sanctum::actingAs($this->manager);
        $id = $this->create()->assertCreated()->json('data.id');

        $this->postJson("/api/v1/agreements/{$id}/approve")->assertStatus(403);

        $this->assertSame('draft', Agreement::findOrFail($id)->status);
    }

    /** Ажлын мөргүй тохиролцоо батлагдахгүй. */
    public function test_an_agreement_without_lines_cannot_be_approved(): void
    {
        Sanctum::actingAs($this->manager);
        $id = $this->create()->assertCreated()->json('data.id');

        Agreement::findOrFail($id)->lines()->delete();

        Sanctum::actingAs($this->chief);
        $this->postJson("/api/v1/agreements/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');
    }

    /** Гүйцэтгэгчийг солих боломжгүй — шинэ тохиролцоо бичнэ. */
    public function test_the_contractor_cannot_be_swapped(): void
    {
        Sanctum::actingAs($this->manager);
        $id = $this->create()->assertCreated()->json('data.id');

        $other = Contractor::create(['name' => 'Өөр ХХК', 'registration_no' => '7654321']);

        $this->patchJson("/api/v1/agreements/{$id}", [
            ...$this->payload(),
            'contractorId' => $other->id,
        ])->assertStatus(422)->assertJsonValidationErrors('contractorId');
    }

    /** Дугаар нь он тус бүрд 1-ээс эхэлж, давхардахгүй. */
    public function test_numbers_are_sequential(): void
    {
        Sanctum::actingAs($this->manager);

        $first = $this->create()->json('data.number');

        $other = Contractor::create([
            'name' => 'Хоёрдугаар ХХК', 'registration_no' => '7654321',
            'bank_name' => 'Голомт', 'bank_account' => '1112223', 'phone' => '88008800',
        ]);

        $second = $this->postJson("/api/v1/blocks/{$this->block->id}/agreements", [
            ...$this->payload(),
            'contractorId' => $other->id,
        ])->assertCreated()->json('data.number');

        $this->assertSame('ТХ-'.now()->year.'-001', $first);
        $this->assertSame('ТХ-'.now()->year.'-002', $second);
    }

    /** Талбайн инженер тохиролцоо харахгүй — мөнгөний баримт. */
    public function test_a_site_engineer_cannot_read_agreements(): void
    {
        Sanctum::actingAs($this->manager);
        $id = $this->create()->json('data.id');

        Sanctum::actingAs(User::factory()->create(['role' => 'site_engineer']));

        $this->getJson("/api/v1/agreements/{$id}")->assertStatus(403);
        $this->getJson('/api/v1/projects/'.Project::firstOrFail()->id.'/agreements')
            ->assertStatus(403);
    }

    /**
     * Бланкны хэлбэрээр татагдана.
     *
     * Файл нь ЖИНХЭНЭ xlsx байх ёстой: zip бүтэц, уншигдах мөрүүд. Урьд нь
     * актын экспорт дээр ийм тест нэг зөрүүг барьсан — бичигч, уншигч хоёр
     * ижил форматыг ойлгож байгааг дайвраар шалгана.
     */
    public function test_the_sheet_downloads_as_a_real_spreadsheet(): void
    {
        Sanctum::actingAs($this->manager);
        $id = $this->create()->assertCreated()->json('data.id');

        $response = $this->get("/api/v1/agreements/{$id}/sheet.xlsx")->assertOk();

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type'),
        );

        $tmp = tempnam(sys_get_temp_dir(), 'sheet').'.xlsx';
        file_put_contents($tmp, $response->streamedContent());

        $reader = new \App\Services\XlsxReader($tmp);
        $rows = $reader->rows('Тохиролцоо', 6);
        $text = implode(' | ', array_map(
            fn ($r) => implode(' ', array_map('strval', $r)),
            $rows,
        ));

        // Бланкны гол хэсгүүд бүгд байх ёстой.
        foreach ([
            'АЖИЛ ГҮЙЦЭТГЭХ ТОХИРОЛЦООНЫ ХУУДАС',
            'Регистрийн дугаар',
            '1234567',
            'Ажлын гүйцэтгэл',
            'Шавардлага',
            'БАТАЛГААЖУУЛСАН',
            'драфт',
        ] as $needle) {
            $this->assertStringContainsString($needle, $text, "«{$needle}» хуудсанд алга.");
        }
    }

    /** Талбайн инженер хуудсыг татахгүй — мөнгөний баримт. */
    public function test_a_site_engineer_cannot_download_the_sheet(): void
    {
        Sanctum::actingAs($this->manager);
        $id = $this->create()->json('data.id');

        Sanctum::actingAs(User::factory()->create(['role' => 'site_engineer']));

        $this->get("/api/v1/agreements/{$id}/sheet.xlsx")->assertStatus(403);
    }

    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $line = [
            'workTypeId' => $this->type->id,
            'label' => 'Шавардлага',
            'unit' => 'м2',
            'quantity' => 1200,
            'unitPrice' => $overrides['unitPrice'] ?? 13000,
            'budgetUnitPrice' => 12000,
        ];

        unset($overrides['unitPrice']);

        return [
            'contractorId' => $this->contractor->id,
            'scope' => 'Дотор засал',
            'startDate' => '2026-11-01',
            'endDate' => '2027-03-01',
            'retentionPercent' => 5,
            'barterPercent' => 0,
            'cashPercent' => 100,
            'workDaysPerWeek' => 6,
            'restDay' => 'Ням',
            'headcount' => 24,
            'lines' => [$line],
            ...$overrides,
        ];
    }

    private function create(array $overrides = [])
    {
        return $this->postJson(
            "/api/v1/blocks/{$this->block->id}/agreements",
            $this->payload($overrides),
        );
    }
}
