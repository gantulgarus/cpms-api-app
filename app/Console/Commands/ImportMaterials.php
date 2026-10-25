<?php

namespace App\Console\Commands;

use App\Console\Concerns\RendersImportReport;
use App\Models\Block;
use App\Services\Import\BudgetImportRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * «Материалын төсөв» хуудсыг импортлох.
 *
 * ЭНЭ КОМАНД НЬ ЗӨВХӨН БҮРХҮҮЛ. Бодит ажлыг `BudgetImportRunner` хийнэ —
 * вэбийн импортын дэлгэц ч мөн тэр. Хоёр тусдаа зам бичвэл «командаар
 * оруулахад өөр, дэлгэцээр оруулахад өөр» гэсэн зөрүү үүснэ.
 *
 * АНХДАГЧААР ЮУ Ч БИЧИХГҮЙ. `--apply` тугтай үед л өөрчилнө. Урьдчилан
 * харах нь бодитоор транзакц дотор гүйцэтгээд буцаадаг — харсан тоо яг
 * тэр хэвээрээ орно.
 *
 * Хэрэглээ:
 *   php artisan cpms:import-materials --file=/tmp/tosov.xlsx --block="А1 блок"
 *   php artisan cpms:import-materials --file=/tmp/tosov.xlsx --block="А1 блок" --apply
 */
class ImportMaterials extends Command
{
    use RendersImportReport;

    protected $signature = 'cpms:import-materials
                            {--file= : Төсвийн .xlsx файлын зам}
                            {--block= : Блокийн нэр эсвэл id}
                            {--apply : Үнэхээр бичих (үгүй бол зөвхөн харуулна)}
                            {--force : Баталгаажуулалт асуухгүй}
                            {--errors=30 : Тайланд харуулах алдааны мөрийн тоо}';

    protected $description = 'Төсвийн Excel-ээс материалын тоо хэмжээ, үнийг импортлоно';

    public function handle(): int
    {
        $path = (string) $this->option('file');
        $block = $this->resolveBlock();

        if ($path === '' || ! $block) {
            if ($path === '') {
                $this->error('--file заавал өгнө.');
            }

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $runner = app(BudgetImportRunner::class);

        DB::beginTransaction();

        try {
            $report = $runner->preview($block, $path, 'materials');

            $this->renderReport($report);

            if (! $report['canApply']) {
                $this->error('Оруулах мөр алга — бичих зүйлгүй.');
                DB::rollBack();

                return self::FAILURE;
            }

            if ($apply && ! $this->option('force') && ! $this->confirm('Үргэлжлүүлэх үү?', false)) {
                $this->warn('Цуцлав.');
                DB::rollBack();

                return self::SUCCESS;
            }

            if ($apply) {
                $result = $runner->apply($block, $path, 'materials')['result'];

                $this->newLine();
                $this->table(['Юу', 'Тоо'], [
                    ['Лавлахад шинээр нэмэгдсэн материал', number_format($result['materialsCreated'])],
                    ['Лавлахад шинэчлэгдсэн материал', number_format($result['materialsUpdated'])],
                    ['Блокийн төсвийн мөр', number_format($result['items'])],
                    ['Байршлын задаргааны мөр', number_format($result['quantities'])],
                ]);
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($apply) {
            DB::commit();
            $this->newLine();
            $this->info('Импорт дууслаа.');
        } else {
            DB::rollBack();
            $this->newLine();
            $this->warn('ЗӨВХӨН ХАРУУЛАВ — юу ч өөрчлөгдөөгүй. Бичихийн тулд --apply нэмнэ үү.');
        }

        return self::SUCCESS;
    }

    private function resolveBlock(): ?Block
    {
        $needle = (string) $this->option('block');

        if ($needle === '') {
            $this->error('--block заавал өгнө (нэр эсвэл id).');

            return null;
        }

        $block = Block::where('id', $needle)->orWhere('name', $needle)->first();

        if (! $block) {
            $this->error("Блок олдсонгүй: {$needle}");
            $this->table(['id', 'Нэр'], Block::orderBy('name')->get(['id', 'name'])->toArray());
        }

        return $block;
    }
}
