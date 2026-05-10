# ✅ Validation Checklist - Multi-Engine Architecture

Use este checklist para validar que a implementação foi completa e está funcionando.

## 🔧 Backend Validation

### Database Migration
- [ ] Arquivo existe: `database/migrations/2026_05_09_000001_add_engine_to_servers_table.php`
- [ ] Migration foi executada: `php artisan migrate`
- [ ] Coluna `engine` existe na tabela `servers`
- [ ] Coluna tem enum values: ['samp', 'fivem']
- [ ] Valor padrão é 'samp'
- [ ] Servidores existentes têm engine = 'samp'

### Engine Classes
- [ ] Arquivo existe: `app/Engines/EngineInterface.php`
  - [ ] Contém interface com 7 métodos
  - [ ] start, stop, restart, getLogs, sendCommand, getStatus, detect

- [ ] Arquivo existe: `app/Engines/SampEngine.php`
  - [ ] Implementa EngineInterface
  - [ ] Construtor recebe: ip, port, password
  - [ ] Usa SampRconService

- [ ] Arquivo existe: `app/Engines/FiveM Engine.php`
  - [ ] Implementa EngineInterface
  - [ ] Construtor recebe: ip, port, apiToken
  - [ ] Detecta pasta artifacts/ e resources/

- [ ] Arquivo existe: `app/Engines/EngineFactory.php`
  - [ ] Contém método create(Server)
  - [ ] Contém método autoDetect(Server)
  - [ ] Contém método getEngineDefaults(engine)
  - [ ] Contém método getAvailableEngines()

### Models
- [ ] Server.php atualizado
  - [ ] 'engine' adicionado ao $fillable array
  - [ ] Sem erros ao salvar: `$server->save()`

### Controllers
- [ ] RconController.php atualizado
  - [ ] Importa EngineFactory
  - [ ] Método send() valida engine
  - [ ] FiveM retorna erro apropriado
  - [ ] SA-MP continua funcionando

### Services
- [ ] MultiEngineExampleService.php criado
  - [ ] 12 exemplos práticos incluídos
  - [ ] Bem documentado

## 🎨 Frontend Validation

### CreateServer.jsx
- [ ] Campo engine adicionado ao estado
- [ ] handleEngineChange() implementado
- [ ] Seletor visual com botões SA-MP | FiveM
- [ ] Clique em botão atualiza porta
  - [ ] SA-MP → 7777
  - [ ] FiveM → 30120
- [ ] Clique em botão atualiza gamemode
  - [ ] SA-MP → 'SA-MP'
  - [ ] FiveM → 'FiveM'
- [ ] Carrega engine corretamente ao editar servidor

### ServerCard.jsx
- [ ] engineDisplay object definido
- [ ] engineInfo calculado corretamente
- [ ] Badge renderizado com:
  - [ ] Ícone apropriado (🎮 ou 🚀)
  - [ ] Nome do engine
  - [ ] Styling visual
- [ ] Badge posicionado corretamente (ao lado do status)

### Console.jsx
- [ ] engineInfo object definido
- [ ] Engine exibido no header
- [ ] Placeholder dinâmico conforme engine
- [ ] handleSendCommand() valida FiveM
- [ ] FiveM mostra aviso ao tentar RCON
- [ ] SA-MP permite enviar comando RCON

## 📄 Documentation Validation

- [ ] MULTI_ENGINE_README.md existe
  - [ ] Contém especificações de cada engine
  - [ ] Exemplos de uso
  - [ ] Guia de extensão

- [ ] IMPLEMENTATION_GUIDE.md existe
  - [ ] Passo-a-passo de implementação
  - [ ] Como usar em código
  - [ ] Como estender com novo engine

- [ ] QUICK_START.md existe
  - [ ] Quick start em 7 passos
  - [ ] Checklist de validação
  - [ ] FAQ

## 🧪 Testing Validation

- [ ] tests/Unit/Engines/EngineTest.php existe
- [ ] Contém 10+ testes
- [ ] Testes cobrem:
  - [ ] Factory creation (SAMP e FiveM)
  - [ ] Auto-detection
  - [ ] Defaults
  - [ ] Compatibilidade retroativa

## 🚀 Functional Testing

### SA-MP
- [ ] Criar servidor SA-MP
  - [ ] Nome: "Test SA-MP"
  - [ ] Engine: samp
  - [ ] Porta: 7777
  - [ ] Salvar servidor

