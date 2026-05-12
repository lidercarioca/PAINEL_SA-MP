<?php

namespace App\Services;

use App\Models\Server;

/**
 * Serviço para enviar comandos reais ao FXServer
 * 
 * Suporta múltiplos métodos de comunicação:
 * A) txAdmin API HTTP (média prioridade)
 * B) STDIN do processo FXServer (alta prioridade)
 * C) RCON fallback (baixa prioridade)
 */
class FiveMCommandBridge
{
    private string $ip;
    private int $port;
    private ?int $txadminPort;
    private ?string $apiToken;
    private ?string $folder;
    private ?int $serverId;

    public function __construct(string $ip, int $port, ?string $apiToken = null, ?string $folder = null, ?int $serverId = null, ?int $txadminPort = null)
    {
        $this->ip = $ip;
        $this->port = $port;
        $this->txadminPort = $txadminPort;
        $this->apiToken = $apiToken;
        $this->folder = $folder;
        $this->serverId = $serverId;
    }

    /**
     * Enviar comando sanitizado para resource
     * 
     * @param string $command Comando a enviar (ex: "ensure my-resource")
     * @return array Resultado com sucesso, método usado, e mensagem
     */
    public function sendConsoleCommand(string $command): array
    {
        // Sanitizar comando
        $sanitized = $this->sanitizeCommand($command);
        if ($sanitized === null) {
            ActionLogService::append("FiveMCommandBridge: Comando rejeitado - inválido/perigoso: '{$command}'");
            return [
                'success' => false,
                'method' => 'none',
                'message' => 'Comando inválido ou perigoso',
                'command' => $command,
                'attempted_bridges' => [],
            ];
        }

        // Log detalhado no laravel.log
        \Log::info("FiveMCommandBridge: Iniciando envio de comando", [
            'serverId' => $this->serverId,
            'command' => $sanitized,
            'fxserver_port' => $this->port,
            'txadmin_port' => $this->txadminPort,
            'folder' => $this->folder,
            'ip' => $this->ip,
            'hasApiToken' => !empty($this->apiToken),
        ]);

        ActionLogService::append("FiveMCommandBridge: Tentando enviar comando '{$sanitized}' para {$this->ip}:{$this->port} (token: " . (!empty($this->apiToken) ? 'sim' : 'não') . ", folder: " . (!empty($this->folder) ? 'sim' : 'não') . ")");

        $attempted_bridges = [];
        $lastResult = null;
        $skipRconFallback = false;

        // Método A: Tentar via HTTP API txAdmin
        if ($this->apiToken) {
            ActionLogService::append("FiveMCommandBridge: Tentando HTTP API txAdmin");
            $result = $this->sendViaHttpApi($sanitized);
            $attempted_bridges[] = [
                'bridge' => 'txadmin',
                'success' => $result['success'],
                'reason' => $result['message'] ?? 'desconhecido',
                'details' => $result['details'] ?? null,
            ];
            if ($result['success']) {
                \Log::info("FiveMCommandBridge: txAdmin sucesso", [
                    'serverId' => $this->serverId,
                    'command' => $sanitized,
                    'fxserver_port' => $this->port,
                    'txadmin_port' => $this->txadminPort,
                    'txAdmin_url' => $result['endpoint'] ?? null,
                    'txAdmin_status' => 'success',
                    'txAdmin_body' => $result['response'] ?? null,
                ]);
                ActionLogService::append("FiveMCommandBridge: Sucesso via HTTP API - resposta: " . substr($result['response'] ?? '', 0, 200));
                return array_merge($result, ['attempted_bridges' => $attempted_bridges]);
            }
            \Log::info("FiveMCommandBridge: txAdmin falhou", [
                'serverId' => $this->serverId,
                'command' => $sanitized,
                'fxserver_port' => $this->port,
                'txadmin_port' => $this->txadminPort,
                'txAdmin_urls_attempted' => $result['details']['endpoints_attempted'] ?? [],
                'txAdmin_status' => 'failed',
                'txAdmin_error' => $result['details']['last_error'] ?? null,
                'txAdmin_exception' => $result['details']['exception'] ?? null,
                'txAdmin_status_code' => $result['details']['last_status'] ?? null,
            ]);
            $lastResult = $result;
            ActionLogService::append("FiveMCommandBridge: HTTP API falhou - " . ($result['message'] ?? 'desconhecido'));

            if (in_array($result['error_code'] ?? '', ['txadmin_unauthorized', 'txadmin_endpoint_not_found'], true)) {
                $skipRconFallback = true;
            }
        } else {
            $attempted_bridges[] = [
                'bridge' => 'txadmin',
                'success' => false,
                'reason' => 'Sem token txAdmin configurado',
            ];
            \Log::info("FiveMCommandBridge: txAdmin pulado - sem token", [
                'serverId' => $this->serverId,
                'command' => $sanitized,
            ]);
            ActionLogService::append("FiveMCommandBridge: Pulando HTTP API - sem token");
        }

        // Método B: Tentar via console local / STDIN do processo (Windows)
        if ($this->folder) {
            ActionLogService::append("FiveMCommandBridge: Tentando console local via STDIN");
            $result = $this->executeLocalConsoleCommand($sanitized);
            $attempted_bridges[] = [
                'bridge' => 'stdin',
                'success' => $result['success'],
                'reason' => $result['message'] ?? 'desconhecido',
                'details' => $result['details'] ?? null,
            ];
            if ($result['success']) {
                \Log::info("FiveMCommandBridge: STDIN sucesso", [
                    'serverId' => $this->serverId,
                    'command' => $sanitized,
                    'fxserver_pid' => $result['details']['pid_found'] ?? null,
                    'pipe_path' => $result['details']['pipe_path'] ?? null,
                    'pipe_exists' => $result['details']['pipe_exists'] ?? null,
                ]);
                ActionLogService::append("FiveMCommandBridge: Sucesso via console local");
                return array_merge($result, ['attempted_bridges' => $attempted_bridges]);
            }
            \Log::info("FiveMCommandBridge: STDIN falhou", [
                'serverId' => $this->serverId,
                'command' => $sanitized,
                'fxserver_pid' => $result['details']['pid_found'] ?? null,
                'pipe_path' => $result['details']['pipe_path'] ?? null,
                'pipe_exists' => $result['details']['pipe_exists'] ?? null,
                'stdin_error' => $result['details']['exception'] ?? null,
            ]);
            $lastResult = $result;
            ActionLogService::append("FiveMCommandBridge: Console local falhou - " . ($result['message'] ?? 'desconhecido'));
        } else {
            $attempted_bridges[] = [
                'bridge' => 'stdin',
                'success' => false,
                'reason' => 'Sem pasta do servidor configurada',
            ];
            \Log::info("FiveMCommandBridge: STDIN pulado - sem folder", [
                'serverId' => $this->serverId,
                'command' => $sanitized,
            ]);
            ActionLogService::append("FiveMCommandBridge: Pulando console local - sem folder");
        }

        // Método C: Tentar RCON fallback (se houver senha configurada)
        if (!empty($this->apiToken) && !$skipRconFallback) {
            ActionLogService::append("FiveMCommandBridge: Tentando RCON fallback");
            $result = $this->sendViaRcon($sanitized);
            $attempted_bridges[] = [
                'bridge' => 'rcon',
                'success' => $result['success'],
                'reason' => $result['message'] ?? 'desconhecido',
                'details' => $result['details'] ?? null,
            ];
            if ($result['success']) {
                \Log::info("FiveMCommandBridge: RCON sucesso", [
                    'serverId' => $this->serverId,
                    'command' => $sanitized,
                    'rcon_response' => $result['details']['raw_response'] ?? null,
                ]);
                ActionLogService::append("FiveMCommandBridge: Sucesso via RCON");
                return array_merge($result, ['attempted_bridges' => $attempted_bridges]);
            }
            \Log::info("FiveMCommandBridge: RCON falhou", [
                'serverId' => $this->serverId,
                'command' => $sanitized,
                'rcon_status' => $result['details']['rcon_status'] ?? null,
                'rcon_error' => $result['details']['rcon_error'] ?? null,
                'rcon_exception' => $result['details']['exception'] ?? null,
            ]);
            $lastResult = $result;
            ActionLogService::append("FiveMCommandBridge: RCON falhou - " . ($result['message'] ?? 'desconhecido'));
        } elseif (!empty($this->apiToken) && $skipRconFallback) {
            $attempted_bridges[] = [
                'bridge' => 'rcon',
                'success' => false,
                'reason' => 'Saltado devido a erro txAdmin anterior',
            ];
            \Log::info("FiveMCommandBridge: RCON pulado devido a erro txAdmin anterior", [
                'serverId' => $this->serverId,
                'command' => $sanitized,
            ]);
            ActionLogService::append("FiveMCommandBridge: Pulando RCON - erro txAdmin anterior");
        } else {
            $attempted_bridges[] = [
                'bridge' => 'rcon',
                'success' => false,
                'reason' => 'Sem senha/token RCON configurado',
            ];
            \Log::info("FiveMCommandBridge: RCON pulado - sem senha/token", [
                'serverId' => $this->serverId,
                'command' => $sanitized,
            ]);
            ActionLogService::append("FiveMCommandBridge: Pulando RCON - sem senha/token");
        }

        \Log::info("FiveMCommandBridge: Todos os bridges falharam", [
            'serverId' => $this->serverId,
            'command' => $sanitized,
            'attempted_bridges' => $attempted_bridges,
            'fallback_used' => 'none',
        ]);

        ActionLogService::append("FiveMCommandBridge: Todos os métodos falharam para '{$sanitized}'");

        return [
            'success' => false,
            'method' => $lastResult['method'] ?? 'none',
            'message' => $lastResult['message'] ?? 'Command bridge indisponível. Configure RCON ou txAdmin token.',
            'command' => $sanitized,
            'error_code' => $lastResult['error_code'] ?? 'bridge_unavailable',
            'attempted_bridges' => $attempted_bridges,
        ];
    }

