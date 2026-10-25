<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Inspection;
use App\Models\ProgressEntry;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Services\Import\WorkBudgetWriter;
use App\Services\WorkBudgetImport;
use App\Services\XlsxWriter;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * ШИНЭЧЛЭХ горим — гүйцэтгэл устгадаггүй импорт.
 *
 * ЯАГААД ХЭРЭГТЭЙ ВЭ: төсөв нэг удаа эцэслэгддэггүй. 75 барилгын
 * цогцолборт инженер тоо хэмжээгээ сар бүр тодруулна. Тэр болгонд
 * хоёр сарын хэмжилт устгах нь системийг ашиглах боломжгүй болгоно.
 *
 * ХОЁР ГОРИМЫН ЯЛГАА ЭНД ЛАВЛАГДАНА.
 */
class ImportUpdateModeTest extends TestCase
{
    use RefreshDatabase;

    private Block $block;

    private User $engineer;

    private WorkType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkTypeSeeder::class);

        $company = Company::create(['name' => 'Инэл ХХК']);
        $project = $company->projects()->create(['name' => 'Төсөл', 'status' => 'active']);

        $this->block = $project->blocks()->create([
            'name' => 'А1 блок',
            'building_no' => 'A1',
            'floors' => 2,
            'units_per_floor' => 2,
            'start_date' => '2026-10-01',
        ]);

        app(\App\Services\BlockLocationBuilder::class)->build($this->block, 2, 2);

        $this->type = WorkType::whereNotNull('code')->where('level', 'floor')->firstOrFail();

        $this->engineer = User::create([
            'name' => 'Ерөнхий инженер', 'email' => 'ge@inel.mn',
            'password' => bcrypt('secret123'), 'role' => 'general_engineer',
        ]);
    }

    // ───────────────────────── гол баталгаа ─────────────────────────

    /**
     * ШИНЭЧЛЭХ горимд ГҮЙЦЭТГЭЛ ҮЛДЭНЭ.
     *
     * Энэ бол энэ горимын бүхий л утга. Уствал «шинэчлэх» гэдэг үг
     * худал болно.
     */
    public function test_update_mode_keeps_progress(): void
    {
        $item = $this->itemWithProgress(648, reported: 400);

        $this->apply(712, mode: 'update');

        $item->refresh();

        $this->assertEqualsWithDelta(712, $item->planned_qty, 0.001, 'Тоо хэмжээ шинэчлэгдэх ёстой.');
        $this->assertEqualsWithDelta(400, $item->reported_qty, 0.001, 'Явц ҮЛДЭХ ёстой.');
        $this->assertSame(1, ProgressEntry::where('work_item_id', $item->id)->count());
        $this->assertSame(1, Inspection::where('work_item_id', $item->id)->count());
    }

    /** ШИНЭЭР ОРУУЛАХ горимд гүйцэтгэл УСТАНА — хуучин зан төлөв хэвээр. */
    public function test_replace_mode_still_wipes_progress(): void
    {
        $item = $this->itemWithProgress(648, reported: 400);

        $this->apply(712, mode: 'replace');

        $item->refresh();

        $this->assertEqualsWithDelta(712, $item->planned_qty, 0.001);
        $this->assertEqualsWithDelta(0, $item->reported_qty, 0.001);
        $this->assertSame(0, ProgressEntry::where('work_item_id', $item->id)->count());
    }

    /** Анхдагч горим нь `replace` — хуучин зан төлөв санамсаргүй өөрчлөгдөхгүй. */
    public function test_the_default_mode_is_replace(): void
    {
        $item = $this->itemWithProgress(648, reported: 400);

        $this->apply(712);   // горим заагаагүй

        $this->assertEqualsWithDelta(0, $item->refresh()->reported_qty, 0.001);
    }

    // ───────────────────────── зөрчил ─────────────────────────

    /**
     * ТӨЛӨВЛӨСӨН ТОО МЭДЭЭЛСНЭЭС ДООШ БУУХГҮЙ.
     *
     * 400 м² мэдээлчихсэн ажлыг 300 болговол гүйцэтгэл 133% болж,
     * цаашид мэдээлэх ч боломжгүй болно. Хуучин тоог үлдээж, хүнд
     * жагсааж өгнө.
     */
    public function test_a_quantity_below_the_reported_amount_is_refused_and_listed(): void
    {
        $item = $this->itemWithProgress(648, reported: 400);

        $reports = $this->apply(300, mode: 'update');

        $this->assertEqualsWithDelta(
            648,
            $item->refresh()->planned_qty,
            0.001,
            'Зөрчилтэй тоо бичигдэх ёсгүй.',
        );

        $conflicts = $reports[0]['result']['conflicts'];

        $this->assertCount(1, $conflicts);
        $this->assertEqualsWithDelta(400, $conflicts[0]['reportedQty'], 0.001);
        $this->assertEqualsWithDelta(300, $conflicts[0]['newPlannedQty'], 0.001);
    }

    /** Мэдээлсэнтэй ТЭНЦҮҮ тоо зөрчил биш. */
    public function test_a_quantity_equal_to_the_reported_amount_is_accepted(): void
    {
        $item = $this->itemWithProgress(648, reported: 400);

        $reports = $this->apply(400, mode: 'update');

        $this->assertEqualsWithDelta(400, $item->refresh()->planned_qty, 0.001);
        $this->assertSame([], $reports[0]['result']['conflicts']);
    }

    // ───────────────────────── бусад талбар ─────────────────────────

    /**
     * ОГНОО ХӨДӨЛӨХГҮЙ.
     *
     * Такт хуваарийг дахин бодвол батлагдсан хугацааны сунгалт ул
     * мөргүй буцна.
     */
    public function test_update_mode_does_not_move_dates(): void
    {
        $item = $this->itemWithProgress(648, reported: 0);
        $item->update(['planned_end_date' => '2027-06-30']);

        $this->apply(700, mode: 'update');

        $this->assertSame('2027-06-30', $item->refresh()->planned_end_date->toDateString());
    }

    /** Үнэ, инженер шинэчлэгдэнэ. */
    public function test_update_mode_refreshes_price_and_inspector(): void
    {
        $item = $this->itemWithProgress(648, reported: 100);
        $item->update(['budget_unit_price' => 1000]);

        $this->apply(648, mode: 'update', unitPrice: 32000);

        $this->assertEqualsWithDelta(32000, $item->refresh()->budget_unit_price, 0.01);
    }

    /** Төсөвт шинээр нэмэгдсэн ажил ҮҮСНЭ. */
    public function test_update_mode_creates_newly_budgeted_work(): void
    {
        $this->itemWithProgress(648, reported: 100);

        $before = WorkItem::where('block_id', $this->block->id)->count();

        // Хоёр дахь давхар нэмэгдсэн төсөв.
        $this->apply(648, mode: 'update', floors: ['1-р давхар', '2-р давхар']);

        $this->assertSame($before + 1, WorkItem::where('block_id', $this->block->id)->count());
    }

    /**
     * ШИНЭЧЛЭХ горимд `prune` ҮЙЛЧЛЭХГҮЙ.
     *
     * Төсөвт таараагүй ажилд гүйцэтгэл байж болно — устгавал тэр түүх
     * эзэнгүй алга болно.
     */
    public function test_prune_is_ignored_in_update_mode(): void
    {
        $this->itemWithProgress(648, reported: 100);

        // Төсөвт БАЙХГҮЙ, гараар нэмэгдсэн ажил.
        $extra = WorkItem::create([
            'block_id' => $this->block->id,
            'location_id' => $this->block->locations()->where('name', '2-р давхар')->firstOrFail()->id,
            'work_type_id' => WorkType::whereNotNull('code')->where('level', 'floor')->skip(1)->firstOrFail()->id,
            'name' => 'Гараар нэмсэн ажил',
            'unit' => 'м2',
            'planned_qty' => 50,
            'status' => 'not_started',
            'review_state' => 'none',
        ]);

        $this->apply(648, mode: 'update', prune: true);

        $this->assertDatabaseHas('work_items', ['id' => $extra->id]);
    }

    // ───────────────────────── туслахууд ─────────────────────────

    private function itemWithProgress(float $planned, float $reported): WorkItem
    {
        $location = $this->block->locations()->where('name', '1-р давхар')->firstOrFail();

        $item = WorkItem::create([
            'block_id' => $this->block->id,
            'location_id' => $location->id,
            'work_type_id' => $this->type->id,
            'name' => $this->type->name,
            'unit' => $this->type->unit,
            'planned_qty' => $planned,
            'reported_qty' => $reported,
            'accepted_qty' => 0,
            'status' => $reported > 0 ? 'in_progress' : 'not_started',
            'review_state' => $reported > 0 ? 'pending_check' : 'none',
            'planned_start_date' => '2026-10-01',
            'planned_end_date' => '2026-10-20',
            'baseline_end_date' => '2026-10-20',
        ]);

        if ($reported > 0) {
            ProgressEntry::create([
                'work_item_id' => $item->id,
                'reported_by_id' => $this->engineer->id,
                'completed_qty' => $reported,
                'recorded_at' => now(),
            ]);

            Inspection::create([
                'work_item_id' => $item->id,
                'inspector_id' => $this->engineer->id,
                'stage' => Inspection::STAGE_SITE,
                'result' => 'accepted',
                'accepted_qty' => 0,
                'rejected_qty' => 0,
                'inspected_at' => now(),
            ]);
        }

        return $item;
    }

    /**
     * Төсөв оруулж, тайлангуудыг буцаана.
     *
     * @param  array<int, string>  $floors
     * @return array<int, array<string, mixed>>
     */
    private function apply(
        float $qty,
        string $mode = WorkBudgetWriter::MODE_REPLACE,
        ?float $unitPrice = null,
        bool $prune = false,
        array $floors = ['1-р давхар'],
    ): array {
        $file = $this->makeWorkbook($qty, $unitPrice, $floors);

        $importId = $this->actingAs($this->engineer)
            ->post("/api/v1/blocks/{$this->block->id}/imports", ['file' => $file])
            ->assertCreated()
            ->json('data.id');

        return $this->actingAs($this->engineer)
            ->postJson("/api/v1/imports/{$importId}/apply", [
                'mode' => $mode,
                'prune' => $prune,
            ])
            ->assertOk()
            ->json('reports');
    }

    /** @param array<int, string> $floors */
    private function makeWorkbook(float $qty, ?float $unitPrice, array $floors): UploadedFile
    {
        $width = 30;
        $blank = fn () => array_fill(0, $width, '');

        $label = $blank();
        $label[8] = 'Хэмжих нэгж';

        $names = $blank();
        foreach ($floors as $i => $name) {
            $names[9 + $i] = $name;
        }

        $row = $blank();
        $row[2] = $this->type->code;
        $row[5] = $this->type->name;
        $row[8] = $this->type->unit;
        foreach ($floors as $i => $_) {
            $row[9 + $i] = $qty;
        }
        if ($unitPrice !== null) {
            $row[29] = $unitPrice;
        }

        $writer = new XlsxWriter(WorkBudgetImport::SHEET);

        foreach ([$blank(), $label, $names, $row] as $line) {
            $writer->row($line);
        }

        return new UploadedFile(
            $writer->save(tempnam(sys_get_temp_dir(), 'upd').'.xlsx'),
            'tosov.xlsx',
            null,
            null,
            true,
        );
    }
}
