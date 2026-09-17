<?php

namespace Database\Seeders;

use App\Jobs\ApplyBlockDesign;
use App\Models\Block;
use App\Models\BlockDesign;
use App\Models\Company;
use App\Models\Contractor;
use App\Models\Issue;
use App\Models\Photo;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ажиллуулж үзэх бэлэн орчин: хэрэглэгч, компани, төсөл, нэг бүтэн блок.
 *
 * Хоосон блок нь бүх дэлгэц дээр 0% харуулдаг тул явцыг бас дуурайлгана —
 * доод давхрын угсралт дууссан, дээд давхрынх эхлээгүй, дунд нь хэсэгчлэн.
 */
class DemoSeeder extends Seeder
{
    /**
     * Demo блокууд: `[нэр, барилга №, давхар, явцын фронт]`.
     *
     * Фронт нь барилга хаана явааг заана (`бүлгийн дараалал × 12 + давхар`
     * нэгжээр). Блок бүр ӨӨР шатанд байх нь чухал: блокуудын жагсаалт, хянах
     * самбарын харьцуулалт нь бүгд ижил хувьтай бол юу ч илэрхийлэхгүй.
     */
    private const BLOCKS = [
        ['А блок — 16 давхар орон сууц', '18', 16, 72],
        ['Б блок — 12 давхар орон сууц', '19', 12, 104],
        ['В блок — 12 давхар орон сууц', '20', 12, 34],
        ['Үйлчилгээний барилга — 4 давхар', '21', 4, 70],
    ];

    /** Явцын зурвасын өргөн — фронтын энэ зайд ажил хэсэгчлэн хийгдсэн байна. */
    private const PROGRESS_BAND = 22;

    /**
     * Төсөл хэдэн хоногийн өмнө эхэлсэн бол.
     *
     * ОГНОО ХАРЬЦАНГУЙ БАЙХ ЁСТОЙ. Урьд нь `2026-03-01` гэж хатуу бичсэн байв.
     * `takt_days = 5`-аар бодоход бүх хуваарь ~175 хоногт багтдаг тул хугацаа
     * өнгөрөх тусам demo-гийн БҮХ ажил хугацаа хэтэрсэн болж, хянах самбар
     * «6,761 хоцорсон» гэсэн утгагүй тоо харуулдаг байлаа.
     *
     * 50 хоногийг ТААМАГЛААГҮЙ, хэмжиж сонгосон. Явцын загвар (`бүлэг×12 +
     * давхар`) ба хуваарийн загвар (`(бүлэг + давхар)×такт`) нь бүлгийн жинг
     * өөрөөр авдаг тул хоёулаа зэрэг тааруулах боломжгүй. Эхлэх огноог
     * урагшлуулахад хоцролт ингэж өөрчлөгддөг:
     *   100 хоног → 5,708 (84%)   60 хоног → 986 (14%)
     *    80 хоног → 3,531 (52%)   45 хоног → 220 (3%)
     * 50 нь ~400 орчим өгнө: танилцуулгад бодит боловч удирдаж болохуйц
     * саатал харагдана. 100 байхад «бүх ажил хоцорсон» гэж утгагүй болно.
     */
    private const STARTED_DAYS_AGO = 50;

