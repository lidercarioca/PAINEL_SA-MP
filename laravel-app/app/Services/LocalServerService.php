<?php

namespace App\Services;

use App\Models\Server;
use App\Services\ActionLogService;

class LocalServerService
{
    public function start(Server $server): array
    {
        $rootPath = $this->resolveFolder($server->folder);

        if (!$rootPath || !is_dir($rootPath)) {
            return [
                'success' => false,
                'message' => 'Pasta do servidor inválida ou inexistente.'
            ];
        }

        $engine = strtolower($server->engine ?? 'samp');
        $useRunBat = false;
        $runBatPath = null;

        ActionLogService::append("Start debug: engine={$engine}, rootPath={$rootPath}");

        if ($engine === 'fivem') {
            $runBatPath = $this->locateRunBatForFiveM($rootPath);
            if ($runBatPath) {
                $exe = basename($runBatPath);
                $useRunBat = true;
                $configFile = null;
                ActionLogService::append("Start debug: runBatPath={$runBatPath}, file_exists=" . (file_exists($runBatPath) ? '1' : '0'));
            } else {
                // FiveM sem run.bat não deve iniciar
                ActionLogService::append("Start debug: run.bat não encontrado para FiveM, abortando");
                return [
                    'success' => false,
                    'message' => 'run.bat não encontrado na pasta do servidor FiveM.'
                ];
            }
        } else {
            $exe = $this->resolveSampExecutable($rootPath);
            $configFile = $this->resolveSampConfig($rootPath);
        }

        if (!$exe) {
            $message = $engine === 'fivem'
                ? 'Executável do servidor não encontrado.'
                : 'samp-server.exe não encontrado na pasta cadastrada.';

            ActionLogService::append("Start debug: engine={$engine}, rootPath={$rootPath}, executableFound=0, configFound=" . ($configFile ? '1' : '0') . ", useRunBat=" . ($useRunBat ? '1' : '0'));

            return [
                'success' => false,
                'message' => $message,
            ];
        }

        if (!$configFile && !$useRunBat) {
            ActionLogService::append("Start debug: engine={$engine}, rootPath={$rootPath}, executableFound=1, configFound=0, useRunBat=" . ($useRunBat ? '1' : '0'));
            return [
                'success' => false,
                'message' => $engine === 'fivem'
                    ? 'Arquivo de configuração do FiveM não encontrado na pasta cadastrada.'
                    : 'server.cfg não encontrado na pasta cadastrada.',
            ];
        }

        $executablePath = $rootPath . DIRECTORY_SEPARATOR . $exe;
        $configPath = $configFile ? $rootPath . DIRECTORY_SEPARATOR . $configFile : null;

        if ($configPath && !file_exists($configPath)) {
            return [
                'success' => false,
                'message' => 'Arquivo ' . $configFile . ' não encontrado.',
            ];
        }

        if ($engine !== 'fivem' && $configPath && !$this->validateServerConfig($configPath)) {
            return [
                'success' => false,
                'message' => $configFile . ' inválido.',
            ];
        }

        if ($engine !== 'fivem' && $configPath) {
            if (!$this->syncServerConfig($server, $configPath)) {
                return [
                    'success' => false,
                    'message' => 'Falha ao aplicar limites ao ' . $configFile . '.',
                ];
            }
        }

        if (!empty($server->port) && $this->isPortInUse((int) $server->port)) {
            return [
                'success' => false,
                'message' => 'Porta já em uso. Verifique se outro processo está ocupando a porta do servidor.',
            ];
        }

        try {
            if ($engine === 'fivem') {
                $existingMainPid = $this->findFiveMMainProcess($rootPath, false);
                $existingHttp = $this->isFivemHttpResponsive($server);

                if ($existingMainPid || $existingHttp) {
                    return [
                        'success' => true,
                        'status' => 'online',
                        'message' => 'Servidor já estava em execução.',
                        'output' => null,
                        'debug' => 'existing_pid=' . ($existingMainPid ?: 'none') . ', http=' . ($existingHttp ? 'ok' : 'failed'),
                    ];
                }
            } else {
                $existingPids = $this->findProcessIds($rootPath, $exe);
                if (!empty($existingPids)) {
                    return [
                        'success' => true,
                        'status' => 'online',
                        'message' => 'Servidor já estava em execução.',
                        'output' => null,
                    ];
                }
            }

            $cmd = $this->buildStartCommandByEngine($server, $rootPath, $exe, $configFile, $runBatPath);
            ActionLogService::append("Start resolver: engine={$engine}, rootPath={$rootPath}, executablePath={$executablePath}, configPath={$configPath}, workingDirectory={$rootPath}, useRunBat=" . ($useRunBat ? '1' : '0') . ", command={$cmd}");

            pclose(popen($cmd, "r"));
            $startDebug = null;
            $startError = null;
            $started = $this->waitForServerStart($server, false, $startDebug, $startError);

            if (!$started) {
                $portStatus = (!empty($server->port) && $this->isPortInUse((int) $server->port)) ? 'port_in_use' : 'port_free';
                ActionLogService::append("Start failed: command={$cmd}, port={$server->port}, portStatus={$portStatus}");
                return [
                    'success' => false,
                    'message' => $startError ?? 'Falha ao iniciar o servidor. Verifique os detalhes de debug.',
                    'debug' => trim(($startDebug ?? '') . " | port_status={$portStatus}"),
                    'output' => $cmd
                ];
            }

            return [
                'success' => true,
                'message' => 'Servidor iniciado com sucesso.',
                'output' => $cmd
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    protected function captureCommandOutput(string $cmd, int $timeoutMs = 1000): string
    {
        $output = '';
        $handle = @popen($cmd, 'r');
        if ($handle === false || !is_resource($handle)) {
            return '';
        }

        stream_set_blocking($handle, false);
        $startTime = microtime(true);

        while (!feof($handle) && (microtime(true) - $startTime) * 1000 < $timeoutMs) {
            $line = fgets($handle);
            if ($line !== false) {
                $output .= $line;
                if (!empty(trim($output))) {
                    break;
                }
            }
            usleep(50000);
        }

        pclose($handle);
        return trim($output);
    }

    protected function parseWmicProcessCsvLine(string $line): ?array
    {
        $parts = str_getcsv($line);
        if (count($parts) < 3) {
            return null;
        }

        $parts = array_map('trim', $parts);
        $count = count($parts);

        // WMIC CSV may output [Node,CommandLine,ExecutablePath,ProcessId]
        // or [CommandLine,ExecutablePath,ProcessId]
        if ($count >= 4) {
            return [
                'commandLine' => $parts[$count - 3] ?? '',
                'executablePath' => $parts[$count - 2] ?? '',
                'pid' => $parts[$count - 1] ?? '',
            ];
        }

        if ($count === 3) {
            return [
                'commandLine' => $parts[0] ?? '',
                'executablePath' => $parts[1] ?? '',
                'pid' => $parts[2] ?? '',
            ];
        }

        return null;
    }

    protected function getKnownExecutables(): array
    {
        return [
            'samp-server.exe',
            'samp03svr.exe',
            'sampsvr.exe',
            'server.exe',
            'samp-server',
            'samp03svr',
            'sampsvr',
            'server',
        ];
    }

    protected function resolveSampExecutable(string $rootPath): ?string
    {
        $candidates = [
            'samp-server.exe',
            'samp03svr.exe',
            'sampsvr.exe',
            'server.exe',
            'samp-server',
            'samp03svr',
            'sampsvr',
            'server',
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($rootPath . DIRECTORY_SEPARATOR . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function resolveSampConfig(string $rootPath): ?string
    {
        return file_exists($rootPath . DIRECTORY_SEPARATOR . 'server.cfg') ? 'server.cfg' : null;
    }

    protected function locateRunBatForFiveM(string $folder): ?string
    {
        $candidates = ['run.bat', 'run.cmd'];
        foreach ($candidates as $candidate) {
            $path = rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
            if (file_exists($path)) {
                return $path;
            }
        }
        return null;
    }

    protected function resolveServerConfig(string $rootPath, string $engine): ?string
    {
        if (strtolower($engine) === 'fivem') {
            return $this->resolveFiveMConfig($rootPath);
        }

        return $this->resolveSampConfig($rootPath);
    }

    protected function isWindows(): bool
    {
        return strncasecmp(PHP_OS_FAMILY, 'Windows', 7) === 0;
    }

    protected function isLinux(): bool
    {
        return strncasecmp(PHP_OS_FAMILY, 'Linux', 5) === 0;
    }

    protected function isProcessRunning(string $exe): bool
    {
        if ($this->isWindows()) {
            $output = [];
            $status = 0;
            exec('tasklist /FI "IMAGENAME eq ' . $exe . '" /NH', $output, $status);

            foreach ($output as $line) {
                if (stripos($line, $exe) !== false) {
                    return true;
                }
            }

            return false;
        }

        $basename = basename($exe);
        $output = [];
        exec('ps -eo pid=,args= 2>/dev/null', $output);

        foreach ($output as $line) {
            if (stripos($line, $basename) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function isAnyKnownProcessRunning(array $executables): bool
    {
        foreach ($executables as $exe) {
            if ($this->isProcessRunning($exe)) {
                return true;
            }
        }

        return false;
    }

    protected function findProcessIdsForKnownExecutables(string $folder, array $executables): array
    {
        $pids = [];
        foreach ($executables as $exe) {
            $pids = array_merge($pids, $this->findProcessIds($folder, $exe));
        }

        return array_values(array_unique($pids));
    }

    public function isRunning(Server $server): bool
    {
        $rootPath = $this->resolveFolder($server->folder);
        if (!$rootPath || !is_dir($rootPath)) {
            return false;
        }

        $engine = strtolower($server->engine ?? 'samp');

        if ($engine === 'fivem') {
            if (!empty($server->port) && $this->isFivemHttpResponsive($server)) {
                ActionLogService::append("[FiveM] Server RUNNING: HTTP endpoint responsive on port {$server->port}");
                return true;
            }

            $mainPid = $this->findFiveMMainProcess($rootPath, false); // strict mode for status check
            if ($mainPid) {
                ActionLogService::append("[FiveM] Server RUNNING: FXServer main PID={$mainPid} found");
                return true;
            }

            if (!empty($server->port) && $this->isPortInUse((int) $server->port)) {
                ActionLogService::append("[FiveM] Server NOT RUNNING: port {$server->port} is OPEN but ignored for FiveM status");
            } else {
                ActionLogService::append("[FiveM] Server NOT RUNNING: no HTTP response and no FXServer process");
            }

            return false;
        }

        $exe = $this->resolveSampExecutable($rootPath);
        if (!$exe) {
            return false;
        }

        $pids = $this->findProcessIds($rootPath, $exe);
        if (!empty($pids)) {
            ActionLogService::append("SA-MP Server RUNNING: process {$exe} found");
            return true;
        }

        if (!empty($server->port) && $this->isPortInUse((int) $server->port)) {
            ActionLogService::append("SA-MP Server RUNNING: port {$server->port} is OPEN");
            return true;
        }

        ActionLogService::append("SA-MP Server NOT RUNNING: no process and no port open");
        return false;
    }

    public function getServerStatus(Server $server): string
    {
        return $this->isRunning($server) ? 'online' : 'offline';
    }

    protected function isFivemHttpResponsive(Server $server): bool
    {
        if (empty($server->port)) {
            return false;
        }

        $ip = $server->type === 'local' ? '127.0.0.1' : $server->ip;
        $urls = [
            "http://{$ip}:{$server->port}/info.json",
            "http://{$ip}:{$server->port}/players.json"
        ];

        foreach ($urls as $url) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 1000,
                CURLOPT_TIMEOUT_MS => 2000,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: painel-samp/1.0'
                ],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && empty($error)) {
                return true;
            }
        }

        return false;
    }

    protected function findProcessIds(string $folder, string $exe): array
    {
        if ($this->isWindows()) {
            $output = [];
            $status = 0;
            $escapedExe = str_replace('"', '\\"', $exe);
            $escapedFolder = str_replace('"', '\\"', $folder);
            $basename = strtolower(pathinfo($exe, PATHINFO_BASENAME));

            if (str_ends_with($basename, '.bat')) {
                $cmd = 'wmic process get ProcessId,ExecutablePath,CommandLine /FORMAT:CSV';
            } else {
                $cmd = 'wmic process where "name=\'' . $escapedExe . '\'" get ProcessId,ExecutablePath,CommandLine /FORMAT:CSV';
            }

            exec($cmd, $output, $status);
            $pids = [];

            foreach ($output as $line) {
                $line = trim($line);
                if ($line === '' || stripos($line, 'ProcessId') !== false || stripos($line, 'CommandLine') !== false || stripos($line, 'ExecutablePath') !== false) {
                    continue;
                }

                $parts = str_getcsv($line);
                if (count($parts) < 4) {
                    continue;
                }

                $commandLine = $parts[1] ?? '';
                $executablePath = $parts[2] ?? '';
                $pid = $parts[3] ?? '';

                if (!is_numeric($pid)) {
                    continue;
                }

                if ($this->stringContainsPath($commandLine, $folder) || $this->stringContainsPath($executablePath, $folder)) {
                    $pids[] = (int) $pid;
                }
            }

            return array_values(array_unique($pids));
        }

        $pids = [];
        $output = [];
        exec('ps -eo pid=,args= 2>/dev/null', $output);
        $basename = basename($exe);

        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (!preg_match('/^(\d+)\s+(.*)$/', $line, $matches)) {
                continue;
            }

            $pid = $matches[1];
            $args = $matches[2];
            if (!is_numeric($pid)) {
                continue;
            }

            if ($this->stringContainsPath($args, $folder) || stripos($args, $basename) !== false) {
                $pids[] = (int) $pid;
            }
        }

        return array_values(array_unique($pids));
    }

    public function getServerProcessMemoryUsage(Server $server): ?array
    {
        if ($server->type !== 'local') {
            return null;
        }

        $engine = strtolower($server->engine ?? 'samp');
        if ($engine === 'fivem') {
            return $this->getFiveMServerMemoryUsage($server);
        }

        // SA-MP: usar lógica existente
        $folder = $this->resolveFolder($server->folder);
        if (!$folder || !is_dir($folder)) {
            return null;
        }

        $exe = $this->findExecutable($folder, $this->getKnownExecutables());
        if (!$exe) {
            return null;
        }

        $pids = $this->findProcessIds($folder, $exe);
        if (empty($pids)) {
            return null;
        }

        $usedBytes = 0;
        foreach ($pids as $pid) {
            $memory = $this->getProcessWorkingSetSize($pid);
            if ($memory !== null) {
                $usedBytes += $memory;
            }
        }

        if ($usedBytes <= 0) {
            return null;
        }

        $memoryLimitBytes = null;
        if ($server->limit_ram !== null && $server->limit_ram > 0) {
            $memoryLimitBytes = (int) $server->limit_ram * 1024 * 1024;
        }

        $percent = null;
        if ($memoryLimitBytes !== null && $memoryLimitBytes > 0) {
            $percent = round(($usedBytes / $memoryLimitBytes) * 100, 1);
        }

        return [
            'used' => $usedBytes,
            'total' => $memoryLimitBytes,
            'percent' => $percent,
        ];
    }

    protected function getFiveMServerMemoryUsage(Server $server): ?array
    {
        $folder = $this->resolveFolder($server->folder);
        if (!$folder || !is_dir($folder)) {
            ActionLogService::append("FiveM RAM: invalid folder {$server->folder}");
            return null;
        }

        // Usar PID salvo se existir e for válido
        $pid = null;
        if ($server->pid && $this->isProcessRunningByPid($server->pid)) {
            $pid = $server->pid;
            ActionLogService::append("FiveM RAM: using saved PID={$pid} for server {$server->id}");
        } else {
            // Tentar localizar PID principal
            $pid = $this->findFiveMMainProcess($folder, false);
            if ($pid) {
                // Salvar PID no banco
                $server->update(['pid' => $pid]);
                ActionLogService::append("FiveM RAM: found and saved PID={$pid} for server {$server->id}");
            } else {
                ActionLogService::append("FiveM RAM: no valid PID found for server {$server->id}");
                return null;
            }
        }

        $memoryBytes = $this->getProcessWorkingSetSize($pid);
        if ($memoryBytes === null || $memoryBytes <= 0) {
            ActionLogService::append("FiveM RAM: failed to get memory for PID={$pid}, server {$server->id}");
            return null;
        }

        $memoryLimitBytes = null;
        if ($server->limit_ram !== null && $server->limit_ram > 0) {
            $memoryLimitBytes = (int) $server->limit_ram * 1024 * 1024;
        }

        $percent = null;
        if ($memoryLimitBytes !== null && $memoryLimitBytes > 0) {
            $percent = round(($memoryBytes / $memoryLimitBytes) * 100, 1);
        }

        ActionLogService::append("FiveM RAM: server_id={$server->id}, engine=fivem, pid={$pid}, ram_process=" . round($memoryBytes / 1024 / 1024, 1) . "MB");
        return [
            'used' => $memoryBytes,
            'total' => $memoryLimitBytes,
            'percent' => $percent,
        ];
    }

    public function getServerDiskUsage(Server $server): ?array
    {
        if ($server->type !== 'local') {
            return null;
        }

        $engine = strtolower($server->engine ?? 'samp');
        if ($engine === 'fivem') {
            return $this->getFiveMServerDiskUsage($server);
        }

        // SA-MP: usar lógica existente (disco do sistema)
        $folder = $this->resolveFolder($server->folder);
        if (!$folder || !is_dir($folder)) {
            return null;
        }

        $total = @disk_total_space($folder);
        $free = @disk_free_space($folder);

        if ($total === false || $free === false) {
            return null;
        }

        $used = $total - $free;
        $percent = $total > 0 ? round(($used / $total) * 100, 1) : null;

        return [
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'percent' => $percent,
            'path' => $folder,
        ];
    }

    protected function getFiveMServerDiskUsage(Server $server): ?array
    {
        $folder = $this->resolveFolder($server->folder);
        if (!$folder || !is_dir($folder)) {
            ActionLogService::append("FiveM Disk: invalid folder {$server->folder}");
            return null;
        }

        // Calcular tamanho da pasta do servidor
        $usedBytes = $this->getFolderSize($folder);
        if ($usedBytes === null) {
            ActionLogService::append("FiveM Disk: failed to calculate folder size for {$folder}");
            return null;
        }

        $limitBytes = null;
        if ($server->disk_limit_gb !== null && $server->disk_limit_gb > 0) {
            $limitBytes = (int) $server->disk_limit_gb * 1024 * 1024 * 1024; // GB to bytes
        }

        $percent = null;
        if ($limitBytes !== null && $limitBytes > 0) {
            $percent = round(($usedBytes / $limitBytes) * 100, 1);
        }

        ActionLogService::append("FiveM Disk: server_id={$server->id}, engine=fivem, folder_used=" . round($usedBytes / 1024 / 1024 / 1024, 2) . "GB, limit=" . ($limitBytes ? round($limitBytes / 1024 / 1024 / 1024, 2) . "GB" : "none"));
        return [
            'used' => $usedBytes,
            'total' => $limitBytes,
            'percent' => $percent,
            'path' => $folder,
        ];
    }

    public function getServerProcessCpuUsage(Server $server): ?array
    {
        if ($server->type !== 'local') {
            return null;
        }

        $engine = strtolower($server->engine ?? 'samp');
        if ($engine === 'fivem') {
            return $this->getFiveMServerCpuUsage($server);
        }

        // SA-MP: usar lógica existente
        $folder = $this->resolveFolder($server->folder);
        if (!$folder || !is_dir($folder)) {
            return null;
        }

        $exe = $this->findExecutable($folder, $this->getKnownExecutables());
        if (!$exe) {
            return null;
        }

        $pids = $this->findProcessIds($folder, $exe);
        if (empty($pids)) {
            return null;
        }

        $percent = 0.0;
        $validCount = 0;

        foreach ($pids as $pid) {
            $value = $this->getProcessCpuPercent($pid);
            if ($value !== null) {
                $percent += $value;
                $validCount++;
            }
        }

        if ($validCount === 0) {
            return null;
        }

        return [
            'percent' => round($percent, 1),
        ];
    }

    protected function getFiveMServerCpuUsage(Server $server): ?array
    {
        $folder = $this->resolveFolder($server->folder);
        if (!$folder || !is_dir($folder)) {
            ActionLogService::append("FiveM CPU: invalid folder {$server->folder}");
            return null;
        }

        // Usar PID salvo se existir e for válido
        $pid = null;
        if ($server->pid && $this->isProcessRunningByPid($server->pid)) {
            $pid = $server->pid;
            ActionLogService::append("FiveM CPU: using saved PID={$pid} for server {$server->id}");
        } else {
            // Tentar localizar PID principal
            $pid = $this->findFiveMMainProcess($folder, false);
            if ($pid) {
                // Salvar PID no banco
                $server->update(['pid' => $pid]);
                ActionLogService::append("FiveM CPU: found and saved PID={$pid} for server {$server->id}");
            } else {
                ActionLogService::append("FiveM CPU: no valid PID found for server {$server->id}");
                return null;
            }
        }

        $cpuPercent = $this->getProcessCpuPercent($pid);
        if ($cpuPercent === null) {
            ActionLogService::append("FiveM CPU: failed to get CPU for PID={$pid}, server {$server->id}");
            return null;
        }

        ActionLogService::append("FiveM CPU: server_id={$server->id}, engine=fivem, pid={$pid}, cpu_process={$cpuPercent}%");
        return [
            'percent' => round($cpuPercent, 1),
        ];
    }

    protected function getProcessCpuPercent(int $pid): ?float
    {
        $output = [];
        @exec('wmic path Win32_PerfFormattedData_PerfProc_Process where IDProcess=' . $pid . ' get PercentProcessorTime /value', $output);

        foreach ($output as $line) {
            if (preg_match('/^PercentProcessorTime=(\d+(?:\.\d+)?)$/i', trim($line), $matches)) {
                return (float) $matches[1];
            }
        }

        $output = [];
        @exec('powershell -NoProfile -Command "(Get-CimInstance Win32_PerfFormattedData_PerfProc_Process | Where-Object { $_.IDProcess -eq ' . $pid . ' } | Select-Object -ExpandProperty PercentProcessorTime)"', $output);
        foreach ($output as $line) {
            if (is_numeric(trim($line))) {
                return (float) trim($line);
            }
        }

        if (strncasecmp(PHP_OS_FAMILY, 'Linux', 5) === 0) {
            $output = [];
            @exec('ps -p ' . $pid . ' -o %cpu=', $output);
            if (!empty($output) && is_numeric(trim($output[0]))) {
                return (float) trim($output[0]);
            }
        }

        return null;
    }

    protected function getProcessWorkingSetSize(int $pid): ?int
    {
        if ($this->isWindows()) {
            $output = [];
            exec('wmic process where ProcessId=' . $pid . ' get WorkingSetSize /value', $output);

            foreach ($output as $line) {
                if (preg_match('/^WorkingSetSize=(\d+)$/i', trim($line), $matches)) {
                    return (int) $matches[1];
                }
            }

            return null;
        }

        $output = [];
        exec('ps -p ' . $pid . ' -o rss= 2>/dev/null', $output);
        if (!empty($output) && is_numeric(trim($output[0]))) {
            return (int) trim($output[0]) * 1024;
        }

        return null;
    }

    protected function stringContainsPath(string $value, string $path): bool
    {
        $normalizedValue = str_replace('\\', '/', strtolower($value));
        $normalizedPath = str_replace('\\', '/', strtolower($path));
        return $normalizedPath !== '' && strpos($normalizedValue, $normalizedPath) !== false;
    }

    protected function findFiveMRunBatParentPid(string $folder): ?int
    {
        if (!$this->isWindows()) {
            return null;
        }

        $output = [];
        $cmd = 'wmic process where "name=\'cmd.exe\'" get ProcessId,CommandLine /FORMAT:CSV';
        exec($cmd, $output, $status);

        $normalizedFolder = str_replace('\\', '/', strtolower(rtrim($folder, DIRECTORY_SEPARATOR)));

        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'ProcessId') !== false || stripos($line, 'CommandLine') !== false) {
                continue;
            }

            $processInfo = $this->parseWmicProcessCsvLine($line);
            if (!$processInfo) {
                continue;
            }

            $commandLine = $processInfo['commandLine'];
            $pid = (int) $processInfo['pid'];

            // Skip if no command line
            if (!$commandLine) {
                continue;
            }

            // Check if command line contains run.bat or run.cmd and the folder path
            $hasRunBat = stripos($commandLine, 'run.bat') !== false || stripos($commandLine, 'run.cmd') !== false;
            $hasFolderPath = $this->stringContainsPath($commandLine, $normalizedFolder);

            if ($hasRunBat && $hasFolderPath) {
                ActionLogService::append("FiveM run.bat parent cmd.exe found: PID={$pid}, CommandLine={$commandLine}");
                return $pid;
            }
        }

        ActionLogService::append("FiveM run.bat parent cmd.exe not found for folder: {$normalizedFolder}");
        return null;
    }

    protected function isMetricProcess(int $pid): bool
    {
        if (!$this->isWindows()) {
            return false;
        }

        $output = [];
        $cmd = 'wmic process where "ProcessId=' . $pid . '" get CommandLine /FORMAT:CSV';
        exec($cmd, $output, $status);

        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'CommandLine') !== false) {
                continue;
            }

            $processInfo = $this->parseWmicProcessCsvLine($line);
            if (!$processInfo) {
                continue;
            }

            $commandLine = $processInfo['commandLine'];

            // Check for metric-related processes
            $metricIndicators = [
                'Get-ChildItem',
                'Measure-Object',
                'wmic cpu',
                'wmic process get',
                'netstat -ano',
                'tasklist'
            ];

            foreach ($metricIndicators as $indicator) {
                if (stripos($commandLine, $indicator) !== false) {
                    ActionLogService::append("Metric process detected: PID={$pid}, CommandLine={$commandLine}");
                    return true;
                }
            }
        }

