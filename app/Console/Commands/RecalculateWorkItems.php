<?php

namespace App\Console\Commands;

use App\Models\WorkItem;
use Illuminate\Console\Command;

/**
 * Ажлын нэгжийн хуримтлагдсан дүнг дахин тооцоолно.
 *
 * `reported_qty`, `accepted_qty`, `status`, `review_state` нь хурдны улмаас
 * хадгалагддаг (3,290 мөр × 75 барилга дээр жагсаалт харах бүрд бодох нь
 * боломжгүй). Тиймээс тооцооллын ДҮРЭМ өөрчлөгдөхөд хуучин мөрүүд өөрөө
 * шинэчлэгдэхгүй.
 *
 * Хэрэглээ:
 *      php artisan cpms:recalculate                 # татгалзалтай мөрүүд
 *      php artisan cpms:recalculate --all           # бүгд
 *      php artisan cpms:recalculate --block=<uuid>  # нэг блок
 */
class RecalculateWorkItems extends Command
{
    protected $signature = 'cpms:recalculate
                            {--all : Бүх ажлыг дахин тооцоолно (удаан)}
                            {--block= : Зөвхөн тухайн блокийн ажлууд}';

    protected $description = 'Ажлын мэдээлсэн/батлагдсан дүн ба төлөвийг дахин тооцоолно';

    public function handle(): int
    {
        $query = WorkItem::query()
            ->when($this->option('block'), fn ($q, $id) => $q->where('block_id', $id))
            ->when(
                ! $this->option('all'),
                // Анхдагчаар зөвхөн татгалзал хүрсэн мөр — бусад нь өөрчлөгдөх
                // шалтгаангүй.
                fn ($q) => $q->whereHas('inspections', fn ($i) => $i->where('rejected_qty', '>', 0))
            );

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Дахин тооцоолох ажил алга.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $changed = 0;

        $query->chunkById(200, function ($items) use ($bar, &$changed) {
            foreach ($items as $item) {
                $before = [$item->reported_qty, $item->accepted_qty, $item->review_state];
                $item->recalculate();

                if ($before !== [$item->reported_qty, $item->accepted_qty, $item->review_state]) {
                    $changed++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("{$total} ажил шалгав · {$changed} мөрийн дүн шинэчлэгдлээ.");

        return self::SUCCESS;
    }
}
