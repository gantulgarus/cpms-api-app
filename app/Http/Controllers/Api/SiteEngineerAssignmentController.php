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
            ->leftJoin('work_catalog_entries as wc', function ($join) { $join->on('wc.work_type_id', '=', 'wt.id')->whereNull('wc.deleted_at'); })
            ->where('wi.block_id', $block->id)
            ->groupBy('g.id', 'g.name', 'wc.subgroup_number')->orderBy('g.name')->orderBy('wc.subgroup_number')
            ->select('g.id as groupId', 'g.name as groupName', 'wc.subgroup_number as subgroupNumber')->selectRaw('MIN(wc.type_name) as typeName')
            ->selectRaw('count(*) as workItems')
            ->selectRaw("sum(case when wi.review_state = 'pending_check' then 1 else 0 end) as pendingCheck")
            ->get();
        $assigned = DB::table('block_site_engineer_assignments')->where('block_id', $block->id)->pluck('site_engineer_id', 'work_type_group_id');
        $overrides = DB::table('block_subgroup_engineer_assignments')->where('block_id', $block->id)->get()->keyBy(fn ($row) => $row->work_type_group_id.':'.$row->subgroup_number);
        $users = User::whereIn('id', $assigned->values()->merge($overrides->pluck('site_engineer_id')))->get()->keyBy('id');
        $shape = fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'position' => $u->position];
        $groups = $rows->map(function ($row) use ($assigned, $overrides, $users, $block, $shape) {
            $override = $overrides->get($row->groupId.':'.($row->subgroupNumber ?? ''));
            $user = $users->get($override ? $override->site_engineer_id : $assigned->get($row->groupId));
            $eligible = $user && $user->is_active && $user->role === 'site_engineer' && $user->canSeeBlock($block->id);

            return ['groupId' => $row->groupId, 'groupName' => $row->groupName, 'subgroupNumber' => $row->subgroupNumber ?? '', 'typeName' => $row->typeName,
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
            'subgroupNumber' => ['sometimes', 'present', 'nullable', 'string', 'max:50'],
            'siteEngineerId' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ]);
        $engineer = isset($input['siteEngineerId']) ? User::find($input['siteEngineerId']) : null;
        if ($engineer) {
            abort_unless($engineer->is_active && $engineer->role === 'site_engineer', 422, 'Идэвхтэй талбайн инженер сонгоно уу.');
            abort_unless($engineer->canSeeBlock($block->id), 422, 'Энэ инженерийн хамрах барилгад тухайн блок байхгүй байна.');
        }
        DB::transaction(function () use ($block, $input, $engineer, $request) {
            Block::whereKey($block->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('subgroupNumber', $input)) {
                $subgroup = $input['subgroupNumber'] ?? '';
                $exists = $block->workItems()->whereHas('workType', function ($q) use ($input, $subgroup) {
                    $q->where('work_type_group_id', $input['workTypeGroupId']);
                    if ($subgroup === '') $q->whereDoesntHave('catalogEntry');
                    else $q->whereHas('catalogEntry', fn ($c) => $c->where('subgroup_number', $subgroup));
                })->exists();
                abort_unless($exists, 422, 'Энэ барилгад тухайн дэд бүлгийн ажил алга.');
                DB::table('block_subgroup_engineer_assignments')->updateOrInsert([
                    'block_id' => $block->id, 'work_type_group_id' => $input['workTypeGroupId'], 'subgroup_number' => $subgroup,
                ], ['site_engineer_id' => $engineer?->id, 'assigned_by_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
                return;
            }
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

    public function storeInspector(Request $request, Block $block): JsonResponse
    {
        abort_unless($request->user()?->canAssignInspectors(), 403, 'Танд хяналтын инженер оноох эрх байхгүй.');
        $input = $request->validate([
            'workTypeGroupId' => ['required', 'uuid', 'exists:work_type_groups,id'],
            'subgroupNumber' => ['present', 'nullable', 'string', 'max:50'],
            'inspectorId' => ['present', 'nullable', 'integer', 'exists:users,id'],
        ]);
        $inspector = isset($input['inspectorId']) ? User::find($input['inspectorId']) : null;
        if ($inspector) abort_unless($inspector->is_active && $inspector->canInspect(), 422, 'Идэвхтэй, батлах эрхтэй хяналтын инженер сонгоно уу.');
        $updated = DB::transaction(function () use ($block, $input) {
            Block::whereKey($block->id)->lockForUpdate()->firstOrFail();
            $query = $block->workItems()->whereHas('workType', function ($q) use ($input) {
                $q->where('work_type_group_id', $input['workTypeGroupId']);
                if (! $input['subgroupNumber']) $q->whereDoesntHave('catalogEntry');
                else $q->whereHas('catalogEntry', fn ($c) => $c->where('subgroup_number', $input['subgroupNumber']));
            });
            abort_unless((clone $query)->exists(), 422, 'Энэ барилгад тухайн дэд бүлгийн ажил алга.');
            return $query->update(['inspector_id' => $input['inspectorId']]);
        });
        return response()->json(['data' => ['updated' => $updated]]);
    }

    private function authorizeAssignment(Request $request): void
    {
        abort_unless($request->user()?->canAssignInspectors(), 403, 'Танд талбайн инженер хуваарилах эрх байхгүй.');
    }
}
