<?php

namespace App\Http\Resources;

use App\Models\Contractor;
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
            'registrationNo' => $this->registration_no,
            'tradeSpecialty' => $this->trade_specialty,
            'category' => $this->category,
            'categoryLabel' => Contractor::CATEGORIES[$this->category] ?? null,
            'status' => $this->status,
            'statusLabel' => Contractor::STATUSES[$this->status] ?? $this->status,
            'canTakeWork' => $this->canTakeWork(),
            'contactPerson' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,

            /*
             * Дансны мэдээлэл нь ЗӨВХӨН удирдах эрхтэйд — төлбөрийн өгөгдөл
             * тул талбайн инженерт хэрэггүй.
             */
            'bankName' => $this->when($manager, $this->bank_name),
            'bankAccount' => $this->when($manager, $this->bank_account),

            /*
             * Тохиролцоо бичихэд дутуу талбарууд.
             *
             * Дэлгэц дээр УРЬДЧИЛЖ харуулна: гэрээний мэргэжилтэн хуудас
             * хэвлэх гэж байж «РД байхгүй» гэдгийг мэдэх нь оройтсон.
             */
            'missingContractFields' => $this->missingContractFields(),

            // Нэвтрэх код нь Wi-Fi нууц үг шиг зүйл: оффисын хүн гүйцэтгэгчид
            // утсаар дамжуулах шаардлагатай тул дахин харах боломжтой байх ёстой.
            // Тиймээс задлаж хадгална, гэхдээ ЗӨВХӨН удирдах эрхтэйд харуулна.
            'accessCode' => $this->when($manager, $this->access_code),
            'accessCodeExpiresAt' => $this->when($manager, $this->access_code_expires_at?->toDateString()),
            'hasValidAccessCode' => $this->hasValidAccessCode(),
        ];
    }
}
