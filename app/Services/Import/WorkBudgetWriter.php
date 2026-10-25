<?php

namespace App\Services\Import;

use App\Models\Block;
use App\Models\Inspection;
use App\Models\Photo;
use App\Models\ProgressEntry;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Services\TaktSchedule;
use Illuminate\Support\Facades\Storage;

/**
 * Ажлын хөлсний төсвийн төлөвлөгөөг ажлын мөр рүү бичнэ.
 *
 * `ImportQuantities` командаас салгав — ижил бичилт одоо вэбийн импортын
 * дэлгэцээс бас дуудагдана.
 *
 * ГҮЙЦЭТГЭЛ УСТАНА. Тоо хэмжээ өөрчлөгдөхөд хуучин явц утгагүй болно:
 * 648 м² гэж бодоод 600 мэдээлсэн ажил шинэ тоогоор 15 м² болвол 4000%
 * гүйцэтгэлтэй харагдана. Тиймээс явц, шалгалт, зургийг цэвэрлэнэ.
 *
 * ТРАНЗАКЦ ЭНД НЭЭГДЭХГҮЙ — дуудагч тал хариуцна.
 */
class WorkBudgetWriter
{
    /** Бүгдийг солино, ГҮЙЦЭТГЭЛ УСТАНА. */
    public const MODE_REPLACE = 'replace';

    /** Тоо хэмжээ, үнэ, инженерийг шинэчилнэ. ГҮЙЦЭТГЭЛ ҮЛДЭНЭ. */
    public const MODE_UPDATE = 'update';

    public const MODES = [self::MODE_REPLACE, self::MODE_UPDATE];

