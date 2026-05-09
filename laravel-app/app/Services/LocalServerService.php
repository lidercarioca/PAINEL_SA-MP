<?php

namespace App\Services;

use App\Models\Server;
use App\Services\ActionLogService;

class LocalServerService
{
    public function start(Server $server): array
    {
        $folder = $this->resolveFolder($server->folder);

        if (!$folder || !is_dir($folder)) {
            return [
                'success' => false,
                'message' => 'Pasta do servidor inválida ou inexistente.'
            ];
        }

        $exe = $this->findExecutable($folder, $this->getKnownExecutables());
        if (!$exe) {
            return [
                'success' => false,
                'message' => 'Executável do servidor não encontrado.',
            ];
        }

        $configPath = $folder . DIRECTORY_SEPARATOR . 'server.cfg';
        if (!file_exists($configPath)) {
            return [
                'success' => false,
                'message' => 'Arquivo server.cfg não encontrado.',
            ];
        }

        if (!$this->validateServerConfig($configPath)) {
            return [
                'success' => false,
                'message' => 'server.cfg inválido.',
            ];
        }

        if (!$this->syncServerConfig($server, $configPath)) {
            return [
                'success' => false,
                'message' => 'Falha ao aplicar limites ao server.cfg.',
            ];
        }

        if (!empty($server->port) && $this->isPortInUse((int) $server->port)) {
            return [
                'success' => false,
                'message' => 'Porta já em uso. Verifique se outro processo está ocupando a porta do servidor.',
            ];
        }

        try {
            $existingPids = $this->findProcessIds($folder, $exe);
            if (!empty($existingPids)) {
                return [
                    'success' => true,
                    'message' => 'Servidor já estava em execução.',
                    'output' => null,
                ];
            }

            $cmd = $this->buildStartCommand($folder, $exe, $server->limit_ram);

            ActionLogService::append("Executando comando de start para servidor {$server->name} ({$server->ip}:{$server->port}): {$cmd}");
            pclose(popen($cmd, "r"));

            // Aguarda um pouco para o processo subir e valida se ele realmente começou.
            sleep(1);
            $newPids = $this->findProcessIds($folder, $exe);
            if (empty($newPids)) {
                if ($this->isProcessRunning($exe)) {
                    return [
                        'success' => true,
                        'message' => 'Servidor iniciado com sucesso.',
                        'output' => $cmd
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'Falha ao iniciar o servidor. O processo não foi encontrado após o comando de start.',
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
        $folder = $this->resolveFolder($server->folder);
        if (!$folder || !is_dir($folder)) {
            return false;
        }

        $knownExecutables = $this->getKnownExecutables();
        $exe = $this->findExecutable($folder, $knownExecutables);
        if ($exe) {
            $pids = $this->findProcessIds($folder, $exe);
            if (!empty($pids)) {
                return true;
            }
        }

        return $this->isAnyKnownProcessRunning($knownExecutables);
    }

    protected function findProcessIds(string $folder, string $exe): array
    {
        if ($this->isWindows()) {
            $output = [];
            $status = 0;
            $escapedExe = str_replace('"', '\\"', $exe);
            $escapedFolder = str_replace('"', '\\"', $folder);
            $cmd = 'wmic process where "name=\'' . $escapedExe . '\'" get ProcessId,ExecutablePath,CommandLine /FORMAT:CSV';

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

    public function getServerDiskUsage(Server $server): ?array
    {
        if ($server->type !== 'local') {
            return null;
        }

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

    public function getServerProcessCpuUsage(Server $server): ?array
    {
        if ($server->type !== 'local') {
            return null;
        }

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

    public function stop(Server $server): array
    {
        $folder = $this->resolveFolder($server->folder);

        if (!$folder || !is_dir($folder)) {
            return [
                'success' => false,
                'message' => 'Pasta do servidor inválida.'
            ];
        }

        $knownExecutables = $this->getKnownExecutables();
        $exe = $this->findExecutable($folder, $knownExecutables);
        $pids = [];

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

        try {
            $outputs = [];

            if (!empty($pids)) {
                foreach ($pids as $pid) {
                    $outputs[] = $this->killByPid($pid);
                }
            }

            foreach ($knownExecutables as $name) {
                if ($this->isProcessRunning($name)) {
                    $outputs[] = $this->killByImageName($name, true);
                }
            }

            sleep(1);
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

    public function restart(Server $server): array
    {
        $this->stop($server);
        sleep(1);
        return $this->start($server);
    }

    public function suspend(Server $server): array
    {
        return $this->stop($server);
    }

    protected function buildStartCommand(string $folder, string $exe, ?int $limitRamMb): string
    {
        $fullPath = $folder . DIRECTORY_SEPARATOR . $exe;

        if ($this->isWindows()) {
            $quotedFolder = $this->escapePowerShellSingleQuotedString($folder);
            $quotedExe = $this->escapePowerShellSingleQuotedString($fullPath);

            if ($limitRamMb === null || $limitRamMb <= 0) {
                $quotedFolder = '"' . str_replace('"', '\\"', $folder) . '"';
                $quotedFullPath = '"' . str_replace('"', '\\"', $fullPath) . '"';
                return 'start /B "" /D ' . $quotedFolder . ' ' . $quotedFullPath;
            }

            $psCommand = '& { '
                . '$limit = ' . (int) $limitRamMb . '; '
                . '$exe = ' . $quotedExe . '; '
                . '$wd = ' . $quotedFolder . '; '
                . '$proc = Start-Process -FilePath $exe -WorkingDirectory $wd -PassThru -WindowStyle Hidden; '
                . 'while (-not $proc.HasExited) { '
                . 'try { if ($proc.WorkingSet64 -gt ($limit * 1MB)) { $proc.Kill(); break } } catch {} '
                . 'Start-Sleep -Seconds 1; '
                . '} '
                . '}';

            $escapedPsCommand = str_replace('"', '""', $psCommand);
            return 'start /B "" powershell -NoProfile -WindowStyle Hidden -Command "' . $escapedPsCommand . '"';
        }

        $quotedFolder = escapeshellarg($folder);
        $quotedFullPath = escapeshellarg($fullPath);
        $command = 'cd ' . $quotedFolder . ' && chmod +x ' . $quotedFullPath . ' >/dev/null 2>&1 && nohup ' . $quotedFullPath . ' >/dev/null 2>&1 &';

        return $command;
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

    protected function validateServerConfig(string $configPath): bool
    {
        $content = file_get_contents($configPath);
        if ($content === false) {
            return false;
        }

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

    protected function isPortInUse(int $port): bool
    {
        $output = [];
        if ($this->isLinux()) {
            exec('ss -tulpn 2>/dev/null | grep -E ":' . $port . '( |$)"', $output);
        } else {
            exec('netstat -ano | findstr ":' . $port . '"', $output);
        }

        foreach ($output as $line) {
            if ($this->isLinux()) {
                if (preg_match('/:' . $port . '\b/', $line)) {
                    return true;
                }
            } else {
                if (preg_match('/^\s*TCP/i', $line) || preg_match('/^\s*UDP/i', $line)) {
                    return true;
                }
            }
        }

        return false;
    }
}