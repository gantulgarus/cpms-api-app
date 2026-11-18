<?php
namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Inspection;
use App\Models\Location;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use App\Services\BlockLocationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkDatesTest extends TestCase
{
    use RefreshDatabase;
    private Block $block;
    private User $manager;
    private User $reporter;
    private WorkType $type;
    private WorkItem $first;
    private WorkItem $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 17)->startOfDay());
        $project = Company::create(['name' => 'Компани'])->projects()->create(['name' => 'Төсөл', 'status' => 'active']);
        $this->block = $project->blocks()->create(['name' => 'Барилга', 'floors' => 2, 'units_per_floor' => 1, 'start_date' => '2026-11-01']);
        app(BlockLocationBuilder::class)->build($this->block, 2, 1);
        $this->manager = User::factory()->create(['role' => 'project_manager']);
        $this->reporter = User::factory()->create(['role' => 'site_engineer']);
        $group = WorkTypeGroup::create(['name' => 'Бүлэг', 'sequence_number' => 1, 'build_order' => 1]);
        $this->type = WorkType::create(['name' => 'Тестийн ажил', 'unit' => 'м2', 'level' => 'floor', 'work_type_group_id' => $group->id, 'inspection_stages' => 1]);
        $floors = Location::where('block_id', $this->block->id)->where('level', 'floor')->get();
        $make = fn ($location) => $this->block->workItems()->create(['location_id' => $location->id, 'work_type_id' => $this->type->id, 'name' => 'Тестийн ажил', 'unit' => 'м2', 'planned_qty' => 10, 'planned_start_date' => '2026-11-01', 'planned_end_date' => '2026-11-10', 'status' => 'not_started', 'review_state' => 'none']);
        $this->first = $make($floors[0]); $this->second = $make($floors[1]);
        Sanctum::actingAs($this->manager);
    }
    private function url(WorkItem $item): string { return '/api/v1/work-items/'.$item->id; }

    public function test_manual_dates_are_protected_from_automatic_schedule(): void
    {
        $this->patchJson($this->url($this->first), ['plannedStartDate' => '2026-11-02', 'plannedEndDate' => '2026-11-12'])->assertOk()->assertJsonPath('data.scheduleManual', true);
        $this->postJson('/api/v1/blocks/'.$this->block->id.'/schedule', ['taktDays' => 9])->assertOk()->assertJsonPath('data.frozen', 1)->assertJsonPath('data.updated', 1);
        $this->assertSame('2026-11-12', $this->first->fresh()->planned_end_date->toDateString());
        $this->assertNull($this->second->fresh()->actual_start_date);
        $this->patchJson($this->url($this->second), ['plannedQty' => 9])->assertOk()->assertJsonPath('data.scheduleManual', false);
        $this->patchJson($this->url($this->first), ['plannedQty' => 20, 'plannedStartDate' => '2026-12-30'])->assertUnprocessable();
        $this->assertSame(10.0, (float) $this->first->fresh()->planned_qty);
    }

    public function test_batch_dates_apply_only_to_selected_unprotected_locations(): void
    {
        $url = '/api/v1/blocks/'.$this->block->id.'/work-items/set-dates';
        $payload = ['workTypeId' => $this->type->id, 'locationIds' => [$this->first->location_id], 'plannedStartDate' => '2026-11-03', 'plannedEndDate' => '2026-11-13'];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertSame('2026-11-10', $this->second->fresh()->planned_end_date->toDateString());
        $payload['locationIds'][] = $this->second->location_id;
        $payload['plannedEndDate'] = '2026-11-14';
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.updated', 1)->assertJsonPath('data.skipped', 1);
        $this->assertSame('2026-11-13', $this->first->fresh()->planned_end_date->toDateString());
        $payload['overwriteManual'] = true;
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.updated', 2);
        $this->second->update(['accepted_qty' => 1]);
        $this->postJson($url, $payload)->assertJsonPath('data.updated', 1)->assertJsonPath('data.skipped', 1);
        $this->patchJson($this->url($this->second), ['plannedEndDate' => '2026-11-15'])->assertConflict();
        $this->postJson($url, array_merge($payload, ['locationIds' => []]))->assertUnprocessable();
        Sanctum::actingAs($this->reporter);
        $this->postJson($url, $payload)->assertForbidden();
    }

    public function test_progress_dates_do_not_replace_approval_and_delays_compare_physical_completion(): void
    {
        Sanctum::actingAs($this->reporter);
        $url = $this->url($this->first);
        $this->postJson($url.'/progress', ['completedQty' => 4, 'actualStartDate' => '2026-11-02', 'actualEndDate' => '2026-11-12'])->assertUnprocessable();
        $this->assertSame(0, $this->first->progressEntries()->count());
        $this->postJson($url.'/progress', ['completedQty' => 4, 'actualStartDate' => '2026-11-02'])->assertCreated();
        $this->getJson($url)->assertJsonPath('data.actualStartDate', '2026-11-02')->assertJsonPath('data.actualEndDate', null)->assertJsonPath('data.approvedCompletedAt', null);
        $this->postJson($url.'/progress', ['completedQty' => 6, 'actualStartDate' => '2026-11-02', 'actualEndDate' => '2026-11-12'])->assertCreated();
        $this->getJson($url)->assertJsonPath('data.actualEndDate', '2026-11-12')->assertJsonPath('data.completionDelayDays', 2)->assertJsonPath('data.actualDurationDays', 11)->assertJsonPath('data.approvedCompletedAt', null);
        $this->first->inspections()->create(['inspector_id' => $this->manager->id, 'stage' => Inspection::STAGE_FINAL, 'result' => 'accepted', 'accepted_qty' => 10, 'rejected_qty' => 0, 'inspected_at' => '2026-11-14 10:00:00']);
        $this->first->refresh()->recalculate();
        $this->assertSame('2026-11-14', $this->first->fresh()->approved_completed_at->toDateString());
        $this->assertSame('2026-11-12', $this->first->fresh()->actual_end_date->toDateString());
        Sanctum::actingAs($this->manager);
        $this->getJson('/api/v1/blocks/'.$this->block->id.'/report')->assertOk()->assertJsonPath('data.schedule.actualFinishedItems', 1)->assertJsonPath('data.schedule.actualLateItems', 1);
    }

    public function test_actual_dates_use_the_project_day_after_local_midnight(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-17 17:00:00', 'UTC'));
        $this->patchJson($this->url($this->first).'/actual-dates', ['actualStartDate' => '2026-11-18', 'actualEndDate' => null])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->first->update(['actual_start_date' => null]);
        $this->first->progressEntries()->create(['reported_by_id' => $this->reporter->id, 'completed_qty' => 1, 'recorded_at' => '2026-11-17 16:30:00']);
        $this->getJson($this->url($this->first))->assertJsonPath('data.actualStartSuggestion', '2026-11-18');
    }

    public function test_actual_dates_validate_and_legacy_progress_is_only_a_suggestion(): void
    {
        $url = $this->url($this->first);
        $this->first->progressEntries()->create(['reported_by_id' => $this->reporter->id, 'completed_qty' => 10, 'recorded_at' => '2026-11-05 09:00:00']);
        $this->first->recalculate();
        $this->getJson($url)->assertJsonPath('data.actualStartDate', null)->assertJsonPath('data.actualStartSuggestion', '2026-11-05');
        $this->patchJson($url.'/actual-dates', ['actualStartDate' => null, 'actualEndDate' => '2026-11-06'])->assertUnprocessable();
        $this->patchJson($url.'/actual-dates', ['actualStartDate' => '2026-11-05', 'actualEndDate' => '2026-11-04'])->assertUnprocessable();
        $this->patchJson($url.'/actual-dates', ['actualStartDate' => '2026-11-18', 'actualEndDate' => null])->assertUnprocessable();
        $this->patchJson($url.'/actual-dates', ['actualStartDate' => '2026-11-04', 'actualEndDate' => '2026-11-06'])->assertOk()->assertJsonPath('data.actualEndDate', '2026-11-06');
        $this->postJson('/api/v1/blocks/'.$this->block->id.'/schedule', ['taktDays' => 12])->assertOk()->assertJsonPath('data.frozen', 1);
        $this->assertSame('2026-11-10', $this->first->fresh()->planned_end_date->toDateString());
        $this->first->inspections()->create(['inspector_id' => $this->manager->id, 'stage' => Inspection::STAGE_FINAL, 'result' => 'rejected', 'accepted_qty' => 0, 'rejected_qty' => 2, 'inspected_at' => now()]);
        $this->first->refresh()->recalculate();
        $this->assertNull($this->first->fresh()->actual_end_date);
        Sanctum::actingAs(User::factory()->create(['role' => 'inspector']));
        $this->patchJson($url.'/actual-dates', ['actualStartDate' => '2026-11-05', 'actualEndDate' => null])->assertForbidden();
    }
}
