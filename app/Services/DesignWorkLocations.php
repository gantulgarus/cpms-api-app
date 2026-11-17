<?php

namespace App\Services;

use App\Models\BlockDesign;
use App\Models\Location;
use App\Models\WorkType;

class DesignWorkLocations
{
    public function targets(WorkType $type, array $locations): array
    {
        $targets = $locations[$type->level] ?? [];
        if (! $type->catalogEntry) return $targets;
        $floors = collect($locations['floor'] ?? [])->keyBy('id');
        return array_values(array_filter($targets, function ($row) use ($type, $floors) {
            $location = new Location($row);
            if ($type->level === 'unit') $location->setRelation('parent', new Location($floors[$row['parent_id']]));
            return app(CatalogFloorScope::class)->matches($type->catalogEntry, $location);
        }));
    }

    public function count(WorkType $type, BlockDesign $design): int
    {
        if ($type->level === 'block') return 1;
        $count = 0;
        for ($number = 0; $number <= $design->floors + 1; $number++) {
            $name = $number === 0 ? 'Зоорийн давхар' : ($number === $design->floors + 1 ? 'Техникийн давхар' : "{$number}-р давхар");
            $floor = new Location(['level' => 'floor', 'name' => $name, 'sequence_number' => $number]);
            if ($type->catalogEntry && ! app(CatalogFloorScope::class)->matches($type->catalogEntry, $floor)) continue;
            $count += $type->level === 'floor' ? 1 : ($design->units_per_floor <= 0 || $number === 0 ? 0
                : ($number === $design->floors + 1 ? BlockLocationBuilder::technicalSpaceCount() : $design->units_per_floor));
        }
        return $count;
    }
}
