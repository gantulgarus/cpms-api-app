<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Inspection;
use App\Models\Location;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use App\Services\BlockLocationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SiteEngineerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private $block;

    private $project;

    private User $manager;

    private User $engineerA;

    private User $engineerB;

    private WorkItem $first;

    private WorkItem $second;

    protected function setUp(): void
    {
        parent::setUp();
        $company = Company::create(['name' => 'Компани']);
        $this->project = $company->projects()->create(['name' => 'Төсөл', 'status' => 'active']);
        $this->block = $this->project->blocks()->create(['name' => 'А1', 'building_no' => 'A1', 'floors' => 1, 'units_per_floor' => 1, 'start_date' => '2026-10-01']);
        app(BlockLocationBuilder::class)->build($this->block, 1, 1);
        $this->manager = User::factory()->create(['role' => 'project_manager']);
        $this->engineerA = User::factory()->create(['role' => 'site_engineer', 'scope_block_ids' => [$this->block->id]])->refresh();
        $this->engineerB = User::factory()->create(['role' => 'site_engineer', 'scope_block_ids' => [$this->block->id]])->refresh();
        $this->first = $this->makeWork('Өрлөг');
        $this->second = $this->makeWork('Цахилгаан');
        Sanctum::actingAs($this->manager);
    }

    private function makeWork(string $name, ?WorkType $type = null): WorkItem
    {
        if (! $type) {
            $group = WorkTypeGroup::create(['name' => $name, 'sequence_number' => 1, 'build_order' => 1]);
            $type = WorkType::create(['name' => $name, 'work_type_group_id' => $group->id, 'unit' => 'м2', 'level' => 'floor', 'inspection_stages' => 2]);
        }
        $item = WorkItem::create(['block_id' => $this->block->id, 'location_id' => Location::where('block_id', $this->block->id)->where('level', 'floor')->firstOrFail()->id,
            'work_type_id' => $type->id, 'name' => $name, 'unit' => 'м2', 'planned_qty' => 100, 'status' => 'not_started', 'review_state' => 'none']);
        $item->progressEntries()->create(['reported_by_id' => $this->manager->id, 'completed_qty' => 10, 'recorded_at' => now()]);
        $item->recalculate();

        return $item;
    }

    private function assign(WorkItem $item, ?User $engineer)
    {
        return $this->postJson("/api/v1/blocks/{$this->block->id}/site-engineer-assignments", [
            'workTypeGroupId' => $item->workType->work_type_group_id, 'siteEngineerId' => $engineer?->id,
        ]);
    }

    private function queue(): string
    {
        return "/api/v1/projects/{$this->project->id}/queue";
    }

    private function check(WorkItem $item)
    {
        return $this->postJson("/api/v1/work-items/{$item->id}/inspections", ['stage' => Inspection::STAGE_SITE, 'result' => 'accepted', 'acceptedQty' => 10]);
    }

    public function test_work_code_order_is_applied_before_pagination(): void
    {
        foreach ([$this->second, $this->first] as $item) {
            $entry = \App\Models\WorkCatalogEntry::create(['group_name' => $item->workType->group->name, 'subgroup_number' => '1.1', 'type_name' => 'Төрөл',
                'name' => $item->workType->name, 'unit' => 'м2', 'sequence_number' => 1]);
            $entry->work_type_id = $item->work_type_id;
            $entry->save();
        }
        $legacy = $this->makeWork('А үсгээр эхлэх кодгүй ажил');
        $url = "/api/v1/blocks/{$this->block->id}/work-items?sort=workCode&pageSize=1";
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.id', $this->second->id);
        $this->getJson($url.'&page=2')->assertOk()->assertJsonPath('data.0.id', $this->first->id);
        $this->getJson($url.'&page=3')->assertOk()->assertJsonPath('data.0.id', $legacy->id);
    }

    public function test_subgroups_have_independent_engineers_and_override_group_fallback(): void
    {
        $typeA = $this->first->workType;
        $typeB = WorkType::create(['name' => 'Өөр дэд бүлгийн ажил', 'work_type_group_id' => $typeA->work_type_group_id, 'unit' => 'м2', 'level' => 'floor']);
        $other = $this->makeWork('Өөр дэд бүлгийн ажил', $typeB);
        foreach ([[$typeA, '1.1'], [$typeB, '1.2']] as [$type, $number]) {
            $entry = \App\Models\WorkCatalogEntry::create(['group_name' => 'Өрлөг', 'subgroup_number' => $number, 'type_name' => 'Төрөл '.$number,
                'name' => $type->name, 'unit' => 'м2', 'sequence_number' => 1]);
            $entry->work_type_id = $type->id;
            $entry->save();
        }
        $this->assign($this->first, $this->engineerA)->assertOk();
        $url = "/api/v1/blocks/{$this->block->id}/site-engineer-assignments";
        $payload = ['workTypeGroupId' => $typeA->work_type_group_id, 'subgroupNumber' => '1.2', 'siteEngineerId' => $this->engineerB->id];
        $this->postJson($url, $payload)->assertOk();
        $rows = collect($this->getJson($url)->assertOk()->json('data.groups'));
        $this->assertSame($this->engineerA->id, $rows->firstWhere('subgroupNumber', '1.1')['siteEngineer']['id']);
        $this->assertSame($this->engineerB->id, $rows->firstWhere('subgroupNumber', '1.2')['siteEngineer']['id']);
        $inspector = User::factory()->create(['role' => 'inspector']);
        $bulkUrl = "/api/v1/blocks/{$this->block->id}/inspector-subgroup-assignments";
        $bulk = ['workTypeGroupId' => $typeA->work_type_group_id, 'subgroupNumber' => '1.2', 'inspectorId' => $inspector->id];
        $this->postJson($bulkUrl, array_merge($bulk, ['inspectorId' => $this->engineerA->id]))->assertUnprocessable();
        $this->postJson($bulkUrl, $bulk)->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertSame($inspector->id, $other->fresh()->inspector_id);
        $this->assertNull($this->first->fresh()->inspector_id);
        $this->assertNull($this->second->fresh()->inspector_id);
        Sanctum::actingAs($this->engineerA);
        $this->postJson($bulkUrl, $bulk)->assertForbidden();
        Sanctum::actingAs($this->manager);
        $filtered = $this->getJson("/api/v1/blocks/{$this->block->id}/work-items?subgroupNumber=1.2")->assertOk();
        $filtered->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $other->id);
        Sanctum::actingAs($this->engineerA);
        $this->getJson($this->queue().'?type=check')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->first->id);
        $this->check($other)->assertForbidden();
        Sanctum::actingAs($this->engineerB);
        $this->getJson("/api/v1/work-items/{$other->id}")->assertOk()->assertJsonPath('data.siteEngineer.id', $this->engineerB->id)->assertJsonPath('data.canCheckThisWorkItem', true);
        $new = $this->makeWork('Дараа нэмсэн', $typeB);
        $this->getJson($this->queue().'?type=check')->assertJsonPath('meta.total', 2);
        Sanctum::actingAs($this->manager);
        $payload['siteEngineerId'] = null;
        $this->postJson($url, $payload)->assertOk();
        $this->assertNull(app(\App\Services\SiteEngineerAssignments::class)->engineer($new));
        Sanctum::actingAs($this->engineerA);
        $this->check($other)->assertForbidden();
        Sanctum::actingAs($this->engineerB);
        $this->getJson($this->queue().'?type=check')->assertJsonPath('meta.total', 0);
    }

    public function test_each_engineer_only_gets_their_queue_and_cannot_check_another_group(): void
    {
        $this->assign($this->first, $this->engineerA)->assertOk();
        $this->assign($this->second, $this->engineerB)->assertOk();
        Sanctum::actingAs($this->engineerA);
        $this->getJson($this->queue().'?type=check')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->first->id);
        $this->getJson($this->queue().'/counts')->assertOk()->assertJsonPath('data.check', 1);
        $this->check($this->second)->assertForbidden();
        $this->getJson("/api/v1/work-items/{$this->first->id}")->assertOk()->assertJsonPath('data.canCheckThisWorkItem', true)->assertJsonPath('data.siteEngineer.id', $this->engineerA->id);
        $this->getJson("/api/v1/work-items/{$this->second->id}")->assertOk()->assertJsonPath('data.canCheckThisWorkItem', false);
        $this->check($this->first)->assertCreated();
        $this->getJson($this->queue().'/counts')->assertJsonPath('data.check', 0);
    }

    public function test_unassigned_work_stays_out_of_site_queue_but_managers_can_see_and_assign_it(): void
    {
        $this->getJson("/api/v1/blocks/{$this->block->id}/site-engineer-assignments")->assertOk()->assertJsonCount(2, 'data.groups')->assertJsonPath('data.groups.0.needsAssignment', true);
        Sanctum::actingAs($this->engineerA);
        $this->getJson($this->queue().'?type=check')->assertJsonPath('meta.total', 0);
        $this->check($this->first)->assertForbidden();
        $this->assign($this->first, $this->engineerA)->assertForbidden();
        Sanctum::actingAs($this->manager);
        $this->getJson($this->queue().'?type=check')->assertJsonPath('meta.total', 2);
    }

    public function test_reassignment_removal_and_new_work_follow_the_rule(): void
    {
        $this->assign($this->first, $this->engineerA)->assertOk();
        $new = $this->makeWork('Шинэ ажил', $this->first->workType);
        Sanctum::actingAs($this->engineerA);
        $this->getJson($this->queue().'?type=check')->assertJsonPath('meta.total', 2);
        $this->check($this->first)->assertCreated();
        $historyId = $this->first->inspections()->firstOrFail()->id;
        Sanctum::actingAs($this->manager);
        $this->assign($this->first, $this->engineerB)->assertOk();
        $this->assertDatabaseHas('inspections', ['id' => $historyId, 'inspector_id' => $this->engineerA->id]);
        Sanctum::actingAs($this->engineerA);
        $this->check($new)->assertForbidden();
        $this->getJson($this->queue().'?type=check')->assertJsonPath('meta.total', 0);
        Sanctum::actingAs($this->engineerB);
        $this->getJson($this->queue().'?type=check')->assertJsonPath('meta.total', 1);
        Sanctum::actingAs($this->manager);
        $this->assign($this->first, null)->assertOk();
        Sanctum::actingAs($this->engineerB);
        $this->check($new)->assertForbidden();
    }

    public function test_only_active_site_engineers_with_block_access_are_eligible(): void
    {
        $inspector = User::factory()->create(['role' => 'inspector']);
        $this->assign($this->first, $inspector)->assertUnprocessable();
        $this->engineerA->update(['is_active' => false]);
        $this->assign($this->first, $this->engineerA)->assertUnprocessable();
        $this->engineerB->update(['scope_block_ids' => [(string) Str::uuid()]]);
        $this->assign($this->first, $this->engineerB)->assertUnprocessable();
    }
}
