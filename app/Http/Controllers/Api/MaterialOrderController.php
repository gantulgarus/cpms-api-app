<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\MaterialOrder;
use App\Models\Project;
use App\Services\MaterialOrderBalance;
use App\Services\MaterialOrderService;
use App\Services\MaterialOrderSheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Материалын захиалга — үндсэн, туслах, модон, арматур.
 *
 * Эрх нь бетон захиалгатай ИЖИЛ: бичих нь талбайн инженерээс дээш,
 * баталгаажуулах нь хяналтын инженерээс дээш, захиалсан хүн өөрөө
 * баталгаажуулахгүй. Гүйцэтгэгч хаалттай.
 */
class MaterialOrderController extends Controller
{
    private const RELATIONS = [
        'block', 'location', 'lines.material', 'requestedBy', 'receivedBy', 'confirmedBy',
    ];

    /** GET /projects/{project}/material-orders */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorizeRead($request);

        $orders = MaterialOrder::with(self::RELATIONS)
            ->whereIn('block_id', $project->blocks()->select('id'))
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('kind'), fn ($q, $v) => $q->where('kind', $v))
            ->when($request->query('blockId'), fn ($q, $v) => $q->where('block_id', $v))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $orders->map(fn ($o) => $this->shape($o))->all()]);
    }

    /**
     * GET /blocks/{block}/material-balance
     *
     * Материал бүрийн төсөв, захиалсан, үлдэгдэл — захиалга бичих
     * дэлгэцийн үндэс. Бетон ОРОХГҮЙ: түүнд өөрийн урсгал бий.
     */
    public function balance(Request $request, Block $block): JsonResponse
    {
        $this->authorizeRead($request);

        return response()->json([
            'data' => app(MaterialOrderBalance::class)->forBlock($block),
        ]);
    }

    public function show(Request $request, MaterialOrder $materialOrder): JsonResponse
    {
        $this->authorizeRead($request);

        return response()->json(['data' => $this->shape($materialOrder->load(self::RELATIONS))]);
    }

    /** POST /blocks/{block}/material-orders */
    public function store(Request $request, Block $block): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $this->validatePayload($request, $block);

        try {
            $order = app(MaterialOrderService::class)->create($block, $request->user(), $validated);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->shape($order->load(self::RELATIONS))], 201);
    }

    /** PATCH /material-orders/{materialOrder} — зөвхөн драфт. */
    public function update(Request $request, MaterialOrder $materialOrder): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $this->validatePayload($request, $materialOrder->block, $materialOrder);

        try {
            $order = app(MaterialOrderService::class)->update($materialOrder, $validated);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->shape($order->load(self::RELATIONS))]);
    }

    public function submit(Request $request, MaterialOrder $materialOrder): JsonResponse
    {
        $this->authorizeWrite($request);

        return $this->transition(fn () => app(MaterialOrderService::class)->submit($materialOrder));
    }

    /**
     * POST /material-orders/{materialOrder}/confirm
     *
     * `approved` нь мөрийн id → зөвшөөрсөн тоо. Өгөөгүй мөр захиалсан
     * тоогоороо батлагдана.
     */
    public function confirm(Request $request, MaterialOrder $materialOrder): JsonResponse
    {
        abort_unless(
            in_array($request->user()?->role, MaterialOrder::CONFIRMER_ROLES, true),
            403,
            'Захиалгыг зөвхөн хяналтын инженер, ерөнхий инженер баталгаажуулна.'
        );

        $validated = $request->validate([
            'approved' => ['nullable', 'array'],
            'approved.*' => ['numeric', 'min:0'],
        ]);

        return $this->transition(fn () => app(MaterialOrderService::class)->confirm(
            $materialOrder,
            $request->user(),
            $validated['approved'] ?? [],
        ));
    }

    /** POST /material-orders/{materialOrder}/deliver */
    public function deliver(Request $request, MaterialOrder $materialOrder): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'delivered' => ['required', 'array', 'min:1'],
            'delivered.*' => ['numeric', 'min:0'],
            'deliveredAt' => ['nullable', 'date'],
        ]);

        return $this->transition(fn () => app(MaterialOrderService::class)->deliver(
            $materialOrder,
            $request->user(),
            $validated['delivered'],
            $validated['deliveredAt'] ?? null,
        ));
    }

    /** POST /material-orders/{materialOrder}/cancel */
    public function cancel(Request $request, MaterialOrder $materialOrder): JsonResponse
    {
        $this->authorizeWrite($request);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'reason.required' => 'Цуцлах шалтгаан заавал.',
            'reason.min' => 'Шалтгаан хэт богино байна.',
        ]);

        return $this->transition(
            fn () => app(MaterialOrderService::class)->cancel($materialOrder, $validated['reason'])
        );
    }

    /** GET /material-orders/{materialOrder}/sheet.xlsx */
    public function sheet(Request $request, MaterialOrder $materialOrder): BinaryFileResponse
    {
        $this->authorizeRead($request);

        $name = 'material-zahialga-'.$materialOrder->number.'.xlsx';

        Storage::disk('local')->makeDirectory('orders');
        $path = Storage::disk('local')->path('orders/'.$name);

        app(MaterialOrderSheet::class)->build(
            $materialOrder,
            app(MaterialOrderService::class)->balanceForLines($materialOrder),
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
    private function validatePayload(Request $request, Block $block, ?MaterialOrder $existing = null): array
    {
        return $request->validate([
            'kind' => [
                $existing ? 'prohibited' : 'required',
                Rule::in(array_keys(MaterialOrder::KINDS)),
            ],
            'locationId' => [
                'nullable', 'uuid',
                Rule::exists('locations', 'id')->where('block_id', $block->id),
            ],
            'neededBy' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.materialId' => ['nullable', 'uuid', 'exists:materials,id'],
            /*
             * Лавлахгүй мөрөнд нэр, нэгж ЗААВАЛ. Бланк дээр урьдчилан
             * хэвлэгдсэн зарим мөр төсөвт байдаггүй («Шатны тор») —
             * тэднийг хориглох нь талбайн ажлыг зогсооно.
             */
            'lines.*.name' => ['required_without:lines.*.materialId', 'nullable', 'string', 'max:255'],
            'lines.*.unit' => ['required_without:lines.*.materialId', 'nullable', 'string', 'max:16'],
            'lines.*.stockQty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unitWeightKg' => ['nullable', 'numeric', 'min:0'],
            'lines.*.note' => ['nullable', 'string', 'max:255'],
        ], [
            'kind.prohibited' => 'Хуудасны төрлийг солих боломжгүй — шинэ захиалга бичнэ үү.',
            'lines.required' => 'Дор хаяж нэг мөр шаардлагатай.',
            'lines.*.quantity.gt' => 'Захиалах тоо 0-ээс их байх ёстой.',
            'locationId.exists' => 'Энэ блокт тийм байршил алга.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(MaterialOrder $order): array
    {
        $balances = app(MaterialOrderService::class)->balanceForLines($order);

        $lines = $order->lines->map(function ($line) use ($balances) {
            $balance = $balances[$line->id] ?? null;
            $asked = $line->approved_qty ?? $line->quantity;

            return [
                'id' => $line->id,
                'materialId' => $line->material_id,
                'name' => $line->name,
                'unit' => $line->unit,
                'stockQty' => $line->stock_qty,
                'quantity' => (float) $line->quantity,
                'approvedQty' => $line->approved_qty,
                'deliveredQty' => $line->delivered_qty,
                'unitWeightKg' => $line->unit_weight_kg,
                'totalWeightKg' => $line->totalWeightKg(),
                'note' => $line->note,

                /*
                 * Төсвийн үлдэгдэл нь мөр БҮРТ явна. Зөвхөн нийт дүнд
                 * харуулбал аль мөр нь хэтэрснийг олохын тулд хүн
                 * 27 мөрийг нүдээр шалгана.
                 */
                'budgetQty' => $balance['budget'] ?? null,
                'orderedQty' => $balance['ordered'] ?? null,
                'remainingQty' => $balance ? round($balance['remaining'] - $asked, 3) : null,
                'overBy' => $balance ? max(0, round($asked - $balance['remaining'], 3)) : 0,
            ];
        })->all();

        return [
            'id' => $order->id,
            'number' => $order->number,
            'blockId' => $order->block_id,
            'blockName' => $order->block?->name,
            'kind' => $order->kind,
            'kindLabel' => $order->kindLabel(),
            'isRebar' => $order->isRebar(),
            'locationId' => $order->location_id,
            'locationName' => $order->location?->name,
            'neededBy' => $order->needed_by?->toDateString(),
            'status' => $order->status,
            'statusLabel' => $order->statusLabel(),
            'isEditable' => $order->isEditable(),
            'lineCount' => count($lines),
            'overLineCount' => count(array_filter($lines, fn ($l) => $l['overBy'] > 0)),
            'lines' => $lines,
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
            'Танд материалын захиалга харах эрх байхгүй.'
        );
    }

    private function authorizeWrite(Request $request): void
    {
        abort_unless(
            in_array($request->user()?->role, MaterialOrder::REQUESTER_ROLES, true),
            403,
            'Танд материалын захиалга бичих эрх байхгүй.'
        );
    }
}
