<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Services\ActionLogService;
use App\Services\SampRconService;
use Illuminate\Http\Request;

class RconController extends Controller
{
    private function findServer(int $serverId)
    {
        return Server::find($serverId);
    }

    private function getAuthenticatedUser($request)
    {
        return $request->attributes->get('authenticated_user');
    }

    private function authorizeAdminOrOwner($request, Server $server)
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user || (($user->role ?? null) !== 'admin' && $server->owner_id !== $user->id)) {
            abort(403, 'Acesso negado. Somente administrador ou proprietário do servidor.');
        }
    }

    public function send(Request $request, SampRconService $rconService)
    {
        $data = $request->validate([
            'server_id' => 'required|integer',
            'command' => 'required|string',
        ]);

        $server = $this->findServer($data['server_id']);
        if (!$server) {
            return response()->json(['error' => 'Servidor não encontrado'], 404);
        }

        $this->authorizeAdminOrOwner($request, $server);

        if (empty($server->password)) {
            return response()->json(['error' => 'Senha RCON não configurada'], 400);
        }

        $success = $rconService->sendCommand(
            $server->ip,
            $server->port,
            $server->password,
            $data['command']
        );

        $user = $this->getAuthenticatedUser($request);
        ActionLogService::append("Comando RCON para servidor {$server->name} ({$server->ip}:{$server->port}) enviado pelo usuário " . ($user['email'] ?? 'desconhecido') . ": {$data['command']}");

        return response()->json(['success' => $success]);
    }
}
