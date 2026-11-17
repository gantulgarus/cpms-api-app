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

    public function test_work_can_be_added_to_selected_locations_only(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $project = Company::create(['name' => 'Тест'])->projects()->create(['name' => 'Тест', 'status' => 'active']);
        $makeBlock = fn () => $this->postJson('/api/v1/projects/'.$project->id.'/blocks', [
            'name' => 'Барилга', 'floors' => 3, 'unitsPerFloor' => 2, 'startDate' => '2026-11-17', 'purpose' => 'Орон сууц',
        ])->assertCreated()->json('data.id');
        $blockId = $makeBlock();
        $otherBlockId = $makeBlock();
        $group = WorkTypeGroup::create(['name' => 'Тест', 'sequence_number' => 1, 'build_order' => 1]);
        $floorType = WorkType::create(['work_type_group_id' => $group->id, 'sequence_number' => 1, 'name' => 'Давхрын ажил', 'unit' => 'м2', 'level' => 'floor']);
        $unitType = WorkType::create(['work_type_group_id' => $group->id, 'sequence_number' => 2, 'name' => 'Айлын ажил', 'unit' => 'м2', 'level' => 'unit']);
        $floors = \App\Models\Location::where('block_id', $blockId)->where('level', 'floor')->get();
        $units = \App\Models\Location::where('block_id', $blockId)->where('level', 'unit')->get();
        $url = '/api/v1/blocks/'.$blockId.'/work-items';
        $this->postJson($url, ['workTypeIds' => [$floorType->id], 'locationIds' => [$floors[0]->id], 'plannedQty' => 7])->assertSuccessful()->assertJsonPath('data.created', 1);
        $this->assertSame(1, WorkItem::where('block_id', $blockId)->count());
        $this->assertSame($floors[0]->id, WorkItem::firstOrFail()->location_id);
        $this->postJson($url, ['workTypeIds' => [$floorType->id], 'locationIds' => [$floors[0]->id]])->assertConflict();
        $this->postJson($url, ['workTypeIds' => [$unitType->id], 'locationIds' => [$units[0]->id, $units[1]->id]])->assertSuccessful()->assertJsonPath('data.created', 2);
        $this->postJson($url, ['workTypeIds' => [$floorType->id], 'locationIds' => []])->assertUnprocessable();
        $foreign = \App\Models\Location::where('block_id', $otherBlockId)->firstOrFail();
        $this->postJson($url, ['workTypeIds' => [$floorType->id], 'locationIds' => [$foreign->id]])->assertUnprocessable();
        $this->postJson($url, ['workTypeIds' => [$floorType->id], 'locationIds' => [$units[0]->id]])->assertUnprocessable();
        $this->assertSame(3, WorkItem::where('block_id', $blockId)->count());
        $this->postJson($url, ['workTypeIds' => [$floorType->id]])->assertSuccessful()->assertJsonPath('data.created', $floors->count() - 1);
    }

    public function test_create_catalog_assigns_identity_and_rolls_back_invalid_input(): void
    {
        $payload = ['groupName' => 'Бүлэг', 'subgroupNumber' => '1.1', 'typeName' => 'Төрөл',
            'name' => 'Шинэ ажил', 'unit' => 'м2', 'locationDescription' => 'Барилга бүхэлдээ', 'sequenceNumber' => 1];
        $this->actingAs(User::factory()->create(['role' => 'site_engineer']), 'sanctum');
        $this->postJson('/api/v1/work-catalog', $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $this->postJson('/api/v1/work-catalog', [])->assertUnprocessable();
        $this->postJson('/api/v1/work-catalog', array_merge($payload, ['level' => 'wrong']))->assertUnprocessable();
        $this->assertSame(0, WorkCatalogEntry::count());
        $first = $this->postJson('/api/v1/work-catalog', $payload)->assertCreated()
            ->assertJsonPath('data.sourceKey', null)->assertJsonPath('data.level', 'block');
        $this->assertNotNull($first->json('data.workTypeId'));
        $second = $this->postJson('/api/v1/work-catalog', $payload)->assertCreated();
        $this->assertNotSame($first->json('data.systemCode'), $second->json('data.systemCode'));
        $this->assertSame(0, WorkItem::count());
        $this->assertSame(2, WorkCatalogEntry::count());
    }

    public function test_catalog_edits_all_reference_fields_and_keeps_identity(): void
    {
        $this->artisan('cpms:import-work-catalog')->assertSuccessful();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $entry = WorkCatalogEntry::firstOrFail();
        $code = $entry->toCatalogArray()['systemCode'];
        $payload = ['groupName' => 'Шинэ бүлэг', 'subgroupNumber' => '8.2', 'typeName' => 'Шинэ төрөл',
            'level' => 'block', 'name' => 'Шинэ ажил', 'unit' => 'м2', 'locationDescription' => 'Барилга',
            'floorDescription' => '', 'description' => 'Тайлбар', 'sequenceNumber' => 700];
        $response = $this->patchJson('/api/v1/work-catalog/'.$entry->id, $payload)->assertOk()
            ->assertJsonPath('data.systemCode', $code);
        foreach ($payload as $key => $value) $response->assertJsonPath('data.'.$key, $value === '' ? null : $value);
        $type = $entry->fresh()->workType;
        $this->assertSame('Шинэ бүлэг', WorkTypeGroup::findOrFail($type->work_type_group_id)->name);
        $this->assertSame(700, (int) $type->sequence_number);
        $this->patchJson('/api/v1/work-catalog/'.$entry->id, ['groupName' => '  '])->assertUnprocessable();
        $this->patchJson('/api/v1/work-catalog/'.$entry->id, ['sequenceNumber' => 0])->assertUnprocessable();
    }

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
