<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePhotoRequest;
use App\Http\Resources\PhotoResource;
use App\Models\Photo;
use App\Models\WorkItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Гүйцэтгэлийн зургийн баримт.
 *
 * Захиалагч: "Зөвхөн зураг оруулж байгаа нь ажлын тайлан биш" — зураг нь тоо
 * хэмжээг ОРЛОХГҮЙ, харин баталгаажуулахад нотолгоо болно. Хяналтын инженер
 * батлахаасаа өмнө харах ёстой зүйл.
 */
class PhotoController extends Controller
{
    public function index(WorkItem $workItem): AnonymousResourceCollection
    {
        return PhotoResource::collection(
            $workItem->photos()->with('uploadedBy')->latest('created_at')->get()
        );
    }

    public function store(StorePhotoRequest $request, WorkItem $workItem): JsonResponse
    {
        $path = $request->file('file')->store("work-photos/{$workItem->block_id}", 'local');

        $photo = $workItem->photos()->create([
            'progress_entry_id' => $request->validated('progressEntryId'),
            'type' => $request->validated('type'),
            'path' => $path,
            'uploaded_by_id' => $request->user()?->id,
            'taken_at' => $request->validated('takenAt') ?? now(),
            'locked' => false,
        ]);

        return response()->json(
            ['data' => new PhotoResource($photo->load('uploadedBy'))],
            201,
        );
    }

    /**
     * Батлагдсан зургийг устгахыг хориглоно.
     *
     * Шалгалт хийгдмэгц зураг түгжигддэг — баримтыг дараа нь өөрчлөх
     * боломжгүй байх ёстой.
     */
    public function destroy(Photo $photo): JsonResponse
    {
        abort_if($photo->locked, 409, 'Батлагдсан зургийг устгах боломжгүй.');

        Storage::disk('local')->delete($photo->path);
        $photo->delete();

        return response()->json(null, 204);
    }

    /** Гарын үсэгтэй хаягаар файлыг дамжуулна (`signed` middleware хамгаална). */
    public function file(Photo $photo): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path);
    }
}
