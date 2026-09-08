<?php

namespace Database\Seeders;

use App\Jobs\ApplyBlockDesign;
use App\Models\Block;
use App\Models\BlockDesign;
use App\Models\Company;
use App\Models\Contractor;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Ажиллуулж үзэх бэлэн орчин: хэрэглэгч, компани, төсөл, нэг бүтэн блок.
 *
 * Хоосон блок нь бүх дэлгэц дээр 0% харуулдаг тул явцыг бас дуурайлгана —
 * доод давхрын угсралт дууссан, дээд давхрынх эхлээгүй, дунд нь хэсэгчлэн.
 */
class DemoSeeder extends Seeder
{
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

        $project = $company->projects()->firstOrCreate(
            ['name' => '75 барилгын цогцолбор'],
            ['code' => 'INL-75', 'location' => 'Улаанбаатар', 'status' => 'active', 'start_date' => '2026-03-01'],
        );

        $design = BlockDesign::where('floors', 16)->firstOrFail();

        $block = $project->blocks()->firstOrCreate(
            ['name' => 'А блок — 16 давхар орон сууц'],
            [
                'block_design_id' => $design->id,
                'building_no' => '18',
                'purpose' => $design->purpose,
                'floors' => $design->floors,
                'units_per_floor' => $design->units_per_floor,
                'start_date' => '2026-03-01',
                'status' => 'in_progress',
            ],
        );

        if ($block->workItems()->doesntExist()) {
            $this->command->info('Ажлын нэгж үүсгэж байна…');
            (new ApplyBlockDesign($block->id, $design->id, '2026-03-01'))->handle();
        }

        // Талбайн инженер 1–2 блок хариуцдаг (захиалагчийн хариулт) — хамрах
        // хүрээг өгснөөр эрхийн хяналт бодитоор ажиллаж байгаа нь харагдана.
        $users['engineer']->update(['scope_block_ids' => [$block->id]]);

        $this->assignContractors($block);
        $this->simulateProgress($block, $users['engineer'], $users['inspector']);

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
        $this->command->info("Блок: {$block->id}  ·  ажлын нэгж: ".$block->workItems()->count());
    }

    /** @return array<string, User> */
    private function createUsers(): array
    {
        $make = fn (string $email, string $name, string $role) => User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password'), 'role' => $role],
        );

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
            Contractor::firstOrCreate(
                ['name' => $name],
                [
                    'trade_specialty' => $specialty,
                    'access_code' => $code,
                    'access_code_expires_at' => now()->addYear(),
                ],
            );
        }
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
    private function simulateProgress(Block $block, User $engineer, User $inspector): void
    {
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

            // Явцын фронт 72 нэгж дээр байна, зурвасын өргөн 22.
            $ratio = match (true) {
                $position <= 50 => 1.0,
                $position >= 94 => 0.0,
                default => round((94 - $position) / 44, 2),
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
