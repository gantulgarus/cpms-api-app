<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Гүйцэтгэлийн зургийн баримт.
 *
 * Гол дүрэм: шалгалт хийгдмэгц зураг түгжигдэж, устгах боломжгүй болно
 * (захиалагчийн RULE-10 — батлагдсан нотолгоог дараа нь өөрчлөхгүй).
 *
 * Хоёр дахь дүрэм: зураг нь ажилд биш, тухайн ГҮЙЦЭТГЭЛД хамаарна. 20 удаа
 * мэдээлсэн ажилд 40 зураг нэг овоо болж хэвтвэл хяналтын инженер аль зураг
 * нь алины нотолгоо болохыг ялгаж чадахгүй.
 */
class PhotoTest extends TestCase
{
    use RefreshDatabase;

    private WorkItem $item;
    private User $engineer;
    private User $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Цогцолбор', 'status' => 'active']);
        $block = $project->blocks()->create(['name' => 'А блок', 'floors' => 4, 'units_per_floor' => 2]);

        $location = Location::create([
            'block_id' => $block->id,
            'parent_id' => null,
            'level' => 'block',
            'name' => 'А блок',
            'path' => 'А блок',
            'path_key' => '/root/',
            'sequence_number' => -1,
        ]);

        $group = WorkTypeGroup::create(['name' => 'Угсралт', 'build_order' => 1]);
        $workType = WorkType::create([
            'work_type_group_id' => $group->id,
            'name' => 'Суурь угсралт',
            'unit' => 'м2',
            'level' => 'block',
        ]);

        $this->item = WorkItem::create([
            'block_id' => $block->id,
            'location_id' => $location->id,
            'work_type_id' => $workType->id,
            'name' => 'Суурь угсралт',
            'unit' => 'м2',
            'planned_qty' => 100,
            'planned_start_date' => now()->subDays(20)->toDateString(),
            'planned_end_date' => now()->addDays(20)->toDateString(),
        ]);

