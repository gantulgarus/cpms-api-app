<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Location;
use Illuminate\Support\Str;

/**
 * Блокийн байршлын модыг үүсгэнэ: блок → зоорь + давхрууд → айлууд.
 *
 * ЯАГААД ТУСДАА КЛАСС: урьд нь энэ логик зөвхөн `ApplyBlockDesign` дотор
 * байсан тул ЗАГВАРГҮЙ блок үүсгэх боломжгүй байв — давхар, айл нь загвар
 * буулгах үед л төрдөг. Одоо блок үүсгэх үед ч, загвар буулгах үед ч ижил
 * логик ажиллана.
 *
 * ЧУХАЛ: байршил аль хэдийн байвал ДАХИН ҮҮСГЭХГҮЙ. Загваргүй блок үүсгээд
 * дараа нь загвар буулгахад давхар/айл хоёр дахин үүсэх нь хамгийн ноцтой
 * алдаа болно — бүх тоо хоёр дахин харагдана.
 */
class BlockLocationBuilder
{
    private const CHUNK = 500;

    /** Айлын нэр — захиалагчийн зурагт ийм тэмдэглэгээтэй. */
    private const UNIT_NAMES = ['А1', 'А2', 'B1', 'B2', 'C', 'D1', 'D2', 'E1', 'E2'];

    /**
     * @return array<string, list<array>>  түвшин → байршлын мөрүүд
     */
    public function build(Block $block, int $floors, int $unitsPerFloor): array
    {
        if ($block->locations()->exists()) {
            return $this->existing($block);
        }

        $now = now();
        $rootId = (string) Str::uuid7();
        $rootPath = '/'.$rootId.'/';

        $roots = [
            $this->row($rootId, $block, null, 'block', $block->name, $block->name, $rootPath, -1, $now),
        ];

        $floorRows = [];
        $unitRows = [];

        // Зоорь нь бүх барилгад байдаг тул давхрын тооноос үл хамааран үүснэ.
        $basementId = (string) Str::uuid7();
        $floorRows[] = $this->row(
            $basementId, $block, $rootId, 'floor', 'Зоорийн давхар',
            "{$block->name} › Зоорийн давхар", $rootPath.$basementId.'/', 0, $now
        );

        for ($f = 1; $f <= $floors; $f++) {
            $floorId = (string) Str::uuid7();
            $floorPath = $rootPath.$floorId.'/';

            $floorRows[] = $this->row(
                $floorId, $block, $rootId, 'floor', "{$f}-р давхар",
                "{$block->name} › {$f}-р давхар", $floorPath, $f, $now
            );

            for ($u = 0; $u < $unitsPerFloor; $u++) {
                $unitId = (string) Str::uuid7();
                $name = $this->unitName($u);

                $unitRows[] = $this->row(
                    $unitId, $block, $floorId, 'unit', $name,
                    "{$block->name} › {$f}-р давхар › {$name}", $floorPath.$unitId.'/', $u, $now
                );
            }
        }

        foreach (array_chunk([...$roots, ...$floorRows, ...$unitRows], self::CHUNK) as $chunk) {
            Location::insert($chunk);
        }

        return ['block' => $roots, 'floor' => $floorRows, 'unit' => $unitRows];
    }

    /** Аль хэдийн байгаа байршлыг ижил хэлбэрт оруулж буцаана. */
    private function existing(Block $block): array
    {
        $out = ['block' => [], 'floor' => [], 'unit' => []];

        foreach ($block->locations()->orderBy('sequence_number')->get() as $location) {
            $out[$location->level][] = [
                'id' => $location->id,
                'name' => $location->name,
                'sequence_number' => $location->sequence_number,
            ];
        }

        return $out;
    }

    /** А1, А2, B1, B2, C, D1, D2, E1, E2 … Хавсралт-2-ын нэршил. */
    private function unitName(int $index): string
    {
        $count = count(self::UNIT_NAMES);

        // 9-өөс олон айлтай давхарт нэр давхардахгүйн тулд дугаар нэмнэ.
        return self::UNIT_NAMES[$index % $count]
            .($index >= $count ? '-'.intdiv($index, $count) : '');
    }

    private function row(
        string $id, Block $block, ?string $parentId, string $level,
        string $name, string $path, string $pathKey, int $sequence, $now
    ): array {
        return [
            'id' => $id,
            'block_id' => $block->id,
            'parent_id' => $parentId,
            'level' => $level,
            'name' => $name,
            'path' => $path,
            // Materialized path — удам хайхад индекстэй prefix хайлт болно.
            'path_key' => $pathKey,
            'sequence_number' => $sequence,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
