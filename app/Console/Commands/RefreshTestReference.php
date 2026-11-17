<?php

namespace App\Console\Commands;

use App\Jobs\ApplyBlockDesign;
use App\Models\Block;
use App\Models\BudgetImport;
use App\Models\Photo;
use App\Models\WorkCatalogEntry;
use App\Models\WorkItem;
use App\Models\WorkType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class RefreshTestReference extends Command
{
    protected $signature = 'cpms:refresh-test-reference {--execute : Delete all test buildings and their data, then load the current reference}';
    protected $description = 'Preview or execute the one-time test-building cleanup and current-reference import';

    public function handle(): int
    {
        if (!Schema::hasTable('work_catalog_entries') || !Schema::hasColumn('work_catalog_entries', 'deleted_at')) {
            $this->error('Эхлээд php artisan migrate --force ажиллуулна уу.');
            return self::FAILURE;
        }
        $this->table(['Мэдээлэл', 'Тоо'], [
            ['Цэвэрлэх тестийн барилга', Block::count()],
            ['Цэвэрлэх ажлын мөр', WorkItem::count()],
            ['Одоогийн ажлын төрөл', WorkType::count()],
            ['Шинэ лавлахын идэвхтэй ажил', WorkCatalogEntry::count()],
        ]);
        if (!$this->option('execute')) {
            $this->info('--execute нэмэхэд бүх тестийн барилга, тэдгээрийн гүйцэтгэл, төсөв, захиалгыг цэвэрлээд шинэ лавлахыг ачаална.');
            return self::SUCCESS;
        }

        $paths = [];
        try {
            DB::transaction(function () use (&$paths) {
                // Validate and import the new source before removing existing test data.
                $this->runStep('cpms:import-work-catalog');
                $blocks = Block::lockForUpdate()->get();
                $blockIds = $blocks->pluck('id');
                $workIds = WorkItem::whereIn('block_id', $blockIds)->select('id');
                $paths = Photo::whereIn('work_item_id', $workIds)->pluck('path')
                    ->merge(BudgetImport::whereIn('block_id', $blockIds)->pluck('path'))->filter()->unique()->all();
                foreach ($blocks as $block) $block->delete();

                // Cancel pending template jobs which reference the deleted test buildings.
                foreach (DB::table('jobs')->whereNull('reserved_at')->get(['id', 'payload']) as $job) {
                    $payload = json_decode($job->payload, true);
                    if (($payload['data']['commandName'] ?? null) === ApplyBlockDesign::class) {
                        DB::table('jobs')->where('id', $job->id)->whereNull('reserved_at')->delete();
                    }
                }
                $this->runStep('cpms:retire-legacy-work-reference', ['--execute' => true]);
                $this->runStep('cpms:resolve-catalog-locations');
                $this->runStep('cpms:sync-catalog-designs');
            });
        } catch (Throwable $error) {
            $this->error('Шинэчлэлт буцсан. '.$error->getMessage());
            return self::FAILURE;
        }

        foreach ($paths as $path) Storage::disk('local')->delete($path);
        $this->info('Тестийн барилгууд цэвэрлэгдэж, шинэ лавлах болон загварууд бэлэн боллоо.');
        return self::SUCCESS;
    }

    private function runStep(string $command, array $arguments = []): void
    {
        if ($this->call($command, $arguments) !== self::SUCCESS) {
            throw new RuntimeException("{$command} амжилтгүй.");
        }
    }
}
