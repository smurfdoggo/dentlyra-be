<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function register(RegisterRequest $request): UserResource
    {
        return DB::transaction(function () use ($request): UserResource {
            $user = User::query()->create($request->safe()->only(['name', 'email', 'password']));

            return (new UserResource($user))->additional([
                'token' => $user->createToken($request->validated('device_name', 'api'))->plainTextToken,
                'token_type' => 'Bearer',
            ]);
        });
    }

    public function login(LoginRequest $request): UserResource
    {
        $user = $this->authService->authenticate('users', $request->safe()->only(['email', 'password']));

        return (new UserResource($user))->additional([
            'token' => $user->createToken($request->validated('device_name', 'api'))->plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
