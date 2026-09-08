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
use Tests\TestCase;

/**
 * Гүйцэтгэгч кодоор нэвтрэхэд ЗӨВХӨН өөрийн ажил харагдана.
 *
 * Энэ бол зүгээр л дэлгэцийн шүүлтүүр биш, гэрээний асуудал: "Гоо Засал ХХК"
 * нь "Сантехник ХХК"-ийн тоо хэмжээ, гүйцэтгэлийн хувийг харах эрхгүй.
 *
 * Хамгийн чухал шалгалт нь `summary_and_list_agree` — нэгтгэл ба жагсаалт
 * ижил хамрах хүрээгээр бодогдох ёстой. Тэдгээр нь өөр код замаар (нэг нь
 * GROUP BY, нөгөө нь Eloquent) явдаг тул зөрөх нь хамгийн магадлалтай алдаа.
 */
class ContractorScopeTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;
    private Block $otherBlock;
    private Contractor $mine;
    private Contractor $theirs;
    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkTypeSeeder::class);

        $company = Company::create(['name' => 'Инэл ХХК']);
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

        // Гүйцэтгэгч оролцдоггүй хоёр дахь барилга.
        $this->otherBlock = $project->blocks()->create([
            'block_design_id' => $design->id,
            'name' => 'Б блок',
            'building_no' => '2',
            'floors' => $design->floors,
            'units_per_floor' => $design->units_per_floor,
            'start_date' => '2026-09-01',
        ]);
        (new ApplyBlockDesign($this->otherBlock->id, $design->id, '2026-09-01'))->handle();

        $this->mine = Contractor::create(['name' => 'Гоо Засал ХХК']);
        $this->theirs = Contractor::create(['name' => 'Сантехник ХХК']);

        // А блокийн ажлыг хоёр гүйцэтгэгчийн хооронд хуваана.
        $ids = WorkItem::where('block_id', $this->block->id)->pluck('id');
        WorkItem::whereIn('id', $ids->take(40))->update(['contractor_id' => $this->mine->id]);
        WorkItem::whereIn('id', $ids->slice(40, 60))->update(['contractor_id' => $this->theirs->id]);

        $this->rep = User::create([
            'name' => 'Гоо Засал — талбайн ахлагч',
            'email' => 'goo@cpms.local',
            'password' => bcrypt('x'),
            'role' => 'contractor',
            'contractor_id' => $this->mine->id,
        ]);
    }

    private function asRep(): self
    {
        $this->actingAs($this->rep, 'sanctum');

        return $this;
    }

    private function myItem(): WorkItem
    {
        return WorkItem::where('contractor_id', $this->mine->id)->firstOrFail();
    }

    private function theirItem(): WorkItem
    {
        return WorkItem::where('contractor_id', $this->theirs->id)->firstOrFail();
    }

    // -----------------------------------------------------------------
    // Харах хүрээ
    // -----------------------------------------------------------------

    public function test_list_shows_only_own_work_items(): void
    {
        $response = $this->asRep()
            ->getJson("/api/v1/blocks/{$this->block->id}/work-items?pageSize=200")
            ->assertOk();

        $this->assertSame(40, $response->json('meta.total'));

        foreach ($response->json('data') as $item) {
            $this->assertSame($this->mine->id, $item['contractor']['id'] ?? null);
        }
    }

    public function test_contractor_cannot_widen_the_scope_with_a_filter(): void
    {
        // Хэрэглэгч өөр компанийн id-г шүүлтүүрээр илгээх нь хамгийн хялбар
        // халдлага. Хамрах хүрээ шүүлтүүрээс ХҮЧТЭЙ байх ёстой.
        $this->asRep()
            ->getJson("/api/v1/blocks/{$this->block->id}/work-items?contractorId={$this->theirs->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_summary_and_list_agree(): void
    {
        $summary = $this->asRep()
            ->getJson("/api/v1/blocks/{$this->block->id}/summary?groupBy=floor")
            ->assertOk();

        $listTotal = $this->asRep()
            ->getJson("/api/v1/blocks/{$this->block->id}/work-items")
            ->json('meta.total');

        $this->assertSame(
            $listTotal,
            $summary->json('data.totals.workItems'),
            'Нэгтгэлийн тоо жагсаалтын тоотой таарах ёстой — эс бөгөөс бүлэг дарахад өөр тоо гарна.'
        );

        // Бүлэг тус бүр дээр дарахад гарах тоо нь бүлгийн бичсэн тоотой таарна.
        foreach ($summary->json('data.groups') as $group) {
            $query = http_build_query($group['filter']);
            $actual = $this->asRep()
                ->getJson("/api/v1/blocks/{$this->block->id}/work-items?{$query}")
                ->json('meta.total');

            $this->assertSame(
                $group['workItems'],
                $actual,
                "«{$group['label']}» бүлгийн тоо шүүлтүүрийн үр дүнтэй таарахгүй байна."
            );
        }
    }

    public function test_block_list_hides_blocks_the_contractor_has_no_work_in(): void
    {
        $project = $this->block->project_id;

        $names = collect($this->asRep()->getJson("/api/v1/projects/{$project}/blocks")->assertOk()->json('data'))
            ->pluck('name');

        $this->assertContains('А блок', $names);
        $this->assertNotContains('Б блок', $names);
    }

    public function test_dashboard_percentage_covers_only_own_work(): void
    {
        $data = $this->asRep()
            ->getJson("/api/v1/projects/{$this->block->project_id}/dashboard")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data['blocks'], 'Зөвхөн ажил байгаа барилга орно.');
        $this->assertSame('А блок', $data['blocks'][0]['name']);
    }

    public function test_cannot_open_another_contractors_work_item(): void
    {
        $this->asRep()
            ->getJson("/api/v1/work-items/{$this->theirItem()->id}")
            ->assertForbidden();
    }

    public function test_cannot_open_a_block_without_own_work(): void
    {
        $this->asRep()
            ->getJson("/api/v1/blocks/{$this->otherBlock->id}/work-items")
            ->assertForbidden();
    }

    public function test_unassigned_work_item_is_not_visible(): void
    {
        // Хариуцагч оноогоогүй ажил "хэнийх ч биш" — гүйцэтгэгч түүнийг
        // өөрийнхөө гэж авах ёсгүй.
        $orphan = WorkItem::where('block_id', $this->block->id)
            ->whereNull('contractor_id')->firstOrFail();

        $this->asRep()->getJson("/api/v1/work-items/{$orphan->id}")->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Гүйцэтгэл оруулах
    // -----------------------------------------------------------------

    public function test_contractor_can_report_progress_on_own_work_item(): void
    {
        $item = $this->myItem();

        $this->asRep()
            ->postJson("/api/v1/work-items/{$item->id}/progress", [
                'completedQty' => 5,
                'workersCount' => 4,
                'remarks' => 'Эхний хэсэг',
            ])
            ->assertCreated()
            ->assertJsonPath('data.completedQty', 5);

        $item->refresh();

        $this->assertSame(5.0, (float) $item->reported_qty);
        // Мэдээлсэн нь батлагдсан гэсэн үг БИШ — үлдэгдэл хөдлөхгүй.
        $this->assertSame(0.0, (float) $item->accepted_qty);
        $this->assertSame('pending', $item->review_state);
    }

    public function test_contractor_cannot_report_on_another_contractors_work(): void
    {
        $this->asRep()
            ->postJson("/api/v1/work-items/{$this->theirItem()->id}/progress", ['completedQty' => 5])
            ->assertForbidden();

        $this->assertSame(0.0, (float) $this->theirItem()->reported_qty);
    }

    public function test_contractor_cannot_approve_own_report(): void
    {
        $item = $this->myItem();

        $this->asRep()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 5])
            ->assertCreated();

        // Гомдлын гол шалтгаан: v1-д гүйцэтгэгч өөрийгөө баталж чаддаг байв.
        $this->asRep()
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client',
                'result' => 'accepted',
                'acceptedQty' => 5,
            ])
            ->assertForbidden();
    }

    public function test_contractor_cannot_issue_access_codes(): void
    {
        $this->asRep()
            ->postJson("/api/v1/contractors/{$this->mine->id}/access-code")
            ->assertForbidden();
    }

    /**
     * Байсан нүхний тухайлсан шалгалт.
     *
     * `canSeeAllBlocks()` нь "scope_block_ids хоосон = хязгаарлалтгүй" гэж
     * уншдаг байв. Гүйцэтгэгчийн тэр талбар үргэлж хоосон тул тэд БҮХ
     * барилгын БҮХ ажлыг хардаг байсан. Дүрэм өөрчлөгдвөл энэ мөр унана.
     */
    public function test_empty_block_scope_is_not_unrestricted_for_a_contractor(): void
    {
        $this->assertSame([], $this->rep->scope_block_ids ?? []);
        $this->assertTrue($this->rep->isContractorRep());
        $this->assertFalse(
            $this->rep->canSeeAllBlocks(),
            'Гүйцэтгэгчийн хамрах хүрээ хоосон байсан ч "бүх блок" гэсэн үг биш.'
        );
    }

    public function test_me_reports_the_right_permissions(): void
    {
        $this->asRep()->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.contractorId', $this->mine->id)
            ->assertJsonPath('data.canReportProgress', true)
            ->assertJsonPath('data.canInspect', false)
            ->assertJsonPath('data.canManageContractors', false);
    }
}
