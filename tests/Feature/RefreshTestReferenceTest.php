<?php

namespace Tests\Feature;

use App\Models\BlockDesign;
use App\Models\Company;
use App\Models\Photo;
use App\Models\User;
use App\Models\WorkCatalogEntry;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Services\BlockLocationBuilder;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RefreshTestReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_then_refresh_cleans_test_buildings_and_keeps_accounts_and_projects(): void
    {
        Storage::fake('local');
        $this->seed(WorkTypeSeeder::class);
        $user = User::factory()->create(['role' => 'admin']);
        $project = Company::create(['name' => 'Тест'])->projects()->create(['name' => 'Тест', 'status' => 'active']);
        $block = $project->blocks()->create(['name' => 'Хуучин барилга', 'floors' => 1, 'units_per_floor' => 1, 'start_date' => '2026-11-17']);
        app(BlockLocationBuilder::class)->build($block, 1, 1);
        $type = WorkType::firstOrFail();
        $legacyIds = WorkType::pluck('id');
        $work = WorkItem::create(['block_id' => $block->id, 'work_type_id' => $type->id,
            'location_id' => $block->locations()->where('level', 'block')->firstOrFail()->id,
            'name' => $type->name, 'unit' => $type->unit, 'planned_qty' => 10]);
        Storage::disk('local')->put('photos/test.jpg', 'test');
        Photo::create(['work_item_id' => $work->id, 'type' => 'progress', 'path' => 'photos/test.jpg', 'uploaded_by_id' => $user->id]);

        $this->artisan('cpms:refresh-test-reference')->assertSuccessful();
        $this->assertNotNull($block->fresh());
        $this->assertSame(0, WorkCatalogEntry::count());
        $this->artisan('cpms:refresh-test-reference --execute')->assertSuccessful();
        $this->assertNull($block->fresh());
        $this->assertSame(0, WorkItem::count());
        $this->assertSame(0, WorkType::whereIn('id', $legacyIds)->count());
        $this->assertSame(293, WorkCatalogEntry::count());
        $this->assertNotNull($user->fresh());
        $this->assertNotNull($project->fresh());
        $this->assertGreaterThan(0, BlockDesign::firstOrFail()->items()->count());
        Storage::disk('local')->assertMissing('photos/test.jpg');
        $this->artisan('cpms:refresh-test-reference --execute')->assertSuccessful();
        $this->assertSame(293, WorkCatalogEntry::count());
    }

    public function test_failed_cleanup_rolls_back_the_import_and_building_deletion(): void
    {
        $this->seed(WorkTypeSeeder::class);
        $project = Company::create(['name' => 'Тест'])->projects()->create(['name' => 'Тест', 'status' => 'active']);
        $block = $project->blocks()->create(['name' => 'Буцаах барилга', 'floors' => 1, 'units_per_floor' => 0, 'start_date' => '2026-11-17']);
        // Simulate a real restrictive reference left outside the building cascade.
        DB::statement('CREATE TABLE reference_guard (id INTEGER PRIMARY KEY, work_type_id VARCHAR(36) NOT NULL REFERENCES work_types(id) ON DELETE RESTRICT)');
        DB::table('reference_guard')->insert(['work_type_id' => WorkType::firstOrFail()->id]);
        $count = WorkType::count();
        $this->artisan('cpms:refresh-test-reference --execute')->assertFailed();
        $this->assertNotNull($block->fresh());
        $this->assertSame($count, WorkType::count());
        $this->assertSame(0, WorkCatalogEntry::count());
    }
}
