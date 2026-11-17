<?php

namespace App\Console\Commands;

use App\Models\WorkCatalogEntry;
use App\Services\ConfigureCatalogWork;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResolveCatalogLocations extends Command
{
    protected $signature = 'cpms:resolve-catalog-locations';
    protected $description = 'Resolve reference work locations from source descriptions and prepare planning identities';

    public function handle(ConfigureCatalogWork $configure): int
    {
        DB::transaction(function () use ($configure) {
            foreach (WorkCatalogEntry::orderBy('id')->get() as $entry) $configure->update($entry, []);
        });
        $entries = WorkCatalogEntry::with('workType')->get();
        $resolved = $entries->filter(fn ($entry) => $entry->toCatalogArray()['level'] !== null)->count();
        $ready = $entries->whereNotNull('work_type_id')->count();
        $this->info("{$resolved} байршил тодорхойлогдсон; ".($entries->count() - $resolved)." байршил тодруулах; {$ready} ажил төлөвлөхөд бэлэн.");
        return self::SUCCESS;
    }
}
