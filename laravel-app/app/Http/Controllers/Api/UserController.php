<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActionLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    private function getAuthenticatedUser($request)
    {
        return $request->attributes->get('authenticated_user');
    }

    private function authorizeAdmin($request)
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user || ($user->role ?? null) !== 'admin') {
            abort(403, 'Acesso negado. Admin apenas.');
        }
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);

        return response()->json(User::all());
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'role' => 'required|in:admin,client',
        ]);

        $newUser = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'blocked' => false,
        ]);

        ActionLogService::append("Usuário {$newUser->email} criado pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));

        return response()->json(['message' => 'Usuário criado com sucesso', 'user' => $newUser]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'Usuário não encontrado'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string',
            'email' => 'sometimes|required|email|unique:users,email,' . $id,
            'role' => 'sometimes|required|in:admin,client',
        ]);

        $user->fill($data);
        $user->save();

        ActionLogService::append("Usuário {$user->email} atualizado pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));

        return response()->json(['message' => 'Usuário atualizado com sucesso']);
    }

    public function resetPassword(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'Usuário não encontrado'], 404);
        }

        $data = $request->validate([
            'password' => 'required|string|min:6',
        ]);

        $user->password = Hash::make($data['password']);
        $user->save();

        ActionLogService::append("Senha do usuário {$user->email} redefinida pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));

        return response()->json(['message' => 'Senha redefinida com sucesso']);
    }

    public function toggleBlock(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'Usuário não encontrado'], 404);
        }

        $user->blocked = !$user->blocked;
        $user->save();

        ActionLogService::append("Usuário {$user->email} " . ($user->blocked ? 'bloqueado' : 'desbloqueado') . " pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));

        return response()->json(['message' => $user->blocked ? 'Usuário bloqueado' : 'Usuário desbloqueado', 'blocked' => $user->blocked]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'Usuário não encontrado'], 404);
        }

        $user->delete();

        ActionLogService::append("Usuário {$user->email} excluído pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));

        return response()->json(['message' => 'Usuário removido com sucesso']);
    }

    public function activity(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $user = User::find($id);
        if (!$user) {
            return response()->json(['error' => 'Usuário não encontrado'], 404);
        }

        $logPath = dirname(__DIR__, 2) . '/database/server_log.txt';
        $lines = [];
        if (file_exists($logPath)) {
            $lines = array_filter(
                array_map('trim', file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []),
                fn ($line) => str_contains($line, $user->email)
            );
        }

        return response()->json(['user' => $user, 'activity' => array_values($lines)]);
    }
}
