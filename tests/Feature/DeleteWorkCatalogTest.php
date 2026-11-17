<?php

namespace Tests\Feature;

use App\Models\BlockDesign;
use App\Models\DesignItem;
use App\Models\User;
use App\Models\WorkCatalogEntry;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteWorkCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_unused_work_removes_planning_identity_and_reimport_does_not_resurrect(): void
    {
        $this->artisan('cpms:import-work-catalog')->assertSuccessful();
        $this->artisan('cpms:resolve-catalog-locations')->assertSuccessful();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $entry = WorkCatalogEntry::whereNotNull('work_type_id')->firstOrFail();
        $typeId = $entry->work_type_id;
        $this->deleteJson('/api/v1/work-catalog/'.$entry->id)->assertNoContent();
        $this->assertSoftDeleted($entry);
        $this->assertNull(WorkType::find($typeId));
        $unconfigured = WorkCatalogEntry::whereNull('work_type_id')->firstOrFail();
        $this->deleteJson('/api/v1/work-catalog/'.$unconfigured->id)->assertNoContent();
        $this->artisan('cpms:import-work-catalog')->assertSuccessful();
        $this->artisan('cpms:resolve-catalog-locations')->assertSuccessful();
        $this->assertSame(291, WorkCatalogEntry::count());
        $this->assertSame(293, WorkCatalogEntry::withTrashed()->count());
        $this->getJson('/api/v1/work-catalog')->assertOk()->assertJsonCount(291, 'data');
        $this->patchJson('/api/v1/work-catalog/'.$entry->id, ['name' => 'Сэргээх'])->assertNotFound();
        $this->deleteJson('/api/v1/work-catalog/'.$entry->id)->assertNotFound();
    }

    public function test_permission_is_required_and_unknown_entries_return_404(): void
    {
        $this->artisan('cpms:import-work-catalog')->assertSuccessful();
        $entry = WorkCatalogEntry::firstOrFail();
        $this->deleteJson('/api/v1/work-catalog/'.$entry->id)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'site_engineer']), 'sanctum');
        $this->deleteJson('/api/v1/work-catalog/'.$entry->id)->assertForbidden();
        $this->assertNotNull($entry->fresh());
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $this->deleteJson('/api/v1/work-catalog/999999')->assertNotFound();
    }

    public function test_design_usage_prevents_deletion_without_losing_either_record(): void
    {
        $this->artisan('cpms:import-work-catalog')->assertSuccessful();
        $this->artisan('cpms:resolve-catalog-locations')->assertSuccessful();
        $entry = WorkCatalogEntry::whereNotNull('work_type_id')->firstOrFail();
        $design = BlockDesign::create(['name' => 'Тест', 'purpose' => 'Орон сууц', 'floors' => 2, 'units_per_floor' => 1, 'is_active' => true]);
        $item = DesignItem::create(['block_design_id' => $design->id, 'work_type_id' => $entry->work_type_id, 'sequence_number' => 1]);
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $this->deleteJson('/api/v1/work-catalog/'.$entry->id)->assertConflict();
        $this->assertNotNull($entry->fresh());
        $this->assertNotNull(WorkType::find($entry->work_type_id));
        $this->assertNotNull($item->fresh());
    }
}
