<?php

namespace App\Services\Import;

use App\Models\Block;
use App\Models\Material;
use App\Models\MaterialBudgetItem;
use App\Models\MaterialBudgetQuantity;

/**
 * Материалын төсвийн төлөвлөгөөг өгөгдлийн сан руу бичнэ.
 *
 * ЯАГААД КОМАНДААС САЛГАВ: ижил бичилт одоо хоёр газраас дуудагдана —
 * `cpms:import-materials` команд ба вэбийн импортын дэлгэц. Хуулбарлавал
 * хоёр зам аажмаар салж, «командаар оруулахад өөр, дэлгэцээр оруулахад
 * өөр» гэсэн тайлагдашгүй зөрүү үүснэ.
 *
 * ТРАНЗАКЦ ЭНД НЭЭГДЭХГҮЙ. Дуудагч тал хариуцна — урьдчилан харах нь
 * яг энэ кодыг гүйцэтгээд буцаадаг тул транзакцийн хил нь гадна байх
 * ёстой.
 */
class MaterialBudgetWriter
{
    /**
     * @param  array<int, array<string, mixed>>  $plan
     * @return array{materialsCreated: int, materialsUpdated: int, items: int, quantities: int}
     */
    public function write(Block $block, array $plan): array
    {
        $this->wipe($block);

        $materials = Material::where('project_id', $block->project_id)->get()->keyBy('code');

        $created = 0;
        $updated = 0;
        $quantityRows = 0;

        foreach ($plan as $line) {
            $material = $materials->get($line['code']);

            if ($material) {
                /*
                 * Лавлахын нэр, марк шинэчлэгдэж болно: инженер «Суурь»
                 * гэснээ «Суурийн бетон» болгон тодруулсан байж болно.
                 * Код нь тогтвортой, нэр нь биш.
                 */
                $material->fill([
                    'name' => $line['name'],
                    'grade' => $line['grade'],
                    'unit' => $line['unit'],
                ]);

                if ($material->isDirty()) {
                    $material->save();
                    $updated++;
                }
            } else {
                $material = Material::create([
                    'project_id' => $block->project_id,
                    'code' => $line['code'],
                    'name' => $line['name'],
                    'grade' => $line['grade'],
                    'unit' => $line['unit'],
                ]);
                $materials->put($line['code'], $material);
                $created++;
            }

            $item = MaterialBudgetItem::create([
                'block_id' => $block->id,
                'material_id' => $material->id,
                'work_type_id' => $line['workTypeId'],
                'work_label' => $line['workLabel'],
                'actual_unit_price' => $line['actualPrice'],
                'market_unit_price' => $line['marketPrice'],
                'total_qty' => $line['total'],
                'source_row' => $line['sourceRow'],
            ]);

            foreach ($line['quantities'] as $locationId => $quantity) {
                MaterialBudgetQuantity::create([
                    'material_budget_item_id' => $item->id,
                    'location_id' => $locationId,
                    'quantity' => $quantity,
                ]);
                $quantityRows++;
            }
        }

        return [
            'materialsCreated' => $created,
            'materialsUpdated' => $updated,
            'items' => count($plan),
            'quantities' => $quantityRows,
        ];
    }

    /**
     * Блокийн хуучин мөрийг БҮХЭЛД НЬ устгана.
     *
     * `updateOrCreate` хийвэл төсвөөс ХАСАГДСАН материал системд үлдэнэ.
     * Материалын төсөв нь түүхэн бичлэг биш — хамгийн сүүлийн төсөв л
     * үнэн. Лавлах (`materials`) үлдэнэ: өөр блок түүнийг хэрэглэж
     * байж болно.
     */
    private function wipe(Block $block): void
    {
        $items = MaterialBudgetItem::where('block_id', $block->id)->select('id');

        // Задаргааг ТОДОРХОЙ устгана. FK каскад дээр найдаж болохгүй:
        // SQLite-д `PRAGMA foreign_keys` унтраалттай байвал эзэнгүй мөр үлдэнэ.
        MaterialBudgetQuantity::whereIn('material_budget_item_id', $items)->delete();
        MaterialBudgetItem::where('block_id', $block->id)->delete();
    }
}
