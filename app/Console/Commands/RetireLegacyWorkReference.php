<?php

namespace App\Console\Commands;

use App\Models\Block;
use App\Models\WorkCatalogEntry;
use App\Models\WorkItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RetireLegacyWorkReference extends Command
{
    protected $signature = 'cpms:retire-legacy-work-reference {--execute : Delete unused legacy test reference data}';
    protected $description = 'Remove the legacy reference in an empty test environment, keeping catalog-linked planning types';

    public function handle(): int
    {
        if (Block::exists() || WorkItem::exists()) {
            $this->error('Барилга эсвэл ажлын мөр байна. Хуучин лавлахыг устгаагүй.');
            return self::FAILURE;
        }
        if (WorkCatalogEntry::withTrashed()->where('source_key', 'like', 'sequence-20261116:%')->count() < 293) {
            $this->error('Шинэ лавлах бүрэн ороогүй. Устгаагүй.');
            return self::FAILURE;
        }
        $linked = WorkCatalogEntry::whereNotNull('work_type_id')->pluck('work_type_id');
        $oldIds = WorkType::whereNotIn('id', $linked)->pluck('id');
        $this->info($oldIds->count().' хуучин төрөл цэвэрлэхэд бэлэн.');
        if (! $this->option('execute')) return self::SUCCESS;
        DB::transaction(function () use ($oldIds) {
            // Foreign keys remain enabled. Restrictive references abort the whole cleanup.
            WorkType::whereIn('id', $oldIds)->delete();
            $catalogGroups = WorkCatalogEntry::distinct()->pluck('group_name');
            WorkTypeGroup::whereNotIn('name', $catalogGroups)->whereDoesntHave('workTypes')->delete();
            foreach ($catalogGroups as $name) {
                WorkTypeGroup::firstOrCreate(['name' => $name], [
                    'sequence_number' => (int) $name, 'build_order' => (int) $name,
                ]);
            }
        });
        $this->info('Хуучин тестийн лавлах цэвэрлэгдлээ. Шинэ 293 ажил хадгалагдсан.');
        return self::SUCCESS;
    }
}
