<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlockDesignResource;
use App\Models\BlockDesign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BlockDesignController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return BlockDesignResource::collection(
            BlockDesign::with('items.workType')->where('is_active', true)->orderBy('name')->get()
        );
    }

    public function show(BlockDesign $blockDesign): JsonResponse
    {
        $blockDesign->load('items.workType');

        return response()->json(['data' => new BlockDesignResource($blockDesign)]);
    }
}
