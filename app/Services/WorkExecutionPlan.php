<?php

namespace App\Services;

use App\Models\Block;
use Illuminate\Support\Facades\DB;

class WorkExecutionPlan
{
    public function read(Block $block): array
    {
        $ids = $block->workItems()->select('work_items.id')
            ->leftJoin('work_catalog_entries as catalog', function ($join) {
                $join->on('catalog.work_type_id', '=', 'work_items.work_type_id')->whereNull('catalog.deleted_at');
            })
            ->leftJoin('locations as location', 'location.id', '=', 'work_items.location_id')
            ->leftJoin('locations as parent', 'parent.id', '=', 'location.parent_id')
            ->orderByRaw('plan_order IS NULL DESC')->orderBy('plan_order')
            ->orderByRaw('catalog.id IS NULL')->orderBy('catalog.id')
            ->orderByRaw("CASE WHEN location.level = 'unit' THEN parent.sequence_number ELSE location.sequence_number END")
            ->orderBy('location.sequence_number')->orderBy('work_items.id')->pluck('work_items.id')->all();
        $edges = DB::table('work_item_dependencies as dependency')
            ->join('work_items as work', 'work.id', '=', 'dependency.work_item_id')
            ->where('work.block_id', $block->id)
            ->orderBy('dependency.work_item_id')->orderBy('dependency.predecessor_id')
            ->get(['dependency.work_item_id', 'dependency.predecessor_id']);
        $predecessors = [];
        foreach ($edges as $edge) {
            $predecessors[$edge->work_item_id][] = $edge->predecessor_id;
        }

        return [
            'hasOrder' => $block->workItems()->whereNotNull('plan_order')->exists(),
            'order' => $ids,
            'predecessors' => (object) $predecessors,
            'version' => hash('sha256', json_encode([$ids, $predecessors])),
        ];
    }

    /** Stable topological order preserves the engineer's ordering within available work. */
    public function ordered(array $ids, array $predecessors): array
    {
        $remaining = [];
        $followers = [];
        foreach ($ids as $id) {
            $remaining[$id] = count($predecessors[$id] ?? []);
            foreach ($predecessors[$id] ?? [] as $previous) {
                $followers[$previous][] = $id;
            }
        }
        $rank = array_flip($ids);
        $ready = new \SplPriorityQueue;
        $ready->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        foreach ($ids as $id) {
            if ($remaining[$id] === 0) $ready->insert($id, -$rank[$id]);
        }
        $result = [];
        while (! $ready->isEmpty()) {
            $id = $ready->extract();
            $result[] = $id;
            foreach ($followers[$id] ?? [] as $next) {
                if (--$remaining[$next] === 0) {
                    $ready->insert($next, -$rank[$next]);
                }
            }
        }
        abort_if(count($result) !== count($ids), 422, 'Ажил хооронд тойрог хамаарал үүсэхээр байна. Өмнөх ажлын сонголтоо шалгана уу.');

        return $result;
    }

    public function persistOrder(Block $block, array $ids): void
    {
        $rows = array_map(fn ($id, $index) => ['id' => $id, 'plan_order' => $index + 1], $ids, array_keys($ids));
        // Update existing rows only. Chunked CASE avoids an upsert trying to insert incomplete work.
        foreach (array_chunk($rows, 200) as $chunk) {
            $case = 'CASE id ';
            $bindings = [];
            foreach ($chunk as $row) {
                $case .= 'WHEN ? THEN ? ';
                $bindings[] = $row['id'];
                $bindings[] = $row['plan_order'];
            }
            $case .= 'END';
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            DB::update("UPDATE work_items SET plan_order = {$case} WHERE block_id = ? AND id IN ({$placeholders})", [...$bindings, $block->id, ...array_column($chunk, 'id')]);
        }
    }

    public function appendFrom(Block $block): int
    {
        if ($block->workItems()->whereNull('plan_order')->exists()) {
            $this->persistOrder($block, $this->read($block)['order']);
        }

        return (int) $block->workItems()->max('plan_order');
    }
}
