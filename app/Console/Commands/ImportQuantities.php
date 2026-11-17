<?php

namespace App\Console\Commands;

use App\Console\Concerns\RendersImportReport;
use App\Models\Block;
use App\Models\User;
use App\Services\Import\BudgetImportRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Legacy command name; now imports unit prices only. */
class ImportQuantities extends Command
{
    use RendersImportReport;

    protected $signature = 'cpms:import-quantities
                            {--file= : Төсвийн .xlsx файлын зам}
                            {--block= : Блокийн нэр эсвэл id}
                            {--apply : Үнэхээр бичих (үгүй бол зөвхөн харуулна)}
                            {--prune : Дэмжигдэхгүй: ажлыг төсвөөр устгахгүй}
                            {--mode=prices : Зөвхөн нэгж үнэ шинэчлэх}
                            {--force : Баталгаажуулалт асуухгүй}
                            {--errors=30 : Тайланд харуулах алдааны мөрийн тоо}';

    protected $description = 'Төсвийн Excel-ээс байгаа ажлын нэгж үнийг шинэчилнэ';

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
        $prune = (bool) $this->option('prune');
        $mode = (string) $this->option('mode');

        if ($mode !== 'prices' || $prune) {
            $this->error('Зөвхөн --mode=prices дэмжинэ. --prune хэрэглэхгүй.');

            return self::FAILURE;
        }

        $runner = app(BudgetImportRunner::class);

        DB::beginTransaction();

        try {
            $report = $runner->preview($block, $path, 'quantities');

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
                $result = $runner->apply($block, $path, 'quantities', $prune, $mode)['result'];

                $this->newLine();
                $this->table(['Юу', 'Тоо'], [
                    ['Нийт ажил', number_format($result['items'])],
                    ['Шинээр үүссэн', number_format($result['created'])],
                    ['Шинэчлэгдсэн', number_format($result['updated'])],
                    ['Тоо хэмжээгүй ажил', number_format($result['withoutQuantity'])],
                    ['Устгасан ажил', number_format($result['pruned'])],
                ]);

                $this->renderConflicts($result['conflicts'] ?? []);
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

    /**
     * Төлөвлөсөн тоо нь МЭДЭЭЛСНЭЭС бага болох гэсэн ажлууд.
     *
     * Эдгээрийн тоо хэмжээ ШИНЭЧЛЭГДЭЭГҮЙ — хуучин утга хэвээр үлдсэн.
     * Чимээгүй бичвэл гүйцэтгэл 167% болж, цаашид мэдээлэх ч
     * боломжгүй болно.
     *
     * @param  array<int, array<string, mixed>>  $conflicts
     */
    private function renderConflicts(array $conflicts): void
    {
        if ($conflicts === []) {
            return;
        }

        $this->newLine();
        $this->warn(count($conflicts).' ажлын тоо хэмжээ ШИНЭЧЛЭГДСЭНГҮЙ '
            .'(төсвийн шинэ тоо нь мэдээлсэн хэмжээнээс бага):');

        $this->table(
            ['Ажил', 'Байршил', 'Мэдээлсэн', 'Төсвийн шинэ тоо'],
            array_map(fn ($c) => [
                mb_substr((string) $c['name'], 0, 34),
                $c['location'] ?? '—',
                $c['reportedQty'].' '.$c['unit'],
                $c['newPlannedQty'].' '.$c['unit'],
            ], array_slice($conflicts, 0, 30)),
        );

        $this->line('  Эдгээрийг ажлын хуудсан дээр гараар шийднэ.');
    }

    /**
     * `staff.json`-д байгаа ч системд бүртгэгдээгүй инженерүүд.
     *
     * Задлагч эдгээрийг дуугүй алгасна (хоосон холбоос). Хамгийн түгээмэл
     * шалтгаан нь `StaffSeeder` ажиллаагүй байх — тэр үед 64 мөрийн бүх
     * хариуцагч алга болох бөгөөд «төсөвт нэр байхгүй» гэж буруу
     * тайлагдана.
     */
    private function warnAboutMissingStaff(): void
    {
        $path = database_path('data/staff.json');

        if (! is_file($path)) {
            $this->warn('staff.json олдсонгүй — хяналтын инженер оноогдохгүй.');

            return;
        }

        $missing = [];

        foreach (json_decode((string) file_get_contents($path), true) ?? [] as $row) {
            if (! isset($row['budgetName'], $row['email'])) {
                continue;
            }

            if (! User::where('email', $row['email'])->exists()) {
                $missing[] = "{$row['name']} ({$row['email']})";
            }
        }

        if ($missing === []) {
            return;
        }

        $this->warn('staff.json-д байгаа ч системд бүртгэгдээгүй хяналтын инженер:');
        foreach ($missing as $name) {
            $this->line("  · {$name}");
        }
        $this->line('  `php artisan db:seed --class=StaffSeeder` ажиллуулах шаардлагатай.');
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