        $this->engineer = User::factory()->create(['role' => 'site_engineer']);
        $this->inspector = User::factory()->create(['role' => 'inspector']);
    }

    private function upload(): string
    {
        $response = $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$this->item->id}/photos", [
                'file' => UploadedFile::fake()->image('barilga.jpg', 1200, 900),
                'type' => 'progress',
            ]);

        $response->assertCreated();

        return $response->json('data.id');
    }

    public function test_reporter_can_attach_a_photo(): void
    {
        $id = $this->upload();

        $this->assertDatabaseHas('photos', ['id' => $id, 'work_item_id' => $this->item->id]);

        $this->actingAs($this->engineer, 'sanctum')
            ->getJson("/api/v1/work-items/{$this->item->id}/photos")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'progress')
            ->assertJsonPath('data.0.locked', false);
    }

    // -- Зураг ↔ гүйцэтгэлийн холбоо -------------------------------------

    public function test_a_photo_can_belong_to_a_specific_progress_entry(): void
    {
        $entryId = $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$this->item->id}/progress", ['completedQty' => 40])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$this->item->id}/photos", [
                'file' => UploadedFile::fake()->image('barilga.jpg'),
                'type' => 'progress',
                'progressEntryId' => $entryId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.progressEntryId', $entryId);
    }

    public function test_each_progress_entry_carries_only_its_own_photos(): void
    {
        // Энэ тест байхгүй бол «нэг овоо зураг» гэсэн алдаа чимээгүй эргэж ирнэ.
        $first = $this->reportProgress(40);
        $second = $this->reportProgress(30);

        $this->attachPhoto($first, 'a.jpg');
        $this->attachPhoto($second, 'b.jpg');
        $this->attachPhoto($second, 'c.jpg');

        $entries = collect(
            $this->actingAs($this->engineer, 'sanctum')
                ->getJson("/api/v1/work-items/{$this->item->id}/progress")
                ->assertOk()
                ->json('data')
        )->keyBy('id');

        $this->assertCount(1, $entries[$first]['photos']);
        $this->assertCount(2, $entries[$second]['photos']);
    }

    public function test_a_photo_cannot_be_attached_to_another_work_items_progress(): void
    {
        // Эс бөгөөс өөр барилгын мэдээлэлд зураг залгаж нотолгоо гуйвуулна.
        $other = WorkItem::create([
            'block_id' => $this->item->block_id,
            'location_id' => $this->item->location_id,
            'work_type_id' => $this->item->work_type_id,
            'name' => 'Өөр ажил',
            'unit' => 'м2',
            'planned_qty' => 50,
        ]);

        $foreignEntry = $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$other->id}/progress", ['completedQty' => 10])
            ->json('data.id');

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$this->item->id}/photos", [
                'file' => UploadedFile::fake()->image('a.jpg'),
                'type' => 'progress',
                'progressEntryId' => $foreignEntry,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('progressEntryId');
    }

    public function test_photos_without_an_entry_are_still_allowed(): void
    {
        // Зураг ЗААВАЛ биш: талбайд сүлжээ муу үед ажил зогсох ёсгүй.
        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$this->item->id}/photos", [
                'file' => UploadedFile::fake()->image('a.jpg'),
                'type' => 'progress',
            ])
            ->assertCreated()
            ->assertJsonPath('data.progressEntryId', null);
    }

    private function reportProgress(float $qty): string
    {
        return $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$this->item->id}/progress", ['completedQty' => $qty])
            ->assertCreated()
            ->json('data.id');
    }

    private function attachPhoto(string $entryId, string $name): void
    {
        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$this->item->id}/photos", [
                'file' => UploadedFile::fake()->image($name),
                'type' => 'progress',
                'progressEntryId' => $entryId,
            ])
            ->assertCreated();
    }

    public function test_inspector_cannot_attach_photos(): void
    {
        // Зураг бол гүйцэтгэлийн нотолгоо — хянагч биш, гүйцэтгэгч тавина.
        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$this->item->id}/photos", [
                'file' => UploadedFile::fake()->image('a.jpg'),
                'type' => 'progress',
            ])->assertForbidden();
    }

    public function test_non_image_files_are_rejected(): void
    {
        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$this->item->id}/photos", [
                'file' => UploadedFile::fake()->create('tailan.pdf', 100, 'application/pdf'),
                'type' => 'progress',
            ])->assertStatus(422)->assertJsonValidationErrorFor('file');
    }

    public function test_unapproved_photos_can_be_deleted(): void
    {
        $id = $this->upload();

        $this->actingAs($this->engineer, 'sanctum')
            ->deleteJson("/api/v1/photos/{$id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('photos', ['id' => $id]);
    }

    /** Хамгийн чухал дүрэм: батлагдсан баримтыг өөрчлөх боломжгүй. */
    public function test_inspection_locks_photos_and_blocks_deletion(): void
    {
        $id = $this->upload();

        $this->actingAs($this->engineer, 'sanctum')
            ->postJson("/api/v1/work-items/{$this->item->id}/progress", ['completedQty' => 40])
            ->assertCreated();

        $this->actingAs($this->inspector, 'sanctum')
            ->postJson("/api/v1/work-items/{$this->item->id}/inspections", [
                'stage' => 'client', 'result' => 'accepted', 'acceptedQty' => 40,
            ])->assertCreated();

        $this->assertDatabaseHas('photos', ['id' => $id, 'locked' => true]);

        $this->actingAs($this->engineer, 'sanctum')
            ->deleteJson("/api/v1/photos/{$id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('photos', ['id' => $id]);
    }

    public function test_photo_file_needs_a_valid_signature(): void
    {
        $id = $this->upload();

        $url = $this->actingAs($this->engineer, 'sanctum')
            ->getJson("/api/v1/work-items/{$this->item->id}/photos")
            ->json('data.0.url');

        // Гарын үсэгтэй хаяг ажиллана — `<img src>` толгой дамжуулж чаддаггүй.
        $this->get($url)->assertOk();

        // Гарын үсэггүй бол хаалттай.
        $this->get("/api/v1/photos/{$id}/file")->assertForbidden();
    }
}
