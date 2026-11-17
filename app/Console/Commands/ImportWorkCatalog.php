<?php

namespace App\Console\Commands;

use App\Models\WorkCatalogEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportWorkCatalog extends Command
{
    protected $signature = 'cpms:import-work-catalog';
    protected $description = 'Import the 293 reference stages from the 2026-11-16 workbook without changing existing work';

    public function handle(): int
    {
        $rows = json_decode(file_get_contents(database_path('data/work-catalog-20261116.json')), true, 512, JSON_THROW_ON_ERROR);
        if (count($rows) !== 293 || count(array_unique(array_column($rows, 'sourceKey'))) !== 293) {
            $this->error('Invalid catalog source. No changes made.');
            return self::FAILURE;
        }
        $created = DB::transaction(function () use ($rows) {
            $created = 0;
            foreach ($rows as $row) {
                $entry = WorkCatalogEntry::withTrashed()->firstOrCreate(['source_key' => $row['sourceKey']], [
                    'source_row' => $row['sourceRow'],
                    'sequence_number' => $row['sequenceNumber'],
                    'group_name' => $row['groupName'],
                    'subgroup_number' => $row['subgroupNumber'],
                    'type_name' => $row['typeName'],
                    'name' => $row['name'],
                    'unit' => $row['unit'],
                    'location_description' => $row['locationDescription'],
                    'floor_description' => $row['floorDescription'],
                    'description' => $row['description'],
                    'source_values' => $row['sourceValues'],
                ]);
                $created += (int) $entry->wasRecentlyCreated;
            }
            return $created;
        });
        $this->info("{$created} үе шат нэмэгдлээ. Өмнөх ажил болон дахин импортолсон мөр өөрчлөгдөөгүй.");
        return self::SUCCESS;
    }
}
