<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\WorkCatalogEntry;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Services\InferWorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomaticCatalogLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambiguous_and_conflicting_descriptions_are_not_guessed(): void
    {
        $infer = new InferWorkLocation;
        foreach ([
            ['Газар шороо', 'Барилга бүхэлдээ', null, 'block'],
            ['Өрлөг', 'Зоорь', 'Хана', 'floor'],
            ['Айлуудын таазны эмульс', '2-16-р давхар', 'Тааз', 'unit'],
            ['Айлын самбар суурилуулах', 'Зоорь', null, null],
            ['Техникийн давхар шат', 'Гадна  ажил', null, null],
            ['Хэмжилт', null, 'Хана', null],
            ['Ариун цэврийн өрөөний шалны плита', '2-16-р давхар', 'Шал', null],
        ] as [$name, $floor, $location, $expected]) {
            $entry = new WorkCatalogEntry(['name' => $name, 'floor_description' => $floor, 'location_description' => $location]);
            $this->assertSame($expected, $infer->infer($entry), $name);
        }
    }

    public function test_bulk_resolution_is_idempotent_and_manual_clarification_is_preserved(): void
    {
        $this->artisan('cpms:import-work-catalog')->assertSuccessful();
        $this->artisan('cpms:resolve-catalog-locations')->assertSuccessful();
        $ready = WorkCatalogEntry::whereNotNull('work_type_id')->count();
        $this->assertGreaterThan(200, $ready);
        $this->assertLessThan(293, $ready);
        $identities = WorkCatalogEntry::orderBy('id')->pluck('work_type_id')->toArray();
        $this->artisan('cpms:resolve-catalog-locations')->assertSuccessful();
        $this->assertSame($identities, WorkCatalogEntry::orderBy('id')->pluck('work_type_id')->toArray());
        $this->assertSame($ready, WorkType::count());
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $ambiguous = WorkCatalogEntry::where('sequence_number', 210)->firstOrFail();
        $this->assertNull($ambiguous->toCatalogArray()['level']);
        $this->patchJson('/api/v1/work-catalog/'.$ambiguous->id, ['level' => 'floor'])
            ->assertOk()->assertJsonPath('data.locationLevelOverride', 'floor');
        $this->artisan('cpms:resolve-catalog-locations')->assertSuccessful();
        $this->assertSame('floor', $ambiguous->fresh()->toCatalogArray()['level']);
        $this->assertSame($ready + 1, WorkType::count());
    }

    public function test_basement_and_apartment_work_only_create_on_matching_floors(): void
    {
        $this->artisan('cpms:import-work-catalog')->assertSuccessful();
        $this->artisan('cpms:resolve-catalog-locations')->assertSuccessful();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $project = Company::create(['name' => 'Тест'])->projects()->create(['name' => 'Тест', 'status' => 'active']);
        $blockId = $this->postJson('/api/v1/projects/'.$project->id.'/blocks', [
            'name' => 'Тест', 'buildingNo' => '2', 'floors' => 3, 'unitsPerFloor' => 2,
            'startDate' => '2026-11-16', 'purpose' => 'Орон сууц',
        ])->assertCreated()->json('data.id');
        foreach ([24 => 1, 110 => 2, 254 => 4] as $sequence => $expected) {
            $entry = WorkCatalogEntry::where('sequence_number', $sequence)->firstOrFail();
            $this->postJson('/api/v1/blocks/'.$blockId.'/work-items', ['workTypeIds' => [$entry->work_type_id]])
                ->assertSuccessful()->assertJsonPath('data.created', $expected);
            $this->deleteJson('/api/v1/work-catalog/'.$entry->id)->assertConflict();
            $this->assertNotNull($entry->fresh());
            $works = WorkItem::where('work_type_id', $entry->work_type_id)->with('location.parent')->get();
            foreach ($works as $work) {
                $floor = $work->location->level === 'floor' ? $work->location : $work->location->parent;
                $this->assertFalse(str_contains($floor->name, 'Техник'));
                if ($sequence === 24) $this->assertSame(0, (int) $floor->sequence_number);
                else $this->assertGreaterThanOrEqual(2, $floor->sequence_number);
            }
        }
    }
}
