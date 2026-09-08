<?php

namespace Tests\Feature;

use App\Jobs\ApplyBlockDesign;
use App\Models\BlockDesign;
use App\Models\Company;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Загваргүй (хоосон) блок.
 *
 * ЯАГААД ХЭРЭГТЭЙ: 75 барилгыг бүгдийг нь загвартай үүсгэвэл 75 × 3,290 ≈
 * 247,000 мөр төрнө. Эхэлж байгаа барилгад нь л ажил үүсгэх нь зөв тул
 * "одоо бүртгээд, загварыг дараа буулгах" зам байх ёстой.
 *
 * ХАМГИЙН ЧУХАЛ ТЕСТ: `test_applying_a_design_later_does_not_duplicate_locations`.
 * Байршил хоёр дахин үүсвэл бүх тоо хоёр дахин болж, систем бүхэлдээ худал
 * болно.
 */
class EmptyBlockTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkTypeSeeder::class);

        $company = Company::create(['name' => 'Инэл ХХК']);
        $this->project = $company->projects()->create(['name' => 'Цогцолбор', 'status' => 'active']);
        $this->manager = User::factory()->create(['role' => 'project_manager']);
    }

    /** @return array{0: string, 1: array} */
    private function createEmpty(int $floors = 5, int $units = 4): array
    {
        $data = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/blocks", [
                'name' => 'Хоосон блок',
                'buildingNo' => '77',
                'startDate' => '2026-09-01',
                'floors' => $floors,
                'unitsPerFloor' => $units,
                'purpose' => 'Орон сууц',
            ])
            ->assertCreated()
            ->json('data');

        return [$data['id'], $data];
    }

    // -----------------------------------------------------------------
    // Үүсгэх
    // -----------------------------------------------------------------

    public function test_block_can_be_created_without_a_design(): void
    {
        [$id, $data] = $this->createEmpty();

        $this->assertSame(5, $data['floors']);
        $this->assertSame(20, $data['unitCount']);
        $this->assertNull($data['designId'] ?? null);

        // Ажил үүсэхгүй — энэ нь гол зорилго.
        $this->assertSame(0, WorkItem::where('block_id', $id)->count());
    }

    public function test_locations_are_created_immediately(): void
    {
        [$id] = $this->createEmpty();

        // Байршилгүй бол ажил нэмэх газар байхгүй — блок мухардмал болно.
        // 1 блок + (зоорь + 5 давхар) + 5×4 айл = 27
        $this->assertSame(27, Location::where('block_id', $id)->count());
        $this->assertSame(6, Location::where('block_id', $id)->where('level', 'floor')->count());
        $this->assertSame(20, Location::where('block_id', $id)->where('level', 'unit')->count());
    }

    public function test_floors_are_required_when_no_design_is_chosen(): void
    {
        // Давхрын тоогүй бол байршил үүсгэх мэдээлэлгүй болно.
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/blocks", [
                'name' => 'Мэдээлэлгүй',
                'startDate' => '2026-09-01',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.name', 'ValidationError');
    }

    public function test_design_based_creation_still_works(): void
    {
        $design = BlockDesign::where('floors', 12)->firstOrFail();

        $data = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/blocks", [
                'name' => 'Загвартай блок',
                'buildingNo' => '1',
                'designId' => $design->id,
                'startDate' => '2026-09-01',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame($design->id, $data['designId']);
        $this->assertSame($design->floors, $data['floors']);
    }

    // -----------------------------------------------------------------
    // Дараа нь загвар буулгах
    // -----------------------------------------------------------------

    /**
     * Хамгийн чухал тест.
     *
     * Загваргүй блокт байршил аль хэдийн үүссэн байна. Загвар буулгахад
     * дахин үүсвэл давхар, айл хоёр дахин болж, ажил ч хоёр дахин үүснэ —
     * гүйцэтгэлийн хувь, дүн бүгд худал болно.
     */
    public function test_applying_a_design_later_does_not_duplicate_locations(): void
    {
        [$id] = $this->createEmpty(4, 0);
        $before = Location::where('block_id', $id)->count();

        $design = BlockDesign::where('floors', 12)->firstOrFail();
        (new ApplyBlockDesign($id, $design->id, '2026-09-01'))->handle();

        $this->assertSame($before, Location::where('block_id', $id)->count());
        $this->assertGreaterThan(0, WorkItem::where('block_id', $id)->count());
    }

    public function test_work_is_generated_on_the_existing_locations(): void
    {
        [$id] = $this->createEmpty(4, 0);

        $design = BlockDesign::where('floors', 12)->firstOrFail();
        (new ApplyBlockDesign($id, $design->id, '2026-09-01'))->handle();

        // Ажил бүр ЭНЭ блокийн байршилд холбогдсон байх ёстой.
        $locationIds = Location::where('block_id', $id)->pluck('id');
        $orphans = WorkItem::where('block_id', $id)
            ->whereNotIn('location_id', $locationIds)
            ->count();

        $this->assertSame(0, $orphans, 'Ажил бүр энэ блокийн байршилтай холбогдсон байх ёстой.');
    }

    // -----------------------------------------------------------------
    // Ажлыг гараар нэмэх
    // -----------------------------------------------------------------

    public function test_a_work_type_can_be_added_manually(): void
    {
        [$id] = $this->createEmpty(5, 4);
        $floorType = WorkType::where('level', 'floor')->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$id}/work-items", [
                'workTypeId' => $floorType->id,
                'plannedQty' => 30,
            ])
            ->assertCreated()
            // Давхрын түвшин — зоорь + 5 давхар = 6 мөр.
            ->assertJsonPath('data.created', 6);

        $this->assertSame(6, WorkItem::where('block_id', $id)->count());
    }

    public function test_unit_level_work_lands_on_every_apartment(): void
    {
        [$id] = $this->createEmpty(5, 4);
        $unitType = WorkType::where('level', 'unit')->firstOrFail();

        // Айлын түвшин — 5 давхар × 4 айл = 20 мөр. Гараар 20 удаа дарах
        // шаардлагагүй байх ёстой.
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$id}/work-items", [
                'workTypeId' => $unitType->id,
                'plannedQty' => 8,
            ])
            ->assertCreated()
            ->assertJsonPath('data.created', 20);
    }

    public function test_adding_the_same_work_type_twice_is_blocked(): void
    {
        [$id] = $this->createEmpty();
        $type = WorkType::where('level', 'floor')->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$id}/work-items", ['workTypeId' => $type->id])
            ->assertCreated();

        // Давхардвал тоо хэмжээ давхарлаж, гүйцэтгэлийн хувь худал болно.
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$id}/work-items", ['workTypeId' => $type->id])
            ->assertStatus(409);
    }

    public function test_manually_added_work_accepts_progress(): void
    {
        [$id] = $this->createEmpty();
        $type = WorkType::where('level', 'floor')->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$id}/work-items", [
                'workTypeId' => $type->id,
                'plannedQty' => 30,
            ])->assertCreated();

        // Гараар нэмсэн ажил бүрэн ажиллах ёстой — тоо хэмжээтэй үүссэн тул
        // гүйцэтгэл шууд орно.
        $item = WorkItem::where('block_id', $id)->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => 'site_engineer']), 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 5])
            ->assertCreated();
    }

    public function test_manually_added_work_can_be_removed(): void
    {
        [$id] = $this->createEmpty();
        $type = WorkType::where('level', 'floor')->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$id}/work-items", ['workTypeId' => $type->id])
            ->assertCreated();

        $item = WorkItem::where('block_id', $id)->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/v1/work-items/{$item->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('work_items', ['id' => $item->id]);
    }

    public function test_work_with_progress_cannot_be_removed(): void
    {
        [$id] = $this->createEmpty();
        $type = WorkType::where('level', 'floor')->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$id}/work-items", [
                'workTypeId' => $type->id,
                'plannedQty' => 30,
            ])->assertCreated();

        $item = WorkItem::where('block_id', $id)->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => 'site_engineer']), 'sanctum')
            ->postJson("/api/v1/work-items/{$item->id}/progress", ['completedQty' => 5])
            ->assertCreated();

        // Устгавал гүйцэтгэлийн түүх алга болно.
        $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/v1/work-items/{$item->id}")
            ->assertStatus(409);
    }

    public function test_site_engineer_cannot_add_work(): void
    {
        [$id] = $this->createEmpty();
        $type = WorkType::where('level', 'floor')->firstOrFail();

        // Ажил нэмэх нь төлөвлөгөөний шийдвэр — гүйцэтгэлийн хувь өөрчлөгдөнө.
        $this->actingAs(User::factory()->create(['role' => 'site_engineer']), 'sanctum')
            ->postJson("/api/v1/blocks/{$id}/work-items", ['workTypeId' => $type->id])
            ->assertForbidden();
    }

    public function test_unit_level_work_is_rejected_on_a_block_without_apartments(): void
    {
        [$id] = $this->createEmpty(4, 0);
        $unitType = WorkType::where('level', 'unit')->firstOrFail();

        // Айлгүй үйлчилгээний барилгад айлын ажил нэмэх нь утгагүй.
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/blocks/{$id}/work-items", ['workTypeId' => $unitType->id])
            ->assertStatus(422);
    }
}
