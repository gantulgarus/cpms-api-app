<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PhotoResource extends JsonResource
{
    /** `routes/api.php`-ийн угтвар: Laravel-ийн `api` + `->prefix('v1')`. */
    private const API_PREFIX = '/api/v1';


    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workItemId' => $this->work_item_id,
            'progressEntryId' => $this->progress_entry_id,
            'type' => $this->type,
            /*
             * Гарын үсэгтэй, хугацаатай ХАРЬЦАНГУЙ хаяг.
             *
             * `<img src>` нь Authorization толгой дамжуулж чаддаггүй тул
             * энгийн token ажиллахгүй — гарын үсэг нь хандалтыг хамгаална,
             * хаяг задарсан ч 1 цагийн дараа хүчингүй болно.
             *
             * ЯАГААД ХАРЬЦАНГУЙ (`absolute: false`): бүтэн хаяг нь ИРЖ БУЙ
             * ХҮСЭЛТИЙН Host толгойноос үүсдэг, `APP_URL`-ээс биш. Frontend
             * нь прокси дамжуулан `127.0.0.1` руу залгадаг тул зургийн хаяг
             * `http://127.0.0.1/...` болж, хэрэглэгчийн хөтөч түүнийг
             * ӨӨРИЙНХӨӨ компьютер гэж ойлгоод зураг харагддаггүй байв.
             *
             * Харьцангуй хаяг нь ямар ч хост, порт, HTTPS дээр ажиллана.
             */
            'url' => self::relativeFileUrl($this->id),
            'takenAt' => $this->taken_at?->toIso8601String(),
            'uploadedAt' => $this->created_at?->toIso8601String(),
            'uploadedBy' => $this->whenLoaded('uploadedBy', fn () => $this->uploadedBy?->name),
            // Батлагдсаны дараа устгах боломжгүй (RULE-10).
            'locked' => (bool) $this->locked,
        ];
    }

    /**
     * API-ийн ҮНДЭСНЭЭС харьцангуй зам: `/photos/{id}/file?expires=…`.
     *
     * Frontend үүнийг өөрийн прокси хаягтай нийлүүлнэ. Ингэснээр хөгжүүлэлт
     * (`:3000` → `:8000`) ба сервер (нэг эх үүсвэр) хоёулан дээр ижил
     * ажиллана.
     */
    private static function relativeFileUrl(string $photoId): string
    {
        $signed = URL::temporarySignedRoute(
            'photos.file',
            now()->addHour(),
            ['photo' => $photoId],
            absolute: false,
        );

        // `$signed` = "/api/v1/photos/{id}/file?…". API-ийн угтварыг хасна —
        // frontend нь өөрийн проксигийн угтварыг нэмнэ.
        return Str::after($signed, self::API_PREFIX);
    }
}
