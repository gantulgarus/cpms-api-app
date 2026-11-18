<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use App\Services\BlockLocationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkExecutionPlanTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;
    private User $manager;
    private array $works;

    protected function setUp(): void
    {
        parent::setUp();
        $project = Company::create(['name' => 'Компани'])->projects()->create(['name' => 'Төсөл', 'status' => 'active']);
        $this->block = $project->blocks()->create(['name' => 'Барилга', 'floors' => 2, 'units_per_floor' => 1, 'start_date' => '2026-11-18']);
        app(BlockLocationBuilder::class)->build($this->block, 2, 1);
        $group = WorkTypeGroup::create(['name' => 'Бүлэг', 'sequence_number' => 1, 'build_order' => 1]);
        $floor = Location::where('block_id', $this->block->id)->where('level', 'floor')->firstOrFail();
        $this->works = [];
        foreach (['А', 'Б', 'В', 'Г'] as $name) {
            $type = WorkType::create(['name' => $name, 'work_type_group_id' => $group->id, 'unit' => 'м2', 'level' => 'floor']);
            $this->works[] = $this->block->workItems()->create([
                'name' => $name, 'work_type_id' => $type->id, 'location_id' => $floor->id, 'unit' => 'м2', 'planned_qty' => 10,
                'budget_unit_price' => 123, 'planned_start_date' => '2026-11-18', 'planned_end_date' => '2026-11-25', 'status' => 'not_started', 'review_state' => 'none',
            ]);
        }
        $this->manager = User::factory()->create(['role' => 'project_manager']);
        Sanctum::actingAs($this->manager);
    }

    private function url(): string { return '/api/v1/blocks/'.$this->block->id.'/execution-plan'; }
    private function current(): array { return $this->getJson($this->url())->assertOk()->json('data'); }
    private function link(array $links)
    {
        return $this->postJson($this->url().'/dependencies', ['version' => $this->current()['version'], 'links' => $links]);
    }

    public function test_existing_work_has_no_invented_dependencies_or_data_changes(): void
    {
        $before = $this->works[0]->fresh()->getAttributes();
        $plan = $this->current();
        $this->assertFalse($plan['hasOrder']);
        $this->assertEmpty($plan['predecessors']);
        $this->assertCount(4, $plan['order']);
        $this->assertSame($before, $this->works[0]->fresh()->getAttributes());
        $this->assertNull($this->works[0]->fresh()->plan_order);
    }

    public function test_fork_join_links_and_order_preserve_existing_quantities_and_dates(): void
    {
        [$a, $b, $c, $d] = $this->works;
        $fields = ['planned_qty', 'budget_unit_price', 'planned_start_date', 'planned_end_date', 'baseline_end_date', 'reported_qty', 'accepted_qty', 'contractor_id'];
        $before = array_intersect_key($a->fresh()->getAttributes(), array_flip($fields));
        $this->link([
            ['workItemId' => $b->id, 'predecessorIds' => [$a->id]],
            ['workItemId' => $c->id, 'predecessorIds' => [$a->id]],
            ['workItemId' => $d->id, 'predecessorIds' => [$b->id, $c->id]],
        ])->assertOk();
        $plan = $this->current();
        $ranks = array_flip($plan['order']);
        $this->assertLessThan($ranks[$b->id], $ranks[$a->id]);
        $this->assertLessThan($ranks[$c->id], $ranks[$a->id]);
        $this->assertLessThan($ranks[$d->id], $ranks[$b->id]);
        $this->assertLessThan($ranks[$d->id], $ranks[$c->id]);
        $this->assertSame($before, array_intersect_key($a->fresh()->getAttributes(), array_flip($fields)));
        $this->assertSame(4, WorkItem::count());
        $this->assertDatabaseCount('work_item_dependencies', 4);
    }

    public function test_cycles_self_links_foreign_work_and_stale_edits_are_atomic(): void
    {
        [$a, $b, $c] = $this->works;
        $old = $this->current();
        $this->link([['workItemId' => $b->id, 'predecessorIds' => [$a->id]]])->assertOk();
        $before = $this->current();
        $this->postJson($this->url().'/dependencies', ['version' => $old['version'], 'links' => [['workItemId' => $c->id, 'predecessorIds' => [$a->id]]]])->assertConflict();
        $this->link([
            ['workItemId' => $a->id, 'predecessorIds' => [$b->id]],
            ['workItemId' => $c->id, 'predecessorIds' => [$a->id]],
        ])->assertUnprocessable();
        $this->assertSame($before, $this->current());
        $this->link([['workItemId' => $a->id, 'predecessorIds' => [$a->id]]])->assertUnprocessable();
        $other = $this->block->project->blocks()->create(['name' => 'Өөр барилга', 'floors' => 1, 'units_per_floor' => 0]);
        app(BlockLocationBuilder::class)->build($other, 1, 0);
        $foreign = $a->replicate(); $foreign->block_id = $other->id; $foreign->location_id = $other->locations()->firstOrFail()->id; $foreign->save();
        $this->link([['workItemId' => $b->id, 'predecessorIds' => [$foreign->id]]])->assertUnprocessable();
        $this->assertSame($before, $this->current());
    }

    public function test_move_rejects_dependency_inversions_and_accepts_independent_siblings(): void
    {
        [$a, $b, $c, $d] = $this->works;
        $this->link([
            ['workItemId' => $b->id, 'predecessorIds' => [$a->id]],
            ['workItemId' => $c->id, 'predecessorIds' => [$a->id]],
        ])->assertOk();
        $plan = $this->current();
        $this->postJson($this->url().'/move', ['version' => $plan['version'], 'workItemId' => $c->id, 'relativeToId' => $b->id, 'position' => 'before'])->assertOk();
        $changed = $this->current();
        $this->assertLessThan(array_search($b->id, $changed['order']), array_search($c->id, $changed['order']));
        $this->postJson($this->url().'/move', ['version' => $changed['version'], 'workItemId' => $b->id, 'relativeToId' => $a->id, 'position' => 'before'])->assertUnprocessable();
        $this->assertSame($changed, $this->current());
        Sanctum::actingAs(User::factory()->create(['role' => 'site_engineer']));
        $this->postJson($this->url().'/move', ['version' => $changed['version'], 'workItemId' => $d->id, 'relativeToId' => $a->id, 'position' => 'before'])->assertForbidden();
    }

    public function test_add_work_respects_selection_order_and_appends_after_older_work(): void
    {
        $new = [];
        foreach (['Я сүүлд', 'А эхэнд'] as $name) {
            $new[] = WorkType::create(['name' => $name, 'work_type_group_id' => $this->works[0]->workType->work_type_group_id, 'level' => 'floor', 'unit' => 'м2']);
        }
        $before = $this->current()['order'];
        $this->postJson('/api/v1/blocks/'.$this->block->id.'/work-items', ['workTypeIds' => [$new[0]->id, $new[1]->id], 'locationIds' => [$this->works[0]->location_id]])->assertCreated();
        $after = $this->current();
        $this->assertSame($before, array_slice($after['order'], 0, 4));
        $tail = WorkItem::whereIn('id', array_slice($after['order'], 4))->get()->keyBy('id');
        $this->assertSame($new[0]->id, $tail[$after['order'][4]]->work_type_id);
        $this->assertSame($new[1]->id, $tail[$after['order'][5]]->work_type_id);
        $this->assertEmpty($after['predecessors']);
    }

    public function test_deletion_cascades_links_and_changes_version(): void
    {
        [$a, $b] = $this->works;
        $this->link([['workItemId' => $b->id, 'predecessorIds' => [$a->id]]])->assertOk();
        $before = $this->current();
        $this->deleteJson('/api/v1/work-items/'.$a->id)->assertNoContent();
        $this->assertDatabaseCount('work_item_dependencies', 0);
        $after = $this->current();
        $this->assertNotSame($before['version'], $after['version']);
        $this->assertNotContains($a->id, $after['order']);
    }
}
