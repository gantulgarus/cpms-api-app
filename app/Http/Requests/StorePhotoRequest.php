<?php

namespace App\Http\Requests;

use App\Models\ProgressEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Зураг хавсаргах валидаци.
 *
 * Талбайн утасны зураг 5–10 МБ хүрдэг тул client тал багасгаж илгээнэ.
 * Сервер хязгаарыг ердөө хамгаалалт болгож тавина.
 */
class StorePhotoRequest extends FormRequest
{
    /** Зураг хавсаргах эрх нь гүйцэтгэл мэдээлэх эрхтэй ижил. */
    public function authorize(): bool
    {
        return (bool) $this->user()?->canReportProgress();
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,heic', 'max:12288'],
            'type' => ['required', Rule::in(['before', 'progress', 'after'])],
            'takenAt' => ['nullable', 'date', 'before_or_equal:now'],
            'progressEntryId' => ['nullable', 'uuid', 'exists:progress_entries,id'],
        ];
    }

    /**
     * Зураг нь ЗӨВХӨН тухайн ажлын гүйцэтгэлд хавсрана.
     *
     * Эс бөгөөс өөр барилгын, өөр гүйцэтгэгчийн мэдээлэлд зураг залгаж,
     * нотолгоог гуйвуулах боломжтой болно.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $entryId = $this->input('progressEntryId');
                if (! $entryId) {
                    return;
                }

                $entry = ProgressEntry::find($entryId);
                $workItem = $this->route('workItem');

                if ($entry && $workItem && $entry->work_item_id !== $workItem->id) {
                    $validator->errors()->add(
                        'progressEntryId',
                        'Энэ гүйцэтгэл өөр ажилд хамаарч байна.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'Зураг 12 МБ-аас их байж болохгүй. Илгээхээсээ өмнө багасгана уу.',
            'file.image' => 'Зөвхөн зураг файл хавсаргана.',
        ];
    }
}
