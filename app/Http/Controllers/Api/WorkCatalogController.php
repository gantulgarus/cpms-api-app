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

    public function update(Request $request, WorkCatalogEntry $workCatalogEntry): JsonResponse
    {
        abort_unless($request->user()?->canManageReferenceData(), 403, 'Танд лавлах сан засах эрх байхгүй.');
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:5000'],
            'level' => ['nullable', Rule::in(WorkType::LEVELS)],
        ]);
        $workCatalogEntry = app(ConfigureCatalogWork::class)->update($workCatalogEntry, $validated);
        return response()->json(['data' => $workCatalogEntry->toCatalogArray()]);
    }
}
