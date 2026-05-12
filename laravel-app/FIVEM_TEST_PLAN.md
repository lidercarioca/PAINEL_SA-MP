# FiveM Command Bridge - Próximos Passos de Teste

## Status Atual

✅ **Implementado**:
- [x] `FiveMCommandBridge` service com sanitização rigorosa
- [x] Suporte para HTTP API (txAdmin)
- [x] Suporte para STDIN (named pipes)
- [x] Integração com `FiveMResourceController`
- [x] Logging detalhado de comandos
- [x] Validação de segurança

❌ **Não Quebrado**:
- [x] SA-MP continua funcionando normalmente
- [x] Start/stop/restart principal não afetado
- [x] txAdmin não foi alterado
- [x] run.bat não foi alterado

## Teste Manual - Pré-requisitos

### Infraestrutura

1. **Servidor FiveM ativo**
   ```bash
   cd C:\FiveM\server
   .\FXServer.exe
   ```

2. **txAdmin rodando**
   - Acesso em: `http://127.0.0.1:40120` (ou porta configurada)
   - Console disponível para testes

3. **Banco de dados atualizado**
   - Server cadastrado com `engine = 'fivem'`
   - `password` field contém token do txAdmin (ou está vazio para STDIN)

## Teste 1: Validação de Sanitização

```bash
cd laravel-app
php artisan tinker

# Testar sanitização
$bridge = new App\Services\FiveMCommandBridge('127.0.0.1', 30120, 'token');

# Comando válido
$result = $bridge->sendConsoleCommand('ensure my-resource');
dd($result);

# Comando perigoso (deve falhar)
$result = $bridge->sendConsoleCommand('ensure resource; rm -rf /');
dd($result);  # Esperado: success = false
```

## Teste 2: HTTP API - Verificar Conexão

```bash
# Verificar se txAdmin responde
curl -X POST http://127.0.0.1:30120/api/manage/server/log \
  -H "Authorization: Bearer <token-do-txAdmin>" \
  -H "Content-Type: application/json" \
  -d '{"command": "help"}' \
  -v

# Resposta esperada: 200 OK ou aceita comando
```

## Teste 3: Frontend - Testar Ações

1. **Abrir Dashboard** → Gerenciar Resources
2. **Selecionar servidor FiveM**
3. **Botões devem estar habilitados**:
   - ✓ Ensure
   - ✓ Iniciar
   - ✓ Parar
   - ✓ Reiniciar

4. **Clicar em "Ensure" em um resource**
   - Esperado: Sucesso e reload de status
   - Ou: Erro com mensagem clara

## Teste 4: Casos de Uso Completos

### Use Case 1: Iniciar Resource Parado

```
1. Ver resource "meu-resource" em STOPPED
2. Clicar "Ensure"
3. Sistema envia: ensure meu-resource
4. Aguardar resposta
5. Resource muda para ONLINE
```

### Use Case 2: Reiniciar Resource

```
1. Ver resource "meu-resource" em ONLINE
2. Clicar "Restart"
3. Sistema envia: stop meu-resource
4. Aguardar 1 segundo
5. Sistema envia: ensure meu-resource
6. Resource volta ONLINE
```

### Use Case 3: Múltiplos Recursos

```
1. Ter 3 resources: A (online), B (stopped), C (online)
2. Ensure em B
3. Todos continuam funcionando
4. B muda de stopped para online
5. A e C não são afetados
```

## Teste 5: Segurança

### Testar Rejeições

```bash
# Todos devem ser rejeitados com mensagem de erro

# Case 1: Shell injection
POST /servers/1/fivem/resources/ensure
{"resource": "my-resource; rm -rf /"}
# Resultado: Rejeitado

# Case 2: Path traversal
POST /servers/1/fivem/resources/ensure
{"resource": "../../../etc/passwd"}
# Resultado: Rejeitado

# Case 3: Special characters
POST /servers/1/fivem/resources/ensure
{"resource": "my-resource@!#$%"}
# Resultado: Rejeitado

# Case 4: Comando inválido
POST /servers/1/fivem/resources/invalid-action
# Resultado: Rejeitado no controller
```

## Teste 6: Logging

Verificar que todos os comandos estão sendo registrados:

```bash
# Arquivo de logs
tail -f laravel-app/storage/logs/laravel.log

# Esperado:
# FiveMCommandBridge: Enviando comando 'ensure my-resource' para 127.0.0.1:30120
# FiveMCommandBridge: HTTP API respondeu com sucesso
# FiveM resources: Comando 'ensure my-resource' enviado com sucesso (método: http_api)
```

## Teste 7: Dois Servidores FiveM Diferentes

```
1. Adicionar Server A: IP 127.0.0.1:30120
2. Adicionar Server B: IP 127.0.0.1:40120
3. Ligar apenas Server A
4. Resource Manager de A: funciona
5. Resource Manager de B: mostra erro ao tentar ação
6. Ligar Server B
7. Ambos funcionam independentemente
8. Ensure em resource A não marca B como online
```

## Teste 8: Sem Token txAdmin

Se `server->password` está vazio:

```
1. Bridge tenta HTTP API (sem token)
2. Falha esperada
3. Bridge tenta STDIN
4. Se folder acessível: sucesso
5. Se folder não acessível: falha com mensagem
```

## Checklist de Validação

- [ ] Comando ensure: Resource inicia
- [ ] Comando start: Resource já em pausa inicia
- [ ] Comando stop: Resource para
- [ ] Comando restart: Resource para e inicia novamente
- [ ] Múltiplos servers: Não há confusão
- [ ] Múltiplos resources: Ações independentes
- [ ] Sanitização: Comandos perigosos rejeitados
- [ ] Logs: Todos os comandos registrados
- [ ] Frontend: Sem mensagens de "console não conectado"
- [ ] SA-MP: Continua funcionando
- [ ] Dashboard: Sem quebras

## Se Encontrar Problemas

1. **Verificar logs**:
   ```bash
   tail -f laravel-app/storage/logs/laravel.log | grep FiveM
   ```

2. **Verificar token txAdmin**:
   ```bash
   # Em txAdmin admin panel
   # Settings → Admin Management → Revelar tokens
   ```

3. **Verificar conectividade**:
   ```bash
   curl -v http://127.0.0.1:30120/
   ```

4. **Debug PHP**:
   ```php
   // Adicionar em FiveMCommandBridge ou controller
   error_log("DEBUG: " . json_encode($result));
   ```

## Próximas Melhorias (Future)

- [ ] Cache de status de resources
- [ ] Real-time WebSocket updates
- [ ] Batch operations
- [ ] Resource dependencies
- [ ] Auto-restart on crash
- [ ] Performance monitoring
