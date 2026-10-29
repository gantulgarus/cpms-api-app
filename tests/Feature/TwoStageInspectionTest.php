<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Inspection;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use App\Services\AcceptanceReport;
use App\Services\BlockLocationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Хоёр шаттай баталгаажуулалт: ТГ → талбайн инженер → хяналтын инженер.
 *
 * ЭНЭ ТЕСТИЙН ГОЛ ЗОРИЛГО: шат бүр ӨӨРИЙН сангаас иддэг эсэхийг тогтоох.
 *
 * Шат нь өмнө нь байсан ч хоёулаа `мэдээлсэн − баталсан` гэсэн НЭГ сангаас
 * иддэг байв. Тиймээс талбайн инженер 100 м² шалгахад хяналтын инженерт
 * батлах юу ч үлдэхгүй, актад орох хэмжээ 0 болдог тул шат хасагдсан.
 * Доорх `test_the_two_stages_draw_from_separate_pools` нь тэр регрессийг
 * дахин орохыг хориглоно.
 */
class TwoStageInspectionTest extends TestCase
{
    use RefreshDatabase;

    private WorkItem $item;

    private User $reporter;

    private User $siteEngineer;

    private User $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Төсөл', 'status' => 'active']);
        $block = $project->blocks()->create([
            'name' => 'А1 блок', 'building_no' => 'A1',
            'floors' => 1, 'units_per_floor' => 1, 'start_date' => '2026-10-01',
        ]);

        app(BlockLocationBuilder::class)->build($block, 1, 1);

        $group = WorkTypeGroup::create(['name' => 'Угсралт', 'sequence_number' => 1, 'build_order' => 1]);
        $type = WorkType::create([
            'name' => 'Арматурын ажил', 'work_type_group_id' => $group->id,
            'level' => 'floor', 'unit' => 'тн', 'inspection_stages' => 2,
        ]);

        $this->item = WorkItem::create([
            'block_id' => $block->id,
            'location_id' => Location::where('block_id', $block->id)->where('level', 'floor')->firstOrFail()->id,
            'work_type_id' => $type->id,
            'name' => $type->name, 'unit' => $type->unit,
            'planned_qty' => 100, 'status' => 'not_started', 'review_state' => 'none',
        ]);

