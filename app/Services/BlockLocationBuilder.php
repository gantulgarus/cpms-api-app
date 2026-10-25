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

        // Техникийн давхар — төсөвт БАЙДАГ тул байршил нь ч байх ёстой.
        [$techFloor, $techSpaces] = $this->technicalFloor(
            $block, $rootId, $rootPath, $floors, $now, $unitsPerFloor > 0
        );
        $floorRows[] = $techFloor;
        $unitRows = [...$unitRows, ...$techSpaces];

        foreach (array_chunk([...$roots, ...$floorRows, ...$unitRows], self::CHUNK) as $chunk) {
            Location::insert($chunk);
        }

        return ['block' => $roots, 'floor' => $floorRows, 'unit' => $unitRows];
    }

    /**
     * Техникийн давхрын зайнууд.
     *
     * ЯАГААД АЙЛ БИШ ВЭ: техникийн давхарт орон сууц байхгүй. Гэвч төсөвт
     * тэнд шавардлага, замаска, эмульс зэрэг АЙЛЫН ТҮВШНИЙ ажил бүртгэгдсэн
     * байдаг (403 м² хана, 99 м² тааз). Байршилгүй бол тэдгээрийн тоо
     * хэмжээ хаана ч буухгүй — импорт дээр 16 нүд алдагдаж байсан.
     *
     * Нэрс нь төсвийн задаргааны хуудаснаас: тэнд яг ийм дөрвөн зай бий.
     */
    /** @see self::TECHNICAL_SPACE_COUNT — хоёр нь таарахыг тест шалгана. */
    private const TECHNICAL_SPACES = [
        'Сууцны бус талбай-1',
        'Сууцны бус талбай-2',
        'Нийтийн эдэлбэр',
        'Шатны хонгил',
    ];

    public const TECHNICAL_FLOOR = 'Техникийн давхар';

    /**
     * Техникийн давхрын зайны тоо — массиваас ГАРНА, дахин бичигдэхгүй.
     *
     * Загварын тооцоо (`BlockDesign::estimatedItems`) эндээс уншина. Тоог
     * тусад нь бичвэл зай нэмэхэд тооцоо хоцорч, дэлгэц дээр үүсэхээс өөр
     * тоо харагдана — яг тэр болсон.
     */
    public static function technicalSpaceCount(): int
    {
        return count(self::TECHNICAL_SPACES);
    }

    /**
     * Байгаа блокт дутуу стандарт байршлыг нөхнө.
     *
     * ЯАГААД ХЭРЭГТЭЙ ВЭ: техникийн давхрыг хожим нэмсэн тул өмнө үүссэн
     * блокуудад байхгүй. Төсвийн импорт тэр давхрын тоо хэмжээг хаана ч
     * буулгаж чадахгүй — чимээгүй алдагдана.
     *
     * ЗӨВХӨН НЭМНЭ, хэзээ ч устгахгүй. Давтаж дуудахад аюулгүй.
     *
     * @return int  хэдэн байршил нэмэгдсэн
     */
    public function ensureStandardFloors(Block $block): int
    {
        $existing = Location::where('block_id', $block->id)
            ->where('level', 'floor')
            ->pluck('name')
            ->all();

        if (in_array(self::TECHNICAL_FLOOR, $existing, true)) {
            return 0;
        }

        $root = Location::where('block_id', $block->id)->where('level', 'block')->first();

        if (! $root) {
            return 0;   // байршлын мод огт байхгүй — энд засах зүйлгүй
        }

        [$floor, $spaces] = $this->technicalFloor(
            $block, $root->id, $root->path_key, max((int) $block->floors, 1), now(),
            (int) $block->units_per_floor > 0,
        );

        Location::insert([$floor, ...$spaces]);

        return 1 + count($spaces);
    }

    /**
     * @return array{0: array, 1: list<array>}  [давхар, зайнууд]
     */
    private function technicalFloor(
        Block $block, string $rootId, string $rootPath, int $floors, $now,
        bool $withSpaces = true,
    ): array {
        $floorId = (string) Str::uuid7();
        $floorPath = $rootPath.$floorId.'/';

        /*
         * Дарааллын дугаар нь ДЭЭД давхраас хойш.
         *
         * Хуваарь үүнийг давхрын дугаар болгон хэрэглэдэг тул техникийн
         * давхар хамгийн сүүлд товлогдоно — байгаа давхруудын дугаар
         * хөдлөхгүй, тиймээс одоо байгаа огноо өөрчлөгдөхгүй.
         */
        $floor = $this->row(
            $floorId, $block, $rootId, 'floor', self::TECHNICAL_FLOOR,
            "{$block->name} › ".self::TECHNICAL_FLOOR, $floorPath, $floors + 1, $now
        );

        $spaces = [];

        /*
         * АЙЛГҮЙ барилгад техникийн давхрын зайнуудыг үүсгэхгүй.
         *
         * Зайнууд нь АЙЛЫН түвшний байршил. Үйлчилгээний барилга
         * (`units_per_floor = 0`) дээр үүсгэвэл «айлгүй блокт айлын ажил
         * нэмж болохгүй» гэсэн дүрэм хүчингүй болно: шалгалт нь айлын
         * байршил байгаа эсэхээр хийгддэг тул 4 зай нь айлын ажлыг
         * зөвшөөрүүлнэ. Яг тэр регресс гарсан.
         *
         * Төсвийн «Сууцны бус талбай» гэх задаргаа нь орон сууцны барилгын
         * хуудсанд байдаг — үйлчилгээний барилгад хамаарахгүй.
         */
        if (! $withSpaces) {
            return [$floor, $spaces];
        }

        foreach (self::TECHNICAL_SPACES as $i => $name) {
            $spaceId = (string) Str::uuid7();
            $spaces[] = $this->row(
                $spaceId, $block, $floorId, 'unit', $name,
                "{$block->name} › ".self::TECHNICAL_FLOOR." › {$name}",
                $floorPath.$spaceId.'/', $i, $now
            );
        }

        return [$floor, $spaces];
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
