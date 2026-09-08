<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Блок үүсгэх.
 *
 * Загвар нь ЗААВАЛ БИШ. 75 барилгыг бүгдийг нь одоо бүртгэвэл 75 × 3,290 ≈
 * 247,000 мөр үүснэ — эхэлж байгаа барилгад нь л ажил үүсгэх нь зөв. Тиймээс
 * "одоо бүртгээд, загварыг дараа буулгах" урсгал боломжтой байх ёстой.
 *
 * Загваргүй бол давхар/айлын тоог ГАРААР авна — эс бөгөөс байршил үүсгэх
 * мэдээлэлгүй болно.
 */
class StoreBlockRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'buildingNo' => ['nullable', 'string', 'max:20'],
            'designId' => ['nullable', 'uuid', 'exists:block_designs,id'],
            'startDate' => ['required', 'date'],
            // Давхрын хугацаа — давхар тутамд ногдох ажлын өдөр. Өгөөгүй бол 5.
            'taktDays' => ['nullable', 'integer', 'min:1', 'max:60'],

            // Загваргүй үед л хэрэглэгдэнэ.
            'purpose' => ['nullable', 'string', 'max:255'],
            'floors' => ['nullable', 'integer', 'min:1', 'max:100'],
            'unitsPerFloor' => ['nullable', 'integer', 'min:0', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'taktDays.integer' => 'Давхрын хугацаа бүтэн тоо байна.',
            'taktDays.min' => 'Давхрын хугацаа хамгийн багадаа 1 ажлын өдөр.',
            'taktDays.max' => 'Давхрын хугацаа хамгийн ихдээ 60 ажлын өдөр.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->filled('designId')) {
                    return;   // Загвараас бүх хэмжээ ирнэ.
                }

                if (! $this->filled('floors')) {
                    $validator->errors()->add(
                        'floors',
                        'Загвар сонгоогүй бол давхрын тоог оруулна уу.'
                    );
                }
            },
        ];
    }
}
