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
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        app(\App\Services\BlockLocationBuilder::class)->build($this->block, 1, 2);

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
