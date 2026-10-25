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
use Illuminate\Validation\Rule;

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

        $contractor = Contractor::create($this->fields($request));

        return response()->json(['data' => new ContractorResource($contractor)], 201);
    }

    /**
     * PATCH /contractors/{contractor}
     *
     * ЯАГААД ШИНЭЭР НЭМЭГДЭВ: гүйцэтгэгчийг үүсгэж болдог ч ЗАСАХ зам
     * байгаагүй. Тиймээс РД, дансны дугаар хожим мэдэгдвэл шинэ бүртгэл
     * үүсгэхээс өөр арга байхгүй — тэгвэл акт, барьцаа хоёр хуваагдана.
     */
    public function update(Request $request, Contractor $contractor): JsonResponse
    {
        abort_unless(
            $request->user()?->canManageContractors(),
            403,
            'Танд гүйцэтгэгч засах эрх байхгүй.'
        );

        $contractor->update($this->fields($request, $contractor));

        return response()->json(['data' => new ContractorResource($contractor->refresh())]);
    }

    /**
     * Үүсгэх ба засах НЭГ валидацитай.
     *
     * Хоёр газар бичвэл нэг нь хоцорч, засах замаар РД-гүй утга орох
     * нүх үүснэ.
     *
     * @return array<string, mixed>
     */
    private function fields(Request $request, ?Contractor $existing = null): array
    {
        $validated = $request->validate([
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:255'],
            'companyName' => ['nullable', 'string', 'max:255'],

            /*
             * РД — 7 ОРОН, давхардахгүй.
             *
             * Нэрээр давхардлыг барих нь хүрэлцэхгүй: «Бетон И дезайн» ба
             * «Бетон И Дезайн» хоёр өөр мөр болдог (бодит хуваарьт яг тэр
             * байв). РД нь цорын гагц найдвартай таних тэмдэг.
             */
            'registrationNo' => [
                'nullable', 'string', 'digits:7',
                Rule::unique('contractors', 'registration_no')->ignore($existing?->id),
            ],
            'tradeSpecialty' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(array_keys(Contractor::CATEGORIES))],
            'status' => ['nullable', Rule::in(array_keys(Contractor::STATUSES))],
            'contactPerson' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'bankName' => ['nullable', 'string', 'max:100'],
            'bankAccount' => ['nullable', 'string', 'max:50'],
        ], [
            'registrationNo.digits' => 'Регистрийн дугаар 7 орон байх ёстой.',
            'registrationNo.unique' => 'Ийм регистрийн дугаартай гүйцэтгэгч аль хэдийн бүртгэгдсэн.',
        ]);

        $map = [
            'name' => 'name',
            'companyName' => 'company_name',
            'registrationNo' => 'registration_no',
            'tradeSpecialty' => 'trade_specialty',
            'category' => 'category',
            'status' => 'status',
            'contactPerson' => 'contact_person',
            'phone' => 'phone',
            'email' => 'email',
            'bankName' => 'bank_name',
            'bankAccount' => 'bank_account',
        ];

        $fields = [];

        /*
         * ЗӨВХӨН илгээсэн талбарыг хөндөнө.
         *
         * Бүх талбарыг `?? null`-аар бичвэл дэлгэц нэг талбар засахад
         * бусад нь ЦЭВЭРЛЭГДЭНЭ — яг тэр алдаа хэрэглэгчийн хуудсанд
         * нэг удаа гарсан.
         */
        foreach ($map as $input => $column) {
            if ($request->has($input)) {
                $fields[$column] = $validated[$input] ?? null;
            }
        }

        return $fields;
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
