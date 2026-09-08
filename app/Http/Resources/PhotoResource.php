<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

class PhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workItemId' => $this->work_item_id,
            'progressEntryId' => $this->progress_entry_id,
            'type' => $this->type,
            // Гарын үсэгтэй, хугацаатай хаяг. `<img src>` толгой дамжуулж
            // чаддаггүй тул энгийн token ажиллахгүй — гарын үсэг нь хандалтыг
            // хамгаална, хаяг задарсан ч 1 цагийн дараа хүчингүй болно.
            'url' => URL::temporarySignedRoute('photos.file', now()->addHour(), ['photo' => $this->id]),
            'takenAt' => $this->taken_at?->toIso8601String(),
            'uploadedAt' => $this->created_at?->toIso8601String(),
            'uploadedBy' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy?->name),
            // Батлагдсаны дараа устгах боломжгүй (RULE-10).
            'locked' => (bool) $this->locked,
        ];
    }
}
