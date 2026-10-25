<?php

namespace App\Console\Commands;

use App\Jobs\ApplyBlockDesign;
use App\Models\Block;
use App\Models\BlockDesign;
use App\Models\Company;
use App\Models\Project;
use App\Models\WorkItem;
use Illuminate\Console\Command;

/**
 * Бодит барилгыг загвараар үүсгэнэ.
 *
 * ЯАГААД ВЕБЭЭС БИШ КОМАНДААР ВЭ: вебийн «Блок үүсгэх» урсгал нь загвар
 * буулгах ажлыг ДАРААЛАЛД шиддэг. Дараалал боловсруулагч (queue worker)
 * ажиллаагүй бол блок үүсээд ажил нь үүсэхгүй үлддэг. Энэ команд ажлыг
 * ШУУД, синхроноор гүйцэтгэнэ — дараалал хэрэггүй.
 *
 * Мөн түүнчлэн энэ нь ДАВТАЖ АЖИЛЛУУЛАХАД АЮУЛГҮЙ: ижил нэртэй блок байвал
 * зогсоно. Хэрэв ажил нь дутуу үүссэн байвал `--resume` тугаар үргэлжлүүлнэ.
 *
 * Хэрэглээ:
 *   php artisan cpms:import-block --name="А1 блок" --building-no=A1 \
 *       --start=2026-10-01 --design=16
 *
 * Тоо хэмжээ нь `database/data/work-types.json`-оос ирнэ. Түүнийг өөрчилсөн
 * бол ЭХЛЭЭД `php artisan db:seed --class=WorkTypeSeeder` ажиллуулж загварыг
 * шинэчилнэ — эс бөгөөс блок хуучин тоогоор үүснэ.
 */
class ImportBlock extends Command
{
    protected $signature = 'cpms:import-block
                            {--name= : Блокийн нэр}
                            {--building-no= : Барилгын дугаар}
                            {--start= : Эхлэх огноо (Y-m-d)}
                            {--design=16 : Загварын давхрын тоо}
                            {--project= : Төслийн id (өгөөгүй бол цор ганцыг нь авна)}
                            {--create-project= : Ийм нэртэй ШИНЭ бодит төсөл үүсгэнэ}
                            {--takt=5 : Давхрын хугацаа — нэг давхарт ногдох ажлын өдөр}
                            {--resume : Ажил нь дутуу үүссэн блокийг үргэлжлүүлэх}';

    protected $description = 'Бодит барилгыг зураг төслийн загвараар үүсгэнэ (дараалалгүй)';

    public function handle(): int
    {
        foreach (['name', 'building-no', 'start'] as $required) {
            if (! $this->option($required)) {
                $this->error("--{$required} заавал өгнө.");

                return self::FAILURE;
            }
        }

        $project = $this->resolveProject();
        if (! $project) {
            return self::FAILURE;
        }

        $design = BlockDesign::where('floors', (int) $this->option('design'))->first();
        if (! $design) {
            $this->error("{$this->option('design')} давхрын загвар олдсонгүй. ".
                'Эхлээд `php artisan db:seed --class=WorkTypeSeeder` ажиллуулна уу.');

            return self::FAILURE;
        }

        $name = $this->option('name');
        $existing = $project->blocks()->where('name', $name)->first();

        if ($existing && ! $this->option('resume')) {
            $this->error("«{$name}» нэртэй блок аль хэдийн байна.");
            $this->line('Ажил нь дутуу үүссэн бол --resume тугаар үргэлжлүүлнэ үү.');

            return self::FAILURE;
        }

        $block = $existing ?? $this->createBlock($project, $design);

        if ($existing) {
            $have = WorkItem::where('block_id', $block->id)->count();
            $this->warn("«{$name}» хэвээр байна ({$have} ажилтай) — үргэлжлүүлнэ.");

            if ($have > 0) {
                $this->error('Ажил аль хэдийн үүссэн байна. Дахин үүсгэвэл давхардана.');

                return self::FAILURE;
            }
        }

        $this->line("Загвар: {$design->name} ({$design->floors} давхар × {$design->units_per_floor} айл)");
        $this->line('Ойролцоогоор '.number_format($design->estimatedItems()).' ажлын мөр үүснэ…');

        // ДАРААЛАЛГҮЙ — `dispatch` биш `handle`. CLI-д timeout байхгүй тул
        // 3,600 мөрийг тэвчээртэй хүлээж болно.
        (new ApplyBlockDesign($block->id, $design->id, $this->option('start')))->handle();

        $items = WorkItem::where('block_id', $block->id)->count();
        $zero = WorkItem::where('block_id', $block->id)->where('planned_qty', 0)->count();

        $this->newLine();
        $this->info("«{$block->name}» бэлэн боллоо.");
        $this->table(['Юу', 'Тоо'], [
            ['Блокийн id', $block->id],
            ['Байршил', $block->locations()->count()],
            ['Ажлын мөр', number_format($items)],
            ['Тоо хэмжээгүй мөр', number_format($zero)],
        ]);

        if ($zero > 0) {
            $this->warn("{$zero} мөрөнд төлөвлөгөөт тоо хэмжээ алга — тэдгээрт ГҮЙЦЭТГЭЛ ".
                'ОРУУЛАХ БОЛОМЖГҮЙ. Блокийн хуудасны «Тоо хэмжээ нөхөх» цонхоор бөглөнө үү.');
        }

        return self::SUCCESS;
    }

