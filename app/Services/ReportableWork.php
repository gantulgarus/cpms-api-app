<?php

namespace App\Services;

use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Reporting readiness, filtered before pagination; no per-row database queries. */
class ReportableWork
{
    /** SQL equivalent of HoldPointGate::blockers, including ancestor locations. */
    private function blockersQuery(): QueryBuilder
    {
        $prefix = DB::connection()->getDriverName() === 'mysql'
            ? "concat(hp_location.path_key, '%')" : "hp_location.path_key || '%'";

        return DB::table('work_items as hp')
            ->selectRaw('1')
            ->join('work_types as hp_type', 'hp_type.id', '=', 'hp.work_type_id')
            ->join('work_type_groups as hp_group', 'hp_group.id', '=', 'hp_type.work_type_group_id')
            ->join('locations as hp_location', 'hp_location.id', '=', 'hp.location_id')
            // MySQL cannot resolve outer-query columns inside a subquery JOIN ON.
            // Correlate in WHERE instead; the equality predicates still constrain both tables.
            ->crossJoin('locations as target_location')
            ->crossJoin('work_types as target_type')
            ->join('work_type_groups as target_group', 'target_group.id', '=', 'target_type.work_type_group_id')
            ->whereColumn('target_location.id', 'work_items.location_id')
            ->whereColumn('target_type.id', 'work_items.work_type_id')
            ->whereColumn('hp.block_id', 'work_items.block_id')
            ->whereColumn('hp.id', '!=', 'work_items.id')
            ->where('hp.planned_qty', '>', 0)
            ->where('hp.status', '!=', 'completed')
            ->where('hp_type.is_hold_point', true)
            ->whereColumn('hp_group.build_order', '<', 'target_group.build_order')
            ->whereRaw("target_location.path_key like {$prefix}");
    }

    public function filter(Builder $query, string $state): Builder
    {
        return match ($state) {
            'completed' => $query->where('status', 'completed'),
            'pending' => $query->where('status', '!=', 'completed')->where('planned_qty', '>', 0)
                ->whereRaw('round(planned_qty - reported_qty, 3) <= 0'),
            'ready' => $query->where('status', '!=', 'completed')->where('planned_qty', '>', 0)
                ->whereRaw('round(planned_qty - reported_qty, 3) > 0')
                ->whereNotExists($this->blockersQuery()),
            'blocked' => $query->where('status', '!=', 'completed')->where(function ($q) {
                $q->where('planned_qty', '<=', 0)->orWhere(function ($q) {
                    $q->whereRaw('round(planned_qty - reported_qty, 3) > 0')->whereExists($this->blockersQuery());
                });
            }),
        };
    }

    /** Load blockers for only the current page in one batch, including other contractors. */
    public function describe(Collection $items, $user): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $candidates = WorkItem::query()->with(['workType.group', 'location'])
            ->where('planned_qty', '>', 0)->where('status', '!=', 'completed')
            ->whereHas('workType', fn ($q) => $q->where('is_hold_point', true))
            ->where(function ($q) use ($items) {
                foreach ($items as $item) {
                    $q->orWhere(function ($q) use ($item) {
                        $q->where('block_id', $item->block_id)
                            ->whereIn('location_id', array_filter(explode('/', $item->location?->path_key ?? '')))
                            ->whereHas('workType.group', fn ($g) => $g->where('build_order', '<', $item->workType?->group?->build_order ?? -1));
                    });
                }
            })->orderBy('name')->get()->groupBy('block_id');

        $result = [];
        foreach ($items as $item) {
            $ancestors = array_filter(explode('/', $item->location?->path_key ?? ''));
            $order = $item->workType?->group?->build_order;
            $blockers = ($candidates->get($item->block_id) ?? collect())->filter(fn ($w) => $order !== null && $w->id !== $item->id && in_array($w->location_id, $ancestors, true)
                && $w->workType?->group?->build_order !== null && $w->workType->group->build_order < $order
            );
            $qty = max(0, round((float) $item->planned_qty - (float) $item->reported_qty, 3));
            $state = $item->status === 'completed' ? 'completed'
                : ((float) $item->planned_qty <= 0 ? 'blocked'
                : ($qty <= 0 ? 'pending' : ($blockers->isNotEmpty() ? 'blocked' : 'ready')));
            $reason = match ($state) {
                'completed' => 'Ажил бүрэн батлагдсан.',
                'pending' => 'Мэдээлсэн гүйцэтгэл шалгалт, батлалт хүлээж байна.',
                'blocked' => (float) $item->planned_qty <= 0 ? 'Төлөвлөгөөт тоо хэмжээ оруулаагүй байна.'
                    : 'Өмнөх далд ажлыг бүрэн батлуулах шаардлагатай: '.$blockers->take(3)->map(fn ($w) => $w->name.' ('.$w->location?->path.')')->implode(', ')
                        .($blockers->count() > 3 ? ' болон бусад '.($blockers->count() - 3).' ажил' : ''),
                default => null,
            };
            $result[$item->id] = [
                'reportingState' => $state,
                'reportableQty' => $state === 'ready' ? $qty : 0,
                'reportingReason' => $reason,
                // Never link the representative to another contractor's private work.
                'blockingWorkItems' => $blockers->filter(fn ($w) => app(WorkScope::class)->allows($user, $w))->take(3)
                    ->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'locationPath' => $w->location?->path])->values()->all(),
            ];
        }

        return $result;
    }
}
