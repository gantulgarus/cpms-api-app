<?php

namespace Tests\Feature;

use App\Jobs\ApplyBlockDesign;
use App\Models\Block;
use App\Models\BlockDesign;
use App\Models\Company;
use App\Models\DesignItem;
use App\Models\ProgressEntry;
use App\Models\User;
use App\Models\WorkCatalogEntry;
use App\Models\WorkItem;
use App\Services\BudgetTemplateSheet;
use App\Services\Import\WorkPriceImport;
use App\Services\WorkBudgetImport;
use App\Services\XlsxReader;
use App\Services\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CatalogTemplatePriceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function building(): array
    {
        $this->artisan('cpms:import-work-catalog')->assertSuccessful();
        $this->artisan('cpms:resolve-catalog-locations')->assertSuccessful();
        $design = BlockDesign::create(['name' => 'Орон сууц', 'floors' => 3, 'units_per_floor' => 2, 'is_active' => true]);
        $this->artisan('cpms:sync-catalog-designs')->assertSuccessful();
        $project = Company::create(['name' => 'Тест'])->projects()->create(['name' => 'Тест', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $blockId = $this->postJson('/api/v1/projects/'.$project->id.'/blocks', [
            'name' => 'А блок', 'buildingNo' => 'A1', 'designId' => $design->id, 'startDate' => '2026-11-16',
        ])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/block-designs/'.$design->id.'/apply', ['blockId' => $blockId])->assertAccepted();
        $block = Block::findOrFail($blockId);
        return [$block, $design];
    }

    private function priceFile(array $lines): UploadedFile
    {
        $writer = new XlsxWriter(WorkBudgetImport::SHEET);
        $writer->row(['Үнийн импорт']); $writer->row(['Толгой']); $writer->row();
        foreach ($lines as [$code, $unit, $price]) {
            $row = array_fill(0, 30, null);
            $row[2] = $code; $row[5] = 'Өөр нэр'; $row[6] = 'Өөр инженер';
            $row[8] = $unit; $row[9] = 999999; $row[28] = 999999; $row[29] = $price;
            $writer->row($row);
        }
        $path = $writer->save(tempnam(sys_get_temp_dir(), 'prices').'.xlsx');
        return new UploadedFile($path, 'ajil-prices.xlsx', null, null, true);
    }

    public function test_templates_create_catalog_work_on_matching_floors_without_made_up_quantities(): void
    {
        [$block, $design] = $this->building();
        $design->refresh();
        $this->assertGreaterThan(200, $design->items()->count());
        $this->assertSame($design->estimatedItems(), $block->workItems()->count());
        $this->assertSame(0, $block->workItems()->where('planned_qty', '>', 0)->count());
        $basement = WorkCatalogEntry::where('sequence_number', 24)->firstOrFail();
        $this->assertSame(1, $block->workItems()->where('work_type_id', $basement->work_type_id)->count());
        $apartment = WorkCatalogEntry::where('sequence_number', 254)->firstOrFail();
        $this->assertSame(4, $block->workItems()->where('work_type_id', $apartment->work_type_id)->count());
        $item = $design->items()->firstOrFail(); $item->update(['qty_per_location' => 12]);
        $count = DesignItem::count();
        $this->artisan('cpms:sync-catalog-designs')->assertSuccessful();
        $this->assertSame($count, DesignItem::count());
        $this->assertSame(12.0, (float) $item->fresh()->qty_per_location);
    }

    public function test_import_only_updates_price_and_keeps_quantities_ids_history_and_assignments(): void
    {
        [$block] = $this->building();
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user, 'sanctum');
        $work = $block->workItems()->with('workType.catalogEntry')->firstOrFail();
        $work->update(['planned_qty' => 25, 'reported_qty' => 2, 'accepted_qty' => 1, 'inspector_id' => $user->id, 'review_state' => 'pending_check']);
        $entry = ProgressEntry::create(['work_item_id' => $work->id, 'reported_by_id' => $user->id, 'completed_qty' => 2, 'recorded_at' => now()]);
        $before = $work->fresh()->getAttributes();
        $ids = $block->workItems()->orderBy('id')->pluck('id')->all();
        $upload = $this->postJson('/api/v1/blocks/'.$block->id.'/imports', ['file' => $this->priceFile([[WorkPriceImport::codeFor($work->workType), $work->unit, 12345]])])->assertCreated();
        $this->assertTrue($upload->json('reports.0.canApply'));
        $this->assertSame($before, $work->fresh()->getAttributes());
        $this->postJson('/api/v1/imports/'.$upload->json('data.id').'/apply', ['types' => ['quantities'], 'mode' => 'prices', 'prune' => true])
            ->assertOk()->assertJsonPath('reports.0.result.created', 0)->assertJsonPath('reports.0.result.pruned', 0);
        $after = $work->fresh()->getAttributes();
        $this->assertSame(12345.0, (float) $after['budget_unit_price']);
        unset($before['budget_unit_price'], $before['updated_at'], $after['budget_unit_price'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame($ids, $block->workItems()->orderBy('id')->pluck('id')->all());
        $this->assertNotNull($entry->fresh());
        $this->postJson('/api/v1/imports/'.$upload->json('data.id').'/apply', ['mode' => 'prices'])->assertUnprocessable();
    }

    public function test_invalid_or_duplicate_prices_cannot_apply_and_export_uses_live_codes(): void
    {
        [$block] = $this->building();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        $work = $block->workItems()->with('workType.catalogEntry')->firstOrFail();
        $code = WorkPriceImport::codeFor($work->workType);
        foreach ([[["WT-999999", $work->unit, 100]], [[$code, 'Буруу нэгж', 100]], [[$code, $work->unit, -1]], [[$code, $work->unit, 100], [$code, $work->unit, 200]]] as $lines) {
            $upload = $this->postJson('/api/v1/blocks/'.$block->id.'/imports', ['file' => $this->priceFile($lines)])->assertCreated();
            $this->assertFalse($upload->json('reports.0.canApply'));
            $this->postJson('/api/v1/imports/'.$upload->json('data.id').'/apply', ['mode' => 'prices'])->assertUnprocessable();
        }
        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';
        app(BudgetTemplateSheet::class)->build($block, $path);
        $rows = (new XlsxReader($path))->rows(WorkBudgetImport::SHEET, 30);
        $this->assertStringStartsWith('WT-', $rows[4][2]);
        $this->assertSame('Нэгж үнэ', $rows[2][29]);
        $this->assertSame($block->workItems()->distinct()->count('work_type_id'), count($rows) - 3);
        unlink($path);
    }
}
