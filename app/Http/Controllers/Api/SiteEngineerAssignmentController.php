<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteEngineerAssignmentController extends Controller
{
    public function index(Request $request, Block $block): JsonResponse
    {
        $this->authorizeAssignment($request);
        $rows = DB::table('work_items as wi')
            ->join('work_types as wt', 'wt.id', '=', 'wi.work_type_id')
            ->join('work_type_groups as g', 'g.id', '=', 'wt.work_type_group_id')
            ->where('wi.block_id', $block->id)
            ->groupBy('g.id', 'g.name')->orderBy('g.name')
            ->select('g.id as groupId', 'g.name as groupName')
            ->selectRaw('count(*) as workItems')
            ->selectRaw("sum(case when wi.review_state = 'pending_check' then 1 else 0 end) as pendingCheck")
            ->get();
        $assigned = DB::table('block_site_engineer_assignments')->where('block_id', $block->id)->pluck('site_engineer_id', 'work_type_group_id');
        $users = User::whereIn('id', $assigned->values())->get()->keyBy('id');
        $shape = fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'position' => $u->position];
        $groups = $rows->map(function ($row) use ($assigned, $users, $block, $shape) {
            $user = $users->get($assigned->get($row->groupId));
            $eligible = $user && $user->is_active && $user->role === 'site_engineer' && $user->canSeeBlock($block->id);

            return ['groupId' => $row->groupId, 'groupName' => $row->groupName,
                'workItems' => (int) $row->workItems, 'pendingCheck' => (int) $row->pendingCheck,
                'siteEngineer' => $user ? $shape($user) : null, 'needsAssignment' => ! $eligible];
        });
        $engineers = User::where('role', 'site_engineer')->where('is_active', true)->orderBy('name')->get()
            ->filter(fn ($u) => $u->canSeeBlock($block->id))->map($shape)->values();

        return response()->json(['data' => ['groups' => $groups, 'engineers' => $engineers]]);
    }

    public function store(Request $request, Block $block): JsonResponse
    {
        $this->authorizeAssignment($request);
        $input = $request->validate([
            'workTypeGroupId' => ['required', 'uuid', 'exists:work_type_groups,id'],
            'siteEngineerId' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ]);
        $engineer = isset($input['siteEngineerId']) ? User::find($input['siteEngineerId']) : null;
        if ($engineer) {
            abort_unless($engineer->is_active && $engineer->role === 'site_engineer', 422, 'Идэвхтэй талбайн инженер сонгоно уу.');
            abort_unless($engineer->canSeeBlock($block->id), 422, 'Энэ инженерийн хамрах барилгад тухайн блок байхгүй байна.');
        }
        DB::transaction(function () use ($block, $input, $engineer, $request) {
            Block::whereKey($block->id)->lockForUpdate()->firstOrFail();
            $query = DB::table('block_site_engineer_assignments')->where('block_id', $block->id)->where('work_type_group_id', $input['workTypeGroupId']);
            if (! $engineer) {
                $query->delete();

                return;
            }
            $now = now();
            if ($query->exists()) {
                $query->update(['site_engineer_id' => $engineer->id, 'assigned_by_id' => $request->user()->id, 'updated_at' => $now]);
            } else {
                DB::table('block_site_engineer_assignments')->insert([
                    'block_id' => $block->id, 'work_type_group_id' => $input['workTypeGroupId'],
                    'site_engineer_id' => $engineer->id, 'assigned_by_id' => $request->user()->id,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });

        return response()->json(['data' => ['saved' => true]]);
    }

    private function authorizeAssignment(Request $request): void
    {
        abort_unless($request->user()?->canAssignInspectors(), 403, 'Танд талбайн инженер хуваарилах эрх байхгүй.');
    }
}
