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
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Батлахыг хүлээж буй дараалал ба асуудлын бүртгэл.
 *
 * Дараалал нь БЛОКоор биш ТӨСЛӨӨР явна: "өнөөдөр юу батлах вэ" гэсэн
 * асуултад аль барилга гэдэг нь хамаагүй.
 */
class QueueAndIssueTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Block $block;
    private User $engineer;
    private User $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkTypeSeeder::class);

        $company = Company::create(['name' => 'Инэл ХХК']);
        $this->project = $company->projects()->create(['name' => 'Цогцолбор', 'status' => 'active']);
        $design = BlockDesign::where('floors', 12)->firstOrFail();

        $this->block = $this->project->blocks()->create([
            'block_design_id' => $design->id,
            'name' => 'А блок',
            'building_no' => '1',
            'floors' => $design->floors,
            'units_per_floor' => $design->units_per_floor,
            'start_date' => '2026-09-01',
        ]);
        (new ApplyBlockDesign($this->block->id, $design->id, '2026-09-01'))->handle();

        $this->engineer = User::factory()->create(['role' => 'site_engineer']);
        $this->inspector = User::factory()->create(['role' => 'inspector']);
    }

    private function reportOn(WorkItem $item, float $qty = 5): void
    {
        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => $qty])
            ->assertCreated();
    }

    // -----------------------------------------------------------------
    // Дараалал
    // -----------------------------------------------------------------

    public function test_queue_lists_only_items_awaiting_inspection(): void
    {
        $items = WorkItem::where('block_id', $this->block->id)->take(3)->get();

        foreach ($items as $item) {
            $this->reportOn($item);
        }

        $response = $this->actingAs($this->inspector, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/queue")
            ->assertOk();

        $this->assertSame(3, $response->json('meta.total'));

        $returned = collect($response->json('data'))->pluck('id')->sort()->values();
        $this->assertSame($items->pluck('id')->sort()->values()->all(), $returned->all());
    }

    public function test_queue_counts_match_the_lists(): void
    {
        $items = WorkItem::where('block_id', $this->block->id)->take(2)->get();

        foreach ($items as $item) {
            $this->reportOn($item);
        }

        $this->actingAs($this->inspector, 'sanctum');

        $counts = $this->getJson("/api/v1/projects/{$this->project->id}/queue/counts")
            ->assertOk()->json('data');

        // Тоо ба жагсаалт өөр query-гээр гардаг тул заавал тулгана.
        foreach (['inspection', 'returned', 'overdue'] as $type) {
            $listed = $this->getJson("/api/v1/projects/{$this->project->id}/queue?type={$type}&pageSize=1")
                ->json('meta.total');

            $this->assertSame(
                $counts[$type],
                $listed,
                "[{$type}] табын тоо жагсаалтын тоотой таарахгүй байна."
            );
        }
    }

    public function test_approved_item_leaves_the_queue(): void
    {
        $item = WorkItem::where('block_id', $this->block->id)->firstOrFail();
        $this->reportOn($item, 5);

        $this->actingAs($this->inspector, 'sanctum');
        $this->assertSame(1, $this->getJson("/api/v1/projects/{$this->project->id}/queue")->json('meta.total'));

        $this->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => 'client',
            'result' => 'accepted',
            'acceptedQty' => 5,
        ])->assertCreated();

        $this->assertSame(0, $this->getJson("/api/v1/projects/{$this->project->id}/queue")->json('meta.total'));
    }

    public function test_rejected_item_moves_to_the_returned_tab(): void
    {
        $item = WorkItem::where('block_id', $this->block->id)->firstOrFail();
        $this->reportOn($item, 5);

        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'rejected',
                'acceptedQty' => 0,
                'reason' => 'Чанар хангаагүй',
            ])->assertCreated();

        $this->assertSame(
            1,
            $this->getJson("/api/v1/projects/{$this->project->id}/queue?type=returned")->json('meta.total')
        );
    }

    public function test_contractor_queue_shows_only_their_own_work(): void
    {
        $mine = Contractor::create(['name' => 'Гоо Засал ХХК']);
        $ids = WorkItem::where('block_id', $this->block->id)->pluck('id');

        WorkItem::whereIn('id', $ids->take(2))->update(['contractor_id' => $mine->id]);

        foreach (WorkItem::whereIn('id', $ids->take(4))->get() as $item) {
            $this->reportOn($item);
        }

        $rep = User::create([
            'name' => 'Төлөөлөгч',
            'email' => 'rep@cpms.local',
            'password' => Hash::make('x'),
            'role' => 'contractor',
            'contractor_id' => $mine->id,
        ]);

        // Инженер 4 харна, гүйцэтгэгч зөвхөн өөрийн 2-ыг.
        $this->assertSame(
            4,
            $this->actingAs($this->inspector, 'sanctum')
                ->getJson("/api/v1/projects/{$this->project->id}/queue")->json('meta.total')
        );
        $this->assertSame(
            2,
            $this->actingAs($rep, 'sanctum')
                ->getJson("/api/v1/projects/{$this->project->id}/queue")->json('meta.total')
        );
    }

    // -----------------------------------------------------------------
    // Асуудал
    // -----------------------------------------------------------------

    public function test_issue_is_recorded_and_appears_in_the_project_list(): void
    {
        $item = WorkItem::where('block_id', $this->block->id)->firstOrFail();

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/issues", [
                'category' => 'material_shortage',
                'severity' => 'high',
                'description' => 'Гипсэн хавтан ирээгүй.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.categoryLabel', 'Материал дутсан');

        $this->getJson("/api/v1/projects/{$this->project->id}/issues")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'open');
    }

    public function test_unknown_category_is_rejected(): void
    {
        $item = WorkItem::where('block_id', $this->block->id)->firstOrFail();

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/issues", [
                'category' => 'ямар_нэгэн_зүйл',
                'description' => 'Тодорхойгүй',
            ])->assertStatus(422);
    }

    public function test_dashboard_counts_open_issues(): void
    {
        $items = WorkItem::where('block_id', $this->block->id)->take(2)->get();

        $this->actingAs($this->engineer, 'sanctum');

        foreach ($items as $item) {
            $this->postJson("/api/v1/work-items/{$item->id}/issues", [
                'category' => 'weather',
                'description' => 'Бороо',
            ])->assertCreated();
        }

        // Урьд нь энэ тоо хатуу 0 байсан.
        $this->assertSame(
            2,
            $this->getJson("/api/v1/projects/{$this->project->id}/dashboard")->json('data.openIssues')
        );

        $issueId = $this->getJson("/api/v1/projects/{$this->project->id}/issues")->json('data.0.id');
        $this->patchJson("/api/v1/issues/{$issueId}", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->assertSame(
            1,
            $this->getJson("/api/v1/projects/{$this->project->id}/dashboard")->json('data.openIssues')
        );
    }

    // -----------------------------------------------------------------
    // Хянах самбарын тоонууд
    // -----------------------------------------------------------------

    public function test_dashboard_reports_all_three_quantities(): void
    {
        $item = WorkItem::where('block_id', $this->block->id)->firstOrFail();
        $this->reportOn($item, 5);

        $d = $this->actingAs($this->inspector, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/dashboard")
            ->assertOk()
            ->json('data');

        // Гурван тоо ЗААВАЛ энэ дарааллаар. Мэдээлэгдсэн нь батлагдсанаас бага
        // байвал батлагдсан хэмжээ хаанаас ч ирээгүй гэсэн үг — тоо эвдэрсэн.
        $this->assertLessThanOrEqual($d['reportedQty'], $d['acceptedQty']);
        $this->assertLessThanOrEqual($d['plannedQty'], $d['reportedQty']);
        $this->assertSame(5.0, (float) $d['reportedQty']);
        $this->assertSame(0.0, (float) $d['acceptedQty']);

        // Хувь нь БАТЛАГДСАНААР бодогдоно — мэдээлэгдсэнээр биш.
        $this->assertSame(0, $d['percentage']);
    }

    public function test_dashboard_block_totals_sum_to_the_project_total(): void
    {
        foreach (WorkItem::where('block_id', $this->block->id)->take(3)->get() as $item) {
            $this->reportOn($item);
        }

        $d = $this->actingAs($this->inspector, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/dashboard")
            ->json('data');

        // Ерөнхий хувь ба барилга тус бүрийн хувь зөрвөл самбар итгэл алдана.
        $this->assertEqualsWithDelta(
            $d['acceptedQty'],
            array_sum(array_column($d['blocks'], 'acceptedQty')),
            0.01,
        );
        $this->assertSame(
            $d['pendingInspections'],
            array_sum(array_column($d['blocks'], 'pendingInspections')),
        );
    }

    public function test_dashboard_breaks_issues_down_by_category(): void
    {
        $items = WorkItem::where('block_id', $this->block->id)->take(3)->get();

        $this->actingAs($this->engineer, 'sanctum');
        foreach (['material_shortage', 'material_shortage', 'weather'] as $i => $category) {
            $this->postJson("/api/v1/work-items/{$items[$i]->id}/issues", [
                'category' => $category,
                'description' => 'Тест',
            ])->assertCreated();
        }

        $byCategory = $this->getJson("/api/v1/projects/{$this->project->id}/dashboard")
            ->json('data.issuesByCategory');

        // Олноос цөөн рүү эрэмбэлэгдэнэ — хамгийн том шалтгаан эхэнд.
        $this->assertSame('material_shortage', $byCategory[0]['category']);
        $this->assertSame(2, $byCategory[0]['count']);
        $this->assertSame('Материал дутсан', $byCategory[0]['categoryLabel']);
        $this->assertSame(3, array_sum(array_column($byCategory, 'count')));
    }

    public function test_summary_reports_the_planned_end_date(): void
    {
        $summary = $this->actingAs($this->inspector, 'sanctum')
            ->getJson("/api/v1/blocks/{$this->block->id}/summary?groupBy=floor")
            ->assertOk()
            ->json('data');

        $groupDates = array_filter(array_column($summary['groups'], 'plannedEndDate'));
        $this->assertNotEmpty($groupDates);

        // Блокийн огноо нь бүлгүүдийн хамгийн сүүлийнх — "хэзээ дуусах ёстой".
        $this->assertSame(max($groupDates), $summary['totals']['plannedEndDate']);
    }

    public function test_contractor_sees_only_issues_on_their_own_work(): void
    {
        $mine = Contractor::create(['name' => 'Гоо Засал ХХК']);
        $items = WorkItem::where('block_id', $this->block->id)->take(2)->get();
        WorkItem::where('id', $items[0]->id)->update(['contractor_id' => $mine->id]);

        $this->actingAs($this->engineer, 'sanctum');
        foreach ($items as $item) {
            $this->postJson("/api/v1/work-items/{$item->id}/issues", [
                'category' => 'weather',
                'description' => 'Бороо',
            ])->assertCreated();
        }

        $rep = User::create([
            'name' => 'Төлөөлөгч',
            'email' => 'rep2@cpms.local',
            'password' => Hash::make('x'),
            'role' => 'contractor',
            'contractor_id' => $mine->id,
        ]);

        $this->actingAs($rep, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/issues")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