    private function createBlock(Project $project, BlockDesign $design): Block
    {
        return $project->blocks()->create([
            'block_design_id' => $design->id,
            'name' => $this->option('name'),
            'building_no' => $this->option('building-no'),
            'purpose' => $design->purpose,
            'floors' => $design->floors,
            'units_per_floor' => $design->units_per_floor,
            'start_date' => $this->option('start'),
            'takt_days' => max((int) $this->option('takt'), 1),
            'status' => 'not_started',
        ]);
    }

    private function resolveProject(): ?Project
    {
        if ($name = $this->option('create-project')) {
            return $this->createProject($name);
        }

        if ($id = $this->option('project')) {
            $project = Project::find($id);
            if (! $project) {
                $this->error("Төсөл олдсонгүй: {$id}");
            }

            return $project;
        }

        $projects = Project::orderBy('name')->get();

        if ($projects->count() === 1) {
            return $projects->first();
        }

        if ($projects->isEmpty()) {
            $this->error('Төсөл алга. Эхлээд төсөл үүсгэнэ үү.');

            return null;
        }

        // Олон төсөлтэй үед ТААМАГЛАХГҮЙ — буруу төсөлд 3,600 мөр үүсгэвэл
        // гараар цэвэрлэхэд хэцүү.
        $this->error('Олон төсөл байна — --project=<id> заана уу:');
        $this->table(['id', 'Нэр'], $projects->map(fn ($p) => [$p->id, $p->name])->all());

        return null;
    }

    /**
     * Бодит (demo БИШ) төсөл үүсгэнэ.
     *
     * ЯАГААД ХЭРЭГТЭЙ ВЭ: `demo:purge` нь `is_demo` тугтай төслийг БҮХЭЛД нь
     * устгадаг. Тиймээс demo өгөгдлөө цэвэрлэсний дараа бодит барилгаа
     * байрлуулах төсөл үлдэхгүй. Компани нь устдаггүй тул түүн дор нь шинэ
     * төсөл үүсгэнэ.
     */
    private function createProject(string $name): ?Project
    {
        $company = Company::orderBy('name')->first();

        if (! $company) {
            $this->error('Компани алга. Эхлээд захиалагчийн компанийг бүртгэнэ үү.');

            return null;
        }

        $project = $company->projects()->create([
            'name' => $name,
            'status' => 'active',
            'start_date' => $this->option('start'),
            // Бодит төсөл — `demo:purge` үүнийг ХЭЗЭЭ Ч устгахгүй.
            'is_demo' => false,
        ]);

        $this->info("Шинэ төсөл үүслээ: «{$project->name}» ({$company->name})");

        return $project;
    }
}
