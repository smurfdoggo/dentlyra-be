<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AdminResource;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function login(LoginRequest $request): AdminResource
    {
        $admin = $this->authService->authenticate('admins', $request->safe()->only(['email', 'password']));

        return (new AdminResource($admin))->additional([
            'token' => $admin->createToken($request->validated('device_name', 'api'))->plainTextToken,
            'token_type' => 'Bearer',
        ]);
    }

    public function me(Request $request): AdminResource
    {
        return new AdminResource($request->user());
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
