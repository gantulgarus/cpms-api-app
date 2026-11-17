<?php

namespace App\Services;

use App\Models\WorkCatalogEntry;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Illuminate\Support\Facades\DB;

/** The catalog owns reference information; WorkType is its planning identity. */
class ConfigureCatalogWork
{
    public function update(WorkCatalogEntry $entry, array $input): WorkCatalogEntry
    {
        return DB::transaction(function () use ($entry, $input) {
            $entry = WorkCatalogEntry::lockForUpdate()->findOrFail($entry->id);
            $type = $entry->workType;
            foreach (['name', 'unit', 'description'] as $field) {
                if (array_key_exists($field, $input)) {
                    $value = trim((string) $input[$field]);
                    if ($field === 'unit' && $value === '0') $value = '';
                    abort_if($field === 'name' && $value === '', 422, 'Нэр заавал.');
                    $entry->{$field} = $value === '' ? null : $value;
                }
            }
            $inferred = app(InferWorkLocation::class)->infer($entry);
            $inUse = $type && $type->workItems()->exists();
            if (array_key_exists('level', $input)) {
                $requested = $input['level'];
                abort_if($requested && $inferred && $requested !== $inferred && ! $entry->location_level_override,
                    $inUse ? 409 : 422, 'Байршил / Давхар мэдээллээс түвшин автоматаар тодорхойлогдоно.');
                $entry->location_level_override = $inferred && ! $entry->location_level_override ? null : $requested;
            }
            $level = $entry->location_level_override ?? $inferred;
            // Used work keeps its meaning; the entire catalog update rolls back on conflict.
            if ($inUse) {
                abort_if($type->unit !== $entry->unit || $type->level !== $level, 409,
                    'Энэ ажлаар төлөвлөгөө үүссэн тул нэгж, байршлын түвшинг өөрчлөх боломжгүй.');
            }
            abort_if($type && (! $entry->unit || ! $level), 422,
                'Байршил эсвэл хэмжих нэгжийг тодруулна уу.');
            if ($level && $entry->unit) {
                $group = WorkTypeGroup::firstOrCreate(['name' => $entry->group_name], [
                    'sequence_number' => (int) $entry->group_name,
                    'build_order' => (int) $entry->group_name,
                ]);
                $values = ['name' => $entry->name, 'unit' => $entry->unit, 'level' => $level,
                    'work_type_group_id' => $group->id, 'sequence_number' => $entry->sequence_number];
                if ($type) {
                    $type->update($values);
                } else {
                    $type = WorkType::create($values);
                    $entry->work_type_id = $type->id;
                }
            }
            $entry->save();
            return $entry->fresh('workType');
        });
    }
}
