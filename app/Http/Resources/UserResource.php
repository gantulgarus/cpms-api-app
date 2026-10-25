<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'roleLabel' => User::ROLES[$this->role] ?? $this->role,

            /*
             * Албан тушаал нь үүргээс ТУСДАА. Б.Сайхантамир «Гэрээний
             * мэргэжилтэн» атлаа `inspector` үүрэгтэй — хяналтын инженер
             * сонгох жагсаалтад дөрвөн хүн бүгд «Хяналтын инженер» гэж
             * харагдвал хэн нь хэн болохыг ялгах арга үгүй болно.
             */
            'position' => $this->position,
            'unit' => $this->unit,

            'scopeBlockIds' => $this->scope_block_ids ?? [],
            'contractorId' => $this->contractor_id,
            'contractorName' => $this->whenLoaded('contractor', fn () => $this->contractor?->name),
            'isActive' => (bool) $this->is_active,

            // Эрхийг дэлгэц дахин бодохгүй — жагсаалт дээр "энэ хүн батлах
            // эрхтэй юу" гэдгийг харахад хэрэгтэй.
            'canReportProgress' => $this->canReportProgress(),
            'canInspect' => $this->canInspect(),
            'canCheck' => $this->canCheck(),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
