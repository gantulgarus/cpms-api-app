<?php

namespace Tests\Feature;

use App\Jobs\ApplyBlockDesign;
use App\Models\Block;
use App\Models\BlockDesign;
use App\Models\Company;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `cpms-web/scripts/verify-mock.ts` доторх шалгалтуудын Laravel хувилбар.
 *
 * Frontend-ийн mock ба энэ backend ижил зан төлөвтэй байх ёстой — эс бөгөөс
 * mock дээр бүтсэн UI жинхэнэ сервер дээр эвдэрнэ.
 */
class WorkItemFlowTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Block $block;
    private BlockDesign $design;
    private User $engineer;
    private User $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkTypeSeeder::class);

        $company = Company::create(['name' => 'Инэл ХХК']);
        $this->project = $company->projects()->create([
            'name' => '75 барилгын цогцолбор',
            'status' => 'active',
        ]);

        $this->design = BlockDesign::where('floors', 12)->firstOrFail();

        $this->block = $this->project->blocks()->create([
            'block_design_id' => $this->design->id,
            'name' => 'Б блок',
            'building_no' => '19',
            'floors' => $this->design->floors,
            'units_per_floor' => $this->design->units_per_floor,
            'start_date' => '2026-09-01',
        ]);

        // Загварыг синхроноор буулгана (тестэд queue хэрэггүй).
        (new ApplyBlockDesign($this->block->id, $this->design->id, '2026-09-01'))->handle();

        // Мэдээлэх ба батлах эрх салсан тул хоёр хэрэглэгч хэрэгтэй.
        $this->engineer = User::factory()->create(['role' => 'site_engineer']);
        $this->inspector = User::factory()->create(['role' => 'inspector']);

        $this->actingAs(User::factory()->create(['role' => 'director']), 'sanctum');
    }

    /** Гүйцэтгэл мэдээлэхэд талбайн инженерээр ажиллана. */
    private function asEngineer(): self
    {
        $this->actingAs($this->engineer, 'sanctum');

        return $this;
    }

    /** Баталгаажуулахад хяналтын инженерээр ажиллана. */
    private function asInspector(): self
    {
        $this->actingAs($this->inspector, 'sanctum');

        return $this;
    }

    public function test_design_generates_expected_number_of_work_items(): void
    {
        $this->assertSame(
            $this->design->estimatedItems(),
            $this->block->workItems()->count(),
            'Загварын тооцоо бодит үүссэн тоотой таарах ёстой.'
        );
    }

    public function test_list_is_paginated_and_reports_true_total(): void
    {
        $response = $this->getJson("/api/v1/blocks/{$this->block->id}/work-items?pageSize=50");

        $response->assertOk()->assertJsonCount(50, 'data');
        $this->assertSame($this->block->workItems()->count(), $response->json('meta.total'));
    }

    public function test_page_size_is_capped_at_200(): void
    {
        $this->getJson("/api/v1/blocks/{$this->block->id}/work-items?pageSize=9999")
            ->assertOk()
            ->assertJsonCount(200, 'data');
    }

    public function test_descendant_filter_includes_units_under_a_floor(): void
    {
        $floor = Location::where('block_id', $this->block->id)
            ->where('level', 'floor')->where('sequence_number', 5)->firstOrFail();

        $own = $this->getJson("/api/v1/blocks/{$this->block->id}/work-items?locationId={$floor->id}&pageSize=1");
        $withUnits = $this->getJson(
            "/api/v1/blocks/{$this->block->id}/work-items?locationId={$floor->id}&includeDescendants=true&pageSize=1"
        );

        $this->assertGreaterThan(
            $own->json('meta.total'),
            $withUnits->json('meta.total'),
            'Айлын ажлууд эцэг давхарт хамрагдах ёстой.'
        );
    }

    /**
     * Хамгийн чухал регресс: нэгтгэлд харуулсан тоо, тэр бүлгийг дарахад
     * гарах тоо ижил байх ёстой. Блокийн үндэс зангилаа бүх зүйлийн эцэг тул
     * `includeDescendants` буруу хэрэглэвэл 11-ийн оронд бүх мөр гарч ирдэг.
     */
    public function test_every_summary_group_filter_reproduces_its_own_count(): void
    {
        foreach (['floor', 'workType', 'workTypeGroup', 'contractor'] as $groupBy) {
            $summary = $this->getJson("/api/v1/blocks/{$this->block->id}/summary?groupBy={$groupBy}")
                ->assertOk()->json('data');

            foreach ($summary['groups'] as $group) {
                $query = http_build_query($group['filter'] + ['pageSize' => 1]);
                $actual = $this->getJson("/api/v1/blocks/{$this->block->id}/work-items?{$query}")
                    ->json('meta.total');

                $this->assertSame(
                    $group['workItems'],
                    $actual,
                    "[{$groupBy}] «{$group['label']}» бүлгийн шүүлтүүр буруу байна."
                );
            }
        }
    }

    public function test_summary_totals_match_the_sum_of_groups(): void
    {
        $summary = $this->getJson("/api/v1/blocks/{$this->block->id}/summary")->json('data');

        $this->assertSame(
            $this->block->workItems()->count(),
            array_sum(array_column($summary['groups'], 'workItems'))
        );
        $this->assertSame($summary['totals']['workItems'], $this->block->workItems()->count());
    }

    public function test_progress_cannot_exceed_remaining_quantity(): void
    {
        $item = $this->workItemWithQuantity();

        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 999999])
            ->assertStatus(422)->assertJsonValidationErrorFor('completedQty');

        $this->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => -5])
            ->assertStatus(422);
    }

    public function test_reporting_progress_moves_item_to_pending(): void
    {
        $item = $this->workItemWithQuantity();

        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 10])
            ->assertCreated();

        $item->refresh();
        $this->assertEqualsWithDelta(10, (float) $item->reported_qty, 0.001);
        $this->assertSame('pending', $item->review_state);
        // Үлдэгдэл нь БАТЛАГДСАНААС бодогдоно — мэдээлсэн нь хараахан тоологдохгүй.
        $this->assertEqualsWithDelta((float) $item->planned_qty, $item->remaining_qty, 0.001);
    }

    public function test_accepting_an_inspection_reduces_remaining(): void
    {
        $item = $this->workItemWithQuantity();
        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 10])->assertCreated();

        $this->asInspector()->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => 'client', 'result' => 'accepted', 'acceptedQty' => 10,
        ])->assertCreated();

        $item->refresh();
        $this->assertSame('approved', $item->review_state);
        $this->assertEqualsWithDelta((float) $item->planned_qty - 10, $item->remaining_qty, 0.001);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $item = $this->workItemWithQuantity();
        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 10])->assertCreated();

        $this->asInspector()->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => 'client', 'result' => 'rejected', 'acceptedQty' => 0,
        ])->assertStatus(422)->assertJsonValidationErrorFor('reason');

        $this->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => 'client', 'result' => 'rejected', 'acceptedQty' => 0,
            'reason' => 'Гадаргуугийн тэгш байдал зөрсөн',
        ])->assertCreated();

        $this->assertSame('returned', $item->refresh()->review_state);
    }

    /**
     * ХАМГИЙН ЧУХАЛ ТЕСТ: буцаагдсан ажлыг ДАХИН ИЛГЭЭХ бүрэн гогцоо.
     *
     * Хоёр алдааг зэрэг хамгаална:
     *   1. Татгалзсан хэмжээ мэдээлсэн дүнгээс хасагдахгүй бол «үлдэгдэл 0»
     *      болж, гүйцэтгэгч засвараа ОГТ илгээж чадахгүй болно.
     *   2. Буцаагдсан төлөв цэвэрлэгдэхгүй бол дахин илгээсэн ажил хяналтын
     *      инженерийн дараалалд ХЭЗЭЭ Ч гарч ирэхгүй.
     */
    public function test_a_rejected_item_can_be_redone_and_resubmitted_on_the_same_row(): void
    {
        $item = $this->workItemWithQuantity();
        $item->update(['planned_qty' => 100]);

        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 100])
            ->assertCreated();

        $this->asInspector()->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => 'client', 'result' => 'rejected', 'acceptedQty' => 0,
            'reason' => 'Дахин хийнэ',
        ])->assertCreated();

        $item->refresh();
        $this->assertSame('returned', $item->review_state);
        $this->assertEqualsWithDelta(0, (float) $item->reported_qty, 0.001,
            'Татгалзсан хэмжээ мэдээлсэн дүнгээс хасагдах ёстой.');
        $this->assertEqualsWithDelta(100, $item->remaining_qty, 0.001,
            'Үлдэгдэл эргэж бүтэн болох ёстой.');

        // Засвараа ЯГ ЭНЭ мөрөн дээр дахин илгээнэ.
        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 100])
            ->assertCreated();

        $this->assertSame('pending', $item->refresh()->review_state);

        $this->asInspector()->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => 'client', 'result' => 'accepted', 'acceptedQty' => 100,
        ])->assertCreated();

        $item->refresh();
        $this->assertSame('completed', $item->status);
        $this->assertSame(100, $item->percentage);
    }

    public function test_a_rejection_does_not_create_a_duplicate_work_item(): void
    {
        // Тусдаа «дахин хийх» мөр үүсгэвэл 100 м²-ийн ажил 200 м² болж
        // харагдаж, блокийн гүйцэтгэлийн хувь худал болно.
        $item = $this->workItemWithQuantity();
        $before = $this->block->workItems()->count();

        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 10])
            ->assertCreated();
        $this->asInspector()->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => 'client', 'result' => 'rejected', 'acceptedQty' => 0,
            'reason' => 'Дахин хийнэ',
        ])->assertCreated();

        $this->assertSame($before, $this->block->workItems()->count());
    }

    public function test_a_partial_acceptance_returns_only_the_rejected_amount(): void
    {
        // 100-аас 60-ыг батлав → 40 нь буцаж үлдэгдэл болно.
        $item = $this->workItemWithQuantity();
        $item->update(['planned_qty' => 100]);

        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 100])
            ->assertCreated();

        $this->asInspector()->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => 'client', 'result' => 'partial', 'acceptedQty' => 60,
            'reason' => 'Хэсэгчлэн хүлээж авав',
        ])->assertCreated();

        $item->refresh();
        $this->assertEqualsWithDelta(60, (float) $item->reported_qty, 0.001);
        $this->assertEqualsWithDelta(60, (float) $item->accepted_qty, 0.001);
        $this->assertEqualsWithDelta(40, $item->remaining_qty, 0.001);
        $this->assertSame('returned', $item->review_state);
    }

    public function test_a_partial_acceptance_leaves_the_rest_pending_not_rejected(): void
    {
        // «Батлав» гэж 40-өөс 10-ыг батлахад үлдсэн 30 нь ТАТГАЛЗСАН биш,
        // зүгээр л хараахан хянагдаагүй. Татгалзсан гэж тэмдэглэвэл
        // гүйцэтгэгчийн хийсэн ажил үгүй болно.
        $item = $this->workItemWithQuantity();
        $item->update(['planned_qty' => 200]);

        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 40])
            ->assertCreated();

        $response = $this->asInspector()->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => 'client', 'result' => 'accepted', 'acceptedQty' => 10,
        ])->assertCreated();

        $item->refresh();
        $this->assertEqualsWithDelta(0, (float) $response->json('data.rejectedQty'), 0.001);
        $this->assertEqualsWithDelta(40, (float) $item->reported_qty, 0.001);
        $this->assertSame('pending', $item->review_state);
    }

    public function test_the_total_redone_quantity_is_visible_on_the_work_item(): void
    {
        // Дахин хийлтийн зардлыг хэмжих зам — тусдаа мөр үүсгэхгүйгээр.
        $item = $this->workItemWithQuantity();
        $item->update(['planned_qty' => 100]);

        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 100])
            ->assertCreated();
        $this->asInspector()->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => 'client', 'result' => 'rejected', 'acceptedQty' => 0,
            'reason' => 'Дахин хийнэ',
        ])->assertCreated();

        $this->asEngineer()
            ->getJson("/api/v1/work-items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.rejectedTotal', 100.0);
    }

    /**
     * Хуучин өгөгдлийг засах зам ажиллаж байгаа эсэх.
     *
     * Дүрэм өөрчлөгдөхөөс өмнө татгалзуулсан мөрүүд хуучин `reported_qty`-тэй
     * үлддэг тул «үлдэгдэл 0» болж, гүйцэтгэгч дахин илгээж чадахгүй. Кодын
     * засвар өгөгдлийг өөрөө засдаггүй.
     */
    public function test_recalculate_command_repairs_stale_totals(): void
    {
        $item = $this->workItemWithQuantity();
        $item->update(['planned_qty' => 100]);

        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 100])
            ->assertCreated();
        $this->asInspector()->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => 'client', 'result' => 'rejected', 'acceptedQty' => 0,
            'reason' => 'Дахин хийнэ',
        ])->assertCreated();

        // Хуучин дүрмээр хадгалагдсан байдлыг дуурайлгана.
        $item->forceFill(['reported_qty' => 100, 'review_state' => 'returned'])->saveQuietly();
        $this->assertEqualsWithDelta(0, $item->fresh()->remaining_qty, 0.001);

        $this->artisan('cpms:recalculate')->assertSuccessful();

        $item->refresh();
        $this->assertEqualsWithDelta(0, (float) $item->reported_qty, 0.001);
        $this->assertEqualsWithDelta(100, $item->remaining_qty, 0.001);

        // Засагдсаны дараа дахин илгээх боломжтой болно.
        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 100])
            ->assertCreated();
    }

    // -- Хугацаа сунгах --------------------------------------------------

    /**
     * Хугацаа сунгахад ШАЛТГААН заавал.
     *
     * Огноог чимээгүй хойшлуулж болдог бол «хугацаа хэтэрсэн» гэсэн тоо
     * утгагүй болно — хэн ч хэзээ ч хоцрохгүй.
     */
    public function test_extending_a_deadline_requires_a_reason(): void
    {
        $item = $this->workItemWithQuantity();
        $item->update(['planned_end_date' => now()->subDays(5)->toDateString()]);

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/extend", [
                'plannedEndDate' => now()->addDays(7)->toDateString(),
            ])
            ->assertStatus(422);

        $this->asInspector()
            ->postJson("/api/v1/work-items/{$item->id}/extend", [
                'plannedEndDate' => now()->addDays(7)->toDateString(),
                'category' => 'weather',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('reason');
    }

    public function test_extending_records_the_reason_as_an_issue(): void
    {
        $item = $this->workItemWithQuantity();
        $old = now()->subDays(5)->toDateString();
        $item->update(['planned_end_date' => $old]);

        $new = now()->addDays(10)->toDateString();

        $this->asInspector()
            ->postJson("/api/v1/work-items/{$item->id}/extend", [
                'plannedEndDate' => $new,
                'category' => 'material_shortage',
                'reason' => 'Цонхны хүргэлт хоцорсон.',
            ])
            ->assertOk();

        $this->assertSame($new, $item->fresh()->planned_end_date->toDateString());
        $this->assertSame(0, $item->fresh()->overdue_days, 'Сунгасны дараа хоцролт үлдэх ёсгүй.');

        $issue = $item->issues()->latest('created_at')->firstOrFail();
        $this->assertSame('material_shortage', $issue->category);
        // Хуучин огноо тайлбарт үлдэх ёстой — хэдэн хоногоор сунгасныг хожим
        // тоолох боломжтой байх ёстой.
        $this->assertStringContainsString($old, $issue->description);
        $this->assertStringContainsString($new, $issue->description);
        $this->assertStringContainsString('Цонхны хүргэлт', $issue->description);
    }

    public function test_a_deadline_cannot_be_pulled_earlier(): void
    {
        // Огноог урагш татах нь сунгах биш — хоцролтыг хиймлээр үүсгэнэ.
        $item = $this->workItemWithQuantity();
        $item->update(['planned_end_date' => now()->addDays(10)->toDateString()]);

        $this->asInspector()
            ->postJson("/api/v1/work-items/{$item->id}/extend", [
                'plannedEndDate' => now()->addDays(2)->toDateString(),
                'category' => 'weather',
                'reason' => 'Урагшлуулах гэсэн',
            ])
            ->assertStatus(422);
    }

    public function test_a_contractor_cannot_extend_a_deadline(): void
    {
        $item = $this->workItemWithQuantity();
        $rep = User::factory()->create(['role' => 'contractor']);

        $this->actingAs($rep, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/extend", [
                'plannedEndDate' => now()->addDays(7)->toDateString(),
                'category' => 'weather',
                'reason' => 'Цас орсон',
            ])
            ->assertForbidden();
    }

    public function test_progress_history_is_newest_first(): void
    {
        $item = $this->workItemWithQuantity();

        // Огноо өнгөрсөнд байх ёстой — ирээдүйд гүйцэтгэл мэдээлэх нь утгагүй
        // тул `before_or_equal:now` дүрэм зориудаар тавигдсан.
        $this->asEngineer()->postJson("/api/v1/work-items/{$item->id}/progress",
            ['completedQty' => 5, 'recordedAt' => now()->subDays(10)->toIso8601String()])->assertCreated();
        $this->postJson("/api/v1/work-items/{$item->id}/progress",
            ['completedQty' => 5, 'recordedAt' => now()->subDays(2)->toIso8601String()])->assertCreated();

        $dates = $this->getJson("/api/v1/work-items/{$item->id}/progress")->json('data.*.recordedAt');

        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates, 'Түүх шинэ→хуучин дараалалтай байх ёстой.');
    }

    public function test_blocks_do_not_leak_into_each_other(): void
    {
        $other = $this->project->blocks()->create([
            'block_design_id' => $this->design->id,
            'name' => 'В блок',
            'floors' => $this->design->floors,
            'units_per_floor' => $this->design->units_per_floor,
            'start_date' => '2026-10-01',
        ]);
        (new ApplyBlockDesign($other->id, $this->design->id, '2026-10-01'))->handle();

        $first = $this->getJson("/api/v1/blocks/{$this->block->id}/work-items?pageSize=1")->json('meta.total');
        $second = $this->getJson("/api/v1/blocks/{$other->id}/work-items?pageSize=1")->json('meta.total');

        $this->assertSame($this->design->estimatedItems(), $first);
        $this->assertSame($this->design->estimatedItems(), $second);
    }

    public function test_applying_a_design_twice_is_rejected(): void
    {
        $this->postJson("/api/v1/block-designs/{$this->design->id}/apply", [
            'blockId' => $this->block->id,
        ])->assertStatus(409);
    }


    // -----------------------------------------------------------------
    // Мэдээлэх ба батлах эрхийн зааг
    // -----------------------------------------------------------------

    public function test_only_reporter_roles_may_submit_progress(): void
    {
        $item = $this->workItemWithQuantity();

        // Захирал ба хяналтын инженер гүйцэтгэл мэдээлэхгүй — тэд батална.
        foreach (['director', 'inspector'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum')
                ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 5])
                ->assertForbidden();
        }

        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 5])
            ->assertCreated();
    }

    public function test_only_inspector_roles_may_approve(): void
    {
        $item = $this->workItemWithQuantity();
        $this->asEngineer()
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 5])->assertCreated();

        // Туслан гүйцэтгэгч өөрийгөө баталж чадахгүй.
        $this->actingAs(User::factory()->create(['role' => 'contractor']), 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'client', 'result' => 'accepted', 'acceptedQty' => 5,
            ])->assertForbidden();

        $this->asInspector()->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => 'client', 'result' => 'accepted', 'acceptedQty' => 5,
        ])->assertCreated();
    }

    /**
     * Хамгийн чухал бүрэн бүтэн байдлын дүрэм: өөрийн мэдээлсэн ажлыг өөрөө
     * батлах боломжгүй. v1-д яг энэ нүх байсан.
     */
    public function test_nobody_can_approve_their_own_report(): void
    {
        $item = $this->workItemWithQuantity();

        // Ерөнхий инженер хоёр эрхтэй — мэдээлэх ба батлах. Гэхдээ өөрийнхөө
        // мэдээлсэнийг батлахыг хориглоно.
        $dual = User::factory()->create(['role' => 'project_manager']);
        $this->actingAs($dual, 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 5])
            ->assertCreated();

        $dual->update(['role' => 'general_engineer']);

        $this->actingAs($dual->refresh(), 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/inspections", [
                'stage' => 'general_contractor', 'result' => 'accepted', 'acceptedQty' => 5,
            ])->assertForbidden();

        // Өөр хүн бол асуудалгүй.
        $this->asInspector()->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => 'general_contractor', 'result' => 'accepted', 'acceptedQty' => 5,
        ])->assertCreated();
    }

    private function workItemWithQuantity(): WorkItem
    {
        return $this->block->workItems()->where('planned_qty', '>', 20)->firstOrFail();
    }
}