    public function run(): void
    {
        $this->call(WorkTypeSeeder::class);
        $this->call(ChecklistSeeder::class);

        $users = $this->createUsers();
        $this->createContractors();

        $company = Company::firstOrCreate(
            ['name' => 'Инэл ХХК'],
            ['registration_number' => '5261234', 'phone' => '7000-1234'],
        );

        $project = $this->markDemo($company->projects()->firstOrCreate(
            ['name' => '75 барилгын цогцолбор'],
            [
                'code' => 'INL-75',
                'location' => 'Улаанбаатар',
                'status' => 'active',
                'start_date' => now()->subDays(self::STARTED_DAYS_AGO)->toDateString(),
            ],
        ));

        $startDate = now()->subDays(self::STARTED_DAYS_AGO)->toDateString();
        $blocks = [];

        foreach (self::BLOCKS as [$name, $buildingNo, $floors, $front]) {
            $design = BlockDesign::where('floors', $floors)->firstOrFail();

            $block = $project->blocks()->firstOrCreate(
                ['name' => $name],
                [
                    'block_design_id' => $design->id,
                    'building_no' => $buildingNo,
                    'purpose' => $design->purpose,
                    'floors' => $design->floors,
                    'units_per_floor' => $design->units_per_floor,
                    'start_date' => $startDate,
                    'status' => 'in_progress',
                ],
            );

            if ($block->workItems()->doesntExist()) {
                $this->command->info("Ажлын нэгж үүсгэж байна — {$name}…");
                (new ApplyBlockDesign($block->id, $design->id, $startDate))->handle();
            }

            $this->assignContractors($block);
            $this->simulateProgress($block, $users['engineer'], $users['inspector'], $front);

            /*
             * Блокийн төлөвийг ЯВЦААС нь гаргана.
             *
             * `ApplyBlockDesign` нь ажлын нэгж үүсгэсний дараа блокийг
             * `not_started` болгож буцаан тавьдаг. Тиймээс явц дуурайлгасан ч
             * блок «эхлээгүй» гэж үлдэж, бааз өөртэйгөө зөрчилддөг байв.
             * Нөхцөлгүй тавина — seeder дахин ажиллуулахад ч засагдана.
             */
            $done = $block->workItems()->where('status', 'completed')->count();
            $total = $block->workItems()->count();
            $block->update([
                'status' => match (true) {
                    $total > 0 && $done >= $total => 'completed',
                    $block->workItems()->where('status', '!=', 'not_started')->exists() => 'in_progress',
                    default => 'not_started',
                },
            ]);

            $blocks[] = $block;
        }

        // Талбайн инженер 1–2 блок хариуцдаг (захиалагчийн хариулт) — хамрах
        // хүрээг өгснөөр эрхийн хяналт бодитоор ажиллаж байгаа нь харагдана.
        $users['engineer']->update(['scope_block_ids' => [$blocks[0]->id]]);

        $this->seedIssues($blocks, $users);
        $this->seedPhotos($blocks, $users['engineer']);

        $this->command->newLine();
        $this->command->info('Бэлэн боллоо.');
        $this->command->table(
            ['Имэйл', 'Нууц үг', 'Үүрэг'],
            [
                ['admin@cpms.mn', 'password', 'Админ — бүх эрх'],
                ['director@cpms.mn', 'password', 'Захирал — бүх блок'],
                ['engineer@cpms.mn', 'password', 'Талбайн инженер — зөвхөн А блок'],
                ['inspector@cpms.mn', 'password', 'Хяналтын инженер'],
            ],
        );

        foreach ($blocks as $b) {
            $this->command->info("{$b->name}  ·  ажлын нэгж: ".$b->workItems()->count());
        }
    }

    /**
     * Мөрийг demo гэж тэмдэглэнэ.
     *
     * Seeder ажиллуулах нь «эдгээр мөр бол миний танилцуулгын өгөгдөл» гэсэн
     * мэдэгдэл — тиймээс өмнө нь үүссэн байсан ч тугийг тавина. Эс бөгөөс
     * `demo:purge` тэднийг олохгүй, гараар устгах болно.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  T  $model
     * @return T
     */
    private function markDemo($model)
    {
        if (! $model->is_demo) {
            $model->forceFill(['is_demo' => true])->save();
        }

        return $model;
    }

    /** @return array<string, User> */
    private function createUsers(): array
    {
        /*
         * `firstOrCreate` нь БАЙГАА мөрийн талбарыг шинэчилдэггүй.
         *
         * Хоосон биш баазад seeder ажиллахад хэрэглэгч аль хэдийн байвал туг
         * тавигдахгүй үлдэж, `demo:purge` түүнийг олохгүй болно — demo өгөгдөл
         * устгах аргагүй болно. Тиймээс тугийг тусад нь батална.
         */
        $make = function (string $email, string $name, string $role): User {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password'), 'role' => $role],
            );

            return $this->markDemo($user);
        };

