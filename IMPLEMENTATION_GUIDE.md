# 📋 Guia de Implementação - Multi-Engine Architecture

## 🎯 Objetivo

Implementar suporte a múltiplos game engines (SA-MP, FiveM, etc.) no painel sem duplicação de código.

## ✅ Checklist de Implementação

### Phase 1: Backend Setup

- [x] Criar migration para adicionar campo `engine`
  - Arquivo: `database/migrations/2026_05_09_000001_add_engine_to_servers_table.php`
  - Campo: `engine ENUM('samp', 'fivem') DEFAULT 'samp'`
  - Comando: `php artisan migrate`

- [x] Criar EngineInterface (contrato)
  - Arquivo: `app/Engines/EngineInterface.php`
  - Métodos: start, stop, restart, getLogs, sendCommand, getStatus, detect

- [x] Implementar SampEngine
  - Arquivo: `app/Engines/SampEngine.php`
  - Herdança de EngineInterface
  - Usa SampRconService existente

- [x] Implementar FiveM Engine
  - Arquivo: `app/Engines/FiveM Engine.php`
  - Herdança de EngineInterface
  - Suporta detectar pasta artifacts/ e resources/

- [x] Criar EngineFactory
  - Arquivo: `app/Engines/EngineFactory.php`
  - Método: create(Server) - cria engine apropriado
  - Método: autoDetect(Server) - detecta engine automaticamente
  - Método: getEngineDefaults(engine) - obtém configurações padrão

- [x] Atualizar Model Server
  - Arquivo: `app/Models/Server.php`
  - Adicionar 'engine' ao $fillable array

- [x] Atualizar Controllers
  - Arquivo: `app/Http/Controllers/Api/RconController.php`
  - Injetar EngineFactory
  - Validar: FiveM rejeita RCON SA-MP
  - Validar: SA-MP aceita RCON

### Phase 2: Frontend Setup

- [x] Atualizar CreateServer.jsx
  - Adicionar campo engine ao estado
  - Criar seletor visual (botões SA-MP | FiveM)
  - Implementar handleEngineChange para atualizar porta/gamemode
  - Auto-atualizar quando carregar server para edição

- [x] Atualizar ServerCard.jsx
  - Adicionar engineDisplay object
  - Renderizar badge com ícone e nome
  - Posicionar ao lado do status badge

- [x] Atualizar Console.jsx
  - Exibir engine selecionado
  - Atualizar placeholder conforme engine
  - Validar: FiveM mostra aviso sobre RCON SA-MP
  - Implementar handleEngineChange para placeholders dinâmicos

### Phase 3: Testing & Documentation

- [x] Criar testes unitários
  - Arquivo: `tests/Unit/Engines/EngineTest.php`
  - Testar: Factory creation, auto-detect, defaults

- [x] Criar exemplos de uso
  - Arquivo: `app/Services/MultiEngineExampleService.php`
  - 12 exemplos práticos de uso

- [x] Documentação completa
  - Arquivo: `MULTI_ENGINE_README.md`
  - Arquivo: `IMPLEMENTATION_GUIDE.md` (este arquivo)

## 🚀 Como Usar

### 1. Executar Migration

```bash
cd laravel-app
php artisan migrate
```

Isso adiciona a coluna `engine` à tabela `servers` com valor padrão 'samp'.

### 2. Criar Servidor SA-MP

Via API:
```bash
curl -X POST http://localhost/api/servers/create \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Meu SA-MP Server",
    "ip": "127.0.0.1",
    "port": 7777,
    "engine": "samp",
    "folder": "C:/sa-mp-server",
    "password": "rconpass"
  }'
```

Via UI:
1. Clique em "Criar Servidor"
2. Preencha os dados
3. Clique em "🎮 SA-MP"
4. Porta muda para 7777 automaticamente

### 3. Criar Servidor FiveM

Via API:
```bash
curl -X POST http://localhost/api/servers/create \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Meu FiveM Server",
    "ip": "127.0.0.1",
    "port": 30120,
    "engine": "fivem",
    "folder": "C:/fivem-server"
  }'
```

Via UI:
1. Clique em "Criar Servidor"
2. Preencha os dados
3. Clique em "🚀 FiveM"
4. Porta muda para 30120 automaticamente

### 4. Usar em Seu Código

```php
<?php
use App\Engines\EngineFactory;
use App\Models\Server;

class MyService
{
    public function __construct(
        private EngineFactory $engineFactory
    ) {}

    public function restartServer(int $serverId)
    {
        $server = Server::find($serverId);
        $engine = $this->engineFactory->create($server);
        
        // Mesmo código funciona para SA-MP e FiveM!
        return $engine->restart($server->folder);
    }
}
```

## 📦 Estrutura de Arquivos

