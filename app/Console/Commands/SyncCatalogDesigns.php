<?php

namespace App\Console\Commands;

use App\Models\BlockDesign;
use App\Models\DesignItem;
use App\Models\WorkType;
use App\Services\DesignWorkLocations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncCatalogDesigns extends Command
{
    protected $signature = 'cpms:sync-catalog-designs';
    protected $description = 'Link active building templates to configured catalog work, without inventing quantities';

    public function handle(DesignWorkLocations $scope): int
    {
        $types = WorkType::whereHas('catalogEntry')->with('catalogEntry')->get();
        $added = DB::transaction(function () use ($types, $scope) {
            $added = 0;
            foreach (BlockDesign::where('is_active', true)->get() as $design) {
                foreach ($types as $type) {
                    if (! $scope->count($type, $design)) continue;
                    $item = DesignItem::firstOrCreate(['block_design_id' => $design->id, 'work_type_id' => $type->id],
                        ['qty_per_location' => null, 'sequence_number' => $type->sequence_number]);
                    $added += (int) $item->wasRecentlyCreated;
                }
            }
            return $added;
        });
        $this->info("{$added} ажил загваруудад холбогдлоо. Тоо хэмжээг барилга дээр нөхнө.");
        return self::SUCCESS;
    }
}
