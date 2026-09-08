<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContractorResource;
use App\Models\Contractor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * Туслан гүйцэтгэгч ба тэдний нэвтрэх эрх.
 *
 * Захиалагч: "Ажил гүйцэтгэх хугацаанд олгогдсон кодоор нэвтэрнэ",
 * "Туслан гүйцэтгэгчээс 1 хүн систем ашиглана — талбай дээр ажил ахалж
 * байгаа хүн". Тиймээс нэг гүйцэтгэгчид нэг код.
 */
class ContractorController extends Controller
{
    /** Ойлгомжгүй тэмдэгтгүй цагаан толгой — утсаар уншиж дамжуулна. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function index(): AnonymousResourceCollection
    {
        return ContractorResource::collection(Contractor::orderBy('name')->get());
    }

    public function show(Contractor $contractor): JsonResponse
    {
        return response()->json(['data' => new ContractorResource($contractor)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->canManageContractors(), 403, 'Танд гүйцэтгэгч нэмэх эрх байхгүй.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'companyName' => ['nullable', 'string', 'max:255'],
            'tradeSpecialty' => ['nullable', 'string', 'max:255'],
            'contactPerson' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $contractor = Contractor::create([
            'name' => $validated['name'],
            'company_name' => $validated['companyName'] ?? null,
            'trade_specialty' => $validated['tradeSpecialty'] ?? null,
            'contact_person' => $validated['contactPerson'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
        ]);

        return response()->json(['data' => new ContractorResource($contractor)], 201);
    }

    /**
     * Шинэ нэвтрэх код олгоно (сэргээнэ).
     *
     * Хуучин код тэр дороо хүчингүй болно — гүйцэтгэгч солигдох, төлөөлөгч
     * гарах, утас алдагдах үед энэ нь хандалтыг таслах арга.
     */
    public function issueAccessCode(Request $request, Contractor $contractor): JsonResponse
    {
        abort_unless($request->user()?->canManageContractors(), 403, 'Танд код олгох эрх байхгүй.');

        $validated = $request->validate([
            'expiresAt' => ['nullable', 'date', 'after:today'],
        ]);

        $contractor->update([
            'access_code' => $this->generateCode($contractor->name),
            'access_code_expires_at' => $validated['expiresAt'] ?? now()->addYear(),
        ]);

        $signedOut = $this->signOutDevices($contractor);

        return response()->json([
            'data' => new ContractorResource($contractor->refresh()),
            // Дэлгэц "хэдэн утаснаас гарлаа" гэдгийг хэлнэ — админ үйлдлийнхээ
            // үр дагаврыг харах ёстой.
            'meta' => ['signedOutDevices' => $signedOut],
        ]);
    }

    /** Гүйцэтгэгчийн хандалтыг тэр дор нь хаана. */
    public function revokeAccessCode(Request $request, Contractor $contractor): JsonResponse
    {
        abort_unless($request->user()?->canManageContractors(), 403, 'Танд эрх хаах эрх байхгүй.');

        $contractor->update(['access_code' => null, 'access_code_expires_at' => null]);

        $signedOut = $this->signOutDevices($contractor);

        return response()->json([
            'data' => new ContractorResource($contractor->refresh()),
            'meta' => ['signedOutDevices' => $signedOut],
        ]);
    }

    /**
     * Гүйцэтгэгчийн нэвтэрсэн бүх төхөөрөмжийг гаргана.
     *
     * ЯАГААД ЗААВАЛ: код солих нь ганцаараа хандалтыг таслахгүй. Кодоор нэг
     * удаа нэвтэрмэгц Sanctum token үүсэх бөгөөд тэр нь кодоос ХАМААРАЛГҮЙ
     * амьдарна. Token-ыг устгахгүй бол ажлаас гарсан төлөөлөгчийн утас
     * хязгааргүй хугацаагаар нэвтэрсэн хэвээр үлдэнэ — "код сэргээлээ" гэсэн
     * үйлдэл хуурамч аюулгүй байдал төрүүлнэ.
     *
     * Захиалагчийн шаардлага ч үүнийг нэрлэсэн: "Хэрэглэгч төхөөрөмжөө алдсан
     * үед remote logout шаардлагатай."
     */
    private function signOutDevices(Contractor $contractor): int
    {
        $reps = User::where('contractor_id', $contractor->id)->get();
        $count = 0;

        foreach ($reps as $rep) {
            $count += $rep->tokens()->count();
            $rep->tokens()->delete();
        }

        return $count;
    }

    /** Нэрнээс таних угтвар + санамсаргүй хэсэг: "ГОО-K4M7XP". */
    private function generateCode(string $name): string
    {
        $prefix = Str::upper(Str::substr(Str::ascii($name) ?: 'CTR', 0, 3)) ?: 'CTR';

        do {
            $random = collect(range(1, 6))
                ->map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])
                ->join('');
            $code = "{$prefix}-{$random}";
        } while (Contractor::where('access_code', $code)->exists());

        return $code;
    }
}
