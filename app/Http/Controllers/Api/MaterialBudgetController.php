<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Material;
use App\Models\MaterialBudgetItem;
use App\Models\MaterialOrder;
use App\Services\MaterialBudgetImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Материалын төсвийн мөрийг гараар засах.
 *
 * ЗӨВХӨН ҮНЭ. Тоо хэмжээ нь байршлаар задарсан байдаг тул гараар
 * засвал нийлбэр ба задаргаа зөрнө — түүнийг импортоор л солино.
 * Үнэ эсрэгээрээ нэг тоо: зах зээл хөдөлсөн эсвэл төсөвт тавигдаагүй
 * байхад гараар бичих нь ердийн ажил.
 */
class MaterialBudgetController extends Controller
{
    /**
     * POST /blocks/{block}/material-budget-items
     *
     * Төсөвт БАЙХГҮЙ материалыг гараар нэмэх.
     *
     * ЯАГААД ХЭРЭГТЭЙ ВЭ: төсвийн файлын 109 мөр эвдэрсэн, 14 мөр
     * таараагүй. Тэднийг оруулахын тулд бүх төсвийг дахин импортлох
     * нь хэт том алхам — нэг материал нэмэхэд 273 мөрийг дахин
     * бичих шаардлагагүй.
     *
     * ТОО ХЭМЖЭЭГ БАЙРШЛААР өгнө. Нийт дүнг задаргаанаас бодно —
     * хоёрыг тусад нь авбал зөрөх эрсдэлтэй. Бүхэл барилгад хамаарах
     * материалыг блокийн үндэс байршилд бичнэ.
     */
    public function store(Request $request, Block $block): JsonResponse
    {
        $this->authorizeEdit($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'grade' => ['nullable', 'string', 'max:64'],
            'unit' => ['required', Rule::in(MaterialBudgetImport::UNITS)],
            'actualUnitPrice' => ['nullable', 'numeric', 'gt:0'],
            'marketUnitPrice' => ['nullable', 'numeric', 'gt:0'],

            'quantities' => ['required', 'array', 'min:1'],
            'quantities.*.locationId' => [
                'required', 'uuid',
                Rule::exists('locations', 'id')->where('block_id', $block->id),
            ],
            'quantities.*.quantity' => ['required', 'numeric', 'gt:0'],
        ], [
            'unit.in' => 'Танигдахгүй хэмжих нэгж.',
            'quantities.required' => 'Дор хаяж нэг байршилд тоо хэмжээ оруулна уу.',
            'quantities.*.locationId.exists' => 'Энэ блокт тийм байршил алга.',
        ]);

        /*
         * Лавлах нь ТӨСЛИЙН хэмжээнд — код нь түлхүүр.
         *
         * «Ф8» гэдэг арматур 75 барилгад ижил зүйл. Блок бүрт шинээр
         * үүсгэвэл лавлах давхардаж, захиалгын жагсаалт уншигдахгүй
         * болно.
         */
        $material = Material::firstOrNew([
            'project_id' => $block->project_id,
            'code' => $validated['code'],
        ]);

        $material->fill([
            'name' => $validated['name'],
            'grade' => $validated['grade'] ?? null,
            'unit' => $validated['unit'],
        ])->save();

        if (MaterialBudgetItem::where('block_id', $block->id)
            ->where('material_id', $material->id)
            ->exists()
        ) {
            return response()->json([
                'message' => "«{$validated['code']}» код энэ блокийн төсөвт аль хэдийн байна — "
                    .'тэр мөрийг нь засна уу.',
            ], 422);
        }

        $item = DB::transaction(function () use ($block, $material, $validated) {
            $total = array_sum(array_column($validated['quantities'], 'quantity'));

            $item = MaterialBudgetItem::create([
                'block_id' => $block->id,
                'material_id' => $material->id,
                'actual_unit_price' => $validated['actualUnitPrice'] ?? null,
                'market_unit_price' => $validated['marketUnitPrice'] ?? null,
                'total_qty' => round((float) $total, 3),
            ]);

            foreach ($validated['quantities'] as $row) {
                $item->quantities()->create([
                    'location_id' => $row['locationId'],
                    'quantity' => round((float) $row['quantity'], 3),
                ]);
            }

            return $item;
        });

        return response()->json(['data' => ['id' => $item->id]], 201);
    }

    /**
     * DELETE /material-budget-items/{materialBudgetItem}
     *
     * Гараар нэмсэн эсвэл буруу орсон мөрийг хасах.
     *
     * ЗАХИАЛГАТАЙ бол ХОРИГЛОНО: үлдэгдэл нь төсөв дээр тогтдог тул
     * төсвийг нь устгавал захиалга «төсөвгүй» болж, хэтэрсэн эсэх нь
     * мэдэгдэхгүй болно.
     */
    public function destroy(Request $request, MaterialBudgetItem $materialBudgetItem): JsonResponse
    {
        $this->authorizeEdit($request);

        $ordered = MaterialOrder::query()
            ->committed()
            ->where('block_id', $materialBudgetItem->block_id)
            ->whereHas(
                'lines',
                fn ($q) => $q->where('material_id', $materialBudgetItem->material_id),
            )
            ->exists();

        abort_if(
            $ordered,
            422,
            'Энэ материалаар захиалга хийгдсэн тул төсвийн мөрийг устгах боломжгүй.'
        );

        $materialBudgetItem->quantities()->delete();
        $materialBudgetItem->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** PATCH /material-budget-items/{materialBudgetItem}/price */
    public function setPrice(Request $request, MaterialBudgetItem $materialBudgetItem): JsonResponse
    {
        $this->authorizeEdit($request);

        /*
         * `null` нь үнийг АРИЛГАНА, 0 нь зөвшөөрөгдөхгүй — «үнэгүй
         * материал» ба «үнэ нь мэдэгдэхгүй» хоёр өөр зүйл.
         */
        $validated = $request->validate([
            'actualUnitPrice' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'marketUnitPrice' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
        ], [
            'actualUnitPrice.gt' => 'Үнэ 0-ээс их байх ёстой. Арилгах бол хоосон үлдээнэ үү.',
            'marketUnitPrice.gt' => 'Үнэ 0-ээс их байх ёстой. Арилгах бол хоосон үлдээнэ үү.',
        ]);

        // Зөвхөн ИЛГЭЭСЭН талбарыг хөндөнө: хоёуланг дамжуулбал нэгийг
        // засахад нөгөө нь чимээгүй цэвэрлэгдэнэ.
        foreach (['actualUnitPrice' => 'actual_unit_price', 'marketUnitPrice' => 'market_unit_price'] as $in => $column) {
            if ($request->has($in)) {
                $materialBudgetItem->{$column} = $validated[$in] ?? null;
            }
        }

        $materialBudgetItem->save();

        return response()->json([
            'data' => [
                'id' => $materialBudgetItem->id,
                'actualUnitPrice' => $materialBudgetItem->actual_unit_price,
                'marketUnitPrice' => $materialBudgetItem->market_unit_price,
                'actualAmount' => $materialBudgetItem->actualAmount(),
                'marketAmount' => $materialBudgetItem->marketAmount(),
                'variancePercent' => $materialBudgetItem->variancePercent(),
            ],
        ]);
    }

    private function authorizeEdit(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->canManageReferenceData(),
            403,
            'Төсөв засах эрх зөвхөн ерөнхий инженер, админд байна.'
        );
    }
}
