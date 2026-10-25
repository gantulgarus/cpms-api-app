<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\ConcreteOrder;
use App\Models\Project;
use App\Services\ConcreteBudgetBalance;
use App\Services\ConcreteOrderService;
use App\Services\ConcreteOrderSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Бетон зуурмагийн захиалга.
 *
 * ЭРХ ХОЁР ТҮВШИН:
 *   бичих        — талбайн инженер, төслийн менежер, дээш
 *   баталгаажуулах — хяналтын инженер, дээш (захиалсан хүнээс ӨӨР)
 *
 * Гүйцэтгэгч ОРОХГҮЙ: бетон бол захиалагчийн зардал.
 */
class ConcreteOrderController extends Controller
{
    private const RELATIONS = [
        'block', 'location', 'material', 'requestedBy', 'receivedBy', 'confirmedBy',
    ];

    /** GET /projects/{project}/concrete-orders */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorizeRead($request);

        $orders = ConcreteOrder::with(self::RELATIONS)
            ->whereIn('block_id', $project->blocks()->select('id'))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('blockId'), fn ($q, $v) => $q->where('block_id', $v))
            ->orderByDesc('pour_date')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $orders->map(fn ($o) => $this->shape($o))->all()]);
    }

    /**
     * GET /blocks/{block}/concrete-balance
     *
     * Байршил × марк бүрийн төсөв, захиалсан, үлдэгдэл.
     *
     * ЭНЭ НЬ ЗАХИАЛГА БИЧИХ ДЭЛГЭЦИЙН ҮНДЭС: инженер давхраа сонгоход
     * «М250 төсөвт 240, өмнө нь 180 захиалсан, 60 үлдсэн» гэж ТЭР ДОР НЬ
     * харна. Цаасан хуудас үүнийг хэзээ ч хэлж чадахгүй.
     */
    public function balance(Request $request, Block $block): JsonResponse
    {
        $this->authorizeRead($request);

        return response()->json([
            'data' => app(ConcreteBudgetBalance::class)->forBlock($block),
        ]);
    }

    public function show(Request $request, ConcreteOrder $concreteOrder): JsonResponse
    {
        $this->authorizeRead($request);

        return response()->json(['data' => $this->shape($concreteOrder->load(self::RELATIONS))]);
    }

    /** POST /blocks/{block}/concrete-orders */
    public function store(Request $request, Block $block): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $this->validatePayload($request, $block);

        try {
            $order = app(ConcreteOrderService::class)
                ->create($block, $request->user(), $validated);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->shape($order->load(self::RELATIONS))], 201);
    }

    /** PATCH /concrete-orders/{concreteOrder} — зөвхөн драфт. */
    public function update(Request $request, ConcreteOrder $concreteOrder): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $this->validatePayload($request, $concreteOrder->block);

        try {
            $order = app(ConcreteOrderService::class)->update($concreteOrder, $validated);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->shape($order->load(self::RELATIONS))]);
    }

    /** POST /concrete-orders/{concreteOrder}/submit */
    public function submit(Request $request, ConcreteOrder $concreteOrder): JsonResponse
    {
        $this->authorizeWrite($request);

        return $this->transition(fn () => app(ConcreteOrderService::class)->submit($concreteOrder));
    }

    /** POST /concrete-orders/{concreteOrder}/confirm */
    public function confirm(Request $request, ConcreteOrder $concreteOrder): JsonResponse
    {
        abort_unless(
            in_array($request->user()?->role, ConcreteOrder::CONFIRMER_ROLES, true),
            403,
            'Захиалгыг зөвхөн хяналтын инженер, ерөнхий инженер баталгаажуулна.'
        );

        return $this->transition(
            fn () => app(ConcreteOrderService::class)->confirm($concreteOrder, $request->user())
        );
    }

    /** POST /concrete-orders/{concreteOrder}/deliver */
    public function deliver(Request $request, ConcreteOrder $concreteOrder): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'deliveredQty' => ['required', 'numeric', 'gt:0'],
            'deliveredAt' => ['nullable', 'date'],
        ], [
            'deliveredQty.gt' => 'Ирсэн хэмжээ 0-ээс их байх ёстой.',
        ]);

        return $this->transition(fn () => app(ConcreteOrderService::class)->deliver(
            $concreteOrder,
            $request->user(),
            (float) $validated['deliveredQty'],
            $validated['deliveredAt'] ?? null,
        ));
    }

    /** POST /concrete-orders/{concreteOrder}/cancel */
    public function cancel(Request $request, ConcreteOrder $concreteOrder): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            // Шалтгаангүй цуцлалт нь дараа тайлагдахгүй — бетон
            // захиалга цуцлах нь үйлдвэртэй харилцах асуудал.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Цуцлах шалтгаан заавал.',
            'reason.min' => 'Шалтгаан хэт богино байна.',
        ]);

        return $this->transition(
            fn () => app(ConcreteOrderService::class)->cancel($concreteOrder, $validated['reason'])
        );
    }

    /**
     * GET /concrete-orders/{concreteOrder}/sheet.xlsx
     *
     * Захиалагчийн бланкны хэлбэрээр — хэвлээд гарын үсэг зуруулна.
     */
    public function sheet(Request $request, ConcreteOrder $concreteOrder): BinaryFileResponse
    {
        $this->authorizeRead($request);

        $name = 'beton-zahialga-'.$concreteOrder->number.'.xlsx';

        Storage::disk('local')->makeDirectory('orders');
        $path = Storage::disk('local')->path('orders/'.$name);

        app(ConcreteOrderSheet::class)->build(
            $concreteOrder,
            app(ConcreteOrderService::class)->balanceFor($concreteOrder),
            $path,
        );

        return response()
            ->download($path, $name, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend();
    }

    // -----------------------------------------------------------------

    private function transition(callable $run): JsonResponse
    {
        try {
            $order = $run();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->shape($order->load(self::RELATIONS))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, Block $block): array
    {
        return $request->validate([
            'locationId' => [
                'required', 'uuid',
                // Байршил нь ЭНЭ блокийнх байх ёстой — өөр блокийн
                // давхарт захиалга бичвэл үлдэгдэл огт утгагүй болно.
                Rule::exists('locations', 'id')->where('block_id', $block->id),
            ],
            'materialId' => ['required', 'uuid', 'exists:materials,id'],
            'pourDate' => ['required', 'date', 'after_or_equal:today'],
            'pourHalf' => ['nullable', Rule::in(['am', 'pm'])],
            'pourTime' => ['nullable', 'date_format:H:i'],
            'structure' => ['required', 'string', 'max:255'],
            'concreteClass' => ['nullable', 'string', 'max:16'],
            'slumpCm' => ['nullable', 'integer', 'min:0', 'max:30'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'needsPump' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'locationId.exists' => 'Энэ блокт тийм байршил алга.',
            'pourDate.after_or_equal' => 'Өнгөрсөн огноонд бетон захиалах боломжгүй.',
            'quantity.gt' => 'Захиалах хэмжээ 0-ээс их байх ёстой.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(ConcreteOrder $order): array
    {
        $balance = app(ConcreteOrderService::class)->balanceFor($order);
        $thisOrder = $order->delivered_qty ?? $order->quantity;

        return [
            'id' => $order->id,
            'number' => $order->number,
            'blockId' => $order->block_id,
            'blockName' => $order->block?->name,
            'locationId' => $order->location_id,
            'locationName' => $order->location?->name,
            'materialId' => $order->material_id,
            'materialName' => $order->material?->label(),
            'grade' => $order->grade,
            'concreteClass' => $order->concrete_class,
            'slumpCm' => $order->slump_cm,
            'structure' => $order->structure,
            'pourDate' => $order->pour_date?->toDateString(),
            'pourHalf' => $order->pour_half,
            'pourTime' => $order->pour_time,
            'quantity' => (float) $order->quantity,
            'needsPump' => (bool) $order->needs_pump,
            'deliveredQty' => $order->delivered_qty !== null ? (float) $order->delivered_qty : null,
            'deliveredAt' => $order->delivered_at?->toDateString(),
            'status' => $order->status,
            'statusLabel' => $order->statusLabel(),
            'isEditable' => $order->isEditable(),

            /*
             * ЯАРАЛТАЙ ба ХЭТЭРСЭН нь мөр бүр дээр явна.
             *
             * Жагсаалт дээр харагдахгүй бол дэлгэрэнгүй рүү орж байж
             * мэдэх болно — тэр үед хэтэрсэн захиалга анзаарагдахгүй
             * өнгөрнө.
             */
            'isRush' => $order->isRush(),
            'budgetQty' => $balance['budget'],
            'orderedQty' => $balance['ordered'],
            'remainingQty' => round($balance['remaining'] - $thisOrder, 3),
            'overBy' => max(0, round($thisOrder - $balance['remaining'], 3)),

            'requestedBy' => $order->requestedBy?->name,
            'receivedBy' => $order->receivedBy?->name,
            'confirmedBy' => $order->confirmedBy?->name,
            'submittedAt' => $order->submitted_at?->toIso8601String(),
            'confirmedAt' => $order->confirmed_at?->toIso8601String(),
            'notes' => $order->notes,
            'createdAt' => $order->created_at?->toIso8601String(),
        ];
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless(
            $request->user() !== null && $request->user()->role !== 'contractor',
            403,
            'Танд бетон захиалга харах эрх байхгүй.'
        );
    }

    private function authorizeWrite(Request $request): void
    {
        abort_unless(
            in_array($request->user()?->role, ConcreteOrder::REQUESTER_ROLES, true),
            403,
            'Танд бетон захиалга бичих эрх байхгүй.'
        );
    }
}
