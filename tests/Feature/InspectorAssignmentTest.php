<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ажилд хяналтын инженер оноох.
 *
 * Хамгийн чухал хориг: БАТЛАХ ЭРХГҮЙ хүнийг оноож болохгүй. Оноовол
 * батлахыг хүлээсэн ажил мөнхөд гацах бөгөөд шалтгаан нь дэлгэц дээр
 * хаана ч харагдахгүй.
 */
class InspectorAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private WorkItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Төсөл', 'status' => 'active']);
        $block = $project->blocks()->create([
            'name' => 'А1 блок', 'building_no' => 'A1',
            'floors' => 1, 'units_per_floor' => 1, 'start_date' => '2026-10-01',
        ]);

        app(\App\Services\BlockLocationBuilder::class)->build($block, 1, 1);

        $group = WorkTypeGroup::create(['name' => 'Засал', 'sequence_number' => 1, 'build_order' => 7]);
        $type = WorkType::create([
            'name' => 'Шавардлага', 'work_type_group_id' => $group->id,
            'level' => 'floor', 'unit' => 'м2',
        ]);

        $this->item = WorkItem::create([
            'block_id' => $block->id,
            'location_id' => Location::where('block_id', $block->id)->where('level', 'floor')->firstOrFail()->id,
            'work_type_id' => $type->id,
            'name' => $type->name, 'unit' => $type->unit,
            'planned_qty' => 100, 'status' => 'not_started', 'review_state' => 'none',
        ]);
    }

    public function test_an_inspector_can_be_assigned(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'general_engineer']));

        $inspector = User::factory()->create([
            'role' => 'inspector',
            'position' => 'Хяналтын инженер',
        ]);

        $this->patchJson("/api/v1/work-items/{$this->item->id}/inspector", [
            'inspectorId' => $inspector->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.inspector.name', $inspector->name)
            ->assertJsonPath('data.inspector.position', 'Хяналтын инженер');

        $this->assertSame($inspector->id, $this->item->refresh()->inspector_id);
    }

    /**
     * Батлах эрхгүй хүнийг оноож болохгүй.
     *
     * Б.Сайхантамир шиг «гэрээний мэргэжилтэн» хүнд захиалагч `inspector`
     * үүрэг өгсөн бол оноогдоно. Харин үүрэг нь `site_engineer` хүн
     * оноогдвол тэр ажил батлагдахаа болино.
     */
    public function test_a_user_without_approval_rights_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'general_engineer']));

        $engineer = User::factory()->create([
            'role' => 'site_engineer',
            'position' => 'Барилгын инженер',
        ]);

        $this->patchJson("/api/v1/work-items/{$this->item->id}/inspector", [
            'inspectorId' => $engineer->id,
        ])->assertStatus(422);

        $this->assertNull($this->item->refresh()->inspector_id);
    }

    /** Эрх хаагдсан хүн рүү ажил явуулахгүй. */
    public function test_an_inactive_user_is_refused(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'general_engineer']));

        $gone = User::factory()->create(['role' => 'inspector', 'is_active' => false]);

        $this->patchJson("/api/v1/work-items/{$this->item->id}/inspector", [
            'inspectorId' => $gone->id,
        ])->assertStatus(422);
    }

    /** Хариуцагчийг авч болно — төсвөөс хасагдсан бол хоосон байх ёстой. */
    public function test_the_inspector_can_be_cleared(): void
    {
        $inspector = User::factory()->create(['role' => 'inspector']);
        $this->item->update(['inspector_id' => $inspector->id]);

        Sanctum::actingAs(User::factory()->create(['role' => 'general_engineer']));

        $this->patchJson("/api/v1/work-items/{$this->item->id}/inspector", ['inspectorId' => null])
            ->assertOk()
            ->assertJsonPath('data.inspector', null);

        $this->assertNull($this->item->refresh()->inspector_id);
    }

    /**
     * Талбайн инженер өөртөө хяналт оноож чадахгүй.
     *
     * Эс бөгөөс мэдээлэх ба батлах эрхийг салгасан нь утгаа алдана —
     * v1-ийн гол гомдол.
     */
    public function test_a_site_engineer_cannot_assign(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'site_engineer']));

        $this->patchJson("/api/v1/work-items/{$this->item->id}/inspector", [
            'inspectorId' => User::factory()->create(['role' => 'inspector'])->id,
        ])->assertStatus(403);
    }

    /**
     * Сонгох жагсаалт нь ОНООХ ЭРХТЭЙ бүх үүрэгт нээлттэй.
     *
     * `/users` нь зөвхөн админ, захиралд нээлттэй. Хэрэв цонх түүнийг
     * дууддаг бол ерөнхий инженер 403 аваад жагсаалт хоосон гарч, функц
     * ажиллахгүй болно — дэлгэцээс харагдахгүй алдаа.
     */
    public function test_the_candidate_list_is_open_to_anyone_who_can_assign(): void
    {
        $inspector = User::factory()->create([
            'role' => 'inspector',
            'position' => 'Хяналтын инженер',
        ]);
        User::factory()->create(['role' => 'site_engineer']);
        User::factory()->create(['role' => 'inspector', 'is_active' => false]);

        Sanctum::actingAs(User::factory()->create(['role' => 'general_engineer']));

        $rows = $this->getJson('/api/v1/users/inspectors')->assertOk()->json('data');

        $ids = array_column($rows, 'id');

        $this->assertContains($inspector->id, $ids);
        $this->assertContains('Хяналтын инженер', array_column($rows, 'position'));

        // Батлах эрхгүй, эрх хаагдсан хүн жагсаалтад ОРОХГҮЙ: оновол
        // хэрэглэгч сонгоод 422 авна, яагаад болохгүйг ойлгохгүй.
        foreach (User::whereIn('id', $ids)->get() as $user) {
            $this->assertTrue($user->canInspect(), "{$user->name} батлах эрхгүй.");
            $this->assertTrue((bool) $user->is_active, "{$user->name} эрх хаагдсан.");
        }

        // И-мэйл гарах ЁСГҮЙ — энэ эндпойнт хэрэглэгч удирдах эрхгүй хүнд
        // нээлттэй тул бүтэн бүртгэлийг дамжуулж болохгүй.
        $this->assertArrayNotHasKey('email', $rows[0]);
    }

    public function test_the_candidate_list_is_closed_to_a_site_engineer(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'site_engineer']));

        $this->getJson('/api/v1/users/inspectors')->assertStatus(403);
    }

    /** `inspectorId=none` — хариуцагчгүй ажлыг шүүнэ. */
    public function test_items_without_an_inspector_can_be_listed(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'general_engineer']));

        $block = Block::firstOrFail();

        $this->getJson("/api/v1/blocks/{$block->id}/work-items?inspectorId=none")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->item->update(['inspector_id' => User::factory()->create(['role' => 'inspector'])->id]);

        $this->getJson("/api/v1/blocks/{$block->id}/work-items?inspectorId=none")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    /** `inspectorId=me` — инженер өөрийн ажлыг олох зам. */
    public function test_an_inspector_can_list_their_own_work(): void
    {
        $inspector = User::factory()->create(['role' => 'inspector']);
        $this->item->update(['inspector_id' => $inspector->id]);

        Sanctum::actingAs($inspector);

        $this->getJson('/api/v1/blocks/'.Block::firstOrFail()->id.'/work-items?inspectorId=me')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }
}
