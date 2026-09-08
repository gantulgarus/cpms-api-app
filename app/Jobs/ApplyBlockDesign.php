<?php

namespace App\Jobs;

use App\Models\Block;
use App\Models\BlockDesign;
use App\Models\DesignItem;
use App\Services\BlockLocationBuilder;
use App\Services\TaktSchedule;
use App\Models\Location;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Загварыг блокт буулгаж байршил ба ажлын нэгжийг бөөнөөр үүсгэнэ.
 *
 * 16 давхрын загвараар ~3,290 мөр үүснэ. HTTP хүсэлт дотор хийвэл timeout
 * болно — тиймээс queue дээр явна. Дэлгэц `jobId`-аар явцыг хянана.
 *
 * Оруулах өгөгдөл нь загвар тул хүн гараар нэг ч мөр бичихгүй: 49 орон сууцны
 * барилга ердөө 9 загвартай гэдгийг захиалагч баталсан.
 *
 * Түлхүүрт UUIDv7 (цагаар эрэмбэлэгддэг) ашиглана. MySQL/InnoDB нь хүснэгтийг
 * үндсэн түлхүүрээр нь кластерлэдэг тул санамсаргүй UUIDv4 бол 3,290 мөрийг
 * бөөнөөр оруулахад хуудас байнга хуваагдаж, индекс задарна. `HasUuids` нь
 * аль хэдийн v7 буцаадаг — bulk insert мөн адил байх ёстой.
 */
class ApplyBlockDesign implements ShouldQueue
{
    use Queueable;

    /** Бөөнөөр оруулах хэсгийн хэмжээ — санах ой ба query-ийн тэнцвэр. */
    private const CHUNK = 500;

    public int $timeout = 600;

    public function __construct(
        public readonly string $blockId,
        public readonly string $designId,
        public readonly string $startDate,
        /** Явцыг хянах түлхүүр. Дэлгэц `GET /jobs/{id}`-ээр уншина. */
        public readonly ?string $jobId = null,
    ) {}

    /** Явцын бичлэгийн хадгалагдах хугацаа. */
    public const PROGRESS_TTL = 3600;

    public static function progressKey(string $jobId): string
    {
        return "apply-design:{$jobId}";
    }

    public function handle(): void
    {
        $block = Block::findOrFail($this->blockId);
        $design = BlockDesign::with('items.workType.group')->findOrFail($this->designId);

        $this->report('running', 0, $design->estimatedItems());

        try {
            DB::transaction(function () use ($block, $design) {
                // Байршил аль хэдийн байвал ДАХИН үүсэхгүй — загваргүй блок
                // үүсгээд дараа нь загвар буулгах үед давхардахаас сэргийлнэ.
                $locations = app(BlockLocationBuilder::class)
                    ->build($block, $design->floors, $design->units_per_floor);
                $this->createWorkItems($block, $design, $locations);

                $block->update(['status' => 'not_started']);
            });
        } catch (\Throwable $e) {
            $this->report('failed', 0, $design->estimatedItems(), $e->getMessage());

            throw $e;
        }

        $created = $block->workItems()->count();
        $this->report('completed', $created, $created);
    }

    /** Явцыг cache-д бичнэ — queue дээр ажиллаж байгаа тул DB-д бичих шаардлагагүй. */
    private function report(string $status, int $progress, int $total, ?string $error = null): void
    {
        if (! $this->jobId) {
            return;
        }

        Cache::put(self::progressKey($this->jobId), [
            'id' => $this->jobId,
            'status' => $status,
            'progress' => $progress,
            'total' => $total,
            'result' => ['blockId' => $this->blockId],
            'error' => $error,
        ], self::PROGRESS_TTL);
    }

    /** Ажлын төрөл бүрийг өөрт тохирох түвшний байршил бүрд буулгана. */
    private function createWorkItems(Block $block, BlockDesign $design, array $locations): void
    {
        $now = now();
        // Хуваарь — давхрын хугацаагаар огноо тооцно. Загварын давхрын тоог
        // хэрэглэнэ: блокийн `floors` энэ мөчид хараахан шинэчлэгдээгүй байж болно.
        $takt = new TaktSchedule(
            $this->startDate,
            $block->takt_days ?: TaktSchedule::DEFAULT_TAKT_DAYS,
            max($design->floors, 1),
        );
        $buffer = [];
        $inserted = 0;
        $total = $design->estimatedItems();

        foreach ($design->items as $item) {
            $level = $item->workType->level;
            $targets = $locations[$level] ?? [];

            if ($targets === []) {
                continue;   // жишээ нь айлгүй үйлчилгээний барилгад дотор засал
            }

            $order = $item->workType->group?->build_order ?? 5;

            foreach ($targets as $location) {
                $buffer[] = $this->workItemRow($block, $item, $location, $order, $now, $takt, $level);

                if (count($buffer) >= self::CHUNK) {
                    DB::table('work_items')->insert($buffer);
                    $inserted += count($buffer);
                    $buffer = [];
                    $this->report('running', $inserted, $total);
                }
            }
        }

        if ($buffer !== []) {
            DB::table('work_items')->insert($buffer);
        }
    }

    private function workItemRow(
        Block $block,
        DesignItem $item,
        array $location,
        int $order,
        $now,
        TaktSchedule $takt,
        string $level,
    ): array {
        // (багийн дараалал + давхар) × давхрын хугацаа. Дэлгэрэнгүйг
        // `TaktSchedule`-ээс.
        [$start, $end] = $takt->window($level, (int) $location['sequence_number'], $order);

        return [
            'id' => (string) Str::uuid7(),
            'block_id' => $block->id,
            'location_id' => $location['id'],
            'work_type_id' => $item->work_type_id,
            'contractor_id' => null,
            'code' => null,
            'name' => $item->workType->name.' — '.$location['name'],
            'unit' => $item->workType->unit,
            'planned_qty' => $item->qty_per_location ?? 0,
            'reported_qty' => 0,
            'accepted_qty' => 0,
            'planned_start_date' => $start,
            'planned_end_date' => $end,
            'status' => 'not_started',
            'review_state' => 'none',
            'rework_of_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
