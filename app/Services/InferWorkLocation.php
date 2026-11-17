<?php

namespace App\Services;

use App\Models\WorkCatalogEntry;

class InferWorkLocation
{
    public function infer(WorkCatalogEntry $entry): ?string
    {
        $normalize = fn (?string $text) => trim(preg_replace('/\s+/u', ' ', mb_strtolower($text ?? '')));
        $name = $normalize($entry->name);
        $scope = $normalize($entry->floor_description);
        $location = $normalize($entry->location_description);
        $unit = preg_match('/айл(ын|ийн|уудын|ууд)/u', $name.' '.$location);
        $whole = preg_match('/^(барилга бүхэлдээ|гадна ажил|суурь|дээвэр)$/u', $scope);
        $floor = preg_match('/давхар|зоорь|техник/u', $scope);
        $namedFloor = preg_match('/техник|зоор|\d+\s*-?\s*р?\s*давхар/u', $name);
        if ($whole) return $unit || $namedFloor ? null : 'block';
        if ($unit) return $floor && !preg_match('/^(зоорь|зоорийн давхар|техник|техникийн давхарт)$/u', $scope) ? 'unit' : null;
        if (preg_match('/ариун цэврийн|гал тогоо|паркет|обой|ванн|угаалтуур|суултуур|өрөө тусгаарлах|дотор хаалга/u', $name)) return null;
        if ($floor) return 'floor';
        if (preg_match('/^(барилга бүхэлдээ|гадна ажил|суурь|дээвэр)$/u', $location)) return 'block';
        return null;
    }
}
