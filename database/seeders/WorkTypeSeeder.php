<?php

namespace Database\Seeders;

use App\Models\BlockDesign;
use App\Models\DesignItem;
use App\Models\WorkType;
use App\Models\WorkTypeGroup;
use Illuminate\Database\Seeder;

/**
 * Хавсралт-2-оос гаргасан 47 ажлын төрлийг ачаална.
 *
 * `level` (block/floor/unit) нь Excel-д БАЙХГҮЙ мэдээлэл — ерөнхий инженертэй
 * тохирч оноосон. Захиалагч баталсны дараа `database/data/work-types.json`-ыг
 * шинэчлээд дахин ажиллуулна.
 *
 * `estimated: true` гэсэн мөрүүд нь тоо хэмжээ нь тодорхойгүй 30 ажлын төрөл.
 * Тэдгээрийг `qty_per_location = null` гэж хадгална — таамагласан тоог жинхэнэ
 * мэт үзүүлэхээс сэргийлнэ.
 */
class WorkTypeSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/work-types.json')), true);

        $buildOrder = [
            'Газар шороо' => 0, 'Угсралт' => 1, 'Дээвэр' => 2, 'Өрлөг' => 3,
            'Цонх' => 4, 'Фасад' => 5, 'цахилгаан' => 5, 'сантехник' => 5,
            'ХАС' => 6, 'гипсэн хана' => 6, 'Засал' => 7, 'шал' => 8,
            'чулуу' => 8, 'хаалга' => 9, 'Гадна ажил' => 9,
        ];

        $groups = [];
        $workTypes = [];

        foreach ($rows as $row) {
            $groups[$row['group']] ??= WorkTypeGroup::firstOrCreate(
                ['name' => $row['group']],
                [
                    'sequence_number' => count($groups) + 1,
                    'build_order' => $buildOrder[$row['group']] ?? 5,
                ],
            );

            $workTypes[] = WorkType::updateOrCreate(
                ['name' => $row['name'], 'work_type_group_id' => $groups[$row['group']]->id],
                [
                    'unit' => $row['unit'],
                    'level' => $row['level'],
                    'sequence_number' => $row['no'],
                ],
            );
        }

        $this->seedDesigns($rows, $workTypes);

        $this->command->info(count($workTypes).' ажлын төрөл, '.count($groups).' бүлэг ачаалагдлаа.');
    }

    /** Захиалагчийн 9 стандарт зураг төслөөс эхний гурвыг үүсгэнэ. */
    private function seedDesigns(array $rows, array $workTypes): void
    {
        $specs = [
            ['16 давхар орон сууц · 9 айл', 'Орон сууцны бүс', 16, 9, []],
            ['12 давхар орон сууц · 6 айл', 'Орон сууцны бүс', 12, 6, []],
            ['4 давхар үйлчилгээний барилга', 'Худалдаа үйлчилгээ', 4, 0, ['unit']],
        ];

        foreach ($specs as [$name, $purpose, $floors, $units, $excluded]) {
            $design = BlockDesign::updateOrCreate(
                ['name' => $name],
                ['purpose' => $purpose, 'floors' => $floors, 'units_per_floor' => $units, 'is_active' => true],
            );

            foreach ($rows as $i => $row) {
                if (in_array($row['level'], $excluded, true)) {
                    continue;
                }

                DesignItem::updateOrCreate(
                    ['block_design_id' => $design->id, 'work_type_id' => $workTypes[$i]->id],
                    [
                        // Тоо хэмжээ нь таамаглал бол null — үлдэгдэл бодогдохгүй
                        // байгааг дэлгэц анхааруулна.
                        'qty_per_location' => $row['estimated'] ? null : $row['plannedQty'],
                        'sequence_number' => $row['no'],
                    ],
                );
            }
        }
    }
}
