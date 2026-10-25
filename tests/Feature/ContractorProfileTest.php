<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Гүйцэтгэгчийн гэрээний профайл.
 *
 * «Ажил гүйцэтгэх тохиролцооны хуудас» нь РД, дансны дугаар, банкийг
 * шаарддаг. Эдгээргүйгээр тохиролцоо бичигдэхгүй, акт хэвлэгдэхгүй,
 * төлбөр гарахгүй.
 */
class ContractorProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create(['role' => 'project_manager']);
    }

    /**
     * ЗАСАХ зам байх ёстой.
     *
     * Урьд нь `update` эндпойнт БАЙГААГҮЙ: гүйцэтгэгчийг үүсгэж болдог ч
     * РД, данс хожим мэдэгдвэл шинэ бүртгэл үүсгэхээс өөр арга байхгүй.
     * Тэгвэл акт, барьцаа хоёр хуваагдаж, нийт тооцоо бүрэн болохгүй.
     */
    public function test_the_profile_can_be_completed_later(): void
    {
        Sanctum::actingAs($this->manager);

        $id = $this->postJson('/api/v1/contractors', [
            'name' => 'Бетон И Дезайн ХХК',
            'tradeSpecialty' => 'Цутгамал төмөр бетон',
        ])->assertCreated()->json('data.id');

        // РД, данс нь ХОЖИМ ирнэ — хуваарийн файлд байхгүй байсан.
        $this->patchJson("/api/v1/contractors/{$id}", [
            'registrationNo' => '1234567',
            'bankName' => 'Хаан банк',
            'bankAccount' => '5012345678',
            'category' => 'structure',
        ])
            ->assertOk()
            ->assertJsonPath('data.registrationNo', '1234567')
            ->assertJsonPath('data.categoryLabel', 'Угсралт, бетон')
            ->assertJsonPath('data.missingContractFields', ['Холбоо барих утас']);
    }

    /**
     * РД нь ДАВХАРДАХГҮЙ.
     *
     * Нэрээр давхардлыг барих нь хүрэлцэхгүй: «Бетон И дезайн» ба
     * «Бетон И Дезайн» хоёр өөр мөр болдог — бодит хуваарийн файлд яг тэр
     * байв. РД нь цорын гагц найдвартай таних тэмдэг.
     */
    public function test_a_duplicate_registration_number_is_refused(): void
    {
        Sanctum::actingAs($this->manager);

        Contractor::create(['name' => 'Бетон И Дезайн ХХК', 'registration_no' => '1234567']);

        $this->postJson('/api/v1/contractors', [
            'name' => 'Бетон И дезайн ХХК',
            'registrationNo' => '1234567',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('registrationNo');
    }

    /** РД нь 7 орон. */
    public function test_the_registration_number_must_be_seven_digits(): void
    {
        Sanctum::actingAs($this->manager);

        foreach (['123456', '12345678', 'АБ12345'] as $bad) {
            $this->postJson('/api/v1/contractors', [
                'name' => 'Тест ХХК '.$bad,
                'registrationNo' => $bad,
            ])->assertStatus(422)->assertJsonValidationErrors('registrationNo');
        }
    }

    /** Өөрийн РД-г хэвээр үлдээж засахад зөрчил гарахгүй. */
    public function test_keeping_the_same_registration_number_is_allowed(): void
    {
        Sanctum::actingAs($this->manager);

        $one = Contractor::create(['name' => 'Тест ХХК', 'registration_no' => '1234567']);

        $this->patchJson("/api/v1/contractors/{$one->id}", [
            'registrationNo' => '1234567',
            'phone' => '99119911',
        ])->assertOk();
    }

    /**
     * Нэг талбар засахад бусад нь ЦЭВЭРЛЭГДЭХГҮЙ.
     *
     * Бүх талбарыг `?? null`-аар бичсэн PATCH нь дэлгэц утас засахад РД,
     * данс, банкийг хоосон болгодог. Яг тэр алдаа хэрэглэгчийн хуудсанд
     * нэг удаа гарсан.
     */
    public function test_a_partial_update_keeps_untouched_fields(): void
    {
        Sanctum::actingAs($this->manager);

        $one = Contractor::create([
            'name' => 'Тест ХХК',
            'registration_no' => '1234567',
            'bank_name' => 'Хаан банк',
            'bank_account' => '5012345678',
            'trade_specialty' => 'Угсралт',
        ]);

        $this->patchJson("/api/v1/contractors/{$one->id}", ['phone' => '99119911'])->assertOk();

        $one->refresh();

        $this->assertSame('1234567', $one->registration_no);
        $this->assertSame('Хаан банк', $one->bank_name);
        $this->assertSame('5012345678', $one->bank_account);
        $this->assertSame('Угсралт', $one->trade_specialty);
        $this->assertSame('99119911', $one->phone);
    }

    /** Дансны мэдээлэл нь ЗӨВХӨН удирдах эрхтэйд харагдана. */
    public function test_bank_details_are_hidden_from_other_roles(): void
    {
        $one = Contractor::create([
            'name' => 'Тест ХХК',
            'bank_name' => 'Хаан банк',
            'bank_account' => '5012345678',
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'site_engineer']));

        $row = $this->getJson("/api/v1/contractors/{$one->id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('bankAccount', $row);
        $this->assertArrayNotHasKey('bankName', $row);
    }

    /** Талбайн инженер профайл засахгүй — гэрээний мэдээлэл. */
    public function test_a_site_engineer_cannot_edit_the_profile(): void
    {
        $one = Contractor::create(['name' => 'Тест ХХК']);

        Sanctum::actingAs(User::factory()->create(['role' => 'site_engineer']));

        $this->patchJson("/api/v1/contractors/{$one->id}", ['phone' => '99119911'])
            ->assertStatus(403);
    }

    /**
     * ГЭРЭЭ ЦУЦЛАГДСАН гүйцэтгэгчид шинэ ажил оноогдохгүй.
     *
     * Оноовол акт нь түүн рүү явж, төлбөр гарах эрсдэлтэй. Устгахгүй,
     * хаана: хуучин ажлын түүх, барьцаа, торгууль үлдэх ёстой.
     */
    public function test_a_terminated_contractor_cannot_be_assigned_new_work(): void
    {
        $company = \App\Models\Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Төсөл', 'status' => 'active']);
        $block = $project->blocks()->create([
            'name' => 'А1 блок', 'building_no' => 'A1',
            'floors' => 1, 'units_per_floor' => 1, 'start_date' => '2026-10-01',
        ]);
        app(\App\Services\BlockLocationBuilder::class)->build($block, 1, 1);

        $group = \App\Models\WorkTypeGroup::create([
            'name' => 'Угсралт', 'sequence_number' => 1, 'build_order' => 1,
        ]);
        $type = \App\Models\WorkType::create([
            'name' => 'Угсралтын ажил', 'work_type_group_id' => $group->id,
            'level' => 'floor', 'unit' => 'м3',
        ]);
        $item = \App\Models\WorkItem::create([
            'block_id' => $block->id,
            'location_id' => \App\Models\Location::where('block_id', $block->id)
                ->where('level', 'floor')->firstOrFail()->id,
            'work_type_id' => $type->id,
            'name' => $type->name, 'unit' => $type->unit,
            'planned_qty' => 100, 'status' => 'not_started', 'review_state' => 'none',
        ]);

        $gone = Contractor::create(['name' => 'Цуцлагдсан ХХК', 'status' => 'terminated']);

        Sanctum::actingAs($this->manager);

        $response = $this->patchJson("/api/v1/work-items/{$item->id}/contractor", [
            'contractorId' => $gone->id,
        ])->assertStatus(422);

        $this->assertStringContainsString('Гэрээ цуцлагдсан', $response->json('message'));
        $this->assertNull($item->refresh()->contractor_id);

        // Бөөнөөр оноох зам ч ижил хоригтой — хоёр зам, нэг дүрэм.
        $this->postJson("/api/v1/blocks/{$block->id}/work-items/assign", [
            'workTypeGroupId' => $group->id,
            'contractorId' => $gone->id,
        ])->assertStatus(422);
    }

    /**
     * Дутуу талбаруудыг УРЬДЧИЛЖ хэлнэ.
     *
     * Гэрээний мэргэжилтэн хуудас хэвлэх гэж байж «РД байхгүй» гэдгийг
     * мэдэх нь оройтсон.
     */
    public function test_missing_contract_fields_are_reported(): void
    {
        Sanctum::actingAs($this->manager);

        $id = $this->postJson('/api/v1/contractors', ['name' => 'Хоосон ХХК'])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            ['Регистрийн дугаар', 'Дансны дугаар', 'Банк', 'Холбоо барих утас'],
            $this->getJson("/api/v1/contractors/{$id}")->json('data.missingContractFields'),
        );
    }
}
