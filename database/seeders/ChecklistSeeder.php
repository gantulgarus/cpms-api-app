<?php

namespace Database\Seeders;

use App\Models\ChecklistTemplate;
use App\Models\WorkTypeGroup;
use Illuminate\Database\Seeder;

/**
 * Чанарын шалгах хуудасны эхлэл загварууд.
 *
 * Эдгээр нь ЖИШЭЭ — ерөнхий инженер дэлгэцээс засна. Гол зорилго нь систем
 * хоосон эхлэхгүй байх: загваргүй бол checklist-ийн хаалт огт ажиллахгүй тул
 * шинэ функц ажиллаж байгаа эсэхийг хэн ч харж чадахгүй.
 *
 * Бүлгийн түвшинд тавьсан — 47 ажлын төрөл тус бүрт бичих нь эхлэлд хэтэрхий
 * их ажил. Шаардлагатай төрөлд нь дараа нь нарийвчилж болно.
 */
class ChecklistSeeder extends Seeder
{
    /** Бүлгийн нэр → шалгах зүйлс. `false` нь "заавал биш". */
    private const TEMPLATES = [
        'Угсралт' => [
            ['Арматурын диаметр, алхам зурагтай тохирч байна', true],
            ['Хэвний бэхэлгээ бат бөх, гажилтгүй', true],
            ['Хамгаалалтын давхаргын зузаан хангасан', true],
            ['Бетоны маркийн гэрчилгээ хавсаргасан', true],
            ['Ажлын байрны цэвэрлэгээ хийгдсэн', false],
        ],
        'Өрлөг' => [
            ['Өрлөгийн эгнээ хэвтээ, босоо түвшин зөв', true],
            ['Заадасны зузаан жигд', true],
            ['Зуурмагийн харьцаа стандартын дагуу', true],
            ['Хаалга, цонхны нүх зурагтай тохирч байна', true],
        ],
        'Засал' => [
            ['Гадаргуу тэгш, ан цавгүй', true],
            ['Өнгө, материал зурагт заасантай тохирч байна', true],
            ['Булан, уулзвар цэвэр', true],
            ['Ажлын дараа хог цэвэрлэгдсэн', false],
        ],
        'цахилгаан' => [
            ['Кабелийн хөндлөн огтлол төслийн дагуу', true],
            ['Тусгаарлалтын эсэргүүцэл хэмжигдсэн', true],
            ['Газардуулга холбогдсон', true],
            ['Тэмдэглэгээ, шошго хийгдсэн', false],
        ],
        'сантехник' => [
            ['Даралтын сорил хийгдэж, алдагдалгүй', true],
            ['Налуу, хаялбар зөв', true],
            ['Холболтын битүүмжлэл шалгагдсан', true],
        ],
    ];

    public function run(): void
    {
        $created = 0;

        foreach (self::TEMPLATES as $groupName => $items) {
            $group = WorkTypeGroup::where('name', $groupName)->first();

            if (! $group) {
                continue;
            }

            // Дахин ажиллуулахад давхардуулахгүй.
            $template = ChecklistTemplate::firstOrCreate(
                ['work_type_group_id' => $group->id, 'work_type_id' => null],
                ['name' => "{$groupName} — чанарын шалгалт", 'is_active' => true],
            );

            if ($template->items()->exists()) {
                continue;
            }

            foreach ($items as $i => [$text, $required]) {
                $template->items()->create([
                    'sequence_number' => $i,
                    'text' => $text,
                    'is_required' => $required,
                ]);
            }

            $created++;
        }

        $this->command?->info("{$created} чанарын шалгах хуудас үүсгэлээ.");
    }
}