    /**
     * @param  array<int, array<string, mixed>>  $plan
     * @return array{items: int, withoutQuantity: int, pruned: int, created: int, updated: int, wiped: array{entries: int, inspections: int, photos: int}, conflicts: array<int, array<string, mixed>>}
     */
    public function write(
        Block $block,
        array $plan,
        bool $prune = false,
        string $mode = self::MODE_REPLACE,
    ): array {
        $update = $mode === self::MODE_UPDATE;

        /*
         * ШИНЭЧЛЭХ горимд гүйцэтгэлийг ХӨНДӨХГҮЙ.
         *
         * Төсөв нэг удаа эцэслэгддэггүй: 75 барилгын цогцолборт
         * инженер тоо хэмжээгээ сар бүр тодруулна. Тэр болгонд хоёр
         * сарын хэмжилт устгах нь системийг ашиглах боломжгүй болгоно.
         */
        $wiped = $update
            ? ['entries' => 0, 'inspections' => 0, 'photos' => 0]
            : $this->wipeProgress($block);

        $created = 0;
        $updated = 0;
        $conflicts = [];

        $takt = TaktSchedule::forBlock($block);
        $locations = $block->locations()->pluck('sequence_number', 'id')->all();
        $types = WorkType::with('group')->get()->keyBy('id');
        $touched = [];

        foreach ($plan as $line) {
            $type = $types[$line['workTypeId']];
            [$start, $end] = $takt->window(
                $type->level,
                (int) ($locations[$line['locationId']] ?? 0),
                $type->group?->build_order ?? TaktSchedule::DEFAULT_ORDER,
            );

            $key = [
                'block_id' => $block->id,
                'location_id' => $line['locationId'],
                'work_type_id' => $line['workTypeId'],
            ];

            $existing = $update ? WorkItem::where($key)->first() : null;

            if ($existing) {
                $updated++;

                /*
                 * ТӨЛӨВЛӨСӨН ТОО МЭДЭЭЛСНЭЭС ДООШ БУУХГҮЙ.
                 *
                 * 100 м² мэдээлчихсэн ажлыг 60 м² болговол гүйцэтгэл
                 * 167% болж, цаашид мэдээлэх ч боломжгүй болно. Ийм
                 * зөрчлийг ЧИМЭЭГҮЙ бичихгүй — хуучин тоог үлдээж,
                 * хүнд жагсааж өгнө.
                 */
                $clash = (float) $line['quantity'] < (float) $existing->reported_qty;

                if ($clash) {
                    $conflicts[] = [
                        'name' => $existing->name,
                        'location' => $existing->location?->name,
                        'reportedQty' => (float) $existing->reported_qty,
                        'newPlannedQty' => (float) $line['quantity'],
                        'unit' => $existing->unit,
                    ];
                }

                $existing->fill([
                    'name' => $line['name'],
                    'unit' => $line['unit'],
                    'budget_unit_price' => $line['unitPrice'],
                    'inspector_id' => $line['inspectorId'],
                ]);

                if (! $clash) {
                    $existing->planned_qty = $line['quantity'];
                }

                /*
                 * ОГНОО ХӨДӨЛӨХГҮЙ.
                 *
                 * Такт хуваарийг дахин бодвол батлагдсан хугацааны
                 * сунгалт ул мөргүй буцна. Хуваарийг «Хуваарь» товчоор
                 * зориудаар өөрчилнө.
                 */
                $existing->save();
                $existing->recalculate();

                $touched[] = $existing->id;

                continue;
            }

            $item = WorkItem::updateOrCreate(
                $key,
                [
                    'name' => $line['name'],
                    'unit' => $line['unit'],
                    'planned_qty' => $line['quantity'],
                    /*
                     * Төсвийн нэгж үнэ — системд мөнгө орох цорын гагц
                     * эх сурвалж. Тохиролцооны үнэтэй харьцуулахад
                     * хэрэглэгдэнэ.
                     */
                    'budget_unit_price' => $line['unitPrice'],
                    /*
                     * Хоосон байж болно — төсвийн 36 ажилд инженер
                     * заагаагүй. Хоосон бичих нь ЗӨВ: хуучин оноолтыг
                     * үлдээвэл төсвөөс хасагдсан хариуцлага ул мөргүй
                     * үргэлжилнэ.
                     */
                    'inspector_id' => $line['inspectorId'],
                    // Гүйцэтгэл устсан тул бүгд эхнээсээ эхэлнэ.
                    'reported_qty' => 0,
                    'accepted_qty' => 0,
                    'status' => 'not_started',
                    'review_state' => 'none',
                    'planned_start_date' => $start,
                    'planned_end_date' => $end,
                ]
            );

            $created++;
            $touched[] = $item->id;
        }

        /*
         * ШИНЭЧЛЭХ горимд устгахгүй.
         *
         * Төсөвт таараагүй ажилд гүйцэтгэл байж болно — устгавал тэр
         * түүх эзэнгүй алга болно. Хасах шийдвэрийг «Шинээр оруулах»
         * горимоор зориудаар гаргана.
         */
        $pruned = $prune && ! $update ? $this->prune($block, $touched) : 0;

        $items = WorkItem::where('block_id', $block->id);

        return [
            'items' => (clone $items)->count(),
            'withoutQuantity' => (clone $items)->where('planned_qty', '<=', 0)->count(),
            'pruned' => $pruned,
            'created' => $update ? $created : (clone $items)->count(),
            'updated' => $updated,
            'wiped' => $wiped,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Төсөвт таараагүй мөрүүдийг устгана.
     *
     * `whereNotIn`-д 3,191 id өгч болохгүй (SQLite 999 холбоосоор
     * хязгаарладаг) тул хэсэгчлэн шүүнэ.
     *
     * @param  array<int, string>  $touched
     */
    private function prune(Block $block, array $touched): int
    {
        $keep = array_flip($touched);
        $removed = 0;

        WorkItem::where('block_id', $block->id)
            ->select('id')
            ->chunkById(500, function ($chunk) use ($keep, &$removed) {
                $ids = $chunk->pluck('id')
                    ->reject(fn ($id) => isset($keep[$id]))
                    ->all();

                if ($ids !== []) {
                    $removed += WorkItem::whereIn('id', $ids)->delete();
                }
            });

        return $removed;
    }

    /**
     * Гүйцэтгэлийн бүх ул мөрийг устгана — дискэн дээрх зургийг ч мөн.
     *
     * Файлын замыг мөрүүдээс нь ӨМНӨ цуглуулна: устсаны дараа ямар файл
     * байсныг мэдэх арга үлдэхгүй бөгөөд дискэн дээр эзэнгүй зураг
     * хуримтлагдана.
     *
     * @return array{entries: int, inspections: int, photos: int}
     */
    private function wipeProgress(Block $block): array
    {
        /*
         * ДЭД АСУУЛГА хэрэглэнэ, id-ийн ЖАГСААЛТ биш: 3,603 утгыг
         * `whereIn`-д өгвөл SQLite-ийн 999 холбоосын хязгаарт унана.
         */
        $itemIds = WorkItem::where('block_id', $block->id)->select('id');
        $paths = Photo::whereIn('work_item_id', $itemIds)->pluck('path');

        $counts = [
            'photos' => Photo::whereIn('work_item_id', $itemIds)->count(),
            'inspections' => Inspection::whereIn('work_item_id', $itemIds)->count(),
            'entries' => ProgressEntry::whereIn('work_item_id', $itemIds)->count(),
        ];

        Photo::whereIn('work_item_id', $itemIds)->delete();
        Inspection::whereIn('work_item_id', $itemIds)->delete();
        ProgressEntry::whereIn('work_item_id', $itemIds)->delete();

        foreach ($paths as $path) {
            Storage::disk('local')->delete($path);
        }

        return $counts;
    }
}
