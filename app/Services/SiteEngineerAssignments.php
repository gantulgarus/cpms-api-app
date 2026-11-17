<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** A block/group rule also applies to work created after the assignment. */
class SiteEngineerAssignments
{
    public function scope(EloquentBuilder|QueryBuilder $query, User $user, string $alias = 'work_items'): void
    {
        if ($user->role !== 'site_engineer') {
            return;
        }
        $query->where(function ($query) use ($user, $alias) {
            $query->whereExists(fn ($q) => $q->selectRaw('1')
            ->from('block_site_engineer_assignments as sea')
            ->join('work_types as sea_type', 'sea_type.work_type_group_id', '=', 'sea.work_type_group_id')
            // Correlation belongs in WHERE, not JOIN ON (MySQL).
            ->whereColumn('sea.block_id', "{$alias}.block_id")
            ->whereColumn('sea_type.id', "{$alias}.work_type_id")
            ->leftJoin('work_catalog_entries as sea_catalog', function ($join) { $join->on('sea_catalog.work_type_id', '=', 'sea_type.id')->whereNull('sea_catalog.deleted_at'); })
            ->whereNotExists(fn ($override) => $override->selectRaw('1')->from('block_subgroup_engineer_assignments as sea_override')
                ->whereColumn('sea_override.block_id', 'sea.block_id')->whereColumn('sea_override.work_type_group_id', 'sea.work_type_group_id')
                ->whereRaw("sea_override.subgroup_number = COALESCE(sea_catalog.subgroup_number, '')"))
            ->where('sea.site_engineer_id', $user->id));
            $query->orWhereExists(fn ($q) => $q->selectRaw('1')->from('block_subgroup_engineer_assignments as sub')
                ->join('work_types as sub_type', 'sub_type.work_type_group_id', '=', 'sub.work_type_group_id')
                ->leftJoin('work_catalog_entries as sub_catalog', function ($join) { $join->on('sub_catalog.work_type_id', '=', 'sub_type.id')->whereNull('sub_catalog.deleted_at'); })
                ->whereColumn('sub.block_id', "{$alias}.block_id")->whereColumn('sub_type.id', "{$alias}.work_type_id")
                ->whereRaw("sub.subgroup_number = COALESCE(sub_catalog.subgroup_number, '')")
                ->where('sub.site_engineer_id', $user->id));
        });
    }

    public function engineer(WorkItem $item): ?User
    {
        $groupId = $item->workType?->work_type_group_id;
        if (! $groupId) {
            return null;
        }
        $override = DB::table('block_subgroup_engineer_assignments')->where('block_id', $item->block_id)
            ->where('work_type_group_id', $groupId)->where('subgroup_number', $item->workType?->catalogEntry?->subgroup_number ?? '')->first();
        if ($override) return $override->site_engineer_id ? User::find($override->site_engineer_id) : null;
        $id = DB::table('block_site_engineer_assignments')
            ->where('block_id', $item->block_id)->where('work_type_group_id', $groupId)->value('site_engineer_id');

        return $id ? User::find($id) : null;
    }

    public function canCheck(User $user, WorkItem $item): bool
    {
        if (! $user->canCheck()) {
            return false;
        }
        if ($user->role !== 'site_engineer') {
            return true;
        }
        $query = WorkItem::query()->whereKey($item->id);
        $this->scope($query, $user);

        return $user->is_active && $user->canSeeBlock($item->block_id) && $query->exists();
    }
}