        $this->reporter = User::factory()->create(['role' => 'site_engineer']);
        $this->siteEngineer = User::factory()->create(['role' => 'site_engineer'])->refresh();
        $this->inspector = User::factory()->create(['role' => 'inspector']);
        foreach ([$group->id] as $groupId) {
            DB::table('block_site_engineer_assignments')->insert([
                'block_id' => $block->id, 'work_type_group_id' => $groupId,
                'site_engineer_id' => $this->siteEngineer->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * ХАМГИЙН ЧУХАЛ ТЕСТ — шат бүр өөрийн сангаас иднэ.
     */
    public function test_the_two_stages_draw_from_separate_pools(): void
    {
        $this->report(40);

        $this->item->refresh();
        $this->assertSame('pending_check', $this->item->review_state);
        $this->assertSame(40.0, $this->item->stagePool(Inspection::STAGE_SITE));
        $this->assertSame(0.0, $this->item->stagePool(Inspection::STAGE_FINAL));

        // 1-р шат: 40-ийг бүгдийг шалгав.
        $this->inspect($this->siteEngineer, Inspection::STAGE_SITE, 40)->assertCreated();

        $this->item->refresh();
        $this->assertSame('40.000', (string) $this->item->checked_qty);
        $this->assertSame('0.000', (string) $this->item->accepted_qty, 'Шалгасан нь баталсан БИШ.');
        $this->assertSame('pending_approval', $this->item->review_state);

        /*
         * Яг энд хуучин хувилбар уначихдаг байв: `мэдээлсэн − баталсан`
         * гэж бодвол сан нь 40 − 40 = 0 болж, хяналтын инженер юу ч батлаж
         * чадахгүй. Одоо `шалгасан − баталсан` = 40.
         */
        $this->assertSame(40.0, $this->item->stagePool(Inspection::STAGE_FINAL));

        $this->inspect($this->inspector, Inspection::STAGE_FINAL, 40)->assertCreated();

        $this->item->refresh();
        $this->assertSame('40.000', (string) $this->item->accepted_qty);
        $this->assertSame('approved', $this->item->review_state);
        $this->assertSame(40, $this->item->percentage);
    }

    /** Шалгагдаагүй хэмжээг батлах боломжгүй. */
    public function test_the_final_stage_cannot_outrun_the_site_check(): void
    {
        $this->report(40);
        $this->inspect($this->siteEngineer, Inspection::STAGE_SITE, 10)->assertCreated();

        // Шалгасан нь 10 тул 25 батлах боломжгүй.
        $this->inspect($this->inspector, Inspection::STAGE_FINAL, 25)
            ->assertStatus(422)
            ->assertJsonValidationErrors('acceptedQty');

        $this->assertSame('0.000', (string) $this->item->refresh()->accepted_qty);
    }

    /** Шалгаагүй байхад батлах гэвэл шалтгааныг ТОДОРХОЙ хэлнэ. */
    public function test_approving_before_any_check_says_why(): void
    {
        $this->report(40);

        $response = $this->inspect($this->inspector, Inspection::STAGE_FINAL, 10)->assertStatus(422);

        $this->assertStringContainsString(
            'талбайн инженер хараахан шалгаагүй',
            $response->json('errors.acceptedQty.0'),
        );
    }

    /**
     * НЭГ ШАТТАЙ ажил 1-р шатыг АЛГАСНА.
     *
     * Эс бөгөөс `pending_check` төлөвт мөнхөд гацна — тэр шатыг хийх
     * дэлгэц ч байхгүй.
     */
    public function test_a_single_stage_work_type_skips_the_site_check(): void
    {
        $this->item->workType->update(['inspection_stages' => 1]);
        $this->item->load('workType');

        $this->report(40);
        $this->item->refresh()->load('workType');

        $this->assertSame('pending_approval', $this->item->review_state);
        $this->assertSame(40.0, $this->item->stagePool(Inspection::STAGE_FINAL));

        $this->inspect($this->inspector, Inspection::STAGE_FINAL, 40)->assertCreated();

        $this->assertSame('approved', $this->item->refresh()->review_state);
    }

    /** Талбайн инженер 1-р шатыг хийж чадна — `canInspect()` эрхгүй ч. */
    public function test_a_site_engineer_may_perform_the_first_stage(): void
    {
        $this->assertFalse($this->siteEngineer->canInspect(), 'Бэлтгэл: батлах эрхгүй байх ёстой.');

        $this->report(40);
        $this->inspect($this->siteEngineer, Inspection::STAGE_SITE, 40)->assertCreated();
    }

    /** Талбайн инженер 2-р шатыг хийж ЧАДАХГҮЙ. */
    public function test_a_site_engineer_may_not_perform_the_final_stage(): void
    {
        $this->report(40);
        $this->inspect($this->siteEngineer, Inspection::STAGE_SITE, 40)->assertCreated();

        $this->inspect($this->siteEngineer, Inspection::STAGE_FINAL, 40)->assertStatus(403);
    }

    /**
     * НЭГ хүн хоёр шатыг хийж болохгүй.
     *
     * Эс бөгөөс каскад нь зүгээр л хоёр товч болж, «мэдээлэх ба батлах
     * эрхийг салгасан» зарчим утгаа алдана.
     */
    public function test_one_person_cannot_perform_both_stages(): void
    {
        $this->report(40);

        // Ерөнхий инженер хоёр шатыг ч хийх эрхтэй — гэвч НЭГ ажил дээр биш.
        $chief = User::factory()->create(['role' => 'general_engineer']);

        $this->inspect($chief, Inspection::STAGE_SITE, 40)->assertCreated();
        $response = $this->inspect($chief, Inspection::STAGE_FINAL, 40)->assertStatus(403);

        $this->assertStringContainsString('1-р шатад шалгасан', $response->json('message'));
    }

    /** Гүйцэтгэгч ХОЁУЛАНГ Ч хийж чадахгүй. */
    public function test_a_contractor_cannot_inspect_at_all(): void
    {
        $this->report(40);

        $rep = User::factory()->create(['role' => 'contractor']);

        $this->inspect($rep, Inspection::STAGE_SITE, 40)->assertStatus(403);
        $this->inspect($rep, Inspection::STAGE_FINAL, 40)->assertStatus(403);
    }

    /**
     * 1-р шатад буцаавал ТГ рүү эргэнэ, 2-р шатад буцаавал ч мөн адил —
     * гэхдээ ТУСАД НЬ тоологдоно.
     */
    public function test_each_stage_has_its_own_returned_state(): void
    {
        $this->report(40);

        $this->inspect($this->siteEngineer, Inspection::STAGE_SITE, 0, 'rejected', 'Зураасаа барьсангүй.')
            ->assertCreated();

        $this->assertSame('returned_by_site', $this->item->refresh()->review_state);

        // ТГ засвараа мэдээлэхэд дараалалд ЭРГЭЖ ОРНО.
        $this->report(40);
        $this->assertSame('pending_check', $this->item->refresh()->review_state);

        $this->inspect($this->siteEngineer, Inspection::STAGE_SITE, 40)->assertCreated();
        $this->inspect($this->inspector, Inspection::STAGE_FINAL, 0, 'rejected', 'Даац хүрэхгүй.')
            ->assertCreated();

        $this->assertSame('returned_by_final', $this->item->refresh()->review_state);
    }

    /**
     * Акт нь ЗӨВХӨН 2-р шатыг авна.
     *
     * Хольвол баталгаажаагүй ажил актад орж, төлбөр түүн дээр бодогдоно.
     */
    public function test_the_acceptance_report_counts_only_the_final_stage(): void
    {
        $this->report(40);
        $this->inspect($this->siteEngineer, Inspection::STAGE_SITE, 40)->assertCreated();

        $project = Project::firstOrFail();
        $report = app(AcceptanceReport::class);

        $before = $report->build(
            $project, $this->inspector, null,
            now()->subMonth()->toDateString(), now()->toDateString(),
        );
        $this->assertSame(0, $before['inspectionCount'], '1-р шат актад орох ЁСГҮЙ.');

        $this->inspect($this->inspector, Inspection::STAGE_FINAL, 40)->assertCreated();

        $after = $report->build(
            $project, $this->inspector, null,
            now()->subMonth()->toDateString(), now()->toDateString(),
        );
        $this->assertSame(1, $after['inspectionCount']);
    }

    // -----------------------------------------------------------------

    private function report(float $qty): void
    {
        Sanctum::actingAs($this->reporter);

        $this->postJson("/api/v1/work-items/{$this->item->id}/progress", [
            'completedQty' => $qty,
        ])->assertCreated();
    }

    private function inspect(
        User $user,
        string $stage,
        float $qty,
        string $result = 'accepted',
        ?string $reason = null,
    ) {
        Sanctum::actingAs($user);

        return $this->postJson("/api/v1/work-items/{$this->item->id}/inspections", [
            'stage' => $stage,
            'result' => $result,
            'acceptedQty' => $qty,
            'reason' => $reason,
        ]);
    }
}
