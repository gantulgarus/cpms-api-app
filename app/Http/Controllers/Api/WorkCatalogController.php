<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkCatalogEntry;
use App\Models\WorkType;
use App\Services\ConfigureCatalogWork;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class WorkCatalogController
{
    public function destroy(Request $request, WorkCatalogEntry $workCatalogEntry): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->canManageReferenceData(), 403, 'Танд лавлах сан засах эрх байхгүй.');
        try {
            DB::transaction(function () use ($workCatalogEntry) {
                $entry = WorkCatalogEntry::lockForUpdate()->findOrFail($workCatalogEntry->id);
                $type = $entry->work_type_id ? WorkType::lockForUpdate()->find($entry->work_type_id) : null;
                if ($type) {
                    foreach (['work_items', 'design_items', 'agreement_lines', 'material_budget_items', 'checklist_templates'] as $table) {
                        abort_if(DB::table($table)->where('work_type_id', $type->id)->exists(), 409,
                            'Энэ ажил төлөвлөгөө, загвар эсвэл бусад бүртгэлд ашиглагдсан тул устгах боломжгүй.');
                    }
                    $type->delete();
                    $entry->work_type_id = null;
                }
                // Keep source key and generated identity reserved, including on reimport.
                $entry->delete();
            });
        } catch (QueryException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23503'], true)) {
                abort(409, 'Энэ ажил бусад бүртгэлтэй холбоотой тул устгах боломжгүй.');
            }
            throw $error;
        }
        return response()->noContent();
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => WorkCatalogEntry::with(['workType' => fn ($query) => $query->withCount('workItems')])->orderBy('sequence_number')->orderBy('id')->get()
            ->map(fn ($entry) => $entry->toCatalogArray())->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canManageReferenceData(), 403, 'Танд лавлах сан засах эрх байхгүй.');
        $request->validate([
            'groupName' => ['required', 'string', 'max:255'],
            'subgroupNumber' => ['required', 'string', 'max:50'],
            'typeName' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'sequenceNumber' => ['required', 'integer', 'min:1', 'max:2147483647'],
        ]);
        return DB::transaction(function () use ($request) {
            $entry = WorkCatalogEntry::create([
                'group_name' => $request->input('groupName'),
                'subgroup_number' => $request->input('subgroupNumber'),
                'type_name' => $request->input('typeName'),
                'name' => $request->input('name'),
                'sequence_number' => $request->input('sequenceNumber'),
            ]);
            $response = $this->update($request, $entry);
            $response->setStatusCode(201);
            return $response;
        });
    }

    public function update(Request $request, WorkCatalogEntry $workCatalogEntry): JsonResponse
    {
        abort_unless($request->user()?->canManageReferenceData(), 403, 'Танд лавлах сан засах эрх байхгүй.');
        $validated = $request->validate([
            'groupName' => ['sometimes', 'required', 'string', 'max:255'],
            'subgroupNumber' => ['sometimes', 'required', 'string', 'max:50'],
            'typeName' => ['sometimes', 'required', 'string', 'max:255'],
            'locationDescription' => ['nullable', 'string', 'max:5000'],
            'floorDescription' => ['nullable', 'string', 'max:5000'],
            'sequenceNumber' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:5000'],
            'level' => ['nullable', Rule::in(WorkType::LEVELS)],
        ]);
        foreach (['groupName' => 'group_name', 'subgroupNumber' => 'subgroup_number', 'typeName' => 'type_name', 'locationDescription' => 'location_description', 'floorDescription' => 'floor_description', 'sequenceNumber' => 'sequence_number'] as $key => $column) {
            if (array_key_exists($key, $validated)) $validated[$column] = $validated[$key];
        }
        $workCatalogEntry = app(ConfigureCatalogWork::class)->update($workCatalogEntry, $validated);
        return response()->json(['data' => $workCatalogEntry->toCatalogArray()]);
    }
}
