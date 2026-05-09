# Laravel SAMP Panel Starter

Este diretório contém um esqueleto de backend Laravel para gerenciar servidores SAMP.

## Passos iniciais

1. Instale o Laravel e as dependências do backend:
   - `composer install`
   - `composer require laravel/framework laravel/sanctum symfony/process phpseclib/phpseclib`
2. Copie ou renomeie `.env.example` para `.env` se necessário. No Linux, o SQLite usa o caminho relativo `database/database.sqlite`.
3. Gere a chave do aplicativo:
   - `php artisan key:generate`
4. Instale o frontend React:
   - `cd frontend`
   - `npm install`
5. Inicie o backend e o frontend:
   - `php artisan serve`
   - `npm start`

## Login padrão

- Email: `admin@example.com`
- Senha: `admin123`

## Estrutura

- `app/Services/SampRconService.php` — envia pacotes RCON UDP para servidores SAMP.
- `app/Services/RemoteCommandService.php` — executa comandos localmente ou via SSH.
- `app/Http/Controllers/Api/ServerController.php` — rotas de gerenciamento de servidor.
- `app/Http/Controllers/Api/RconController.php` — rota de envio de comando RCON.
- `routes/api.php` — rotas de API protegidas por Sanctum.

## Frontend
Veja os exemplos React em `frontend/src`.
