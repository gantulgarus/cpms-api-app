<?php

namespace App\Services;

use App\Models\Block;
use Illuminate\Support\Carbon;

/**
 * Давхрын хугацаагаар төлөвлөгөөт огноо тооцох.
 *
 * НЭР ТОМЬЁО: олон улсад "takt planning" гэдэг (герман Takt = хэмнэл).
 * Ангийн нэр тэр хэвээр, дэлгэц дээр «давхрын хугацаа» гэж бичнэ.
 *
 * ЯАГААД: урьд нь бүх ажил «эхлэл + 14 хоног» гэж тооцогддог байсан. Энэ нь
 * хоцролтыг утгагүй болгодог — суурийн ажил ба 16-р давхрын шал ижил өдөр
 * дуусах ёстой гэж гардаг байв.
 *
 * ЯАЖ АЖИЛЛАДАГ ВЭ: баг бүр нэг давхарт ТОГТМОЛ хугацаа зарцуулаад дараагийн
 * давхарт шилждэг. Түүний ард дараагийн баг орно. Ингэснээр давхрууд дээгүүр
 * «галт тэрэг» шиг урсгал үүснэ:
 *
 *      Давхрын хугацаа = 5 ажлын өдөр
 *                 1-5 хоног   6-10       11-15      16-20
 *      1-р давхар  Угсралт  → Өрлөг    → Шугам    → Засал
 *      2-р давхар             Угсралт  → Өрлөг    → Шугам
 *      3-р давхар                        Угсралт  → Өрлөг
 *
 * Томьёо:  эхлэх = төслийн эхлэл + (ажлын дараалал + давхар) × давхрын хугацаа
 *          дуусах = эхлэх + давхрын хугацаа
 *
 * Барилгын түвшний ажил (суурь, дээвэр, фасад) давхраар давтагдахгүй тул
 * тухайн багийн бүх зурвасыг эзэлнэ: үргэлжлэх = давхрын хугацаа × давхрын тоо.
 *
 * Огноог зөвхөн АЖЛЫН ӨДРӨӨР тоолно — 5 ажлын өдөр нь хуанлийн 7 хоног.
 * Бямба, ням дээр дуусдаг төлөвлөгөө нь эхнээсээ худал.
 */
class TaktSchedule
{
    /** Багийн дараалал мэдэгдэхгүй бол дунджаар нь тавина. */
    public const DEFAULT_ORDER = 5;

    /** Өгөгдөөгүй бол — давхар тутам ажлын 5 өдөр. */
    public const DEFAULT_TAKT_DAYS = 5;

    private Carbon $start;

    public function __construct(
        string $startDate,
        private readonly int $taktDays = self::DEFAULT_TAKT_DAYS,
        private readonly int $floors = 1,
    ) {
        $start = Carbon::parse($startDate)->startOfDay();
        // Бямба, ням гарагт эхэлдэг хуваарь гаргах нь утгагүй — дараагийн
        // ажлын өдөр рүү шилжүүлнэ.
        $this->start = $start->isWeekday() ? $start : $start->nextWeekday();
    }

    public static function forBlock(Block $block, ?string $startDate = null): self
    {
        return new self(
            $startDate ?? $block->start_date?->toDateString() ?? now()->toDateString(),
            $block->takt_days ?: self::DEFAULT_TAKT_DAYS,
            max((int) $block->floors, 1),
        );
    }

    /**
     * Нэг ажлын мөрийн эхлэх/дуусах огноо.
     *
     * @param  string  $level  ажил ямар түвшинд буусан — block|floor|unit
     * @param  int  $floorNo  давхрын дугаар (байршлын sequence_number)
     * @param  int  $order  ажлын бүлгийн дараалал (build_order)
     * @return array{0: string, 1: string}  [эхлэх, дуусах] — Y-m-d
     */
    public function window(string $level, int $floorNo, int $order): array
    {
        $order = max($order, 0);
        $floorNo = max($floorNo, 0);

        // Барилгын түвшний ажил давхраар давтагдахгүй — багийн зурвасыг бүтэн эзэлнэ.
        $duration = $level === 'block' ? $this->taktDays * $this->floors : $this->taktDays;
        $offset = ($order + $floorNo) * $this->taktDays;

        $start = $this->start->copy()->addWeekdays($offset);
        // `addWeekdays(n)` нь n ажлын өдөр ХОЙШ гэсэн үг тул дуусах өдөр нь
        // сүүлийн ажлын өдөр байхын тулд нэгийг хасна.
        $end = $start->copy()->addWeekdays(max($duration - 1, 0));

        return [$start->toDateString(), $end->toDateString()];
    }

    public function taktDays(): int
    {
        return $this->taktDays;
    }
}
