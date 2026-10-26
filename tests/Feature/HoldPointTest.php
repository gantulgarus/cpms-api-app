<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Contractor;
use App\Models\Inspection;
use App\Models\Location;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use App\Services\BlockLocationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Шалгалтын цэг — батлагдаагүй далд ажил дараагийнхаа замыг хаана.
 *
 * ЯАГААД: хоёр шаттай баталгаажуулалт байгаа ч дараагийн ажил зогсдоггүй
 * бол тэр нь хоосон хэлбэр. Гүйцэтгэгч арматураа шалгуулалгүй бетоноо
 * цутгачихвал хяналтын инженер дараа нь юу ч шалгаж чадахгүй.
 *
 * Энэ тестийн ХАГАС нь хориг хэзээ ТАВИГДАХ ЁСГҮЙ гэдгийг шалгана: хэт
 * өргөн хориг нь талбай дээр ажил зогсоож, хүмүүс системийг тойрч гарах
 * арга хайхад хүргэнэ.
 */
class HoldPointTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;

    private WorkType $hidden;     // далд — шалгалтын цэг (давхрын түвшин)

    private WorkType $covering;   // дарж хийгдэх ажил (айлын түвшин)

    private User $reporter;

    private User $siteEngineer;

    private User $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Төсөл', 'status' => 'active']);
        $this->block = $project->blocks()->create([
            'name' => 'А1 блок', 'building_no' => 'A1',
            'floors' => 1, 'units_per_floor' => 2, 'start_date' => '2026-10-01',
        ]);

        app(BlockLocationBuilder::class)->build($this->block, 1, 2);

        // Цахилгаан (build_order 5) → Засал (build_order 7).
        $mep = WorkTypeGroup::create(['name' => 'цахилгаан', 'sequence_number' => 1, 'build_order' => 5]);
        $finish = WorkTypeGroup::create(['name' => 'Засал', 'sequence_number' => 2, 'build_order' => 7]);

        $this->hidden = WorkType::create([
            'name' => 'Цахилгааны ажил', 'work_type_group_id' => $mep->id,
            'level' => 'floor', 'unit' => 'м', 'is_hold_point' => true,
        ]);
        $this->covering = WorkType::create([
            'name' => 'Шавардлага', 'work_type_group_id' => $finish->id,
            'level' => 'unit', 'unit' => 'м2', 'is_hold_point' => false,
        ]);

        $this->reporter = User::factory()->create(['role' => 'site_engineer']);
        $this->siteEngineer = User::factory()->create(['role' => 'site_engineer']);
        $this->inspector = User::factory()->create(['role' => 'inspector']);
    }

    /**
     * ГОЛ ТЕСТ: давхрын далд ажил нь тэр давхрын АЙЛ бүрийг хаана.
     *
     * Цахилгааны шугам нь давхрын түвшинд бүртгэгддэг ч шавардлага нь
     * айлын түвшинд. Зөвхөн ИЖИЛ байршлыг шалгавал энэ хориг огт
     * ажиллахгүй — яг энэ тохиолдол бодит төсөвт байна.
     */
    public function test_an_unapproved_hold_point_blocks_work_in_the_rooms_below_it(): void
    {
        $this->makeHidden(100);
        $unitWork = $this->makeCovering();

        Sanctum::actingAs($this->reporter);

        $response = $this->postJson("/api/v1/work-items/{$unitWork->id}/progress", [
            'completedQty' => 10,
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'Шалгалтын цэг батлагдаагүй',
            $response->json('errors.completedQty.0'),
        );

        // Хаасан ажлын НЭРИЙГ хэлнэ — «хориотой» гэж орхивол хүн яахаа мэдэхгүй.
        $this->assertStringContainsString(
            'Цахилгааны ажил',
            $response->json('errors.completedQty.0'),
        );
    }

    /** Батлагдсаны дараа зам нээгдэнэ. */
    public function test_approving_the_hold_point_unblocks_the_work(): void
    {
        $hidden = $this->makeHidden(100);
        $unitWork = $this->makeCovering();

        $this->fullyApprove($hidden, 100);

        Sanctum::actingAs($this->reporter);
        $this->postJson("/api/v1/work-items/{$unitWork->id}/progress", ['completedQty' => 10])
            ->assertCreated();
    }

    /**
     * ХЭСЭГЧЛЭН дууссан нь хангалтгүй.
     *
     * 100 м-ийн шугамын 60 нь тавигдаж батлагдсан гэдэг нь үлдсэн 40 нь
     * ХИЙГДЭЭГҮЙ гэсэн үг — тэр хэсгийг шавардлагаар дарж болохгүй.
     *
     * Энэ тест `review_state = 'approved'`-ээр шалгахыг хориглоно: 60-ыг
     * батлуулахад тэр төлөв үнэн болно, гэвч ажил дуусаагүй.
     */
    public function test_a_partially_completed_hold_point_still_blocks(): void
    {
        $hidden = $this->makeHidden(100);
        $unitWork = $this->makeCovering();

        $this->fullyApprove($hidden, 60);

        $this->assertSame(
            'approved',
            $hidden->refresh()->review_state,
            'Бэлтгэл: хүлээх юм алга тул төлөв нь `approved` байх ёстой.',
        );
        $this->assertNotSame('completed', $hidden->status, 'Гэвч ажил ДУУСААГҮЙ.');

        Sanctum::actingAs($this->reporter);
        $response = $this->postJson("/api/v1/work-items/{$unitWork->id}/progress", [
            'completedQty' => 10,
        ])->assertStatus(422);

        // Үлдэгдлийг нэрлэнэ — «батлагдаагүй» гэж орхивол маргаан гарна.
        $this->assertStringContainsString('40 м үлдсэн', $response->json('errors.completedQty.0'));
    }

    // -- Хориг ТАВИГДАХ ЁСГҮЙ тохиолдлууд -----------------------------

    /**
     * ӨМНӨХ биш, ДАРААХ ажил хаахгүй.
     *
     * Засал нь цахилгаанаас ХОЙНО хийгдэнэ. Хэрэв дараалал харгалзахгүй
     * бол шинэ шалгалтын цэг нэмэх бүрд тэр бүлгээс ӨМНӨХ бүх ажил ч
     * зогсож, талбай дээр юу ч хийх боломжгүй болно.
     */
    public function test_a_later_hold_point_does_not_block_earlier_work(): void
    {
        // Заслыг шалгалтын цэг болгоно — цахилгаанаас ХОЙНО.
        $this->covering->update(['is_hold_point' => true]);

        $hidden = $this->makeHidden(100);
        $this->makeCovering();

        Sanctum::actingAs($this->reporter);
        $this->postJson("/api/v1/work-items/{$hidden->id}/progress", ['completedQty' => 10])
            ->assertCreated();
    }

    /** Шалгалтын цэг БИШ ажил юу ч хаахгүй. */
    public function test_an_ordinary_unapproved_work_item_blocks_nothing(): void
    {
        $this->hidden->update(['is_hold_point' => false]);

        $this->makeHidden(100);
        $unitWork = $this->makeCovering();

        Sanctum::actingAs($this->reporter);
        $this->postJson("/api/v1/work-items/{$unitWork->id}/progress", ['completedQty' => 10])
            ->assertCreated();
    }

    /**
     * ТОО ХЭМЖЭЭГҮЙ шалгалтын цэг хаахгүй.
     *
     * Төсөвт байхгүй ажлын мөр `planned_qty = 0`-тэй үүсдэг. Тэр нь хэзээ ч
     * батлагдах боломжгүй (гүйцэтгэл оруулах аргагүй) тул хоригт тооцвол
     * блок бүхэлдээ мөнхөд зогсоно.
     */
    public function test_a_hold_point_without_a_quantity_blocks_nothing(): void
    {
        $this->makeHidden(0);
        $unitWork = $this->makeCovering();

        Sanctum::actingAs($this->reporter);
        $this->postJson("/api/v1/work-items/{$unitWork->id}/progress", ['completedQty' => 10])
            ->assertCreated();
    }

    /** ӨӨР давхрын шалгалтын цэг хаахгүй — 2-р давхрын шугам 1-р давхарт хамаагүй. */
    public function test_a_hold_point_on_another_branch_does_not_block(): void
    {
        $first = $this->floorWithUnits();

        // Өөр ДАВХАР — айлтай эсэх нь хамаагүй, зөвхөн салаа нь өөр байх ёстой.
        $other = Location::where('block_id', $this->block->id)
            ->where('level', 'floor')
            ->where('id', '!=', $first->id)
            ->orderBy('sequence_number')
            ->firstOrFail();

        $this->assertNotSame($other->id, $first->id, 'Бэлтгэл: хоёр өөр давхар хэрэгтэй.');

        // Шалгалтын цэг нь ӨӨР давхарт.
        WorkItem::create([
            'block_id' => $this->block->id,
            'location_id' => $other->id,
            'work_type_id' => $this->hidden->id,
            'name' => $this->hidden->name, 'unit' => $this->hidden->unit,
            'planned_qty' => 100, 'status' => 'not_started', 'review_state' => 'none',
        ]);

        $unitWork = $this->makeCovering($first);

        Sanctum::actingAs($this->reporter);
        $this->postJson("/api/v1/work-items/{$unitWork->id}/progress", ['completedQty' => 10])
            ->assertCreated();
    }

    /** Дэлгэц хоригийг УРЬДЧИЛЖ мэдэх ёстой — товч дарж байж 422 авах нь оройтсон. */
    public function test_the_reason_is_visible_before_submitting(): void
    {
        $this->makeHidden(100);
        $unitWork = $this->makeCovering();

        Sanctum::actingAs($this->reporter);

        $reason = $this->getJson("/api/v1/work-items/{$unitWork->id}")
            ->assertOk()
            ->json('data.holdPointReason');

        $this->assertStringContainsString('Цахилгааны ажил', $reason);
    }

    /** Жагсаалтад хориг БОДОГДОХГҮЙ — 200 мөр тутамд query явуулж болохгүй. */
    public function test_the_list_does_not_compute_hold_points(): void
    {
        $this->makeHidden(100);
        $this->makeCovering();

        Sanctum::actingAs($this->reporter);

        $row = $this->getJson("/api/v1/blocks/{$this->block->id}/work-items")
            ->assertOk()
            ->json('data.0');

        $this->assertArrayNotHasKey('holdPointReason', $row);
    }

    public function test_my_work_filters_before_pagination_and_explains_ancestor_blockers(): void
    {
        $hidden = $this->makeHidden(100);
        $covering = $this->makeCovering();
        $this->reporter->forceFill(['scope_block_ids' => [$this->block->id]])->save();
        Sanctum::actingAs($this->reporter);
        $url = "/api/v1/projects/{$this->block->project_id}/my-work";
        $this->getJson($url.'?pageSize=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $hidden->id)
            ->assertJsonPath('data.0.reportableQty', 100)->assertJsonPath('counts.blocked', 1);
        $this->getJson($url.'?reportingState=blocked')->assertOk()
            ->assertJsonPath('data.0.id', $covering->id)->assertJsonPath('data.0.reportableQty', 0)
            ->assertJsonPath('data.0.blockingWorkItems.0.id', $hidden->id);
        $this->fullyApprove($hidden, 100);
        Sanctum::actingAs($this->reporter);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.id', $covering->id)
            ->assertJsonPath('counts.completed', 1)->assertJsonPath('counts.blocked', 0);
    }

    public function test_my_work_pending_missing_quantity_and_scope(): void
    {
        $hidden = $this->makeHidden(100);
        $this->makeCovering()->update(['planned_qty' => 0]);
        $this->reporter->forceFill(['scope_block_ids' => [$this->block->id]])->save();
        Sanctum::actingAs($this->reporter);
        $url = "/api/v1/projects/{$this->block->project_id}/my-work";
        $this->postJson("/api/v1/work-items/{$hidden->id}/progress", ['completedQty' => 100])->assertCreated();
        $this->getJson($url.'?reportingState=pending')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.reportingState', 'pending')->assertJsonPath('counts.blocked', 1);
        $this->getJson($url.'?pageSize=201')->assertStatus(422);
        $this->reporter->forceFill(['scope_block_ids' => [(string) Str::uuid()]])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('counts.blocked', 0);
    }

    public function test_my_work_keeps_contractor_scope_but_sees_foreign_hold_points_without_links(): void
    {
        $own = Contractor::create(['name' => 'Өөрийн компани']);
        $other = Contractor::create(['name' => 'Өөр компани']);
        $hidden = $this->makeHidden(100);
        $hidden->update(['contractor_id' => $other->id]);
        $covering = $this->makeCovering();
        $covering->update(['contractor_id' => $own->id]);
        $rep = User::factory()->create(['role' => 'contractor', 'contractor_id' => $own->id]);
        Sanctum::actingAs($rep);
        $url = "/api/v1/projects/{$this->block->project_id}/my-work";
        $this->getJson($url.'?reportingState=blocked&contractorId='.$other->id)->assertOk()->assertJsonPath('meta.total', 0);
        $response = $this->getJson($url.'?reportingState=blocked')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $covering->id)
            ->assertJsonPath('data.0.blockingWorkItems', [])->assertJsonPath('counts.ready', 0);
        $this->assertStringContainsString($hidden->name, $response->json('data.0.reportingReason'));
    }

    public function test_my_work_location_filter_and_query_count_are_bounded(): void
    {
        $this->makeHidden(100);
        $covering = $this->makeCovering();
        $this->reporter->forceFill(['scope_block_ids' => [$this->block->id]])->save();
        Sanctum::actingAs($this->reporter);
        $url = "/api/v1/projects/{$this->block->project_id}/my-work";
        DB::enableQueryLog();
        $this->getJson($url.'?reportingState=blocked&blockId='.$this->block->id.'&locationId='.$covering->location_id)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('counts.ready', 0);
        $this->assertLessThan(20, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->getJson($url.'?blockId='.(string) Str::uuid())->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_reporting_query_correlates_outer_columns_in_where_for_mysql(): void
    {
        $query = app(\App\Services\ReportableWork::class)->filter(WorkItem::query(), 'ready');
        $sql = $query->toSql();
        // SQLite accepts correlation in JOIN ON, but MySQL raises error 1054.
        $this->assertStringContainsString('cross join "locations" as "target_location"', $sql);
        $this->assertStringContainsString('"target_location"."id" = "work_items"."location_id"', $sql);
        $this->assertStringContainsString('"target_type"."id" = "work_items"."work_type_id"', $sql);
        $subquery = collect($query->getQuery()->wheres)->firstWhere('type', 'NotExists')['query'];
        foreach ($subquery->joins as $join) {
            foreach ($join->wheres as $condition) {
                foreach (['first', 'second'] as $column) {
                    $this->assertStringNotContainsString('work_items.', $condition[$column] ?? '');
                }
            }
        }
    }

    // -----------------------------------------------------------------

    /**
     * АЙЛТАЙ давхрыг сонгоно.
     *
     * Зоорь нь давхрын түвшний байршил ч доороо айлгүй. `sequence_number`-оор
     * эхнийхийг авбал яг түүн дээр таарч, «айл олдсонгүй» гэж уначихна.
     */
    private function floorWithUnits(): Location
    {
        return Location::where('block_id', $this->block->id)
            ->where('level', 'floor')
            ->whereHas('children', fn ($q) => $q->where('level', 'unit'))
            ->orderBy('sequence_number')
            ->firstOrFail();
    }

    private function makeHidden(float $qty): WorkItem
    {
        $floor = $this->floorWithUnits();

        return WorkItem::create([
            'block_id' => $this->block->id,
            'location_id' => $floor->id,
            'work_type_id' => $this->hidden->id,
            'name' => $this->hidden->name, 'unit' => $this->hidden->unit,
            'planned_qty' => $qty, 'status' => 'not_started', 'review_state' => 'none',
        ]);
    }

    private function makeCovering(?Location $floor = null): WorkItem
    {
        $floor ??= $this->floorWithUnits();

        $unit = Location::where('parent_id', $floor->id)->where('level', 'unit')->firstOrFail();

        return WorkItem::create([
            'block_id' => $this->block->id,
            'location_id' => $unit->id,
            'work_type_id' => $this->covering->id,
            'name' => $this->covering->name, 'unit' => $this->covering->unit,
            'planned_qty' => 50, 'status' => 'not_started', 'review_state' => 'none',
        ]);
    }

    /** Хоёр шатыг хоёр өөр хүнээр дамжуулж бүрэн батална. */
    private function fullyApprove(WorkItem $item, float $qty): void
    {
        Sanctum::actingAs($this->reporter);
        $this->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => $qty])
            ->assertCreated();

        Sanctum::actingAs($this->siteEngineer);
        $this->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => Inspection::STAGE_SITE, 'result' => 'accepted', 'acceptedQty' => $qty,
        ])->assertCreated();

        Sanctum::actingAs($this->inspector);
        $this->postJson("/api/v1/work-items/{$item->id}/inspections", [
            'stage' => Inspection::STAGE_FINAL, 'result' => 'accepted', 'acceptedQty' => $qty,
        ])->assertCreated();
    }
}
