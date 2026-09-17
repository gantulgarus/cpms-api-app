<?php

namespace App\Console\Commands;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Танилцуулгын өгөгдлийг баазаас бүрэн зайлуулна.
 *
 * ЗӨВХӨН `is_demo` тугтай мөрийг устгана — нэрээр, кодоор, огноогоор хайхгүй.
 * Demo компани нь ЖИНХЭНЭ захиалагчийн нэртэй («Инэл ХХК») учир нэрээр хайх нь
 * жинхэнэ төслийн өгөгдлийг устгах эрсдэлтэй. Туг нь seeder-ийн үүсгэсэн
 * мөрөнд л тавигддаг тул энэ тушаал өөрийн үүсгээгүй зүйлд хүрэх боломжгүй.
 *
 * ЛАВЛАХ САН ХЭВЭЭР ҮЛДЭНЭ: ажлын төрөл, бүлэг, зураг төслийн загвар, чанарын
 * шалгах хуудас. Тэдгээрийг жинхэнэ төсөл мөн адил хэрэглэнэ — устгавал шинээр
 * ачаалах хэрэгтэй болно.
 */
class PurgeDemoData extends Command
{
    protected $signature = 'demo:purge {--force : Баталгаажуулалт асуухгүй}';

    protected $description = 'Танилцуулгын (demo) өгөгдлийг устгана. Лавлах сан хэвээр үлдэнэ.';

    public function handle(): int
    {
        $projects = Project::where('is_demo', true)->get();
        $contractors = Contractor::where('is_demo', true)->get();

        /*
         * Гүйцэтгэгчийн НЭВТРЭХ бүртгэлийг хамт устгана.
         *
         * Төлөөлөгч кодоороо нэвтрэхэд `users` дотор сүүдэр бүртгэл үүсдэг —
         * түүнийг seeder биш, систем өөрөө ажиллах үедээ үүсгэдэг тул `is_demo`
         * туггүй. `users.contractor_id` нь FK хязгаарлалтгүй энгийн багана учир
         * гүйцэтгэгчээ устгахад эдгээр мөр эзэнгүй үлдэж, цэвэрлэгээ дутуу
         * болно.
         */
        $users = User::where('is_demo', true)
            ->orWhereIn('contractor_id', $contractors->pluck('id'))
            ->get();

        if ($projects->isEmpty() && $users->isEmpty() && $contractors->isEmpty()) {
            $this->info('Demo өгөгдөл олдсонгүй — устгах юм алга.');

            return self::SUCCESS;
        }

        // Юу устахыг УРЬДЧИЛЖ харуулна. Cascade-аар хэдэн мянган мөр унждаг тул
        // «төсөл устгана» гэсэн ганц мөр нь хэрэглэгчид хангалтгүй мэдээлэл.
        $blockIds = DB::table('blocks')->whereIn('project_id', $projects->pluck('id'))->pluck('id');
        $workItems = DB::table('work_items')->whereIn('block_id', $blockIds);
        $workItemIds = $workItems->pluck('id');

        $this->table(['Юу', 'Тоо'], [
            ['Төсөл', $projects->count()],
            ['Блок', $blockIds->count()],
            ['Байршил', DB::table('locations')->whereIn('block_id', $blockIds)->count()],
            ['Ажлын нэгж', $workItemIds->count()],
            ['Явцын бичлэг', DB::table('progress_entries')->whereIn('work_item_id', $workItemIds)->count()],
            ['Шалгалт', DB::table('inspections')->whereIn('work_item_id', $workItemIds)->count()],
            ['Саатал', DB::table('issues')->whereIn('work_item_id', $workItemIds)->count()],
            ['Зураг', DB::table('photos')->whereIn('work_item_id', $workItemIds)->count()],
            ['Хэрэглэгч (гүйцэтгэгчийн нэвтрэлт орсон)', $users->count()],
            ['Гүйцэтгэгч', $contractors->count()],
        ]);
        $this->line('ҮЛДЭХ: ажлын төрөл, чанарын хуудас, зураг төслийн загвар.');
        // Компани нь захиалагч өөрөө — жинхэнэ төсөл мөн түүнд харьяалагдана.
        $this->line('ҮЛДЭХ: компанийн бүртгэл (жинхэнэ төслөө дор нь үүсгэнэ).');

        if (! $this->option('force') && ! $this->confirm('Устгах уу?')) {
            $this->warn('Цуцлав.');

            return self::SUCCESS;
        }

        /*
         * Зургийн ФАЙЛЫГ мөрөөс нь ӨМНӨ цуглуулна.
         *
         * Мөрүүд cascade-аар устсаны дараа ямар файл байсныг мэдэх арга үлдэхгүй
         * бөгөөд дискэн дээр эзэнгүй файл хуримтлагдана.
         */
        $paths = DB::table('photos')->whereIn('work_item_id', $workItemIds)->pluck('path');

        DB::transaction(function () use ($projects, $users, $contractors) {
            // Төслийг устгахад блок → байршил → ажлын нэгж → явц, шалгалт,
            // зураг, саатал бүгд cascade-аар дагаж арилна.
            Project::whereIn('id', $projects->pluck('id'))->delete();

            // Хэрэглэгч, гүйцэтгэгч нь төслөөс хамаардаггүй тул тусад нь.
            // Тэднийг заасан мөрүүд аль хэдийн устсан (эсвэл nullOnDelete).
            User::whereIn('id', $users->pluck('id'))->delete();
            Contractor::whereIn('id', $contractors->pluck('id'))->delete();
        });

        $deleted = 0;
        foreach ($paths as $path) {
            if (Storage::disk('local')->delete($path)) {
                $deleted++;
            }
        }

        $this->info("Цэвэрлэгдлээ. Зургийн файл: {$deleted}.");

        return self::SUCCESS;
    }
}
