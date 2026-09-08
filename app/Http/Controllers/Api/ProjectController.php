<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Төслийн жагсаалт.
 *
 * Client нь төслийн ID-г мэдэхгүй байж болно (UUID нь орчин бүрд өөр) тул
 * эхлээд эндээс олж авна. Хатуу бичсэн ID нь mock-оос жинхэнэ backend руу
 * шилжихэд эвдэрдэг.
 */
class ProjectController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return \App\Http\Resources\ProjectResource::collection(
            Project::with('company')->orderBy('name')->get()
        );
    }

    public function show(Project $project): JsonResponse
    {
        $project->load('company');

        return response()->json(['data' => new \App\Http\Resources\ProjectResource($project)]);
    }
}
