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
