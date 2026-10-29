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
        $query->whereExists(fn ($q) => $q->selectRaw('1')
            ->from('block_site_engineer_assignments as sea')
            ->join('work_types as sea_type', 'sea_type.work_type_group_id', '=', 'sea.work_type_group_id')
            // Correlation belongs in WHERE, not JOIN ON (MySQL).
            ->whereColumn('sea.block_id', "{$alias}.block_id")
            ->whereColumn('sea_type.id', "{$alias}.work_type_id")
            ->where('sea.site_engineer_id', $user->id));
    }

    public function engineer(WorkItem $item): ?User
    {
        $groupId = $item->workType?->work_type_group_id;
        if (! $groupId) {
            return null;
        }
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
