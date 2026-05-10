# 🎮 Arquitetura Multi-Engine - SAMP Painel

## Visão Geral

O painel agora suporta múltiplos game engines sem duplicação de código:
- **SA-MP** (San Andreas Multiplayer) - Engine padrão
- **FiveM** (GTA V Multiplayer) - Novo engine suportado

## Estrutura Técnica

### Backend (PHP/Laravel)

#### Engines Available

```
app/Engines/
├── EngineInterface.php    # Interface base para todos os engines
├── SampEngine.php         # Implementação SA-MP
├── FiveM Engine.php       # Implementação FiveM
└── EngineFactory.php      # Factory para criar engines
```

#### Como Criar um Engine

Herde de `EngineInterface` e implemente os métodos obrigatórios:

```php
<?php
namespace App\Engines;

class MyCustomEngine implements EngineInterface
{
    public function start(string $folder): bool { }
    public function stop(): bool { }
    public function restart(string $folder): bool { }
    public function getLogs(string $folder): string { }
    public function sendCommand(string $command): bool|string { }
    public function getStatus(string $folder): array { }
    public static function detect(string $executable, string $folder): bool { }
}
```

#### Usando a EngineFactory

```php
use App\Engines\EngineFactory;

// No controller ou serviço
$engineFactory = app(EngineFactory::class);
$engine = $engineFactory->create($server); // Cria instance do engine apropriado

// Ou force um engine específico
$engine = $engineFactory->createByEngine('samp', $server);

// Auto-detecção
$detectedEngine = $engineFactory->autoDetect($server);

// Obter defaults de um engine
$defaults = EngineFactory::getEngineDefaults('fivem');
// Retorna: ['port' => 30120, 'executable' => 'FXServer.exe', ...]
```

#### Auto-Detecção

Se um servidor não tem engine definido, o painel detecta automaticamente:
- **FXServer.exe** → FiveM
- **samp-server.exe** → SA-MP
- Também verifica estrutura de pastas (artifacts/, resources/)

### Database

Campo adicionado à tabela `servers`:

```sql
ALTER TABLE servers ADD COLUMN engine ENUM('samp', 'fivem') DEFAULT 'samp' AFTER type;
```

**Compatibilidade**: Todos os servidores existentes recebem `engine = 'samp'` automaticamente.

### Frontend (React)

#### Criando Servidor

1. **Seletor de Engine**: Escolha entre SA-MP ou FiveM
2. **Placeholders automáticos**:
   - SA-MP: Porta 7777, server.cfg
   - FiveM: Porta 30120, FiveM server.cfg
3. **Gamemode auto-atualizado**: "SA-MP" ou "FiveM"

#### Componentes Atualizados

- **ServerCard**: Badge com ícone do engine (🎮 SA-MP | 🚀 FiveM)
- **Console**: Placeholder dinâmico, validação RCON conforme engine
- **Dashboard**: Mostra engine de cada servidor

#### Validações Frontend

```javascript
// Console.jsx detecta FiveM e previne RCON
if (server.engine === 'fivem') {
  setCommandFeedback('⚠️ FiveM não suporta RCON SA-MP.');
  return;
}
```

## Specs Técnicas por Engine

### SA-MP
```
Port Padrão: 7777
Executável: samp-server.exe
Config: server.cfg
Logs: server_log.txt
RCON: Sim (UDP SAMP Protocol)
Interface: RCON Commands
```

### FiveM
```
Port Padrão: 30120
Executável: FXServer.exe
Config: server.cfg (FiveM format)
Logs: /logs/
RCON: Não (usa txAdmin/Console)
Interface: Console FXServer / HTTP API (opcional)
```

## Fluxo de Funcionamento

### 1. Criar Servidor
```
Frontend: Seleciona Engine
    ↓
Atualiza porta e gamemode automaticamente
    ↓
Backend: Recebe `engine` no payload
    ↓
Database: Salva com engine = 'samp' ou 'fivem'
```

### 2. Listar Servidores
```
Backend: SELECT * FROM servers
    ↓
Cada servidor traz seu `engine`
    ↓
Frontend: Renderiza com badge apropriado
```

