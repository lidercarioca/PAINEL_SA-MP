<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActionLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !$this->verifyPassword($data['password'], $user)) {
            ActionLogService::append("Tentativa de login falhou para {$data['email']}");
            return response()->json(['error' => 'Credenciais inválidas'], 401);
        }

        if ($user->blocked) {
            ActionLogService::append("Tentativa de login bloqueada para {$data['email']}");
            return response()->json(['error' => 'Usuário bloqueado'], 403);
        }

        if (empty($user->api_token)) {
            $user->api_token = bin2hex(random_bytes(16));
            $user->save();
        }

        ActionLogService::append("Login bem-sucedido para {$data['email']}");

        return response()->json([
            'token' => $user->api_token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    private function verifyPassword(string $password, User $user): bool
    {
        if (Hash::check($password, $user->password)) {
            if (Hash::needsRehash($user->password)) {
                $user->password = Hash::make($password);
                $user->save();
            }
            return true;
        }

        if (hash_equals($user->password, $password)) {
            $user->password = Hash::make($password);
            $user->save();
            return true;
        }

        return false;
    }

    public function logout(Request $request)
    {
        $token = str_replace('Bearer ', '', $request->header('Authorization', ''));
        if (!$token) {
            return response()->json(['message' => 'Nenhum token informado'], 400);
        }

        $user = User::where('api_token', $token)->first();
        if (!$user) {
            ActionLogService::append('Logout falhou: token inválido');
            return response()->json(['error' => 'Token inválido'], 401);
        }

        $user->api_token = null;
        $user->save();

        ActionLogService::append("Logout efetuado para {$user->email}");
        return response()->json(['message' => 'Logout efetuado']);
    }
}
