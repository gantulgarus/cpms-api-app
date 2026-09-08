<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Хуудаслалтын мэдээллийг гэрээний хэлбэрт оруулна.
 *
 * Laravel-ийн анхдагч paginator нь `current_page` / `per_page` гэж буцаадаг,
 * харин гэрээ (`docs/api-v2-endpoints.md`) ба mock хоёр `page` / `pageSize`
 * гэж тохирсон. Зөрүүтэй байвал дэлгэц дээрх "1–50 / 3,290" гэсэн тоолуур
 * NaN болно.
 */
class WorkItemCollection extends ResourceCollection
{
    public $collects = WorkItemResource::class;

    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'meta' => [
                'total' => $paginated['total'],
                'page' => $paginated['current_page'],
                'pageSize' => (int) $paginated['per_page'],
            ],
        ];
    }
}
