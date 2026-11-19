<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('api')->plainTextToken;

        $companies = $user->companyAccess()
            ->where('status', 'active')
            ->with(['company', 'role.permissions'])
            ->get()
            ->map(fn ($access) => [
                'id' => $access->company->id,
                'uuid' => $access->company->uuid,
                'code' => $access->company->code,
                'legal_name' => $access->company->legal_name,
                'role' => $access->role->code,
                'permissions' => $access->role->permissions->pluck('code'),
                'is_default' => $access->is_default,
            ]);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'companies' => $companies,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'companies' => $user->companyAccess()
                ->where('status', 'active')
                ->with('company')
                ->get()
                ->map(fn ($access) => [
                    'id' => $access->company->id,
                    'uuid' => $access->company->uuid,
                    'code' => $access->company->code,
                    'legal_name' => $access->company->legal_name,
                    'is_default' => $access->is_default,
                ]),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
