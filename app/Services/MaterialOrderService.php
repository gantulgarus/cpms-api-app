<?php

namespace App\Services;

use App\Models\Block;
use App\Models\Material;
use App\Models\MaterialOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Материалын захиалгын амьдралын мөчлөг.
 *
 * `ConcreteOrderService`-тэй ИЖИЛ дүрэм: драфт төсөв иддэггүй, илгээсний
 * дараа түгжигдэнэ, захиалсан хүн өөрөө баталгаажуулахгүй. Хоёр урсгал
 * өөр дүрэмтэй байвал хэрэглэгч аль нь юу хийдгийг санахаа болино.
 */
class MaterialOrderService
{
    public function __construct(private readonly MaterialOrderBalance $balance) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Block $block, User $user, array $data): MaterialOrder
    {
        return DB::transaction(function () use ($block, $user, $data) {
            $order = MaterialOrder::create([
                'number' => $this->nextNumber(),
                'block_id' => $block->id,
                'kind' => $data['kind'],
                'location_id' => $data['locationId'] ?? null,
                'needed_by' => $data['neededBy'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'requested_by' => $user->id,
            ]);

            $this->replaceLines($order, $data['lines']);

            return $order->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MaterialOrder $order, array $data): MaterialOrder
    {
        if (! $order->isEditable()) {
            throw new RuntimeException(
                'Илгээсэн захиалгыг засах боломжгүй — цуцлаад шинээр бичнэ үү.'
            );
        }

        return DB::transaction(function () use ($order, $data) {
            $order->update([
                'location_id' => $data['locationId'] ?? null,
                'needed_by' => $data['neededBy'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->replaceLines($order, $data['lines']);

            return $order->refresh();
        });
    }

    /**
     * Мөрүүдийг БҮХЭЛД НЬ солино.
     *
     * Мөр мөрөөр нь тулгаж шинэчлэх нь илүү «ухаалаг» боловч драфт дээр
     * утгагүй: хэрэглэгч мөр нэмж, хасаж, дарааллыг нь сольдог. Бүхэлд
     * нь солих нь дэлгэц дээр харагдсантай ҮРГЭЛЖ таарна.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function replaceLines(MaterialOrder $order, array $lines): void
    {
        $order->lines()->delete();

        $materials = Material::whereIn(
            'id',
            array_values(array_filter(array_column($lines, 'materialId'))),
        )->get()->keyBy('id');

        foreach (array_values($lines) as $position => $line) {
            $material = isset($line['materialId']) ? $materials->get($line['materialId']) : null;

            if ($material?->isConcrete()) {
                throw new RuntimeException(
                    "«{$material->label()}» нь бетон — бетон захиалгын хуудсаар захиална."
                );
            }

            $order->lines()->create([
                'material_id' => $material?->id,
                /*
                 * Нэр, нэгжийг лавлахаас ХУУЛБАРЛАНА.
                 *
                 * Лавлах дараа засагдвал хэвлэгдэж, гарын үсэг зурагдсан
                 * захиалга өөрчлөгдөх ёсгүй.
                 */
                'name' => $material?->label() ?? ($line['name'] ?? ''),
                'unit' => $material?->unit ?? ($line['unit'] ?? ''),
                'stock_qty' => $line['stockQty'] ?? null,
                'quantity' => $line['quantity'],
                'unit_weight_kg' => $line['unitWeightKg'] ?? null,
                'note' => $line['note'] ?? null,
                'position' => $position,
            ]);
        }
    }

    public function submit(MaterialOrder $order): MaterialOrder
    {
        if ($order->status !== 'draft') {
            throw new RuntimeException('Зөвхөн драфт захиалгыг илгээнэ.');
        }

        if ($order->lines()->count() === 0) {
            throw new RuntimeException('Мөргүй захиалга илгээх боломжгүй.');
        }

        $order->forceFill(['status' => 'submitted', 'submitted_at' => now()])->save();

        return $order;
    }

    /**
     * Баталгаажуулах — мөр бүрийн ЗӨВШӨӨРСӨН тоог тэр үед тогтооно.
     *
     * Бланк дээр «Зөвшөөрсөн тоо хэмжээ» гэсэн тусдаа багана байгаа нь
     * санамсаргүй биш: баталгаажуулагч 100 ширхэг хүссэнийг 60 болгон
     * бууруулдаг. Өгөгдөөгүй мөр нь захиалсан тоогоороо батлагдана.
     *
     * @param  array<string, float>  $approved  мөрийн id → зөвшөөрсөн тоо
     */
    public function confirm(MaterialOrder $order, User $user, array $approved = []): MaterialOrder
    {
        if ($order->status !== 'submitted') {
            throw new RuntimeException('Зөвхөн илгээсэн захиалгыг баталгаажуулна.');
        }

        if ($order->requested_by !== null && $order->requested_by === $user->id) {
            throw new RuntimeException(
                'Захиалга өгсөн хүн өөрөө баталгаажуулах боломжгүй — өөр инженер батална.'
            );
        }

        return DB::transaction(function () use ($order, $user, $approved) {
            foreach ($order->lines as $line) {
                $value = $approved[$line->id] ?? $line->quantity;

                if ($value < 0) {
                    throw new RuntimeException('Зөвшөөрсөн тоо сөрөг байж болохгүй.');
                }

                $line->update(['approved_qty' => round((float) $value, 3)]);
            }

            $order->forceFill([
                'status' => 'confirmed',
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ])->save();

            return $order->refresh();
        });
    }

    /**
     * Нийлүүлэлт — мөр бүрийн БОДИТ хэмжээгээр.
     *
     * @param  array<string, float>  $delivered  мөрийн id → ирсэн тоо
     */
    public function deliver(
        MaterialOrder $order,
        User $user,
        array $delivered,
        ?string $date = null,
    ): MaterialOrder {
        if (! in_array($order->status, ['submitted', 'confirmed'], true)) {
            throw new RuntimeException('Зөвхөн илгээсэн эсвэл баталгаажсан захиалгыг хүлээж авна.');
        }

        return DB::transaction(function () use ($order, $user, $delivered, $date) {
            $day = $date ?? now()->toDateString();

            foreach ($order->lines as $line) {
                if (! array_key_exists($line->id, $delivered)) {
                    continue;
                }

                $line->update([
                    'delivered_qty' => round((float) $delivered[$line->id], 3),
                    'delivered_at' => $day,
                ]);
            }

            $order->forceFill(['status' => 'delivered', 'received_by' => $user->id])->save();

            return $order->refresh();
        });
    }

    public function cancel(MaterialOrder $order, string $reason): MaterialOrder
    {
        if ($order->status === 'delivered') {
            throw new RuntimeException('Нийлүүлэгдсэн захиалгыг цуцлах боломжгүй.');
        }

        $order->forceFill([
            'status' => 'cancelled',
            'notes' => trim(($order->notes ? $order->notes."\n" : '').'Цуцлав: '.$reason),
        ])->save();

        return $order;
    }

    /**
     * Мөр бүрийн төсвийн үлдэгдэл — дэлгэц, хэвлэх хуудсанд.
     *
     * @return array<string, array{budget: float, ordered: float, remaining: float, overBy: float}>
     */
    public function balanceForLines(MaterialOrder $order): array
    {
        $out = [];

        foreach ($order->lines as $line) {
            if ($line->material_id === null) {
                continue;   // гараар бичсэн мөр — төсөвт байхгүй
            }

            $out[$line->id] = $this->balance->forMaterial(
                $order->block,
                $line->material_id,
                $order->id,
            );
        }

        return $out;
    }

    /** Дараагийн дугаар: «МЗ-2026-001». */
    private function nextNumber(): string
    {
        $prefix = 'МЗ-'.now()->year.'-';

        $last = MaterialOrder::where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
