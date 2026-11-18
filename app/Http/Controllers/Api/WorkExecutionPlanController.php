<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\WorkItem;
use App\Services\WorkExecutionPlan;
use App\Services\WorkScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkExecutionPlanController extends Controller
{
    public function index(Request $request, Block $block, WorkExecutionPlan $plan): JsonResponse
    {
        $query = WorkItem::where('block_id', $block->id);
        app(WorkScope::class)->workItems($query, $request->user());
        $visible = array_fill_keys($query->pluck('id')->all(), true);
        $result = $plan->read($block);
        $result['order'] = array_values(array_filter($result['order'], fn ($id) => isset($visible[$id])));
        $links = [];
        foreach ((array) $result['predecessors'] as $id => $previous) {
            if (isset($visible[$id])) {
                $links[$id] = array_values(array_filter($previous, fn ($previousId) => isset($visible[$previousId])));
            }
        }
        $result['predecessors'] = (object) $links;

        return response()->json(['data' => $result]);
    }

    public function move(Request $request, Block $block, WorkExecutionPlan $plan): JsonResponse
    {
        abort_unless($request->user()?->canEditPlan(), 403, 'Танд ажлын дараалал засах эрх байхгүй.');
        $sameBlock = Rule::exists('work_items', 'id')->where('block_id', $block->id);
        $input = $request->validate([
            'workItemId' => ['required', 'uuid', $sameBlock],
            'relativeToId' => ['required', 'uuid', $sameBlock, 'different:workItemId'],
            'position' => ['required', Rule::in(['before', 'after'])],
            'version' => ['required', 'string', 'size:64'],
        ]);
        $result = DB::transaction(function () use ($block, $plan, $input) {
            Block::whereKey($block->id)->lockForUpdate()->firstOrFail();
            $current = $plan->read($block);
            abort_unless(hash_equals($current['version'], $input['version']), 409, 'Төлөвлөгөө өөрчлөгдсөн байна. Дахин ачаалаад оролдоно уу.');
            $ids = array_values(array_diff($current['order'], [$input['workItemId']]));
            $index = array_search($input['relativeToId'], $ids, true);
            abort_if($index === false, 409, 'Сонгосон ажил устгагдсан байна. Дахин ачаална уу.');
            array_splice($ids, $index + ($input['position'] === 'after' ? 1 : 0), 0, [$input['workItemId']]);
            $ranks = array_flip($ids);
            foreach ((array) $current['predecessors'] as $id => $previous) {
                foreach ($previous as $previousId) {
                    abort_if($ranks[$previousId] >= $ranks[$id], 422, 'Өмнөх ажил дараагийн ажлынхаа урд байрлах ёстой. Хамаарлыг шалгана уу.');
                }
            }
            $plan->persistOrder($block, $ids);

            return $plan->read($block);
        });

        return response()->json(['data' => $result]);
    }

    public function dependencies(Request $request, Block $block, WorkExecutionPlan $plan): JsonResponse
    {
        abort_unless($request->user()?->canEditPlan(), 403, 'Танд ажлын хамаарал тохируулах эрх байхгүй.');
        $sameBlock = Rule::exists('work_items', 'id')->where('block_id', $block->id);
        $input = $request->validate([
            'version' => ['required', 'string', 'size:64'],
            'links' => ['required', 'array', 'min:1', 'max:2000'],
            'links.*.workItemId' => ['required', 'uuid', 'distinct', $sameBlock],
            'links.*.predecessorIds' => ['present', 'array', 'max:2000'],
            'links.*.predecessorIds.*' => ['required', 'uuid', $sameBlock],
        ]);
        $result = DB::transaction(function () use ($block, $plan, $input) {
            Block::whereKey($block->id)->lockForUpdate()->firstOrFail();
            $current = $plan->read($block);
            abort_unless(hash_equals($current['version'], $input['version']), 409, 'Төлөвлөгөө өөрчлөгдсөн байна. Дахин ачаалаад оролдоно уу.');
            $known = array_fill_keys($current['order'], true);
            $predecessors = (array) $current['predecessors'];
            foreach ($input['links'] as $link) {
                abort_unless(isset($known[$link['workItemId']]), 409, 'Ажлын жагсаалт өөрчлөгдсөн байна. Дахин ачаална уу.');
                $previous = array_values(array_unique($link['predecessorIds']));
                abort_if(in_array($link['workItemId'], $previous, true), 422, 'Ажил өөрөөсөө хамаарах боломжгүй.');
                foreach ($previous as $id) {
                    abort_unless(isset($known[$id]), 409, 'Өмнөх ажил устгагдсан байна. Дахин ачаална уу.');
                }
                $predecessors[$link['workItemId']] = $previous;
            }
            $order = $plan->ordered($current['order'], $predecessors);
            foreach ($input['links'] as $link) {
                DB::table('work_item_dependencies')->where('work_item_id', $link['workItemId'])->delete();
                $rows = array_map(fn ($id) => ['work_item_id' => $link['workItemId'], 'predecessor_id' => $id], $predecessors[$link['workItemId']]);
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table('work_item_dependencies')->insert($chunk);
                }
            }
            $plan->persistOrder($block, $order);

            return $plan->read($block);
        });

        return response()->json(['data' => $result]);
    }
}
