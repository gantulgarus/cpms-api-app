<?php

namespace App\Services;

use App\Models\Location;
use App\Models\WorkCatalogEntry;

class CatalogFloorScope
{
    public function matches(WorkCatalogEntry $entry, Location $location): bool
    {
        if ($location->level === 'block') return true;
        $floor = $location->level === 'floor' ? $location : $location->parent;
        if (! $floor || $floor->level !== 'floor') return false;
        $scope = trim(preg_replace('/\s+/u', ' ', mb_strtolower($entry->floor_description ?? '')));
        $name = mb_strtolower($floor->name);
        $number = str_contains($name, 'техник') ? INF : (str_contains($name, 'зоор') ? 0 : (int) $floor->sequence_number);
        if (preg_match('/^зоор(ь|ийн давхар)$/u', $scope)) return $number === 0;
        if (preg_match('/^техник(ийн давхарт)?$/u', $scope)) return $number === INF;
        if ($scope === 'зоорь, техник') return $number === 0 || $number === INF;
        if (preg_match('/^(зоорь|зоорийн давхар|\d+(?:-р давхар)?)-(техник|\d+(?:-р давхар)?)$/u', $scope, $parts)) {
            $low = str_starts_with($parts[1], 'зоор') ? 0 : (int) $parts[1];
            $high = $parts[2] === 'техник' ? INF : (int) $parts[2];
            return $number >= $low && $number <= $high;
        }
        if (preg_match('/^(\d+)-р давхар$/u', $scope, $parts)) return $number === (int) $parts[1];
        // No explicit floor restriction (including a manually clarified entry).
        return true;
    }
}
