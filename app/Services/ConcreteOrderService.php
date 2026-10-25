<?php

namespace App\Services;

use App\Models\Block;
use App\Models\ConcreteOrder;
use App\Models\Location;
use App\Models\Material;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Бетон захиалгын амьдралын мөчлөг.
 *
 * ГУРВАН ДҮРЭМ ЭНД ТОГТДОГ:
 *
 * 1. Драфт нь төсөв иддэггүй. Бичиж байгаа захиалга амлалт биш.
 * 2. Илгээсний дараа мөр ТҮГЖИГДЭНЭ — захиалга үйлдвэр рүү очсон.
 * 3. Захиалсан хүн өөрөө баталгаажуулахгүй. Бланк дээр хоёр өөр
 *    инженерийн гарын үсэг байдаг нь санамсаргүй биш.
 */
class ConcreteOrderService
{
    public function __construct(private readonly ConcreteBudgetBalance $balance) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Block $block, User $user, array $data): ConcreteOrder
    {
        $location = Location::where('block_id', $block->id)
            ->findOrFail($data['locationId']);

        $material = Material::findOrFail($data['materialId']);

        if (! $material->isConcrete()) {
            throw new RuntimeException("«{$material->label()}» нь бетон биш — нэгж нь м³, марк нь М-ээр эхлэх ёстой.");
        }

        return DB::transaction(fn () => ConcreteOrder::create([
            'number' => $this->nextNumber(),
            'block_id' => $block->id,
            'location_id' => $location->id,
            'material_id' => $material->id,
            /*
             * Марк нь лавлахаас ХУУЛБАРЛАГДАНА.
             *
             * Лавлах дараа засагдвал гарсан захиалга өөрчлөгдөх ёсгүй:
             * тэр нь хэвлэгдэж, гарын үсэг зурагдсан баримт.
             */
            'grade' => $material->grade,
            'pour_date' => $data['pourDate'],
            'pour_half' => $data['pourHalf'] ?? null,
            'pour_time' => $data['pourTime'] ?? null,
            'structure' => $data['structure'],
            'concrete_class' => $data['concreteClass'] ?? null,
            'slump_cm' => $data['slumpCm'] ?? null,
            'quantity' => $data['quantity'],
            'needs_pump' => $data['needsPump'] ?? false,
            'notes' => $data['notes'] ?? null,
            'status' => 'draft',
            'requested_by' => $user->id,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ConcreteOrder $order, array $data): ConcreteOrder
    {
        if (! $order->isEditable()) {
            throw new RuntimeException(
                'Илгээсэн захиалгыг засах боломжгүй — цуцлаад шинээр бичнэ үү.'
            );
        }

        $order->fill([
            'pour_date' => $data['pourDate'],
            'pour_half' => $data['pourHalf'] ?? null,
            'pour_time' => $data['pourTime'] ?? null,
            'structure' => $data['structure'],
            'concrete_class' => $data['concreteClass'] ?? null,
            'slump_cm' => $data['slumpCm'] ?? null,
            'quantity' => $data['quantity'],
            'needs_pump' => $data['needsPump'] ?? false,
            'notes' => $data['notes'] ?? null,
        ]);

        if (isset($data['locationId'])) {
            $order->location_id = $data['locationId'];
        }

        if (isset($data['materialId'])) {
            $material = Material::findOrFail($data['materialId']);

            if (! $material->isConcrete()) {
                throw new RuntimeException("«{$material->label()}» нь бетон биш.");
            }

            $order->material_id = $material->id;
            $order->grade = $material->grade;
        }

        $order->save();

        return $order;
    }

    /**
     * Илгээх — ЭНЭ МӨЧӨӨС төсөв иднэ.
     *
     * Төсөв хэтэрсэн эсэхийг ЭНД шалгана, үүсгэх үед биш: драфт бичиж
     * байхад хэтрэлт харуулах нь зөв (инженер тоогоо тааруулж байна),
     * харин илгээхийг зогсоох нь буруу байх үе бий — нэмэлт ажил
     * гарсан байж болно. Тиймээс АНХААРУУЛНА, хориглохгүй; гэхдээ
     * хэтрэлтийг ТЭМДЭГЛЭЖ, баталгаажуулах шатанд харуулна.
     */
    public function submit(ConcreteOrder $order): ConcreteOrder
    {
        if ($order->status !== 'draft') {
            throw new RuntimeException('Зөвхөн драфт захиалгыг илгээнэ.');
        }

        $order->forceFill([
            'status' => 'submitted',
            'submitted_at' => now(),
        ])->save();

        return $order;
    }

    /**
     * Баталгаажуулах — «Захиалагч талын хяналтын инженер».
     *
     * ЗАХИАЛСАН ХҮН ӨӨРӨӨ БАТАЛГААЖУУЛЖ БОЛОХГҮЙ. Бланк дээр хоёр өөр
     * гарын үсэг байдаг нь санамсаргүй биш: бетон бол буцаагдахгүй
     * зарлага бөгөөд нэг хүний алдаа нь хэдэн сая төгрөг.
     */
    public function confirm(ConcreteOrder $order, User $user): ConcreteOrder
    {
        if ($order->status !== 'submitted') {
            throw new RuntimeException('Зөвхөн илгээсэн захиалгыг баталгаажуулна.');
        }

        if ($order->requested_by !== null && $order->requested_by === $user->id) {
            throw new RuntimeException(
                'Захиалга өгсөн хүн өөрөө баталгаажуулах боломжгүй — өөр инженер батална.'
            );
        }

        $order->forceFill([
            'status' => 'confirmed',
            'confirmed_by' => $user->id,
            'confirmed_at' => now(),
        ])->save();

        return $order;
    }

    /**
     * Нийлүүлэлт бүртгэх — БОДИТ хэмжээгээр.
     *
     * Машин 7 м³-аар ирдэг тул 18 м³ захиалбал 21 ирнэ. Бодит хэмжээг
     * бичихгүй бол үлдэгдэл аажмаар худал болно.
     */
    public function deliver(ConcreteOrder $order, User $user, float $qty, ?string $date): ConcreteOrder
    {
        if (! in_array($order->status, ['submitted', 'confirmed'], true)) {
            throw new RuntimeException('Зөвхөн илгээсэн эсвэл баталгаажсан захиалгыг хүлээж авна.');
        }

        $order->forceFill([
            'status' => 'delivered',
            'delivered_qty' => round($qty, 3),
            'delivered_at' => $date ?? now()->toDateString(),
            'received_by' => $user->id,
        ])->save();

        return $order;
    }

    public function cancel(ConcreteOrder $order, string $reason): ConcreteOrder
    {
        if ($order->status === 'delivered') {
            throw new RuntimeException('Нийлүүлэгдсэн захиалгыг цуцлах боломжгүй.');
        }

        $order->forceFill([
            'status' => 'cancelled',
            // Шалтгааныг тайлбарт НЭМНЭ, дарж бичихгүй — цуцлалтын
            // учир шалтгаан нь дараа асуугдах зүйл.
            'notes' => trim(($order->notes ? $order->notes."\n" : '').'Цуцлав: '.$reason),
        ])->save();

        return $order;
    }

    /**
     * Захиалгын үлдэгдлийн байдал.
     *
     * @return array{budget: float, ordered: float, remaining: float, overBy: float}
     */
    public function balanceFor(ConcreteOrder $order): array
    {
        return $this->balance->forLocation(
            $order->location_id,
            $order->material_id,
            $order->id,
        );
    }

    /**
     * Дараагийн дугаар: «БЗ-2026-001».
     *
     * Жил бүр 001-ээс эхэлнэ — цаасан бүртгэлийн уламжлал. Хамгийн
     * сүүлийн ДУГААРААС бодно, тоогоор биш: цуцлагдсан захиалга устдаггүй
     * тул тоолох нь давхардал үүсгэнэ.
     */
    private function nextNumber(): string
    {
        $prefix = 'БЗ-'.now()->year.'-';

        $last = ConcreteOrder::where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }
}