    /**
     * Sanitizar e validar comando
     * 
     * Suporta:
     * - ensure resource_name
     * - start resource_name
     * - stop resource_name
     * - restart resource_name
     * 
     * @param string $command
     * @return string|null Comando sanitizado ou null se inválido
     */
    private function sanitizeCommand(string $command): ?string
    {
        $command = trim($command);

        // Regex para validar comandos de resource
        if (!preg_match('/^(ensure|start|stop|restart)\s+([a-zA-Z0-9_-]+)$/i', $command, $matches)) {
            ActionLogService::append("FiveMCommandBridge: Comando rejeitado por falhar validação regex: {$command}");
            return null;
        }

        $action = strtolower($matches[1]);
        $resourceName = $matches[2];

        // Validar nome do resource
        if (!$this->isValidResourceName($resourceName)) {
            ActionLogService::append("FiveMCommandBridge: Nome de resource rejeitado: {$resourceName}");
            return null;
        }

        // Retornar comando sanitizado
        return "{$action} {$resourceName}";
    }

    /**
     * Validar nome do resource
     * 
     * @param string $name
     * @return bool
     */
    private function isValidResourceName(string $name): bool
    {
        // Resource names podem conter: a-z, A-Z, 0-9, -, _
        // Máximo 100 caracteres
        return preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $name) === 1;
    }

    /**
     * Enviar comando via HTTP API (txAdmin)
     * 
     * @param string $command
     * @return array
     */
    private function sendViaHttpApi(string $command): array
    {
        try {
            $payload = [
                'command' => $command,
            ];

            $headers = [
                'Content-Type: application/json',
            ];
            if (!empty($this->apiToken)) {
                $headers[] = "Authorization: Bearer {$this->apiToken}";
                $headers[] = "X-TxAdmin-Token: {$this->apiToken}";
            }

            $txadminPorts = $this->txadminPort ? [$this->txadminPort] : [40120, 40121, $this->port];
            $hosts = [$this->ip];
            if ($this->folder && !$this->isLocalHost($this->ip)) {
                $hosts[] = '127.0.0.1';
            }

            $paths = [
                '/api/manage/server/command',
                '/api/manage/server/console',
                '/api/manage/server/console/command',
            ];

            $details = [
                'fxserver_port' => $this->port,
                'txadmin_ports_attempted' => $txadminPorts,
                'hosts_attempted' => $hosts,
                'paths_attempted' => $paths,
                'endpoints_attempted' => [],
                'endpoint_errors' => [],
            ];

            foreach ($txadminPorts as $txPort) {
                foreach ($hosts as $host) {
                    foreach ($paths as $path) {
                        $url = "http://{$host}:{$txPort}{$path}";
                        $details['endpoints_attempted'][] = $url;

                        $response = $this->performHttpPost($url, $headers, $payload);

                        $contentType = $this->parseContentType($response['headers']);
                        $bodyPrefix = substr($response['body'] ?? '', 0, 200);
                        $isHtmlResponse = $this->isHtmlResponse($response['body'] ?? '');

                        // Log detalhado
                        \Log::info("FiveMCommandBridge: Tentativa txAdmin", [
                            'endpoint' => $url,
                            'status' => $response['status_code'],
                            'content_type' => $contentType,
                            'body_prefix' => $bodyPrefix,
                            'is_html' => $isHtmlResponse,
                        ]);

                        $details['endpoint_errors'][$url] = [
                            'status_code' => $response['status_code'],
                            'content_type' => $contentType,
                            'error' => $response['error'],
                            'response' => $bodyPrefix,
                            'is_html' => $isHtmlResponse,
                        ];
                        $details['last_status'] = $response['status_code'];
                        $details['last_response'] = $bodyPrefix;
                        $details['last_error'] = $response['error'];
                        $details['last_content_type'] = $contentType;

                        if ($response['status_code'] >= 200 && $response['status_code'] < 300) {
                            // Verificar se é resposta válida de API
                            if ($isHtmlResponse) {
                                ActionLogService::append("FiveMCommandBridge: HTTP API txAdmin retornou HTML/web UI em {$url} - endpoint inválido ou não autenticado");
                                continue; // Tentar próximo endpoint
                            }

                            if (!$this->isJsonContentType($contentType)) {
                                ActionLogService::append("FiveMCommandBridge: HTTP API txAdmin retornou content-type não-JSON em {$url}: {$contentType}");
                                continue; // Tentar próximo endpoint
                            }

                            // Tentar parsear JSON e verificar se tem sucesso
                            $jsonResponse = json_decode($response['body'] ?? '', true);
                            if (json_last_error() !== JSON_ERROR_NONE || !isset($jsonResponse['success']) || $jsonResponse['success'] !== true) {
                                ActionLogService::append("FiveMCommandBridge: HTTP API txAdmin retornou JSON inválido ou sem sucesso em {$url}");
                                continue; // Tentar próximo endpoint
                            }

                            ActionLogService::append("FiveMCommandBridge: HTTP API txAdmin respondeu com sucesso em {$url}");
                            return [
                                'success' => true,
                                'method' => 'txadmin',
                                'message' => 'Comando enviado via txAdmin API',
                                'command' => $command,
                                'response' => $response['body'] ?? null,
                                'endpoint' => $url,
                                'details' => $details,
                            ];
                        }

                        ActionLogService::append("FiveMCommandBridge: HTTP API txAdmin falhou em {$url} com status {$response['status_code']} e erro " . ($response['error'] ?? 'nenhum'));
                    }
                }
            }

            $errorCode = 'txadmin_failed';
            $message = 'Command bridge indisponível. Configure RCON ou txAdmin token.';
            $lastStatus = $details['last_status'] ?? 0;
            if (in_array($lastStatus, [401, 403], true)) {
                $errorCode = 'txadmin_unauthorized';
                $message = 'Autenticação txAdmin inválida ou não autorizada.';
            } elseif ($lastStatus === 404) {
                $errorCode = 'txadmin_endpoint_not_found';
                $message = 'Endpoint txAdmin não encontrado.';
            } elseif ($lastStatus === 0) {
                $errorCode = 'txadmin_connection_error';
                $message = 'Não foi possível conectar ao txAdmin.';
            } elseif ($details['last_content_type'] === 'text/html' || $this->isHtmlResponse($details['last_response'] ?? '')) {
                $errorCode = 'txadmin_html_response';
                $message = 'txAdmin retornou HTML/web UI, endpoint de comando inválido ou não autenticado';
            }

            return [
                'success' => false,
                'method' => 'txadmin',
                'message' => $message,
                'command' => $command,
                'error_code' => $errorCode,
                'details' => $details,
            ];

        } catch (\Exception $e) {
            ActionLogService::append("FiveMCommandBridge: HTTP API exception: " . $e->getMessage());
            return [
                'success' => false,
                'method' => 'txadmin',
                'message' => 'Command bridge indisponível. Configure RCON ou txAdmin token.',
                'command' => $command,
                'error_code' => 'txadmin_failed',
                'details' => [
                    'fxserver_port' => $this->port,
                    'txadmin_ports_attempted' => $this->txadminPort ? [$this->txadminPort] : [40120, 40121, $this->port],
                    'exception' => $e->getMessage(),
                ],
            ];
        }
    }

    private function performHttpPost(string $url, array $headers, array $payload): array
    {
        $headerString = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerString,
                'content' => json_encode($payload),
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $statusCode = $this->parseHttpStatusCode($http_response_header ?? []);
        $body = $response === false ? null : $response;
        $error = null;

        if ($response === false || $statusCode >= 400) {
            $error = $this->getHttpErrorMessage($http_response_header ?? []);
            if ($statusCode > 0) {
                $error .= " (HTTP {$statusCode})";
            }
        }

        return [
            'status_code' => $statusCode,
            'body' => $body,
            'error' => $error,
            'headers' => $http_response_header ?? [],
        ];
    }

    private function parseHttpStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/i', $header, $matches)) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function getHttpErrorMessage(array $headers): string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'failed') !== false || stripos($header, 'error') !== false || stripos($header, 'unauthorized') !== false || stripos($header, 'forbidden') !== false) {
                return $header;
            }
        }

        return 'Resposta inesperada do txAdmin.';
    }

    private function parseContentType(array $headers): string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'content-type:') === 0) {
                $parts = explode(':', $header, 2);
                return trim(strtolower($parts[1] ?? ''));
            }
        }
        return '';
    }

    private function isJsonContentType(string $contentType): bool
    {
        return str_starts_with(trim($contentType), 'application/json');
    }

    private function isHtmlResponse(?string $body): bool
    {
        if (!$body) {
            return false;
        }

        $bodyLower = strtolower($body);

        return strpos($bodyLower, '<html') !== false ||
               strpos($bodyLower, '<!doctype html') !== false ||
               strpos($bodyLower, '<title>txadmin</title>') !== false ||
               strpos($bodyLower, 'window.txconsts') !== false;
    }

    /**
     * Enviar comando via STDIN do processo FXServer
     * 
     * Usa named pipes no Windows ou fallback para arquivo de comando local.
     * 
     * @param string $command
     * @return array
     */
    public function executeLocalConsoleCommand(string $command): array
    {
        return $this->sendViaStdin($command);
    }

    private function sendViaStdin(string $command): array
    {
        if (!$this->folder) {
            return [
                'success' => false,
                'method' => 'stdin',
                'message' => 'Pasta do servidor não configurada para comunicação local.',
                'command' => $command,
                'error_code' => 'stdin_no_folder',
                'details' => [
                    'folder' => $this->folder,
                ],
            ];
        }

        if (!$this->isWindows()) {
            return [
                'success' => false,
                'method' => 'stdin',
                'message' => 'Comunicação via STDIN local apenas suportada no Windows.',
                'command' => $command,
                'error_code' => 'stdin_unsupported_os',
                'details' => [
                    'os' => PHP_OS,
                ],
            ];
        }

        $pid = $this->findLocalFxServerProcessId($this->folder);
        $pipePath = $this->getLocalPipePath($pid);

        $details = [
            'folder' => $this->folder,
            'pid_found' => $pid,
            'pipe_path' => $pipePath,
        ];

        ActionLogService::append("FiveMCommandBridge: Tentando STDIN local via pipe {$pipePath}");

        try {
            $pipe = @fopen($pipePath, 'w');
            if ($pipe === false) {
                ActionLogService::append("FiveMCommandBridge: STDIN pipe não encontrado em {$pipePath}");
                $details['pipe_exists'] = false;
                return [
                    'success' => false,
                    'method' => 'stdin',
                    'message' => 'Command bridge local indisponível.',
                    'command' => $command,
                    'error_code' => 'stdin_unavailable',
                    'details' => $details,
                ];
            }

            $details['pipe_exists'] = true;
            fwrite($pipe, $command . "\n");
            fclose($pipe);

            ActionLogService::append("FiveMCommandBridge: Comando enviado via STDIN local: {$command}");
            return [
                'success' => true,
                'method' => 'stdin',
                'message' => 'Comando enviado via STDIN local',
                'command' => $command,
                'pipe' => $pipePath,
                'pid' => $pid,
                'details' => $details,
            ];
        } catch (\Exception $e) {
            ActionLogService::append("FiveMCommandBridge: STDIN exception: " . $e->getMessage());
            $details['exception'] = $e->getMessage();
            return [
                'success' => false,
                'method' => 'stdin',
                'message' => $e->getMessage(),
                'command' => $command,
                'error_code' => 'stdin_exception',
                'details' => $details,
            ];
        }
    }

    private function findLocalFxServerProcessId(string $folder): ?int
    {
        $output = [];
        @exec('wmic process where "name=\'FXServer.exe\'" get ProcessId,ExecutablePath,CommandLine /FORMAT:CSV', $output);

        $normalizedFolder = $this->normalizePath($folder);
        $candidates = [];
        $ignored = [];
        $allProcesses = [];

        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'ProcessId') !== false || stripos($line, 'CommandLine') !== false || stripos($line, 'ExecutablePath') !== false) {
                continue;
            }

            $parts = str_getcsv($line);
            if (count($parts) === 3) {
                $commandLine = $parts[0] ?? '';
                $executablePath = $parts[1] ?? '';
                $pid = (int) ($parts[2] ?? 0);
            } elseif (count($parts) >= 4) {
                $commandLine = $parts[1] ?? '';
                $executablePath = $parts[2] ?? '';
                $pid = (int) ($parts[3] ?? 0);
            } else {
                continue;
            }

            if ($pid <= 0) {
                continue;
            }

            $allProcesses[] = "PID={$pid}, ExecutablePath={$executablePath}, CommandLine={$commandLine}";

            if (stripos($commandLine, '-dumpserver') !== false || stripos($commandLine, '-parentpid') !== false) {
                $ignored[$pid] = 'dumpserver/parentpid';
                continue;
            }

            $hasTxAdmin = stripos($commandLine, 'txAdminServerMode') !== false;
            $hasExec = stripos($commandLine, '+exec') !== false;
            $hasConfig = stripos($commandLine, 'server.cfg') !== false;

            if (!($hasTxAdmin || $hasExec || $hasConfig)) {
                $ignored[$pid] = 'missing txAdminServerMode/+exec/server.cfg';
                continue;
            }

            $score = 0;
            $reasons = [];
            if ($hasTxAdmin) {
                $score += 100;
                $reasons[] = 'txAdminServerMode';
            }
            if ($hasExec) {
                $score += 80;
                $reasons[] = '+exec';
            }
            if ($hasConfig) {
                $score += 60;
                $reasons[] = 'server.cfg';
            }
            if (stripos($this->normalizePath($executablePath), $normalizedFolder) !== false) {
                $score += 40;
                $reasons[] = 'executable_in_folder';
            }
            if (!empty($this->port) && stripos($commandLine, (string) $this->port) !== false) {
                $score += 20;
                $reasons[] = 'port_match';
            }
            if (stripos($this->normalizePath($commandLine), $normalizedFolder) !== false) {
                $score += 20;
                $reasons[] = 'folder_in_commandline';
            }

            $candidates[] = [
                'pid' => $pid,
                'commandLine' => $commandLine,
                'executablePath' => $executablePath,
                'score' => $score,
                'reason' => implode(', ', $reasons),
            ];
        }

        ActionLogService::append("FiveMCommandBridge: process scan complete. All processes: " . implode(' | ', $allProcesses));
        if (empty($candidates)) {
            if (!empty($ignored)) {
                $ignoredLines = [];
                foreach ($ignored as $pid => $reason) {
                    $ignoredLines[] = "PID={$pid}: {$reason}";
                }
                ActionLogService::append("FiveMCommandBridge: ignored processes: " . implode(' | ', $ignoredLines));
            }
            ActionLogService::append("FiveMCommandBridge: Nenhum FXServer local encontrado para pasta {$folder} (porta {$this->port})");
            return null;
        }

        usort($candidates, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return $b['pid'] <=> $a['pid'];
            }
            return $b['score'] <=> $a['score'];
        });

        $best = $candidates[0];
        ActionLogService::append("FiveMCommandBridge: selected FXServer PID={$best['pid']} score={$best['score']} reason={$best['reason']}");
        return $best['pid'];
    }

    private function getLocalPipePath(?int $pid): string
    {
        if ($pid !== null) {
            return '\\\\.\\pipe\\FXServer_' . $pid;
        }

        return '\\\\.\\pipe\\FXServer_' . md5(strtolower($this->normalizePath($this->folder ?? $this->ip)));
    }

    private function normalizePath(string $path): string
    {
        return str_replace(['\\', '/'], '/', strtolower(trim($path, "\\/ ")));
    }

    private function isLocalHost(string $host): bool
    {
        $localHosts = ['127.0.0.1', '::1', 'localhost'];
        return in_array(strtolower($host), $localHosts, true);
    }

    private function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Enviar comando via RCON fallback
     * 
     * @param string $command
     * @return array
     */
    private function sendViaRcon(string $command): array
    {
        if (empty($this->apiToken)) {
            return [
                'success' => false,
                'method' => 'rcon',
                'message' => 'Falha ao conectar no RCON',
                'command' => $command,
                'auth_success' => false,
                'raw_response' => null,
                'error_code' => 'rcon_connection_failed',
                'details' => [
                    'rcon_password_set' => false,
                ],
            ];
        }

        try {
            $rconService = new SampRconService();
            $response = $rconService->sendCommandWithResponseDetailed(
                $this->ip,
                $this->port,
                $this->apiToken,
                $command,
                2
            );

            $details = [
                'rcon_ip' => $this->ip,
                'rcon_port' => $this->port,
                'rcon_password_set' => true,
                'rcon_status' => $response['status'] ?? null,
                'rcon_error' => $response['error'] ?? null,
            ];

            if ($response['status'] === 'connection_failed') {
                ActionLogService::append("FiveMCommandBridge: RCON connection failed for '{$command}' - " . ($response['error'] ?? 'unknown'));
                return [
                    'success' => false,
                    'method' => 'rcon',
                    'message' => 'Falha ao conectar no RCON',
                    'command' => $command,
                    'auth_success' => false,
                    'raw_response' => null,
                    'error_code' => 'rcon_connection_failed',
                    'details' => $details,
                ];
            }

            if ($response['status'] === 'ok') {
                $rawResponse = $response['raw_response'] ?? null;
                $details['raw_response'] = $rawResponse;

                // Não considerar sucesso se resposta for null/vazia
                if (empty($rawResponse)) {
                    ActionLogService::append("FiveMCommandBridge: RCON resposta vazia para '{$command}'");
                    return [
                        'success' => false,
                        'method' => 'rcon',
                        'message' => 'RCON resposta vazia - comando pode não ter sido executado',
                        'command' => $command,
                        'auth_success' => true,
                        'raw_response' => $rawResponse,
                        'error_code' => 'rcon_empty_response',
                        'details' => $details,
                    ];
                }

                ActionLogService::append("FiveMCommandBridge: RCON enviou comando para '{$command}' com resposta: " . $rawResponse);
                return [
                    'success' => true,
                    'method' => 'rcon',
                    'message' => 'Comando enviado via RCON',
                    'command' => $command,
                    'auth_success' => true,
                    'raw_response' => $rawResponse,
                    'details' => $details,
                ];
            }

            ActionLogService::append("FiveMCommandBridge: RCON status desconhecido para '{$command}' - status=" . ($response['status'] ?? 'unknown'));
            return [
                'success' => false,
                'method' => 'rcon',
                'message' => 'RCON status desconhecido',
                'command' => $command,
                'auth_success' => true,
                'raw_response' => $rawResponse ?? null,
                'error_code' => 'rcon_unknown_status',
                'details' => $details,
            ];
        } catch (\Exception $e) {
            ActionLogService::append("FiveMCommandBridge: RCON exception: " . $e->getMessage());
            return [
                'success' => false,
                'method' => 'rcon',
                'message' => 'Falha ao conectar no RCON',
                'command' => $command,
                'auth_success' => false,
                'raw_response' => null,
                'error_code' => 'rcon_connection_failed',
                'details' => [
                    'rcon_ip' => $this->ip,
                    'rcon_port' => $this->port,
                    'rcon_password_set' => true,
                    'exception' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Construtor estático a partir de um Server model
     * 
     * @param mixed $server Server model ou objeto com propriedades ip, port, password, folder
     * @return self
     */
    public static function fromServer($server): self
    {
        return new self(
            $server->ip ?? '127.0.0.1',
            $server->port ?? 30120,
            $server->password ?? null,
            $server->folder ?? null
        );
    }
}
