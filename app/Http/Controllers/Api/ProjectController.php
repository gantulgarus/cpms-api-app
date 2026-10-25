<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Company;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Төсөл ба захиалагч компани.
 *
 * Client нь төслийн ID-г мэдэхгүй байж болно (UUID нь орчин бүрд өөр) тул
 * эхлээд эндээс олж авна. Хатуу бичсэн ID нь mock-оос жинхэнэ backend руу
 * шилжихэд эвдэрдэг.
 */
class ProjectController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProjectResource::collection(
            Project::with('company')->orderBy('name')->get()
        );
    }

    public function show(Project $project): JsonResponse
    {
        $project->load('company');

        return response()->json(['data' => new ProjectResource($project)]);
    }

    /**
     * GET /companies — төсөл үүсгэх маягтын компани сонголт.
     */
    public function companies(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canManageProjects(), 403, 'Танд төсөл удирдах эрх байхгүй.');

        return response()->json([
            'data' => Company::orderBy('name')->get(['id', 'name'])->map(fn (Company $c) => [
                'id' => $c->id,
                'name' => $c->name,
            ]),
        ]);
    }

    /**
     * POST /projects
     *
     * Компанийг `companyId`-аар сонгоно, эсвэл `companyName`-аар шинээр
     * үүсгэнэ. Хоосон серверт (demo:purge-ийн дараа) компани ч, төсөл ч
     * байхгүй байж болох тул хоёуланг нь нэг алхмаар үүсгэх боломжтой.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canManageProjects(), 403, 'Танд төсөл үүсгэх эрх байхгүй.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'startDate' => ['nullable', 'date_format:Y-m-d'],
            'endDate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:startDate'],
            'companyId' => ['nullable', 'uuid', 'exists:companies,id'],
            'companyName' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => 'Төслийн нэр заавал.',
            'endDate.after_or_equal' => 'Дуусах огноо эхлэх огнооноос өмнө байж болохгүй.',
            'companyId.exists' => 'Компани олдсонгүй.',
        ]);

        if (empty($data['companyId']) && blank($data['companyName'] ?? null)) {
            throw ValidationException::withMessages([
                'companyId' => 'Компани сонгох эсвэл шинэ компанийн нэр оруулна уу.',
            ]);
        }

        $project = DB::transaction(function () use ($data) {
            $company = ! empty($data['companyId'])
                ? Company::findOrFail($data['companyId'])
                : Company::create(['name' => trim($data['companyName'])]);

            return $company->projects()->create([
                'name' => trim($data['name']),
                'code' => $data['code'] ?? null,
                'location' => $data['location'] ?? null,
                'description' => $data['description'] ?? null,
                'start_date' => $data['startDate'] ?? null,
                'end_date' => $data['endDate'] ?? null,
                'status' => 'active',
                // UI-аас үүссэн төсөл бодит — `demo:purge` устгахгүй.
                'is_demo' => false,
            ]);
        });

        $project->load('company');

        return response()->json(['data' => new ProjectResource($project)], 201);
    }
}