        return false;
    }

    protected function findFiveMMainProcess(string $folder, bool $allowEmptyCommandLine = false): ?int
    {
        if (!$this->isWindows()) {
            return null;
        }

        $output = [];
        $cmd = 'wmic process where "name=\'FXServer.exe\'" get ProcessId,ExecutablePath,CommandLine /FORMAT:CSV';
        exec($cmd, $output, $status);

        $ignoredPids = [];
        $mainPid = null;
        $normalizedFolder = str_replace('\\', '/', strtolower(rtrim($folder, DIRECTORY_SEPARATOR)));
        $allProcesses = [];

        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'ProcessId') !== false || stripos($line, 'CommandLine') !== false || stripos($line, 'ExecutablePath') !== false) {
                continue;
            }

            $processInfo = $this->parseWmicProcessCsvLine($line);
            if (!$processInfo) {
                continue;
            }

            $commandLine = $processInfo['commandLine'];
            $executablePath = $processInfo['executablePath'];
            $pid = (int) $processInfo['pid'];
            $allProcesses[] = "PID={$pid}, ExecutablePath={$executablePath}, CommandLine={$commandLine}";

            if (!$commandLine) {
                ActionLogService::append("FiveM process PID={$pid} has empty CommandLine, ignoring");
                $ignoredPids[] = $pid;
                continue;
            }

            if (stripos($commandLine, '-dumpserver') !== false || stripos($commandLine, '-parentpid') !== false) {
                ActionLogService::append("FiveM dump/parent process PID={$pid}, ignoring: {$commandLine}");
                $ignoredPids[] = $pid;
                continue;
            }

            if (stripos($commandLine, '+exec') === false) {
                ActionLogService::append("FiveM process PID={$pid} has no +exec, ignoring: {$commandLine}");
                $ignoredPids[] = $pid;
                continue;
            }

            $normalizedExePath = str_replace('\\', '/', strtolower($executablePath));
            $exeInFolder = strpos($normalizedExePath, $normalizedFolder) !== false;
            $hasConfigFile = stripos($commandLine, 'config.cfg') !== false || stripos($commandLine, 'server.cfg') !== false;

            if ($exeInFolder && $hasConfigFile) {
                $mainPid = $pid;
                ActionLogService::append("FiveM main process selected PID={$pid} with ExecutablePath={$executablePath} and CommandLine: {$commandLine}");
                break;
            }

            ActionLogService::append("FiveM process PID={$pid} ignored: ExecutablePath={$executablePath}, commandLine={$commandLine}");
            $ignoredPids[] = $pid;
        }

        ActionLogService::append("FiveM process scan complete. All processes: " . implode(' | ', $allProcesses));
        if (!$mainPid && !empty($ignoredPids)) {
            ActionLogService::append("FiveM main process not found. Ignored PIDs: " . implode(', ', $ignoredPids));
        }

        return $mainPid;
    }
    public function stop(Server $server): array
    {
        $folder = $this->resolveFolder($server->folder);

        if (!$folder || !is_dir($folder)) {
            return [
                'success' => false,
                'message' => 'Pasta do servidor inválida.'
            ];
        }

        $engine = strtolower($server->engine ?? 'samp');
        $pids = [];
        $outputs = [];
        $exe = null;

        if ($engine === 'fivem') {
            // Step 1: Kill run.bat parent cmd.exe first
            $cmdPid = $this->findFiveMRunBatParentPid($folder);
            if ($cmdPid) {
                ActionLogService::append("FiveM stop: Killing run.bat parent cmd.exe PID={$cmdPid}");
                $outputs[] = $this->killByPid($cmdPid, true); // with children
                sleep(1); // Give time for cmd.exe to terminate
            }

            // Step 2: Kill main FXServer process
            $mainPid = $this->findFiveMMainProcess($folder, false); // false = strict mode for stop
            if ($mainPid) {
                $pids[] = $mainPid;
                ActionLogService::append("FiveM stop: Killing main FXServer PID={$mainPid}");
            }

            // Step 3: Kill any remaining FXServer.exe in the folder
            $fallbackPids = $this->findProcessIds($folder, 'FXServer.exe');
            if (!empty($fallbackPids)) {
                // Filter out metric processes (Get-ChildItem, Measure-Object, wmic cpu)
                $filteredPids = [];
                foreach ($fallbackPids as $pid) {
                    if (!$this->isMetricProcess($pid)) {
                        $filteredPids[] = $pid;
                    }
                }
                if (!empty($filteredPids)) {
                    $pids = array_merge($pids, $filteredPids);
                    ActionLogService::append("FiveM stop: Found additional FXServer PIDs in folder: " . implode(', ', $filteredPids));
                }
            }

            // Step 4: If no PIDs found, try global fallback
            if (empty($pids)) {
                $fxPids = $this->findFxServerProcessIds();
                if (count($fxPids) === 1) {
                    ActionLogService::append("FiveM stop: No folder-specific PID found, one FXServer.exe running globally, using taskkill fallback");
                    $outputs[] = $this->stopSingleFxServerByImageName();
                } elseif (count($fxPids) > 1) {
                    return [
                        'success' => false,
                        'message' => 'Existem múltiplos FXServer.exe em execução. Pare manualmente ou informe o PID correto.',
                        'output' => implode(', ', $fxPids)
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Nenhum processo FiveM encontrado para esta pasta/porta.'
                    ];
                }
            }
        } else {
            $knownExecutables = $this->getKnownExecutables();
            $exe = $this->findExecutable($folder, $knownExecutables);

            if ($exe) {
                $pids = $this->findProcessIds($folder, $exe);
            } else {
                $pids = $this->findProcessIdsForKnownExecutables($folder, $knownExecutables);
                if (empty($pids) && !$this->isAnyKnownProcessRunning($knownExecutables)) {
                    return [
                        'success' => false,
                        'message' => 'Executável não encontrado para encerrar.'
                    ];
                }
            }
        }

        try {
            if (!empty($pids)) {
                foreach ($pids as $pid) {
                    $outputs[] = $this->killByPid($pid);
                }
            }

            sleep(1);

            if ($engine === 'fivem') {
                // For FiveM, comprehensive checks with different logic based on server state
                $remaining = $this->findFiveMMainProcess($folder, false); // false = strict mode for stop
                $portStillInUse = !empty($server->port) && $this->isPortInUse((int) $server->port);
                $httpStillResponsive = $this->isFivemHttpResponsive($server);

                ActionLogService::append("FiveM stop verification: remaining_pid=" . ($remaining ?: 'null') .
                    ", port_in_use=" . ($portStillInUse ? 'yes' : 'no') .
                    ", http_responsive=" . ($httpStillResponsive ? 'yes' : 'no'));

                // Check if we have any remaining processes
                $hasRemainingProcesses = $remaining !== null;

                // Check if we have any cmd.exe run.bat processes
                $runBatParentPid = $this->findFiveMRunBatParentPid($folder);
                $hasRunBatProcess = $runBatParentPid !== null;

                // If we still have processes, that's a problem
                if ($hasRemainingProcesses || $hasRunBatProcess) {
                    ActionLogService::append("FiveM stop failed: still has processes (FXServer: " . ($remaining ?: 'none') . ", run.bat: " . ($runBatParentPid ?: 'none') . ")");
                    return [
                        'success' => false,
                        'message' => 'Não foi possível encerrar completamente o servidor FiveM. Processos ainda em execução.',
                        'output' => implode(' | ', $outputs),
                        'debug' => [
                            'remaining_pid' => $remaining,
                            'run_bat_pid' => $runBatParentPid,
                            'port_in_use' => $portStillInUse,
                            'http_responsive' => $httpStillResponsive
                        ]
                    ];
                }

                // If port is still in LISTENING state (not just TIME_WAIT/SYN_SENT), that's also a problem
                if ($portStillInUse) {
                    $portOwnerInfo = $this->getPortOwnerInfo((int) $server->port);
                    if ($portOwnerInfo) {
                        ActionLogService::append("FiveM stop failed: Port " . $server->port . " still LISTENING by: " . json_encode($portOwnerInfo));
                        return [
                            'success' => false,
                            'message' => 'Porta ainda está sendo usada por outro processo após stop.',
                            'output' => implode(' | ', $outputs),
                            'debug' => [
                                'port_owner' => $portOwnerInfo,
                                'remaining_pid' => $remaining,
                                'run_bat_pid' => $runBatParentPid
                            ]
                        ];
                    } else {
                        // Port shows as in use but no LISTENING process found - this might be TIME_WAIT/SYN_SENT
                        ActionLogService::append("FiveM stop: Port shows in use but no LISTENING process found (likely TIME_WAIT/SYN_SENT)");
                    }
                }

                ActionLogService::append("FiveM stop successful: no processes remaining, port clean");
            } else {
                $knownExecutables = $this->getKnownExecutables();
                $remaining = [];
                if ($exe) {
                    $remaining = $this->findProcessIds($folder, $exe);
                }

                if (!empty($remaining) || $this->isAnyKnownProcessRunning($knownExecutables)) {
                    return [
                        'success' => false,
                        'message' => 'Não foi possível encerrar completamente o servidor. Existem processos remanescentes.',
                        'output' => implode(' | ', $outputs)
                    ];
                }
            }

            if (empty($pids) && empty($outputs)) {
                return [
                    'success' => true,
                    'message' => 'Servidor já estava parado.',
                    'output' => null
                ];
            }

            return [
                'success' => true,
                'message' => 'Servidor parado com sucesso.',
                'output' => implode(' | ', $outputs)
            ];

        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        } finally {
            // Limpar PID salvo quando servidor for parado
            if ($server->pid) {
                $server->update(['pid' => null]);
                ActionLogService::append("FiveM PID cleared: server_id={$server->id}, previous_pid={$server->pid}");
            }
        }
    }

    protected function killByPid(int $pid): string
    {
        $output = [];
        if ($this->isWindows()) {
            $cmd = 'taskkill /F /PID ' . $pid . ' /T';
        } else {
            $cmd = 'kill -TERM ' . $pid;
        }
        exec($cmd, $output);
        return $cmd;
    }

    protected function stopSingleFxServerByImageName(): string
    {
        $output = [];
        if ($this->isWindows()) {
            $cmd = 'taskkill /F /IM FXServer.exe /T';
            exec($cmd, $output);
            return implode(' | ', $output);
        }

        return '';
    }

    protected function findFxServerProcessIds(): array
    {
        if (!$this->isWindows()) {
            return [];
        }

        $output = [];
        exec('wmic process where "name=\'FXServer.exe\'" get ProcessId /FORMAT:CSV', $output);

        $pids = [];
        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'ProcessId') !== false) {
                continue;
            }

            $parts = str_getcsv($line);
            if (count($parts) < 2) {
                continue;
            }

            $pidStr = $parts[1] ?? '';
            if (is_numeric($pidStr)) {
                $pids[] = (int) $pidStr;
            }
        }

        return array_values(array_unique($pids));
    }

    protected function killByImageName(string $exe, bool $withChildren = false): string
    {
        $output = [];
        if ($this->isWindows()) {
            $cmd = 'taskkill /F /IM "' . $exe . '"';
            if ($withChildren) {
                $cmd .= ' /T';
            }
        } else {
            $cmd = 'pkill -f "' . str_replace('"', '\\"', $exe) . '"';
        }
        exec($cmd, $output);
        return $cmd;
    }

    protected function waitForPortRelease(Server $server): array
    {
        $folder = $this->resolveFolder($server->folder);
        if (!$folder) {
            return [
                'success' => false,
                'message' => 'Pasta do servidor inválida.'
            ];
        }

        $port = (int) ($server->port ?? 30120);
        $maxWaitTime = 15000; // 15 seconds in milliseconds
        $checkInterval = 500; // 500ms
        $elapsedTime = 0;

        ActionLogService::append("[FiveM Wait] Starting port release check for port {$port}, max wait: {$maxWaitTime}ms");

        while ($elapsedTime < $maxWaitTime) {
            // Check if port is free
            $portFree = !$this->isPortInUse($port);

            // Check if FiveM main process is gone
            $mainPid = $this->findFiveMMainProcess($folder, false);

            ActionLogService::append("[FiveM Wait] Port free: " . ($portFree ? 'yes' : 'no') . ", MainPID: " . ($mainPid ? $mainPid : 'null') . " (elapsed: {$elapsedTime}ms)");

            // Both conditions met - port free AND process gone
            if ($portFree && $mainPid === null) {
                ActionLogService::append("[FiveM Wait] Success! Port released and process terminated (elapsed: {$elapsedTime}ms)");
                return [
                    'success' => true,
                    'message' => 'Port released and process terminated.'
                ];
            }

            // Sleep and increment elapsed time
            usleep($checkInterval * 1000); // Convert ms to microseconds
            $elapsedTime += $checkInterval;
        }

        // Timeout reached, check final status
        $portStillInUse = $this->isPortInUse($port);
        $processStillRunning = $this->findFiveMMainProcess($folder, false) !== null;

        if ($portStillInUse) {
            ActionLogService::append("[FiveM Wait] TIMEOUT: Port still in use after {$maxWaitTime}ms");
            return [
                'success' => false,
                'message' => 'FXServer não liberou a porta após stop. Verifique se existem outros processos usando a porta ' . $port . '.'
            ];
        }

        if ($processStillRunning) {
            ActionLogService::append("[FiveM Wait] TIMEOUT: FiveM process still running after {$maxWaitTime}ms");
            return [
                'success' => false,
                'message' => 'FXServer não foi completamente encerrado após stop.'
            ];
        }

        // Cleanup successful but took full timeout
        ActionLogService::append("[FiveM Wait] Success after full {$maxWaitTime}ms wait period");
        return [
            'success' => true,
            'message' => 'Port released after timeout period.'
        ];
    }

    public function restart(Server $server): array
    {
        $engine = strtolower($server->engine ?? 'samp');
        
        // Stop the server
        ActionLogService::append("[FiveM Restart] Stopping server...");
        $stopResult = $this->stop($server);
        if (!$stopResult['success']) {
            return $stopResult;
        }
        
        // For FiveM, wait for port release and process termination
        if ($engine === 'fivem') {
            ActionLogService::append("[FiveM Restart] Waiting for port release and process termination...");
            $waitResult = $this->waitForPortRelease($server);
            if (!$waitResult['success']) {
                ActionLogService::append("[FiveM Restart] Port release wait failed: " . $waitResult['message']);
                return $waitResult;
            }
            ActionLogService::append("[FiveM Restart] Port released successfully, starting server...");
        } else {
            sleep(1);
        }
        
        // Start the server
        ActionLogService::append("[FiveM Restart] Starting server...");
        return $this->start($server);
    }

    public function suspend(Server $server): array
    {
        return $this->stop($server);
    }

    protected function buildStartCommandByEngine(Server $server, string $rootPath, string $exe, ?string $configFile = null, ?string $runBatPath = null): string
    {
        $engine = strtolower($server->engine ?? 'samp');
        $executablePath = $rootPath . DIRECTORY_SEPARATOR . $exe;
        $execParam = '';

        if ($engine === 'fivem' && !empty($configFile)) {
            $configPath = $rootPath . DIRECTORY_SEPARATOR . $configFile;
            if ($this->isPathInsideRoot($configPath, $rootPath)) {
                $relativeConfig = $this->getRelativePath($rootPath, $configPath);
                $execParam = ' +exec "' . str_replace('"', '\\"', $relativeConfig) . '"';
            } else {
                $execParam = ' +exec "' . str_replace('"', '\\"', $configPath) . '"';
            }
        }

        if ($this->isWindows()) {
            if ($engine === 'fivem' && in_array(strtolower(basename($exe)), ['run.bat', 'run.cmd'], true)) {
                $runBatPath = $runBatPath ?? $executablePath;
                $psRunBat = str_replace("'", "''", $runBatPath);
                $psRoot = str_replace("'", "''", $rootPath);

                $psCommand = sprintf(
                    "Start-Process -FilePath 'cmd.exe' -ArgumentList '/c','\"%s\"' -WorkingDirectory '%s' -WindowStyle Normal",
                    $psRunBat,
                    $psRoot
                );

                // Encapsular em aspas duplas sem usar escapeshellarg para evitar problemas de encoding
                $command = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . str_replace('"', '\\"', $psCommand) . '"';

                if (str_contains($command, '$quotedRoot') || str_contains($command, '$quotedBat')) {
                    throw new \RuntimeException('Comando PowerShell FiveM inválido: concatenação PHP vazou para a string final.');
                }

                $this->writeFivemStartCommandDebug($command, $runBatPath, $rootPath);
                return $command;
            }

            $quotedFolder = '"' . str_replace('"', '\\"', $rootPath) . '"';
            $quotedExe = '"' . str_replace('"', '\\"', $executablePath) . '"';
            return 'start /B "" /D ' . $quotedFolder . ' ' . $quotedExe . $execParam;
        }

        $quotedFolder = escapeshellarg($rootPath);
        $quotedExe = escapeshellarg($executablePath);
        return 'cd ' . $quotedFolder . ' && chmod +x ' . $quotedExe . ' >/dev/null 2>&1 && nohup ' . $quotedExe . $execParam . ' >/dev/null 2>&1 &';
    }

    protected function writeFivemStartCommandDebug(string $command, string $runBatPath, string $rootPath): void
    {
        $filePath = storage_path('logs/fivem-start-debug.ps1');
        $content = "runBatPath={$runBatPath}\nworkingDirectory={$rootPath}\ncommand={$command}\nfile_exists=" . (file_exists($runBatPath) ? '1' : '0') . "\nis_readable=" . (is_readable($runBatPath) ? '1' : '0') . "\n";
        // Garantir que o conteúdo está em UTF-8 puro
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        @file_put_contents($filePath, $content, FILE_TEXT);
        ActionLogService::append("FiveM start debug file written: {$filePath}");
    }

    protected function waitForFiveMServerStart(string $rootPath, string $exe, ?int $port, bool $useRunBat = false, bool $hasStartOutput = false, ?Server $server = null, ?string &$debug = null, ?string &$errorMessage = null): bool
    {
        $initialDelayMs = 5000000; // 5s
        $maxAttempts = 30; // 60 seconds de checagem após o delay inicial
        $sleepMs = 2000000; // 2s

        $debug = null;
        $errorMessage = null;
        $httpInfo = false;
        $httpPlayers = false;
        $portOpen = false;
        $mainPid = null;
        $onlineDetected = false;
        $startTime = microtime(true);

        ActionLogService::append("[FiveM] Waiting for server start: rootPath={$rootPath}, exe={$exe}, port={$port}, useRunBat=" . ($useRunBat ? '1' : '0') . ", hasStartOutput=" . ($hasStartOutput ? '1' : '0') . ", initialDelayMs={$initialDelayMs}, maxAttempts={$maxAttempts}, sleepMs={$sleepMs}");

        usleep($initialDelayMs);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $httpStatus = $this->getFivemHttpStatus($port);
            $httpInfo = $httpStatus['infoJson'];
            $httpPlayers = $httpStatus['playersJson'];
            $portOpen = !empty($port) && $this->isPortInUse($port);
            $mainPid = $this->findFiveMMainProcess($rootPath, true);

            // Verificar se cmd.exe /c run.bat está rodando
            $cmdProcessRunning = false;
            if ($useRunBat) {
                $cmdProcessRunning = $this->isCmdRunBatProcessRunning($runBatPath ?? ($rootPath . DIRECTORY_SEPARATOR . $exe));
            }

            if ($mainPid) {
                ActionLogService::append("[FiveM] SUCCESS: Main process detected at attempt {$attempt}, PID={$mainPid}");
                if ($server) {
                    $server->update(['pid' => $mainPid]);
                    ActionLogService::append("[FiveM] PID saved: server_id={$server->id}, pid={$mainPid}");
                }
                return true;
            }

            if ($httpInfo || $httpPlayers) {
                $onlineDetected = true;
                ActionLogService::append("[FiveM] ONLINE INDICATOR: attempt {$attempt}, infoJson=" . ($httpInfo ? '1' : '0') . ", playersJson=" . ($httpPlayers ? '1' : '0') . ", portOpen=" . ($portOpen ? '1' : '0') . ", pidPending=1");
            } elseif ($portOpen) {
                ActionLogService::append("[FiveM] Attempt {$attempt}: porta aberta mas nenhum endpoint HTTP responsivo, aguardando FXServer.exe...");
            } else {
                ActionLogService::append("[FiveM] Attempt {$attempt}: server ainda não online, aguardando... cmdRunning=" . ($cmdProcessRunning ? '1' : '0'));
            }

            if ($useRunBat && (microtime(true) - $startTime) >= 30 && !$mainPid && !$httpInfo && !$httpPlayers && !$cmdProcessRunning) {
                $errorMessage = 'run.bat foi chamado, mas cmd.exe /c run.bat não iniciou ou já terminou';
                $debug = $this->buildFiveMStartDebug($port, $portOpen, $httpInfo, $httpPlayers, false);
                ActionLogService::append("[FiveM] FAILED: {$errorMessage} | {$debug}");
                return false;
            }

            usleep($sleepMs);
        }

        $portOpen = !empty($port) && $this->isPortInUse($port);
        $mainPid = $this->findFiveMMainProcess($rootPath, true);
        $httpStatus = $this->getFivemHttpStatus($port);
        $httpInfo = $httpStatus['infoJson'];
        $httpPlayers = $httpStatus['playersJson'];

        if ($mainPid) {
            ActionLogService::append("[FiveM] SUCCESS: Main process detected after timeout, PID={$mainPid}");
            if ($server) {
                $server->update(['pid' => $mainPid]);
                ActionLogService::append("[FiveM] PID saved: server_id={$server->id}, pid={$mainPid}");
            }
            return true;
        }

        if ($httpInfo || $httpPlayers) {
            ActionLogService::append("[FiveM] SUCCESS: server considerado online após timeout por HTTP, sem PID disponível.");
            return true;
        }

        $debug = $this->buildFiveMStartDebug($port, $portOpen, $httpInfo, $httpPlayers, (bool) $mainPid);
        if ($useRunBat) {
            $errorMessage = 'run.bat foi chamado, mas FXServer.exe não iniciou';
        }
        ActionLogService::append("[FiveM] FAILED: " . $debug);
        return false;
    }

    protected function isCmdRunBatProcessRunning(string $runBatPath): bool
    {
        if (!$this->isWindows()) {
            return false;
        }

        $runBatName = basename($runBatPath);
        $command = 'wmic process where "name=\'cmd.exe\' and commandline like \'%cmd.exe /c \\\\"' . str_replace('\\', '\\\\', $runBatName) . '\\"%\'" get processid /value 2>nul';

        $output = shell_exec($command);
        if (!$output) {
            return false;
        }

        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            if (preg_match('/ProcessId=(\d+)/', $line, $matches)) {
                ActionLogService::append("Found cmd.exe /c {$runBatName} process: PID={$matches[1]}");
                return true;
            }
        }

        return false;
    }

    protected function getFivemHttpStatus(?int $port): array
    {
        $status = [
            'infoJson' => false,
            'playersJson' => false,
        ];

        if (empty($port)) {
            return $status;
        }

        $baseUrl = 'http://127.0.0.1:' . $port;
        $endpoints = [
            'infoJson' => $baseUrl . '/info.json',
            'playersJson' => $baseUrl . '/players.json',
        ];

        foreach ($endpoints as $key => $url) {
            if ($this->checkFivemHttpUrl($url)) {
                $status[$key] = true;
            }
        }

        return $status;
    }

    protected function checkFivemHttpUrl(string $url): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => 2000,
            CURLOPT_HTTPHEADER => [
                'User-Agent: painel-samp/1.0'
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return $httpCode === 200 && empty($error);
    }

    protected function buildFiveMStartDebug(?int $port, bool $portOpen, bool $infoOk, bool $playersOk, bool $processFound): string
    {
        return sprintf(
            'port=%s status=%s infoJson=%s playersJson=%s FXServerProcess=%s',
            $port !== null ? $port : 'n/a',
            $portOpen ? 'open' : 'closed',
            $infoOk ? 'ok' : 'failed',
            $playersOk ? 'ok' : 'failed',
            $processFound ? 'found' : 'not_found'
        );
    }

    protected function isFivemServer(Server $server): bool
    {
        return strtolower($server->engine ?? 'samp') === 'fivem';
    }

    protected function waitForServerStart(Server $server, bool $hasStartOutput = false, ?string &$debug = null, ?string &$errorMessage = null): bool
    {
        if ($this->isFivemServer($server)) {
            return $this->waitForFiveMStart($server, $hasStartOutput, $debug, $errorMessage);
        }

        return $this->waitForSampStart($server);
    }

    protected function waitForFiveMStart(Server $server, bool $hasStartOutput = false, ?string &$debug = null, ?string &$errorMessage = null): bool
    {
        $rootPath = $this->resolveFolder($server->folder);
        if (!$rootPath || !is_dir($rootPath)) {
            return false;
        }

        $runBatPath = $this->locateRunBatForFiveM($rootPath);
        if ($runBatPath) {
            $exe = basename($runBatPath);
            $useRunBat = true;
        } else {
            $exe = $this->resolveFiveMExecutable($rootPath);
            $useRunBat = $exe && strtolower(basename($exe)) === 'run.bat';
        }

        if (!$exe) {
            return false;
        }

        return $this->waitForFiveMServerStart($rootPath, $exe, !empty($server->port) ? (int) $server->port : null, $useRunBat, $hasStartOutput, $server, $debug, $errorMessage);
    }

    protected function waitForSampStart(Server $server): bool
    {
        $rootPath = $this->resolveFolder($server->folder);
        if (!$rootPath || !is_dir($rootPath)) {
            return false;
        }

        $exe = $this->resolveSampExecutable($rootPath);
        if (!$exe) {
            return false;
        }

        $port = !empty($server->port) ? (int) $server->port : null;
        $maxAttempts = 60; // 30 segundos para SA-MP
        $sleepMs = 500000; // 500ms

        ActionLogService::append("[SA-MP] Waiting for server start: rootPath={$rootPath}, exe={$exe}, port={$port}, maxAttempts={$maxAttempts}");

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            usleep($sleepMs);

            if (!empty($port) && $this->isPortInUse($port)) {
                ActionLogService::append("[SA-MP] SUCCESS: Port {$port} is OPEN at attempt {$attempt} - server started");
                return true;
            }

            if (!empty($this->findProcessIds($rootPath, $exe))) {
                ActionLogService::append("[SA-MP] SUCCESS: Process {$exe} detected at attempt {$attempt}");
                return true;
            }
        }

        if (!empty($port) && $this->isPortInUse($port)) {
            ActionLogService::append("[SA-MP] SUCCESS: Port {$port} is OPEN after timeout - server started");
            return true;
        }

        ActionLogService::append("[SA-MP] FAILED: no process or port detected after {$maxAttempts} attempts");
        return false;
    }

    protected function isPathInsideRoot(string $path, string $rootPath): bool
    {
        $normalizedPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        $normalizedRoot = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $rootPath), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($normalizedPath, $normalizedRoot);
    }

    protected function getRelativePath(string $rootPath, string $path): string
    {
        $normalizedRoot = rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $rootPath), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalizedPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);

        if (str_starts_with($normalizedPath, $normalizedRoot)) {
            return substr($normalizedPath, strlen($normalizedRoot));
        }

        return $path;
    }

    protected function escapePowerShellSingleQuotedString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    protected function syncServerConfig(Server $server, string $configPath): bool
    {
        $content = file_get_contents($configPath);
        if ($content === false) {
            return false;
        }

        $lines = preg_split('/\r?\n/', $content);
        if ($lines === false) {
            return false;
        }

        $updated = false;
        $foundPort = false;
        $foundMaxplayers = false;

        foreach ($lines as &$line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $trimmed, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $key = strtolower($parts[0]);
            if ($key === 'port') {
                $foundPort = true;
                $desiredPort = (string) ((int) $server->port);
                if ($parts[1] !== $desiredPort) {
                    $line = 'port ' . $desiredPort;
                    $updated = true;
                }
            }

            if ($key === 'maxplayers') {
                $foundMaxplayers = true;
                if ($server->limit_slots !== null) {
                    $desiredSlots = (string) ((int) $server->limit_slots);
                    if ($parts[1] !== $desiredSlots) {
                        $line = 'maxplayers ' . $desiredSlots;
                        $updated = true;
                    }
                }
            }
        }
        unset($line);

        if (!$foundPort) {
            $lines[] = 'port ' . ((int) $server->port);
            $updated = true;
        }

        if ($server->limit_slots !== null && !$foundMaxplayers) {
            $lines[] = 'maxplayers ' . ((int) $server->limit_slots);
            $updated = true;
        }

        if ($updated) {
            return file_put_contents($configPath, implode(PHP_EOL, $lines)) !== false;
        }

        return true;
    }

    protected function resolveFolder(?string $folder): ?string
    {
        return $folder ? trim($folder) : null;
    }

    protected function findExecutable(string $folder, array $names): ?string
    {
        foreach ($names as $name) {
            if (file_exists($folder . DIRECTORY_SEPARATOR . $name)) {
                return $name;
            }
        }

        $files = glob($folder . DIRECTORY_SEPARATOR . '*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (!is_file($file)) {
                    continue;
                }

                $base = basename($file);
                if (preg_match('/^(samp(?:-server)?|samp03svr|sampsvr|server)(?:\.exe)?$/i', $base)) {
                    return $base;
                }
            }

            foreach ($files as $file) {
                if (is_file($file) && is_executable($file)) {
                    return basename($file);
                }
            }
        }

        return null;
    }

    protected function resolveFiveMExecutable(string $folder): ?string
    {
        $candidates = [
            'run.bat',
            'run.cmd',
            'artifacts' . DIRECTORY_SEPARATOR . 'FXServer.exe',
            'artifacts' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'FXServer.exe',
            'FXServer.exe',
        ];

        foreach ($candidates as $candidate) {
            $path = rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
            if (file_exists($path)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function resolveFiveMConfig(string $folder): ?string
    {
        // FiveM config candidates em ordem de prioridade
        $candidates = [
            'server.cfg',
            'config.cfg',
            'txData' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'server.cfg',
            'txData' . DIRECTORY_SEPARATOR . 'default' . DIRECTORY_SEPARATOR . 'config.cfg',
            'zirix-data' . DIRECTORY_SEPARATOR . 'server.cfg',
            'zirix-data' . DIRECTORY_SEPARATOR . 'config.cfg',
            'zirix-data' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'server.cfg',
            'zirix-data' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.cfg',
        ];

        foreach ($candidates as $candidate) {
            $path = rtrim($folder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
            if (file_exists($path)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function validateServerConfig(string $configPath): bool
    {
        $content = file_get_contents($configPath);
        if ($content === false) {
            return false;
        }

        // Verificar extensão do arquivo para determinar tipo de validação
        $extension = strtolower(pathinfo($configPath, PATHINFO_EXTENSION));

        if ($extension === 'cfg') {
            // Validação para arquivos .cfg (SA-MP style)
            $lines = preg_split('/\r?\n/', $content);
            $validLineCount = 0;
            $hasPort = false;

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $parts = preg_split('/\s+/', $line, 2);
                if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                    return false;
                }

                $validLineCount++;
                if (strtolower($parts[0]) === 'port') {
                    $hasPort = is_numeric($parts[1]) && (int) $parts[1] > 0;
                }
            }

            return $validLineCount > 0 && $hasPort;
        }

        // Para outros formatos (FiveM JSON, etc.), apenas verificar se tem conteúdo
        return strlen(trim($content)) > 0;
    }

    protected function isPortInUse(int $port): bool
    {
        $output = [];
        if ($this->isLinux()) {
            exec('ss -tulpn 2>/dev/null | grep -E ":' . $port . '( |$)"', $output);
        } else {
            exec('netstat -ano | findstr ":' . $port . '"', $output);
        }

        $portInUse = false;
        foreach ($output as $line) {
            if ($this->isLinux()) {
                if (preg_match('/:' . $port . '\b/', $line)) {
                    $portInUse = true;
                    break;
                }
            } else {
                // Windows: parse netstat -ano output more carefully
                $line = trim($line);
                if (preg_match('/^\s*TCP\s+([^:]+):(\d+)\s+([^:]+):(\d+)\s+(\w+)\s+(\d+)/i', $line, $matches)) {
                    // TCP line: TCP local_addr:local_port remote_addr:remote_port state pid
                    $localPort = (int) $matches[2];
                    $state = strtoupper($matches[5]);

                    if ($localPort === $port) {
                        // Only consider LISTENING state as "port in use"
                        if ($state === 'LISTENING') {
                            $portInUse = true;
                            ActionLogService::append("Port {$port} IN USE: TCP LISTENING on local port {$localPort}");
                            break;
                        } else {
                            // Log other states but don't consider as "in use"
                            ActionLogService::append("Port {$port} ignoring TCP {$state} connection (local:{$localPort} -> remote:{$matches[4]})");
                        }
                    }
                } elseif (preg_match('/^\s*UDP\s+([^:]+):(\d+)\s+\*\:\*\s+(\d+)/i', $line, $matches)) {
                    // UDP line: UDP local_addr:local_port *:* pid
                    $localPort = (int) $matches[2];

                    if ($localPort === $port) {
                        $portInUse = true;
                        ActionLogService::append("Port {$port} IN USE: UDP listening on local port {$localPort}");
                        break;
                    }
                }
            }
        }

        ActionLogService::append("Port {$port} final check: " . ($portInUse ? 'IN USE' : 'FREE') . " (found " . count($output) . " lines)");
        if (!empty($output)) {
            ActionLogService::append("Port {$port} details: " . implode(' | ', array_slice($output, 0, 3))); // Log first 3 lines
        }

        return $portInUse;
    }

    protected function getPortOwnerInfo(int $port): ?array
    {
        if (!$this->isWindows()) {
            return null;
        }

        $output = [];
        exec('netstat -ano | findstr ":' . $port . '"', $output);

        foreach ($output as $line) {
            if (preg_match('/^\s*TCP\s+([^:]+):(\d+)\s+([^:]+):(\d+)\s+(\w+)\s+(\d+)/i', $line, $matches)) {
                // TCP line: TCP local_addr:local_port remote_addr:remote_port state pid
                $localPort = (int) $matches[2];
                $state = strtoupper($matches[5]);
                $pid = (int) $matches[6];

                if ($localPort === $port) {
                    // Only return owner info if it's actually LISTENING
                    if ($state === 'LISTENING') {
                        // Get process info
                        $processOutput = [];
                        exec('wmic process where "ProcessId=' . $pid . '" get Name,CommandLine /FORMAT:CSV', $processOutput);

                        $processName = 'Unknown';
                        $commandLine = '';

                        foreach ($processOutput as $procLine) {
                            $procLine = trim($procLine);
                            if ($procLine === '' || stripos($procLine, 'Name') !== false || stripos($procLine, 'CommandLine') !== false) {
                                continue;
                            }

                            $procInfo = $this->parseWmicProcessCsvLine($procLine);
                            if ($procInfo) {
                                $processName = basename($procInfo['executablePath'] ?? 'Unknown');
                                $commandLine = $procInfo['commandLine'] ?? '';
                                break;
                            }
                        }

                        return [
                            'pid' => $pid,
                            'process_name' => $processName,
                            'command_line' => $commandLine,
                            'state' => $state,
                            'local_port' => $localPort,
                            'remote_port' => (int) $matches[4],
                            'protocol' => 'TCP'
                        ];
                    } else {
                        // Log but don't return for non-LISTENING states
                        ActionLogService::append("Port {$port} ignoring TCP {$state} connection (not LISTENING)");
                    }
                }
            } elseif (preg_match('/^\s*UDP\s+([^:]+):(\d+)\s+\*\:\*\s+(\d+)/i', $line, $matches)) {
                // UDP line: UDP local_addr:local_port *:* pid
                $localPort = (int) $matches[2];
                $pid = (int) $matches[3];

                if ($localPort === $port) {
                    // Get process info for UDP
                    $processOutput = [];
                    exec('wmic process where "ProcessId=' . $pid . '" get Name,CommandLine /FORMAT:CSV', $processOutput);

                    $processName = 'Unknown';
                    $commandLine = '';

                    foreach ($processOutput as $procLine) {
                        $procLine = trim($procLine);
                        if ($procLine === '' || stripos($procLine, 'Name') !== false || stripos($procLine, 'CommandLine') !== false) {
                            continue;
                        }

                        $procInfo = $this->parseWmicProcessCsvLine($procLine);
                        if ($procInfo) {
                            $processName = basename($procInfo['executablePath'] ?? 'Unknown');
                            $commandLine = $procInfo['commandLine'] ?? '';
                            break;
                        }
                    }

                    return [
                        'pid' => $pid,
                        'process_name' => $processName,
                        'command_line' => $commandLine,
                        'state' => 'LISTENING', // UDP doesn't have states like TCP
                        'local_port' => $localPort,
                        'remote_port' => 0,
                        'protocol' => 'UDP'
                    ];
                }
            }
        }

        return null;
    }

    public function isServerPortInUse(int $port): bool
    {
        return $this->isPortInUse($port);
    }

    protected function getFolderSize(string $folder): ?int
    {
        if (!$this->isWindows()) {
            // Linux/Mac: usar du
            $output = [];
            $cmd = 'du -sb "' . str_replace('"', '\\"', $folder) . '" 2>/dev/null';
            exec($cmd, $output);
            if (!empty($output) && preg_match('/^(\d+)/', $output[0], $matches)) {
                return (int) $matches[1];
            }
            return null;
        }

        // Windows: usar PowerShell para calcular tamanho da pasta
        $output = [];
        $escapedFolder = str_replace("'", "''", $folder);
        $cmd = "powershell -NoProfile -Command \"(Get-ChildItem -Path '$escapedFolder' -Recurse -File -ErrorAction SilentlyContinue | Measure-Object -Property Length -Sum).Sum\"";
        exec($cmd, $output);

        if (!empty($output) && is_numeric(trim($output[0]))) {
            return (int) trim($output[0]);
        }

        return null;
    }

    protected function isProcessRunningByPid(int $pid): bool
    {
        if ($this->isWindows()) {
            $output = [];
            exec('tasklist /FI "PID eq ' . $pid . '" /NH', $output);
            foreach ($output as $line) {
                if (stripos($line, (string) $pid) !== false) {
                    return true;
                }
            }
            return false;
        }

        $output = [];
        exec('ps -p ' . $pid . ' -o pid= 2>/dev/null', $output);
        return !empty($output) && trim($output[0]) == $pid;
    }
}