        return [
            'admin' => $make('admin@cpms.mn', 'Системийн админ', 'admin'),
            'director' => $make('director@cpms.mn', 'Б.Батбаяр', 'director'),
            'engineer' => $make('engineer@cpms.mn', 'Б.Тамир', 'site_engineer'),
            'inspector' => $make('inspector@cpms.mn', 'Д.Хулан', 'inspector'),
        ];
    }

    private function createContractors(): void
    {
        $rows = [
            ['Түшиг Констракшн', 'Бетон, угсралт', 'TSH-2026'],
            ['Мөнх Хишиг ХХК', 'Өрлөг, шавардлага', 'MNH-2026'],
            ['Сүлд Фасад', 'Фасад, цонх', 'SLD-2026'],
            ['Эрчим Инженеринг', 'Цахилгаан, сантехник', 'ERH-2026'],
            ['Гоо Засал ХХК', 'Дотор засал', 'GOO-2026'],
            ['Ногоон Тохижилт', 'Гадна ажил', 'NGN-2026'],
        ];

        foreach ($rows as [$name, $specialty, $code]) {
            $this->markDemo(Contractor::firstOrCreate(
                ['name' => $name],
                [
                    'trade_specialty' => $specialty,
                    'access_code' => $code,
                    'access_code_expires_at' => now()->addYear(),
                ],
            ));
        }
    }

    /**
     * Саатлын бүртгэл.
     *
     * ЯАГААД ЗААВАЛ ХЭРЭГТЭЙ ВЭ: хоосон бүртгэл нь зөвхөн «Саатал» жагсаалтыг
     * хоосон үлдээгээд зогсохгүй — хянах самбарын саатлын хэсэг нээлттэй тоо
     * тэг үед БҮХЭЛДЭЭ зурагддаггүй. Өөрөөр хэлбэл функц нь байгаа ч байхгүй
     * мэт харагдана.
     *
     * `accident` (осол) ангилал ороогүй: осол ховор тохиолддог бөгөөд мөргүй
     * ангилал үлдээх нь шүүлтүүрийг бодитоор шалгах боломж өгнө.
     *
     * `[ангилал, хүндрэл, хэдэн хоногийн өмнө, шийдэгдсэн бол хэдэн хоногийн
     * өмнө (эс бөгөөс null), тайлбар, бүртгэсэн хүний түлхүүр]`
     */
    private const ISSUES = [
        ['material_shortage', 'high', 21, null, 'Арматур Ø12-ийн нийлүүлэлт хоцорч, цутгалт зогссон.', 'engineer'],
        ['material_shortage', 'medium', 18, 9, 'Бетон зуурмагийн машин ирээгүй тул цутгалт маргаашлав.', 'engineer'],
        ['material_shortage', 'medium', 16, null, 'Дулаалгын хөөсөнцөр хавтан агуулахад дууссан.', 'inspector'],
        ['material_shortage', 'high', 14, null, 'Цонхны шил хэмжээ таарахгүй ирсэн тул үйлдвэрт буцаалаа.', 'inspector'],
        ['material_shortage', 'low', 12, 4, 'Хавтангийн өнгө захиалгаас зөрсөн — дахин татан авав.', 'engineer'],
        ['material_shortage', 'medium', 11, null, 'Цахилгааны кабель 3×2.5 татан авалт удаашрав.', 'engineer'],
        ['material_shortage', 'medium', 9, null, 'Шаварчлагын гипс дутуу, хоёр давхрын ажил хүлээгдэж байна.', 'inspector'],
        ['material_shortage', 'low', 7, 2, 'Сантехникийн фитинг дутуу ирсэн.', 'engineer'],
        ['material_shortage', 'low', 5, null, 'Хаалганы бэхэлгээний хэрэгсэл ирээгүй.', 'engineer'],
        ['contractor_late', 'high', 24, null, 'Баг товлосон хугацаанаас 4 хоног хоцорч ирэв.', 'director'],
        ['contractor_late', 'medium', 20, 11, 'Ажилчдын тоо гэрээнд заасны хагас байсныг нөхөв.', 'engineer'],
        ['contractor_late', 'medium', 17, null, 'Өмнөх давхрын ажил дуусаагүй тул эхлэл хойшлов.', 'engineer'],
        ['contractor_late', 'low', 15, 6, 'Ээлжийн ажилчид оройтож ирсэн.', 'inspector'],
        ['contractor_late', 'medium', 13, null, 'Дэд гүйцэтгэгч гэрээний нөхцөл тохироогүйгээс хүлээлт үүсэв.', 'director'],
        ['contractor_late', 'high', 10, null, 'Хоёр дахь удаагаа хуваарь алдсан — сануулга өгөв.', 'director'],
        ['contractor_late', 'low', 8, 3, 'Баг өглөөний ээлжид бүрэн бүрэлдэхүүнээр ирээгүй.', 'engineer'],
        ['contractor_late', 'medium', 6, null, 'Угсралтын баг өөр объект дээр саатсан.', 'engineer'],
        ['weather', 'medium', 23, 19, 'Хүчтэй салхины улмаас өндөрлөг дэх ажил зогссон.', 'engineer'],
        ['weather', 'high', 19, null, 'Хасах 28 хэмд бетон цутгалт хойшлов.', 'inspector'],
        ['weather', 'medium', 15, 12, 'Бороо орсон тул фасадны будгийн ажил зогсов.', 'engineer'],
        ['weather', 'low', 12, 10, 'Цасны улмаас гадна талбайн ажил хагас өдөр зогссон.', 'engineer'],
        ['weather', 'medium', 8, null, 'Шороон шуурганы улмаас гадна ажил түр зогслоо.', 'inspector'],
        ['weather', 'low', 4, null, 'Шөнийн хүйтрэлтээс зуурмагийн чанар унасан.', 'inspector'],
        ['equipment_failure', 'high', 22, 15, 'Цамхаг кран эвдэрч, засварт 2 хоног зогсов.', 'engineer'],
        ['equipment_failure', 'medium', 18, null, 'Барилгын өргөгч шат ажиллахгүй болов.', 'engineer'],
        ['equipment_failure', 'medium', 14, null, 'Бетон шахуургын хоолой гэмтсэн.', 'engineer'],
        ['equipment_failure', 'low', 9, 5, 'Гагнуурын аппарат тасалдсан — нөөцөөр сольсон.', 'inspector'],
        ['equipment_failure', 'high', 5, null, 'Түр цахилгаан хангамж тасарч, бүх давхрын ажил зогссон.', 'director'],
        ['no_contractor', 'high', 20, null, 'Товлосон өдөр баг талбайд огт ирээгүй.', 'director'],
        ['no_contractor', 'medium', 16, 8, 'Хариуцагч солигдож, хүлээлцэх хугацаа шаардагдсан.', 'director'],
        ['no_contractor', 'medium', 12, null, 'Энэ ажилд гүйцэтгэгч оноогдоогүй байна.', 'engineer'],
        ['no_contractor', 'low', 7, null, 'Туслан гүйцэтгэгч холбоо барихгүй байна.', 'engineer'],
        ['no_contractor', 'high', 3, null, 'Гэрээ цуцлагдаж, шинэ гүйцэтгэгч сонгогдоогүй.', 'director'],
        ['complaint', 'medium', 17, 13, 'Оршин суугчаас шөнийн дуу чимээний талаар гомдол ирсэн.', 'director'],
        ['complaint', 'low', 11, null, 'Барилгын хог зам хаасан тухай гомдол.', 'engineer'],
        ['complaint', 'medium', 6, null, 'Захиалагчийн төлөөлөгч заслын чанарт гомдол гаргав.', 'inspector'],
    ];

    /**
     * Саатлыг бодит ажлын мөрүүд дээр буулгана.
     *
     * Хоцорсон, дуусаагүй ажлуудыг сонгоно — саатлын бүртгэл нь ЯГ тэдгээр
     * хоцролтыг тайлбарлах учиртай. Блокуудаар тэнцүү алхмаар тарааж суулгана:
     * нэг барилга дээр бөөгнөрвөл «нэг л газар бүх асуудал байна» гэсэн худал
     * дүр зураг үүснэ.
     *
     * @param  array<int, Block>  $blocks
     * @param  array<string, User>  $users
     */
    private function seedIssues(array $blocks, array $users): void
    {
        $blockIds = array_map(fn (Block $b) => $b->id, $blocks);

        /*
         * Хамгаалалтыг ЭНЭ БЛОКУУДААР хязгаарлана, глобалаар БИШ.
         *
         * Урьд нь «ямар нэг саатал байна уу» гэж шалгадаг байсан тул хоосон
         * биш баазад (жишээ нь гараар нэг саатал бүртгэсэн) demo саатал огт
         * үүсдэггүй, шалтгаан нь ч мэдэгдэхгүй байв.
         */
        if (Issue::whereHas('workItem', fn ($q) => $q->whereIn('block_id', $blockIds))->exists()) {
            return;   // аль хэдийн үүссэн
        }

        $candidates = WorkItem::whereIn('block_id', $blockIds)
            ->where('status', '!=', 'completed')
            ->where('planned_qty', '>', 0)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($candidates === []) {
            return;
        }

        $rows = [];
        $count = count(self::ISSUES);

        foreach (self::ISSUES as $i => [$category, $severity, $days, $resolvedDays, $text, $who]) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'work_item_id' => $candidates[intdiv($i * count($candidates), $count)],
                'reported_by_id' => $users[$who]->id,
                'category' => $category,
                'severity' => $severity,
                'status' => $resolvedDays === null ? 'open' : 'resolved',
                'description' => $text,
                'resolved_at' => $resolvedDays === null ? null : now()->subDays($resolvedDays),
                'created_at' => now()->subDays($days),
                'updated_at' => now()->subDays($resolvedDays ?? $days),
            ];
        }

        DB::table('issues')->insert($rows);
        $this->command->info(count($rows).' саатлын бүртгэл үүслээ.');
    }

    /**
     * Зураг хавсаргах явцын бичлэгийн дээд хязгаар.
     *
     * Урьд нь 200 байсан нь ноцтой алдаа байв: 6,812 ажлын нэгжийн ердөө 2.9%
     * нь зурагтай болж, түүнийг нь «хамгийн сүүлийн бичлэг» гэж эрэмбэлсэн тул
     * 7–8-р давхарт бөөгнөрч, апп дотор эргэлдэхэд зураг БАРАГ ТААРАХГҮЙ байлаа.
     *
     * Нэг файл ~7.5 КБ тул хязгаарлах утгагүй: явцтай бүх бичлэгт зураг өгөхөд
     * ~2,000 зураг, ~15 МБ болно. Энэ тоо нь зөвхөн гэнэтийн том өгөгдлөөс
     * хамгаалах таазны үүрэгтэй.
     */
    private const PHOTO_ENTRY_LIMIT = 5000;

    /**
     * Гүйцэтгэлийн зураг — нотолгоо нь энэ системийн гол утга.
     *
     * Файлыг НЭГ БҮРД нь хуулна, нийтлэг файл заалгахгүй: дэлгэцээс нэг зураг
     * устгахад `Storage::delete($photo->path)` дуудагддаг тул хуваалцсан файл
     * байвал бусад бүртгэлийн зураг хамт эвдэрнэ.
     *
     * Эх файлыг ХАВТСААР нь уншина, өргөтгөлөөр нь биш — ингэснээр жишээ
     * зургийг жинхэнэ `.jpg`-ээр солиход код өөрчлөх шаардлагагүй.
     *
     * @param  array<int, Block>  $blocks
     */
    private function seedPhotos(array $blocks, User $uploader): void
    {
        $blockIds = array_map(fn (Block $b) => $b->id, $blocks);

        // Саатлынхтай ижил шалтгаанаар demo блокуудаар хязгаарлана.
        if (Photo::whereHas('workItem', fn ($q) => $q->whereIn('block_id', $blockIds))->exists()) {
            return;   // аль хэдийн үүссэн
        }

        $sources = glob(database_path('seeders/demo-photos/*.{svg,jpg,jpeg,png,webp}'), GLOB_BRACE);

        if (! $sources) {
            $this->command->warn('Жишээ зураг олдсонгүй — зураггүй үргэлжилнэ.');

            return;
        }

        /*
         * Явцтай БҮХ бичлэгт зураг өгнө.
         *
         * Огноогоор эрэмбэлж таслах нь зургийг явцын фронтын ирмэг дээр
         * бөөгнүүлдэг (`recorded_at` нь давхраас хамаардаг) тул эрэмбийг
         * id-гаар авна — блок, давхар, ажлын төрлөөр жигд тархана.
         *
         * Явцгүй ажил зураггүй үлдэх нь ЗӨВ: хийгээгүй ажлын нотолгоо байхгүй.
         */
        $entries = DB::table('progress_entries')
            ->join('work_items', 'work_items.id', '=', 'progress_entries.work_item_id')
            ->whereIn('work_items.block_id', $blockIds)
            ->orderBy('progress_entries.id')
            ->limit(self::PHOTO_ENTRY_LIMIT)
            ->get([
                'progress_entries.id as entry_id',
                'progress_entries.work_item_id',
                'progress_entries.recorded_at',
                'work_items.block_id',
                'work_items.accepted_qty',
            ]);

        $rows = [];
        $types = ['before', 'progress', 'after'];

        foreach ($entries as $i => $entry) {
            // 1–2 зураг: бүгд адилхан гурвантай байвал зохиомол харагдана.
            $count = ($i % 3 === 0) ? 2 : 1;

            for ($j = 0; $j < $count; $j++) {
                $source = $sources[($i + $j) % count($sources)];
                $path = "work-photos/{$entry->block_id}/".Str::uuid7().'.'.pathinfo($source, PATHINFO_EXTENSION);

                Storage::disk('local')->put($path, file_get_contents($source));

                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'work_item_id' => $entry->work_item_id,
                    'progress_entry_id' => $entry->entry_id,
                    'type' => $types[$j % 3],
                    'path' => $path,
                    'uploaded_by_id' => $uploader->id,
                    'taken_at' => $entry->recorded_at,
                    // Шалгалт хийгдсэн ажлын зураг түгжигдэнэ (RULE-10).
                    'locked' => (float) $entry->accepted_qty > 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('photos')->insert($chunk);
        }

        $this->command->info(count($rows).' зураг үүслээ.');
    }

    /** Ажлын бүлэг бүрийг тохирох гүйцэтгэгчид оноож өгнө. */
    private function assignContractors(Block $block): void
    {
        $map = [
            'Түшиг Констракшн' => ['Газар шороо', 'Угсралт', 'Дээвэр'],
            'Мөнх Хишиг ХХК' => ['Өрлөг', 'Засал'],
            'Сүлд Фасад' => ['Фасад', 'Цонх'],
            'Эрчим Инженеринг' => ['цахилгаан', 'сантехник', 'ХАС'],
            'Гоо Засал ХХК' => ['шал', 'чулуу', 'гипсэн хана', 'хаалга'],
            'Ногоон Тохижилт' => ['Гадна ажил'],
        ];

        foreach ($map as $contractorName => $groups) {
            $contractor = Contractor::where('name', $contractorName)->first();

            if (! $contractor) {
                continue;
            }

            WorkItem::where('block_id', $block->id)
                ->whereHas('workType.group', fn ($q) => $q->whereIn('name', $groups))
                ->update(['contractor_id' => $contractor->id]);
        }
    }

    /**
     * Явцыг дуурайлгана — барилга давалгаа маягаар урагшилдаг.
     *
     * Доод давхрын угсралт бүрэн дууссан, дээд давхрынх хийгдээгүй, дотор
     * засал бараг эхлээгүй. Ингэснээр дэлгэц бодит харагдана.
     */
    private function simulateProgress(
        Block $block,
        User $engineer,
        User $inspector,
        int $progressFront,
    ): void {
        if (DB::table('progress_entries')->where('work_item_id', function ($q) use ($block) {
            $q->select('id')->from('work_items')->where('block_id', $block->id)->limit(1);
        })->exists()) {
            return;   // аль хэдийн дуурайлгасан
        }

        $items = WorkItem::where('block_id', $block->id)
            ->where('planned_qty', '>', 0)
            ->with(['workType.group', 'location'])
            ->get();

        $this->command->info("Явц дуурайлгаж байна ({$items->count()} ажлын нэгж)…");

        $progress = [];
        $inspections = [];
        $updates = [];
        $now = now();

        foreach ($items as $item) {
            $order = $item->workType->group?->build_order ?? 5;
            $floorNo = max($item->location->sequence_number, 0);
            $position = $order * 12 + $floorNo;

            // Фронтоос хойш бүрэн хийгдсэн, урд нь огт эхлээгүй, зурвас дотор
            // нь хэсэгчлэн. Фронт блок бүрд өөр (`self::BLOCKS`).
            $low = $progressFront - self::PROGRESS_BAND;
            $high = $progressFront + self::PROGRESS_BAND;
            $ratio = match (true) {
                $position <= $low => 1.0,
                $position >= $high => 0.0,
                default => round(($high - $position) / (2 * self::PROGRESS_BAND), 2),
            };

            if ($ratio <= 0) {
                continue;
            }

            $reported = round((float) $item->planned_qty * $ratio, 3);
            $accepted = $ratio >= 1.0 ? $reported : round($reported * 0.8, 3);

            $progress[] = [
                'id' => (string) \Illuminate\Support\Str::uuid7(),
                'work_item_id' => $item->id,
                'reported_by_id' => $engineer->id,
                'contractor_id' => $item->contractor_id,
                'completed_qty' => $reported,
                'workers_count' => 4 + ($floorNo % 7),
                'remarks' => null,
                'recorded_at' => $now->copy()->subDays(30 - min($floorNo, 25)),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($accepted > 0) {
                $inspections[] = [
                    'id' => (string) \Illuminate\Support\Str::uuid7(),
                    'work_item_id' => $item->id,
                    'inspector_id' => $inspector->id,
                    'stage' => 'general_contractor',
                    'result' => $accepted >= $reported ? 'accepted' : 'partial',
                    'accepted_qty' => $accepted,
                    'rejected_qty' => round($reported - $accepted, 3),
                    'reason' => $accepted >= $reported ? null : 'Хэсэгчлэн хүлээн авав',
                    'rework_work_item_id' => null,
                    'inspected_at' => $now->copy()->subDays(28 - min($floorNo, 25)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $updates[] = [
                'id' => $item->id,
                'reported_qty' => $reported,
                'accepted_qty' => $accepted,
                'status' => $accepted >= (float) $item->planned_qty ? 'completed' : 'in_progress',
                'review_state' => $accepted < $reported ? 'pending' : 'approved',
            ];
        }

        foreach (array_chunk($progress, 500) as $chunk) {
            DB::table('progress_entries')->insert($chunk);
        }
        foreach (array_chunk($inspections, 500) as $chunk) {
            DB::table('inspections')->insert($chunk);
        }

        // Хуримтлагдсан дүнг нэг мөсөн шинэчилнэ — мөр бүрд recalculate()
        // дуудвал 3,290 удаа query явна.
        foreach (array_chunk($updates, 200) as $chunk) {
            foreach ($chunk as $u) {
                DB::table('work_items')->where('id', $u['id'])->update([
                    'reported_qty' => $u['reported_qty'],
                    'accepted_qty' => $u['accepted_qty'],
                    'status' => $u['status'],
                    'review_state' => $u['review_state'],
                ]);
            }
        }
    }
}
