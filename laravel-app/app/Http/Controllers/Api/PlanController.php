<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\ActionLogService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    private function getAuthenticatedUser($request)
    {
        return $request->attributes->get('authenticated_user');
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $this->getAuthenticatedUser($request);
        if (!$user || ($user->role ?? null) !== 'admin') {
            abort(403, 'Acesso negado. Admin apenas.');
        }
    }

    public function index(Request $request)
    {
        $this->authorizeAdmin($request);
        return response()->json(Plan::all());
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => 'required|string',
            'slots' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $plan = Plan::create([
            'name' => $data['name'],
            'slots' => $data['slots'],
            'price' => $data['price'],
            'description' => $data['description'] ?? '',
        ]);

        ActionLogService::append("Plano {$plan->name} criado pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));

        return response()->json(['message' => 'Plano criado com sucesso', 'plan' => $plan]);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => 'sometimes|required|string',
            'slots' => 'sometimes|required|integer|min:1',
            'price' => 'sometimes|required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $plan = Plan::find($id);
        if (!$plan) {
            return response()->json(['error' => 'Plano não encontrado'], 404);
        }

        $plan->fill($data);
        $plan->save();

        ActionLogService::append("Plano {$plan->name} atualizado pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));

        return response()->json(['message' => 'Plano atualizado com sucesso']);
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizeAdmin($request);

        $plan = Plan::find($id);
        if (!$plan) {
            return response()->json(['error' => 'Plano não encontrado'], 404);
        }

        $planName = $plan->name;
        $plan->delete();

        ActionLogService::append("Plano {$planName} excluído pelo usuário " . ($this->getAuthenticatedUser($request)['email'] ?? 'desconhecido'));

        return response()->json(['message' => 'Plano removido com sucesso']);
    }
}
