<?php

namespace Tests\Feature;

use App\Jobs\ApplyBlockDesign;
use App\Models\Block;
use App\Models\BlockDesign;
use App\Models\Company;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Ажилд туслан гүйцэтгэгч оноох.
 *
 * ЯАГААД: `ApplyBlockDesign` нь ажлыг `contractor_id = null` гэж үүсгэдэг.
 * Оноох зам байхгүй байсан тул шинэ блокийн БҮХ ажил хариуцагчгүй үлдэж,
 * гүйцэтгэгчийн бүхэл функц — нэвтрэх код, хамрах хүрээ, өөрөө явц оруулах —
 * ажиллах өгөгдөлгүй болдог байв.
 *
 * Хамгийн чухал тест: `test_contractor_sees_the_work_after_assignment`.
 */
class AssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;
    private User $manager;
    private Contractor $goo;
    private WorkTypeGroup $group;

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
        $this->goo = Contractor::create(['name' => 'Гоо Засал ХХК']);

        // Хамгийн олон ажилтай бүлгийг сонгоно — бөөний оноолт үнэхээр
        // ажиллаж байгааг батлахад 1 мөртэй бүлэг хангалтгүй.
        // Энэ блокт ҮНЭХЭЭР ажил үүссэн бүлгүүдээс хамгийн олонтойг сонгоно.
        $groupId = WorkItem::query()
            ->where('work_items.block_id', $this->block->id)
            ->join('work_types as wt', 'wt.id', '=', 'work_items.work_type_id')
            ->groupBy('wt.work_type_group_id')
            ->orderByRaw('count(*) desc')
            ->value('wt.work_type_group_id');

        $this->group = WorkTypeGroup::findOrFail($groupId);
    }

    private function itemsInGroup(): \Illuminate\Database\Eloquent\Builder
    {
        $typeIds = WorkType::where('work_type_group_id', $this->group->id)->pluck('id');

        return WorkItem::query()
            ->where('block_id', $this->block->id)
            ->whereIn('work_type_id', $typeIds);
    }

    // -----------------------------------------------------------------
    // Асуудал
    // -----------------------------------------------------------------

    public function test_generated_work_starts_with_no_contractor(): void
    {
        // Энэ бол илэрсэн алдаа: загвар буулгахад хариуцагч оноогддоггүй.
        $this->assertSame(
            0,
            WorkItem::where('block_id', $this->block->id)->whereNotNull('contractor_id')->count(),
        );
        $this->assertGreaterThan(0, WorkItem::where('block_id', $this->block->id)->count());
    }

    public function test_assignment_summary_shows_what_is_left(): void
    {
        $data = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/blocks/{$this->block->id}/assignments")
            ->assertOk()
            ->json('data');

        $row = collect($data)->firstWhere('groupId', $this->group->id);

        $this->assertNotNull($row);
        $this->assertSame($row['workItems'], $row['unassigned'], 'Эхэндээ бүгд хариуцагчгүй.');
        $this->assertSame([], $row['contractors']);
    }

    // -----------------------------------------------------------------
    // Оноох
    // -----------------------------------------------------------------

    public function test_bulk_assigns_a_whole_work_type_group(): void
    {
        $count = $this->itemsInGroup()->count();
        $this->assertGreaterThan(1, $count);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$this->block->id}/work-items/assign", [
                'workTypeGroupId' => $this->group->id,
                'contractorId' => $this->goo->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.affected', $count);

        $this->assertSame($count, $this->itemsInGroup()->where('contractor_id', $this->goo->id)->count());
    }

    /**
     * Хамгийн чухал тест.
     *
     * Оноолтын бүхэл зорилго нь энэ: гүйцэтгэгч нэвтрээд ажлаа ХАРАХ.
     * Энэ унавал нэвтрэх код, хамрах хүрээ, явц оруулах бүгд утгагүй.
     */
    public function test_contractor_sees_the_work_after_assignment(): void
    {
        $rep = User::create([
            'name' => 'Гоо Засал — ахлагч',
            'email' => 'goo@cpms.local',
            'password' => Hash::make('x'),
            'role' => 'contractor',
            'contractor_id' => $this->goo->id,
        ]);

        // Оноохоос ӨМНӨ — блок нь ч харагдахгүй.
        $this->actingAs($rep, 'sanctum')
            ->getJson("/api/v1/blocks/{$this->block->id}/work-items")
            ->assertForbidden();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$this->block->id}/work-items/assign", [
                'workTypeGroupId' => $this->group->id,
                'contractorId' => $this->goo->id,
            ])->assertOk();

        // Оноосны ДАРАА — яг тэр ажлууд харагдана.
        $this->assertSame(
            $this->itemsInGroup()->count(),
            $this->actingAs($rep, 'sanctum')
                ->getJson("/api/v1/blocks/{$this->block->id}/work-items?pageSize=1")
                ->assertOk()
                ->json('meta.total'),
        );
    }

    public function test_assignment_targets_only_unassigned_work_by_default(): void
    {
        $items = $this->itemsInGroup()->get();
        $other = Contractor::create(['name' => 'Сантехник ХХК']);

        // Нэг ажлыг зориудаар өөр компанид ононо.
        WorkItem::where('id', $items->first()->id)->update(['contractor_id' => $other->id]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$this->block->id}/work-items/assign", [
                'workTypeGroupId' => $this->group->id,
                'contractorId' => $this->goo->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.affected', $items->count() - 1);

        // Зориудаар оноосон ажил ХЭВЭЭР — чимээгүй шилжих ёсгүй.
        $this->assertSame($other->id, $items->first()->refresh()->contractor_id);
    }

    public function test_assignment_can_reassign_when_explicitly_asked(): void
    {
        $items = $this->itemsInGroup()->get();
        $other = Contractor::create(['name' => 'Сантехник ХХК']);
        WorkItem::where('id', $items->first()->id)->update(['contractor_id' => $other->id]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$this->block->id}/work-items/assign", [
                'workTypeGroupId' => $this->group->id,
                'contractorId' => $this->goo->id,
                'reassignExisting' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.affected', $items->count());

        $this->assertSame($this->goo->id, $items->first()->refresh()->contractor_id);
    }

    public function test_a_single_work_item_can_be_reassigned(): void
    {
        $item = $this->itemsInGroup()->first();

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/work-items/{$item->id}/contractor", [
                'contractorId' => $this->goo->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.contractor.id', $this->goo->id);
    }

    public function test_contractor_can_be_cleared(): void
    {
        $item = $this->itemsInGroup()->first();
        WorkItem::where('id', $item->id)->update(['contractor_id' => $this->goo->id]);

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/work-items/{$item->id}/contractor", ['contractorId' => null])
            ->assertOk();

        $this->assertNull($item->refresh()->contractor_id);
    }

    // -----------------------------------------------------------------
    // Хамгаалалт
    // -----------------------------------------------------------------

    public function test_target_must_be_specified(): void
    {
        // Бүлэг/төрөл заахгүй бол БҮХ ажил санамсаргүй оноогдож болзошгүй.
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$this->block->id}/work-items/assign", [
                'contractorId' => $this->goo->id,
            ])
            ->assertStatus(422);
    }

    public function test_cannot_reassign_work_that_already_has_progress(): void
    {
        $item = $this->itemsInGroup()->where('planned_qty', '>', 5)->firstOrFail();
        WorkItem::where('id', $item->id)->update(['contractor_id' => $this->goo->id]);

        $engineer = User::factory()->create(['role' => 'site_engineer']);
        $this->actingAs($engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 5])
            ->assertCreated();

        $other = Contractor::create(['name' => 'Сантехник ХХК']);

        // Хариуцагчийг солих нь гүйцэтгэлийн түүхийг өөр компанид шилжүүлнэ —
        // акт, төлбөр буруу хүнд очно.
        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/work-items/{$item->id}/contractor", ['contractorId' => $other->id])
            ->assertStatus(409);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$this->block->id}/work-items/assign", [
                'workTypeGroupId' => $this->group->id,
                'contractorId' => $other->id,
                'reassignExisting' => true,
            ])
            ->assertStatus(409);
    }

    public function test_site_engineer_cannot_assign_contractors(): void
    {
        // Хэн ямар ажил хийхийг тогтоох нь гэрээний шийдвэр.
        $this->actingAs(User::factory()->create(['role' => 'site_engineer']), 'sanctum')
            ->postJson("/api/v1/blocks/{$this->block->id}/work-items/assign", [
                'workTypeGroupId' => $this->group->id,
                'contractorId' => $this->goo->id,
            ])
            ->assertForbidden();
    }

    public function test_contractor_cannot_assign_work_to_itself(): void
    {
        $rep = User::create([
            'name' => 'Төлөөлөгч',
            'email' => 'rep@cpms.local',
            'password' => Hash::make('x'),
            'role' => 'contractor',
            'contractor_id' => $this->goo->id,
        ]);

        $this->actingAs($rep, 'sanctum')
            ->postJson("/api/v1/blocks/{$this->block->id}/work-items/assign", [
                'workTypeGroupId' => $this->group->id,
                'contractorId' => $this->goo->id,
            ])
            ->assertForbidden();
    }
}
