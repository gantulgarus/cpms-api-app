<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Хэрэглэгчийн бүртгэл.
 *
 * Яагаад чухал: систем нь "хэн мэдээлсэн, хэн баталсан"-д бүхэлдээ тулгуурладаг.
 * Бүгд нэг дансаар нэвтэрвэл тэр бүтэц утгагүй болно — маргаан гарахад
 * "инженер" гэсэн нэг нэр л үлдэнэ. Тиймээс хүн бүр өөрийн данстай байх ёстой,
 * админ түүнийг өөрөө үүсгэж чаддаг байх шаардлагатай.
 */
class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeManage($request);

        $query = User::query()
            ->with('contractor')
            ->when($request->query('role'), fn ($q, $v) => $q->where('role', $v))
            ->when($request->query('search'), fn ($q, $v) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")
            ))
            ->orderBy('name');

        // Идэвхгүй хүнийг анхдагчаар нуухгүй — "яагаад Болд нэвтэрч чадахгүй
        // байна вэ" гэдэг асуултын хариу жагсаалтад шууд харагдах ёстой.
        if ($request->query('active') === 'true') {
            $query->where('is_active', true);
        }

        return UserResource::collection($query->get());
    }

    /** Дэлгэцийн сонголтын эх сурвалж — үүргийн жагсаалтыг хатуу бичихгүй. */
    public function roles(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json([
            'data' => collect(User::ROLES)->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
                'canReportProgress' => in_array($value, User::REPORTER_ROLES, true),
                'canInspect' => in_array($value, User::INSPECTOR_ROLES, true),
                'seesAllBlocks' => in_array($value, User::UNRESTRICTED_ROLES, true),
            ])->values(),
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeManage($request);

        return response()->json(['data' => new UserResource($user->load('contractor'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate($this->rules());

        // Нууц үг өгөөгүй бол түр үг үүсгэнэ — админ утсаар дамжуулж, хүн
        // өөрөө солино. Хоосон нууц үгтэй данс үүсгэхийг зөвшөөрөхгүй.
        $password = $validated['password'] ?? Str::password(10, symbols: false);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($password),
            'role' => $validated['role'],
            'scope_block_ids' => $validated['scopeBlockIds'] ?? null,
            'contractor_id' => $validated['contractorId'] ?? null,
            'is_active' => $validated['isActive'] ?? true,
        ]);

        return response()->json([
            'data' => new UserResource($user),
            // Түр нууц үг ЗӨВХӨН энэ хариултад нэг удаа гарна.
            'meta' => ['temporaryPassword' => $validated['password'] ?? $password],
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate($this->rules($user));

        $this->guardSelfDemotion($request, $user, $validated['role'] ?? $user->role);

        $user->update(array_filter([
            'name' => $validated['name'] ?? null,
            'email' => $validated['email'] ?? null,
            'role' => $validated['role'] ?? null,
        ], fn ($v) => $v !== null) + [
            // `array_filter` эдгээрийг алгасах ёсгүй: хамрах хүрээг ХОOСОН
            // болгох, эсвэл хүнийг идэвхгүй болгох нь хүчинтэй үйлдэл.
            'scope_block_ids' => $validated['scopeBlockIds'] ?? $user->scope_block_ids,
            'contractor_id' => $validated['contractorId'] ?? $user->contractor_id,
            'is_active' => $validated['isActive'] ?? $user->is_active,
        ]);

        return response()->json(['data' => new UserResource($user->refresh())]);
    }

    /**
     * Нууц үг сэргээх.
     *
     * Админ хүний нууц үгийг ХАРАХГҮЙ — зөвхөн шинээр үүсгэж, нэг удаа
     * буцаана. Мөн бүх token-ыг цуцална: нууц үг мартсан гэдэг нь ихэвчлэн
     * утас алдсан гэсэн үг.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $this->authorizeManage($request);

        $request->validate(['password' => ['nullable', 'string', 'min:8']]);

        $password = $request->input('password') ?: Str::password(10, symbols: false);

        $user->update(['password' => Hash::make($password)]);
        $user->tokens()->delete();

        return response()->json(['data' => ['temporaryPassword' => $password]]);
    }

    /**
     * Идэвхгүй болгох — устгахгүй.
     *
     * Хүн ажлаас гарсан ч түүний мэдээлсэн явц, баталсан шалгалт баримт хэвээр
     * үлдэх ёстой. Данс устгавал тэр түүх эзэнгүй болно.
     */
    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->authorizeManage($request);

        abort_if(
            $request->user()->id === $user->id,
            422,
            'Өөрийгөө идэвхгүй болгох боломжгүй.'
        );

        abort_if(
            $user->role === 'admin' && User::where('role', 'admin')->where('is_active', true)->count() <= 1,
            422,
            'Сүүлийн админыг идэвхгүй болговол системд хэн ч орж чадахгүй болно.'
        );

        $user->update(['is_active' => false]);
        $user->tokens()->delete();

        return response()->json(['data' => new UserResource($user->refresh())]);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(?User $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'email' => [
                $required, 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($existing?->id),
            ],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => [$required, Rule::in(array_keys(User::ROLES))],
            'scopeBlockIds' => ['nullable', 'array'],
            'scopeBlockIds.*' => ['uuid', 'exists:blocks,id'],
            'contractorId' => ['nullable', 'uuid', 'exists:contractors,id'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(
            $request->user()?->canManageUsers(),
            403,
            'Танд хэрэглэгч удирдах эрх байхгүй.'
        );
    }

    /** Админ өөрийнхөө эрхийг санамсаргүй бууруулж, системээс түгжигдэхээс сэргийлнэ. */
    private function guardSelfDemotion(Request $request, User $user, string $newRole): void
    {
        if ($request->user()->id !== $user->id || $user->canManageUsers() === false) {
            return;
        }

        abort_if(
            ! in_array($newRole, User::USER_MANAGER_ROLES, true),
            422,
            'Өөрийн эрхээ бууруулах боломжгүй — өөр админ гүйцэтгэнэ.'
        );
    }
}