```
painel-samp/
├── database/
│   └── migrations/
│       └── 2026_05_09_000001_add_engine_to_servers_table.php
├── app/
│   ├── Engines/
│   │   ├── EngineInterface.php
│   │   ├── SampEngine.php
│   │   ├── FiveM Engine.php
│   │   └── EngineFactory.php
│   ├── Services/
│   │   └── MultiEngineExampleService.php
│   ├── Models/
│   │   └── Server.php (atualizado)
│   └── Http/
│       └── Controllers/
│           └── Api/
│               └── RconController.php (atualizado)
├── frontend/
│   └── src/
│       ├── pages/
│       │   ├── CreateServer.jsx (atualizado)
│       │   └── Console.jsx (atualizado)
│       └── components/
│           └── ServerCard.jsx (atualizado)
├── tests/
│   └── Unit/
│       └── Engines/
│           └── EngineTest.php
└── MULTI_ENGINE_README.md
```

## 🔧 Estendendo com Novo Engine

### Passo 1: Criar Nova Engine

```php
<?php
namespace App\Engines;

class RedMEngine implements EngineInterface
{
    private string $ip;
    private int $port;

    public function __construct(string $ip, int $port)
    {
        $this->ip = $ip;
        $this->port = $port;
    }

    public function start(string $folder): bool
    {
        // Implementar lógica de start para RedM
    }

    public function stop(): bool
    {
        // Implementar lógica de stop
    }

    // ... outros métodos

    public static function detect(string $executable, string $folder): bool
    {
        return stripos($executable, 'redm-server.exe') !== false;
    }
}
```

### Passo 2: Atualizar Migration

```php
$table->enum('engine', ['samp', 'fivem', 'redm'])->default('samp');
```

### Passo 3: Atualizar EngineFactory

```php
private function createByEngine(string $engine, Server $server): EngineInterface
{
    return match ($engine) {
        'samp' => new SampEngine(...),
        'fivem' => new FiveM Engine(...),
        'redm' => new RedMEngine(...),  // Novo
        default => new SampEngine(...)
    };
}

public static function getEngineDefaults(string $engine): array
{
    return match ($engine) {
        // ...
        'redm' => [
            'port' => 30120,
            'executable' => 'redm-server.exe',
            'config_file' => 'server.cfg',
            'logs_dir' => '/logs/',
        ],
    };
}

public static function getAvailableEngines(): array
{
    return [
        // ...
        [
            'id' => 'redm',
            'name' => 'RedM',
            'description' => 'RedM - Red Dead Redemption 2',
            'icon' => 'gun'
        ]
    ];
}
```

### Passo 4: Atualizar Frontend

```javascript
// CreateServer.jsx
<button
  type="button"
  onClick={() => handleEngineChange('redm')}
  // ... styles
>
  🔫 RedM
</button>

// EngineFactory.getEngineDefaults
redm: { port: 30120, gamemode: 'RedM' }
```

## ✨ Vantagens da Arquitetura

✅ **Zero Duplicação**: Mesmo código para qualquer engine
✅ **Type Safe**: Interface garante implementação correta
✅ **Extensível**: Adicionar novo engine em minutos
✅ **Testável**: Cada engine é testável isoladamente
✅ **Maintável**: Mudanças centralizadas na Factory
✅ **Retrocompatível**: Servidores antigos continuam funcionando

## 🐛 Troubleshooting

### Erro: Class not found - EngineFactory

Solução:
```php
composer dump-autoload
```

### Migration não encontrada

Solução:
```bash
php artisan migrate:reset
php artisan migrate
```

### Engine não detecta automaticamente

Verificar:
1. Arquivo executável existe na pasta?
2. Seu nome corresponde aos padrões (samp-server.exe, FXServer.exe)?
3. Se FiveM, tem pasta artifacts/ ou resources/?

### FiveM rejeita RCON

Esperado! FiveM não implementa RCON SA-MP.
Use o console do FXServer ou txAdmin.

## 📊 Diagnóstico

Para verificar status dos engines:

```php
$factory = app(EngineFactory::class);

// Listar disponíveis
$engines = $factory->getAvailableEngines();

// Obter defaults
$defaults = $factory->getEngineDefaults('samp');

// Auto-detectar
$detected = $factory->autoDetect($server);
```

## 📞 Suporte

Para dúvidas ou problemas:
1. Consulte MULTI_ENGINE_README.md
2. Veja exemplos em MultiEngineExampleService.php
3. Execute testes em tests/Unit/Engines/EngineTest.php

## 🎓 Conceitos-Chave

### Design Pattern: Strategy
Cada engine é uma estratégia diferente com mesma interface.

### Design Pattern: Factory
EngineFactory cria a estratégia correta automaticamente.

### SOLID Principles
- **S**ingle Responsibility: Cada engine tem uma responsabilidade
- **O**pen/Closed: Aberto para extensão, fechado para modificação
- **L**iskov Substitution: Engines intercambiáveis
- **I**nterface Segregation: Interface mínima e coesa
- **D**ependency Inversion: Depende de abstração, não de concreto

---

**Versão**: 1.0
**Data**: Maio 2026
**Status**: ✅ Completo e testado
