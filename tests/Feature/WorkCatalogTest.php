<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkCatalogEntry;
use App\Models\WorkType;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_keeps_all_rows_and_preserves_legacy_and_manual_edits(): void
    {
        $this->seed(WorkTypeSeeder::class);
        $legacy = WorkType::orderBy('id')->get()->toJson();
        $this->artisan('cpms:import-work-catalog')->assertSuccessful();
        $this->assertSame(293, WorkCatalogEntry::count());
        $this->assertSame(1, WorkCatalogEntry::whereNull('unit')->count());
        $this->assertSame('м2', WorkCatalogEntry::where('source_row', 83)->firstOrFail()->unit);
        $this->assertNull(WorkCatalogEntry::where('source_row', 172)->firstOrFail()->unit);
        $this->assertSame(7, WorkCatalogEntry::distinct()->count('group_name'));
        $entry = WorkCatalogEntry::firstOrFail();
        $code = $entry->toCatalogArray()['systemCode'];
        $entry->update(['name' => 'Зассан нэр', 'unit' => 'м3']);
        $this->artisan('cpms:import-work-catalog')->assertSuccessful();
        $this->assertSame(293, WorkCatalogEntry::count());
        $this->assertSame('Зассан нэр', $entry->fresh()->name);
        $this->assertSame($code, $entry->fresh()->toCatalogArray()['systemCode']);
        $this->assertSame($legacy, WorkType::orderBy('id')->get()->toJson());
        $this->assertDatabaseMissing('work_catalog_entries', ['source_key' => null]);
    }

    public function test_authenticated_read_and_authorized_edit_preserve_code_and_provenance(): void
    {
        $this->artisan('cpms:import-work-catalog')->assertSuccessful();
        $entry = WorkCatalogEntry::firstOrFail();
        $source = $entry->source_values;
        $this->getJson('/api/v1/work-catalog')->assertUnauthorized();
        $reader = User::factory()->create(['role' => 'site_engineer']);
        $this->actingAs($reader, 'sanctum');
        $this->getJson('/api/v1/work-catalog')->assertOk()->assertJsonCount(293, 'data');
        $this->patchJson('/api/v1/work-catalog/'.$entry->id, ['unit' => 'м3'])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $this->patchJson('/api/v1/work-catalog/'.$entry->id, [
            'unit' => 'м3', 'systemCode' => 'BAD', 'sourceKey' => 'BAD',
        ])->assertOk()->assertJsonPath('data.systemCode', $entry->toCatalogArray()['systemCode'])
            ->assertJsonPath('data.unit', 'м3');
        $this->assertSame($source, $entry->fresh()->source_values);
        $this->patchJson('/api/v1/work-catalog/'.$entry->id, ['name' => ' '])->assertUnprocessable();
    }
}