### 3. Enviar Comando
```
Frontend: User digita comando no Console
    ↓
Valida: Se FiveM, mostra erro
    ↓
Senão (SA-MP): Envia RCON
    ↓
Backend: RconController valida engine
    ↓
Rejeita FiveM (sem RCON SA-MP)
    ↓
SA-MP: Executa comando RCON
```

## Compatibilidade Retroativa

✅ **Servidores existentes continuam funcionando**
- Engine não definido? Padrão = 'samp'
- Comportamento idêntico ao anterior
- Nenhum dado perdido

## Endpoints API

Todos os endpoints existentes continuam funcionando:

```
GET  /api/servers              → Lista com engine
POST /api/servers/create       → Cria com engine
POST /api/servers/update       → Atualiza engine
POST /api/servers/start        → Inicia (engine-aware)
POST /api/servers/stop         → Para (engine-aware)
POST /api/servers/restart      → Reinicia (engine-aware)
POST /api/rcon/send            → RCON (SA-MP only)
```

## Exemplos de Uso

### Criar Server SA-MP
```json
POST /api/servers/create
{
  "name": "Meu Server SA-MP",
  "ip": "127.0.0.1",
  "port": 7777,
  "engine": "samp",
  "folder": "C:/SA-MP-Server",
  "password": "rconpass"
}
```

### Criar Server FiveM
```json
POST /api/servers/create
{
  "name": "Meu Server FiveM",
  "ip": "127.0.0.1",
  "port": 30120,
  "engine": "fivem",
  "folder": "C:/FiveM-Server",
  "password": null
}
```

### Auto-detecção (sem especificar engine)
```json
POST /api/servers/create
{
  "name": "Server Auto-detect",
  "ip": "127.0.0.1",
  "port": 7777,
  // Sem campo "engine"
  "folder": "C:/SA-MP-Server"
}
```
Resultado: `engine = 'samp'` (detectado automaticamente)

## Extending com Novo Engine

Para adicionar um novo engine (ex: RedM):

1. **Criar classe**:
```php
namespace App\Engines;

class RedMEngine implements EngineInterface {
    // Implementar métodos obrigatórios
}
```

2. **Atualizar Factory**:
```php
private function createByEngine(string $engine, Server $server): EngineInterface {
    match ($engine) {
        'samp' => new SampEngine(...),
        'fivem' => new FiveM Engine(...),
        'redm' => new RedMEngine(...),  // Novo
        default => new SampEngine(...)
    };
}
```

3. **Adicionar defaults**:
```php
public static function getEngineDefaults(string $engine): array {
    return match ($engine) {
        // ...
        'redm' => [
            'port' => 30120,
            'executable' => 'server.exe',
            // ...
        ],
    };
}
```

4. **Atualizar Frontend**: Adicionar opção no seletor

## Segurança

- ✅ RCON SA-MP rejeita em FiveM (validation backend + frontend)
- ✅ Engine-aware error handling
- ✅ Logs incluem engine utilizado
- ✅ Auto-detecta para evitar configuração manual

## Migração do Banco

```bash
php artisan migrate
```

Todos os servidores existentes receberão `engine = 'samp'` automaticamente.

## Troubleshooting

### "FiveM não suporta RCON SA-MP"
Esperado! FiveM não implementa o protocolo RCON do SA-MP.
Use o console do FXServer ou txAdmin para enviar comandos.

### Engine não detectado
Se `engine` vazio na DB:
1. Migração pode não ter rodado
2. Servidor novo sem engine definido
3. Fallback automático para 'samp'

### Mudar engine de servidor existente
```php
$server->update(['engine' => 'fivem']);
```

## Status

- ✅ Implementação completa
- ✅ Compatibilidade retroativa
- ✅ UI/UX adaptada
- ✅ Auto-detecção funcional
- ⚙️ Testes recomendados

---

**Desenvolvido para**: SAMP Painel Multi-Engine
**Versão**: 1.0
**Data**: Maio 2026
