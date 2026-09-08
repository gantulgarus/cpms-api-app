<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Services\TaktSchedule;
use Database\Seeders\WorkTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Давхрын хугацаагаар хуваарь тооцох (олон улсад takt planning).
 *
 * ЯАГААД ХЭРЭГТЭЙ: урьд нь бүх ажил «эхлэл + 14 хоног» гэсэн ижил цонхтой
 * үүсдэг байсан. Тэгвэл суурийн ажил ба 16-р давхрын шал ижил өдөр дуусах
 * ёстой болж, хоцролтын тоо бүхэлдээ утгагүй болдог.
 *
 * Олон улсын takt planning-д баг бүр давхарт тогтмол хугацаа зарцуулж
 * дараагийн давхарт шилждэг. Энэ тестүүд тэр урсгал үнэхээр үүсэж байгааг
 * шалгана — өөрөөр хэлбэл ДЭЭД давхар ДООД давхраасаа ХОЖУУ эхэлж байгааг.
 */
class TaktScheduleTest extends TestCase
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

    // -- Тооцооллын цөм -----------------------------------------------------

    public function test_upper_floors_start_later_than_lower_floors(): void
    {
        $takt = new TaktSchedule('2026-09-07', taktDays: 5, floors: 10);

        [$f1Start] = $takt->window('floor', 1, 1);
        [$f5Start] = $takt->window('floor', 5, 1);

        $this->assertTrue(
            Carbon::parse($f5Start)->gt(Carbon::parse($f1Start)),
            'Дээд давхар доод давхраасаа хожуу эхлэх ёстой.'
        );
    }

    public function test_later_trades_start_after_earlier_trades_on_the_same_floor(): void
    {
        $takt = new TaktSchedule('2026-09-07', taktDays: 5, floors: 10);

        [$firstTrade] = $takt->window('floor', 3, 1);
        [$lastTrade] = $takt->window('floor', 3, 6);

        $this->assertTrue(Carbon::parse($lastTrade)->gt(Carbon::parse($firstTrade)));
    }

    public function test_the_takt_train_keeps_a_constant_rhythm(): void
    {
        // Гол шинж: баг нэг давхраас нөгөөд шилжих алхам ТОГТМОЛ.
        $takt = new TaktSchedule('2026-09-07', taktDays: 5, floors: 10);

        $gaps = [];
        for ($floor = 1; $floor < 6; $floor++) {
            [$a] = $takt->window('floor', $floor, 2);
            [$b] = $takt->window('floor', $floor + 1, 2);
            $gaps[] = Carbon::parse($a)->diffInDays(Carbon::parse($b));
        }

        $this->assertCount(1, array_unique($gaps), 'Хэмнэл жигд байх ёстой: '.implode(',', $gaps));
    }

    public function test_dates_never_land_on_a_weekend(): void
    {
        // Бямба гаригт дуусдаг төлөвлөгөө нь эхнээсээ худал.
        $takt = new TaktSchedule('2026-09-07', taktDays: 5, floors: 16);

        for ($floor = 0; $floor <= 16; $floor++) {
            [$start, $end] = $takt->window('floor', $floor, 3);
            $this->assertTrue(Carbon::parse($start)->isWeekday(), "Эхлэл амралтын өдөр: $start");
            $this->assertTrue(Carbon::parse($end)->isWeekday(), "Төгсгөл амралтын өдөр: $end");
        }
    }

    public function test_block_level_work_spans_the_whole_building(): void
    {
        // Суурь, дээвэр давхраар давтагдахгүй тул нэг давхрын хугацаанд багтахгүй.
        $takt = new TaktSchedule('2026-09-07', taktDays: 5, floors: 10);

        [$blockStart, $blockEnd] = $takt->window('block', 0, 1);
        [$floorStart, $floorEnd] = $takt->window('floor', 0, 1);

        $blockSpan = Carbon::parse($blockStart)->diffInDays(Carbon::parse($blockEnd));
        $floorSpan = Carbon::parse($floorStart)->diffInDays(Carbon::parse($floorEnd));

        $this->assertGreaterThan($floorSpan, $blockSpan);
    }

    public function test_a_larger_takt_stretches_the_programme(): void
    {
        [, $fast] = (new TaktSchedule('2026-09-07', taktDays: 3, floors: 10))->window('floor', 8, 4);
        [, $slow] = (new TaktSchedule('2026-09-07', taktDays: 8, floors: 10))->window('floor', 8, 4);

        $this->assertTrue(Carbon::parse($slow)->gt(Carbon::parse($fast)));
    }

    // -- Ажил үүсгэх урсгалд хэрэглэгдэж байгаа эсэх -------------------------

    public function test_manually_added_work_gets_a_takt_window(): void
    {
        $block = $this->emptyBlock(taktDays: 4);
        $type = WorkType::where('level', 'floor')->first();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/blocks/{$block->id}/work-items", [
                'workTypeIds' => [$type->id],
                'plannedQty' => 10,
            ])
            ->assertCreated();

        $items = WorkItem::where('block_id', $block->id)
            ->with('location')
            ->get()
            ->sortBy(fn ($w) => $w->location->sequence_number)
            ->values();

        $this->assertNotNull($items->first()->planned_end_date, 'Дуусах огноо хоосон байна.');
        $this->assertTrue(
            $items->last()->planned_start_date->gt($items->first()->planned_start_date),
            'Дээд давхрын ажил хожуу эхлэх ёстой.'
        );
    }

    public function test_rescheduling_moves_every_unstarted_work_item(): void
    {
        $block = $this->emptyBlock(taktDays: 5);
        $type = WorkType::where('level', 'floor')->first();

        $this->actingAs($this->manager)->postJson("/api/v1/blocks/{$block->id}/work-items", [
            'workTypeIds' => [$type->id],
            'plannedQty' => 10,
        ]);

        $before = WorkItem::where('block_id', $block->id)->max('planned_end_date');

        $response = $this->actingAs($this->manager)
            ->postJson("/api/v1/blocks/{$block->id}/schedule", ['taktDays' => 10])
            ->assertOk();

        $after = WorkItem::where('block_id', $block->id)->max('planned_end_date');

        $this->assertSame(10, $block->fresh()->takt_days);
        $this->assertGreaterThan(0, $response->json('data.updated'));
        $this->assertTrue(Carbon::parse($after)->gt(Carbon::parse($before)));
    }

    public function test_rescheduling_does_not_touch_accepted_work(): void
    {
        // Дууссан ажлын огноог хойшлуулж болдог бол хоцролт бүрийг арилгах
        // боломжтой болно — хэмжилт утгагүй болно.
        $block = $this->emptyBlock(taktDays: 5);
        $type = WorkType::where('level', 'floor')->first();

        $this->actingAs($this->manager)->postJson("/api/v1/blocks/{$block->id}/work-items", [
            'workTypeIds' => [$type->id],
            'plannedQty' => 10,
        ]);

        $done = WorkItem::where('block_id', $block->id)->first();
        $done->update(['reported_qty' => 10, 'accepted_qty' => 10, 'status' => 'completed']);
        $frozenDate = $done->planned_end_date->toDateString();

        $response = $this->actingAs($this->manager)
            ->postJson("/api/v1/blocks/{$block->id}/schedule", ['taktDays' => 20])
            ->assertOk();

        $this->assertSame(1, $response->json('data.frozen'));
        $this->assertSame($frozenDate, $done->fresh()->planned_end_date->toDateString());
    }

    public function test_takt_must_be_a_sane_number(): void
    {
        $block = $this->emptyBlock();

        $this->actingAs($this->manager)
            ->postJson("/api/v1/blocks/{$block->id}/schedule", ['taktDays' => 0])
            ->assertStatus(422);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/blocks/{$block->id}/schedule", ['taktDays' => 500])
            ->assertStatus(422);
    }

    public function test_contractors_cannot_reschedule(): void
    {
        $block = $this->emptyBlock();
        $contractor = User::factory()->create(['role' => 'contractor']);

        $this->actingAs($contractor)
            ->postJson("/api/v1/blocks/{$block->id}/schedule", ['taktDays' => 7])
            ->assertForbidden();
    }

    private function emptyBlock(int $taktDays = 5): Block
    {
        $response = $this->actingAs($this->manager)
            ->postJson("/api/v1/projects/{$this->project->id}/blocks", [
                'name' => 'Тест блок',
                'startDate' => '2026-09-07',
                'floors' => 6,
                'unitsPerFloor' => 4,
                'taktDays' => $taktDays,
            ])
            ->assertCreated();

        return Block::findOrFail($response->json('data.id'));
    }
}
