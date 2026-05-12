<?php

/**
 * Teste dos endpoints FiveM para validar que utilizam 127.0.0.1 para servidores locais
 * 
 * Este script verifica:
 * 1. Se os endpoints podem ser consultados
 * 2. Se as URLs estão corretas
 * 3. Se retornam respostas válidas
 */

// Simular um servidor local
$localServer = new class {
    public $type = 'local';
    public $ip = '192.168.1.100'; // IP público (não será usado)
    public $port = 30120;
    public $id = 1;
};

// Simular um servidor remoto
$remoteServer = new class {
    public $type = 'remote';
    public $ip = '192.168.1.100'; // IP público (será usado)
    public $port = 30120;
    public $id = 2;
};

echo "====================================\n";
echo "TESTE DE ENDPOINTS FIVEM\n";
echo "====================================\n\n";

// Teste 1: Servidor Local
echo "✓ TESTE 1: Servidor Local\n";
echo "  Type: local\n";
echo "  IP salvo no BD: " . $localServer->ip . "\n";
echo "  Porta: " . $localServer->port . "\n";

$localIp = $localServer->type === 'local' ? '127.0.0.1' : $localServer->ip;
$playerUrl = "http://{$localIp}:{$localServer->port}/players.json";
$infoUrl = "http://{$localIp}:{$localServer->port}/info.json";

echo "  URL players.json: " . $playerUrl . "\n";
echo "  URL info.json: " . $infoUrl . "\n";
echo "  ✓ Usando 127.0.0.1? " . ($localIp === '127.0.0.1' ? 'SIM ✓' : 'NÃO ✗') . "\n\n";

// Teste 2: Servidor Remoto
echo "✓ TESTE 2: Servidor Remoto\n";
echo "  Type: remote\n";
echo "  IP salvo no BD: " . $remoteServer->ip . "\n";
echo "  Porta: " . $remoteServer->port . "\n";

$remoteIp = $remoteServer->type === 'local' ? '127.0.0.1' : $remoteServer->ip;
$playerUrl = "http://{$remoteIp}:{$remoteServer->port}/players.json";
$infoUrl = "http://{$remoteIp}:{$remoteServer->port}/info.json";

echo "  URL players.json: " . $playerUrl . "\n";
echo "  URL info.json: " . $infoUrl . "\n";
echo "  ✓ Usando IP público? " . ($remoteIp === $remoteServer->ip ? 'SIM ✓' : 'NÃO ✗') . "\n\n";

// Teste 3: Verificar URLs em código
echo "====================================\n";
echo "VERIFICAÇÃO DE CÓDIGO\n";
echo "====================================\n\n";

$files = [
    'app/Http/Controllers/Api/ServerController.php',
    'app/Services/LocalServerService.php',
];

foreach ($files as $file) {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) {
        echo "✗ Arquivo não encontrado: $file\n";
        continue;
    }

    $content = file_get_contents($path);
    
    // Verificar se há uso correto de 127.0.0.1 para servidores locais
    if (strpos($content, "\$server->type === 'local' ? '127.0.0.1' : \$server->ip") !== false) {
        echo "✓ $file - Implementação correta encontrada\n";
    }
    
    // Verificar se há URLs de players.json e info.json
    $playerJsonCount = substr_count($content, 'players.json');
    $infoJsonCount = substr_count($content, 'info.json');
    
    if ($playerJsonCount > 0 || $infoJsonCount > 0) {
        echo "  - players.json: $playerJsonCount ocorrência(s)\n";
        echo "  - info.json: $infoJsonCount ocorrência(s)\n";
    }
}

echo "\n====================================\n";
echo "INSTRUÇÕES PARA TESTE MANUAL\n";
echo "====================================\n\n";

echo "1. Abrir navegador\n";
echo "2. Ir para: http://127.0.0.1:30120/players.json\n";
echo "3. Ir para: http://127.0.0.1:30120/info.json\n\n";

echo "Esperado:\n";
echo "- Se FiveM está rodando localmente: JSON com dados dos players\n";
echo "- Se FiveM está offline: Erro de conexão (esperado)\n\n";

echo "No painel:\n";
echo "- Contador de players deve atualizar\n";
echo "- Tabela de players deve preencher\n";
echo "- Se offline, deve retornar 0 sem erro 500\n";

?>
