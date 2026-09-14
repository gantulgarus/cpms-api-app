<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Sanctum token нэвтрэлт.
 *
 * Хоёр төрөл: ажилтны имэйл/нууц үг, туслан гүйцэтгэгчийн олгогдсон код.
 * Кодоор нэвтрэх нь захиалагчийн шаардлага — "ажил гүйцэтгэх хугацаанд
 * олгогдсон кодоор нэвтэрнэ".
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'deviceName' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'Имэйл эсвэл нууц үг буруу байна.',
            ]);
        }

        return $this->tokenResponse($user, $credentials['deviceName'] ?? 'web');
    }

    /**
     * Туслан гүйцэтгэгчийн төлөөлөгч кодоор нэвтэрнэ.
     *
     * Хугацаа нь дууссан код ажиллахгүй — гэрээ дуусахад хандалт өөрөө хаагдана.
     */
    public function contractorLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'deviceName' => ['nullable', 'string', 'max:100'],
        ]);

        $contractor = Contractor::where('access_code', $validated['code'])->first();

        if (! $contractor || ! $contractor->hasValidAccessCode()) {
            throw ValidationException::withMessages([
                'code' => 'Код буруу эсвэл хугацаа нь дууссан байна.',
            ]);
        }

        $user = User::firstOrCreate(
            ['contractor_id' => $contractor->id],
            [
                'name' => $contractor->name,
                'email' => 'contractor-'.$contractor->id.'@cpms.local',
                'password' => Hash::make(str()->random(40)),
                'role' => 'contractor',
            ],
        );

        return $this->tokenResponse($user, $validated['deviceName'] ?? 'mobile');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                // Үүргийн монгол нэр — дэлгэц шошгыг давхардуулж бичих
                // шаардлагагүй, нэг эх сурвалж (`User::ROLES`) байна.
                'roleLabel' => User::ROLES[$user->role] ?? $user->role,
                'contractorId' => $user->contractor_id,
                'scopeBlockIds' => $user->scope_block_ids ?? [],
                // Эрхийг СЕРВЕР шийднэ. Дэлгэц үүргийн жагсаалтыг давхардуулж
                // мэдэх шаардлагагүй — товч харуулах эсэхийг эндээс уншина.
                'canReportProgress' => $user->canReportProgress(),
                'canInspect' => $user->canInspect(),
                'canManageContractors' => $user->canManageContractors(),
                'canManageUsers' => $user->canManageUsers(),
                'canManageReferenceData' => $user->canManageReferenceData(),
                'canEditPlan' => $user->canEditPlan(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }

    private function tokenResponse(User $user, string $deviceName): JsonResponse
    {
        // Нэг төхөөрөмжид нэг token — дахин нэвтрэхэд хуучныг солино.
        $user->tokens()->where('name', $deviceName)->delete();

        return response()->json([
            'data' => [
                'token' => $user->createToken($deviceName, [$user->role])->plainTextToken,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role,
                // Үүргийн монгол нэр — дэлгэц шошгыг давхардуулж бичих
                // шаардлагагүй, нэг эх сурвалж (`User::ROLES`) байна.
                'roleLabel' => User::ROLES[$user->role] ?? $user->role,
                ],
            ],
        ]);
    }
}
