<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $manager = (bool) $request->user()?->canManageContractors();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'companyName' => $this->company_name,
            'tradeSpecialty' => $this->trade_specialty,
            'contactPerson' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,

            // Нэвтрэх код нь Wi-Fi нууц үг шиг зүйл: оффисын хүн гүйцэтгэгчид
            // утсаар дамжуулах шаардлагатай тул дахин харах боломжтой байх ёстой.
            // Тиймээс задлаж хадгална, гэхдээ ЗӨВХӨН удирдах эрхтэйд харуулна.
            'accessCode' => $this->when($manager, $this->access_code),
            'accessCodeExpiresAt' => $this->when($manager, $this->access_code_expires_at?->toDateString()),
            'hasValidAccessCode' => $this->hasValidAccessCode(),
        ];
    }
}
