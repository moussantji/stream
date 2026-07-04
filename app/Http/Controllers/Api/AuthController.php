<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Register a new account and return an API token. */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create($data);

        return $this->tokenResponse($user, 'Account created.', 201);
    }

    /** Authenticate and return an API token. */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return $this->tokenResponse($user, 'Signed in.', 200, $request->input('device_name'));
    }

    /** Revoke the token used for the current request. */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    /** Return the authenticated user. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()]);
    }

    protected function tokenResponse(User $user, string $message, int $status, ?string $deviceName = null): JsonResponse
    {
        $token = $user->createToken($deviceName ?: 'web')->plainTextToken;

        return response()->json([
            'message' => $message,
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->only(['id', 'name', 'email', 'is_admin']),
        ], $status);
    }
}
