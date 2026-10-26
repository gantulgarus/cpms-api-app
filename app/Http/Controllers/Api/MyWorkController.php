<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkItemResource;
use App\Models\Project;
use App\Models\WorkItem;
use App\Services\ReportableWork;
use App\Services\WorkItemFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyWorkController extends Controller
{
    public function index(Request $request, Project $project, WorkItemFilter $filter, ReportableWork $reporting): JsonResponse
    {
        abort_unless($request->user()?->canReportProgress(), 403);
        $input = $request->validate([
            'reportingState' => ['sometimes', 'in:ready,blocked,pending,completed'],
            'blockId' => ['nullable', 'uuid'], 'locationId' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:150'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'pageSize' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $base = WorkItem::query()->whereHas('block', fn ($q) => $q->where('project_id', $project->id));
        if (! empty($input['blockId'])) {
            $base->where('block_id', $input['blockId']);
        }
        $filter->apply($base, $request);
        $counts = [];
        foreach (['ready', 'blocked', 'pending', 'completed'] as $state) {
            $counts[$state] = $reporting->filter(clone $base, $state)->count();
        }
        $query = $reporting->filter(clone $base, $input['reportingState'] ?? 'ready');
        $page = $query->with(['block', 'location', 'workType.group', 'contractor', 'inspector'])
            ->orderByRaw('planned_end_date is null')->orderBy('planned_end_date')->orderBy('id')
            ->paginate($input['pageSize'] ?? 50);
        $descriptions = $reporting->describe($page->getCollection(), $request->user());

        return response()->json([
            'data' => $page->getCollection()->map(fn ($item) => array_merge(
                (new WorkItemResource($item))->resolve($request), $descriptions[$item->id]
            )),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'pageSize' => $page->perPage()],
            'counts' => $counts,
        ])->header('Cache-Control', 'private, no-store');
    }
}