- [ ] Verificar na lista
  - [ ] Badge mostra 🎮 SA-MP
  - [ ] Porta é 7777

- [ ] Abrir console
  - [ ] Engine mostrado como "SA-MP"
  - [ ] Placeholder RCON apropriado
  - [ ] Pode enviar comando RCON

### FiveM
- [ ] Criar servidor FiveM
  - [ ] Nome: "Test FiveM"
  - [ ] Engine: fivem
  - [ ] Porta: 30120
  - [ ] Salvar servidor

- [ ] Verificar na lista
  - [ ] Badge mostra 🚀 FiveM
  - [ ] Porta é 30120

- [ ] Abrir console
  - [ ] Engine mostrado como "FiveM"
  - [ ] Placeholder FiveM apropriado
  - [ ] Tenta RCON → mostra aviso

### Compatibilidade
- [ ] Servidor SA-MP antigo (sem engine)
  - [ ] Continua funcionando
  - [ ] Dashboard mostra corretamente
  - [ ] Console funciona

- [ ] Editar servidor
  - [ ] Engine atualiza corretamente
  - [ ] Dados não são perdidos

## 📊 Database Validation

```sql
-- Verificar estrutura
DESCRIBE servers;
-- Coluna "engine" deve estar presente

-- Verificar dados
SELECT id, name, engine, port FROM servers LIMIT 5;
-- Todos devem ter engine = 'samp' ou 'fivem'

-- Verificar padrão
INSERT INTO servers (name, ip, port, type) 
VALUES ('Test Auto', '127.0.0.1', 7777, 'local');
SELECT engine FROM servers WHERE name = 'Test Auto';
-- Deve retornar 'samp'
```

## 🔍 Code Quality Validation

- [ ] Sem erros de PHP syntax
  ```bash
  php -l app/Engines/EngineInterface.php
  php -l app/Engines/SampEngine.php
  php -l app/Engines/FiveM Engine.php
  php -l app/Engines/EngineFactory.php
  ```

- [ ] Sem erros de imports
  - [ ] EngineFactory importado em RconController
  - [ ] Server model carrega com engine

- [ ] Sem console warnings no navegador
  - [ ] F12 → Console
  - [ ] Nenhum erro de React

## 🎯 Performance Validation

- [ ] Listar servidores < 500ms
  - [ ] Mesmo com vários servidores
  - [ ] Sem lag ao selecionar engine

- [ ] Criar servidor < 1s
  - [ ] SA-MP criação rápida
  - [ ] FiveM criação rápida

- [ ] Console carrega em < 2s
  - [ ] Independente do engine

## 🔐 Security Validation

- [ ] RCON SA-MP só em SA-MP
  - [ ] FiveM rejeita com erro apropriado
  - [ ] Backend valida engine

- [ ] Sem exposição de dados sensíveis
  - [ ] Engine campo visível mas não crítico
  - [ ] Senha não viaja com engine

- [ ] Validação de input
  - [ ] Engine só aceita 'samp' ou 'fivem'
  - [ ] Campo obrigatório com fallback

## 📋 Rollback Validation

- [ ] Rollback migration funciona
  ```bash
  php artisan migrate:rollback
  ```
  
- [ ] Coluna `engine` é removida
- [ ] Servidores continuam existindo
- [ ] Re-migração funciona
  ```bash
  php artisan migrate
  ```

## 🎓 Code Review Validation

- [ ] Code follows Laravel conventions
  - [ ] PSR-12 standards
  - [ ] Type hints where possible

- [ ] React code quality
  - [ ] Props validation
  - [ ] No inline functions where avoidable
  - [ ] Clean JSX structure

- [ ] Documentation quality
  - [ ] Docstrings em métodos públicos
  - [ ] README completo
  - [ ] Exemplos funcionais

## ✨ Final Validation

- [ ] **All checkboxes above are checked**
- [ ] **No known issues**
- [ ] **Ready for production**

---

## 🎯 Sign-Off

- Validador: ___________________
- Data: ___________________
- Status: ✅ READY | ❌ NEEDS FIXES

### Issues Found (if any):
```
1. 
2. 
3. 
```

### Resolution:
```
1. 
2. 
3. 
```

---

**Estimated Validation Time**: 30-45 minutes
**Date**: Maio 2026
**Version**: 1.0
