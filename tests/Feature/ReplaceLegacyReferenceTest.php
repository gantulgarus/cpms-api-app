<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DesignItem;
use App\Models\User;
use App\Models\WorkCatalogEntry;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Database\Seeders\WorkTypeSeeder;
use Database\Seeders\ChecklistSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplaceLegacyReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_removes_unused_legacy_data_but_keeps_catalog_and_configured_work(): void
    {
        $this->seed([WorkTypeSeeder::class, ChecklistSeeder::class]);
        $this->artisan('cpms:import-work-catalog')->assertSuccessful();
        $entry = WorkCatalogEntry::firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $id = $this->patchJson('/api/v1/work-catalog/'.$entry->id, ['level' => 'block'])
            ->assertOk()->json('data.workTypeId');
        $this->assertNotNull($id);
        $this->artisan('cpms:retire-legacy-work-reference')->assertSuccessful();
        $this->assertGreaterThan(1, WorkType::count());
        $this->artisan('cpms:retire-legacy-work-reference --execute')->assertSuccessful();
        $this->assertSame(1, WorkType::count());
        $this->assertSame($id, WorkType::firstOrFail()->id);
        $this->assertSame(0, DesignItem::count());
        $this->assertSame(7, WorkTypeGroup::count());
        $this->assertSame(293, WorkCatalogEntry::count());
        $this->artisan('cpms:retire-legacy-work-reference --execute')->assertSuccessful();
        $this->assertSame(1, WorkType::count());
    }

    public function test_catalog_configures_planning_and_locks_used_measurements(): void
    {
        $this->artisan('cpms:import-work-catalog')->assertSuccessful();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $entry = WorkCatalogEntry::firstOrFail();
        $code = $entry->toCatalogArray()['systemCode'];
        $id = $this->patchJson('/api/v1/work-catalog/'.$entry->id, ['level' => 'block'])
            ->assertOk()->assertJsonPath('data.systemCode', $code)->json('data.workTypeId');
        $this->patchJson('/api/v1/work-catalog/'.$entry->id, ['name' => 'Шинэ нэр'])
            ->assertOk()->assertJsonPath('data.workTypeId', $id);
        $this->assertSame('Шинэ нэр', WorkType::findOrFail($id)->name);
        $this->assertSame(1, WorkType::count());
        $missing = WorkCatalogEntry::whereNull('unit')->firstOrFail();
        $this->patchJson('/api/v1/work-catalog/'.$missing->id, ['level' => 'floor'])->assertOk()->assertJsonPath('data.workTypeId', null);
        $this->assertNull($missing->fresh()->work_type_id);

        $project = Company::create(['name' => 'Тест'])->projects()->create(['name' => 'Тест', 'status' => 'active']);
        $blockId = $this->postJson('/api/v1/projects/'.$project->id.'/blocks', [
            'name' => 'Шинэ барилга', 'buildingNo' => '1', 'floors' => 2, 'unitsPerFloor' => 1,
            'startDate' => '2026-11-16', 'purpose' => 'Орон сууц',
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/blocks/'.$blockId.'/work-items', ['workTypeIds' => [$id], 'plannedQty' => 10])
            ->assertSuccessful()->assertJsonPath('data.created', 1);
        $this->assertSame(1, WorkItem::where('work_type_id', $id)->count());
        $this->patchJson('/api/v1/work-catalog/'.$entry->id, ['level' => 'floor'])->assertConflict();
        $this->patchJson('/api/v1/work-catalog/'.$entry->id, ['unit' => 'ш'])->assertConflict();
        $this->assertSame('block', $entry->fresh()->workType->level);
        $this->artisan('cpms:retire-legacy-work-reference --execute')->assertFailed();
        $this->assertSame(1, WorkType::count());
    }
}
