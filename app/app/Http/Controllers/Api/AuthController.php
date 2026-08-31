<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Sessie-login voor de zaal-app. De app draait op dezelfde origin als de API,
 * dus een gewone sessiecookie volstaat; het zaaltoestel blijft ingelogd.
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! auth()->attempt($credentials, remember: true)) {
            throw ValidationException::withMessages([
                'email' => 'Deze combinatie van e-mailadres en wachtwoord is onbekend.',
            ]);
        }

        $request->session()->regenerate();

        return response()->json($this->currentUser());
    }

    public function logout(Request $request): JsonResponse
    {
        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['loggedOut' => true]);
    }

    /** @return array<string, mixed> */
    public function me(): array
    {
        return $this->currentUser();
    }

    /** @return array<string, mixed> */
    private function currentUser(): array
    {
        $user = auth()->user();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
