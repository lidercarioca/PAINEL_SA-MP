<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class ResourceController extends Controller
{
    public function index()
    {
        $platform = PHP_OS_FAMILY;
        $stats = [
            'platform' => $platform,
            'cpu' => null,
            'memory' => null,
            'disk' => null,
        ];

        if ($platform === 'Linux') {
            $stats['memory'] = $this->getLinuxMemory();
            $stats['cpu'] = $this->getLinuxCpu();
            $stats['disk'] = $this->getLinuxDisk();
        } elseif ($platform === 'Windows') {
            $stats['memory'] = $this->getWindowsMemory();
            $stats['cpu'] = $this->getWindowsCpu();
            $stats['disk'] = $this->getWindowsDisk();
        }

        return response()->json($stats);
    }

    private function getLinuxMemory(): array
    {
        $data = @file_get_contents('/proc/meminfo');
        $memory = [
            'total' => null,
            'available' => null,
            'used' => null,
            'percent' => null,
        ];

        if (!$data) {
            return $memory;
        }

        $lines = explode("\n", $data);
        $values = [];
        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z_]+):\s+(\d+)\s+kB$/', $line, $matches)) {
                $values[$matches[1]] = (int) $matches[2];
            }
        }

        if (isset($values['MemTotal'], $values['MemAvailable'])) {
            $memory['total'] = $values['MemTotal'] * 1024;
            $memory['available'] = $values['MemAvailable'] * 1024;
            $memory['used'] = $memory['total'] - $memory['available'];
            $memory['percent'] = $memory['total'] > 0 ? round(($memory['used'] / $memory['total']) * 100, 1) : null;
        }

        return $memory;
    }

    private function getLinuxCpu(): array
    {
        $first = $this->readLinuxCpuStat();
        if (!$first) {
            return ['percent' => null];
        }

        usleep(200000);
        $second = $this->readLinuxCpuStat();
        if (!$second) {
            return ['percent' => null];
        }

        $idleDelta = $second['idle'] - $first['idle'];
        $totalDelta = $second['total'] - $first['total'];

        $percent = null;
        if ($totalDelta > 0) {
            $percent = round((1 - ($idleDelta / $totalDelta)) * 100, 1);
        }

        return [
            'percent' => $percent,
        ];
    }

    private function readLinuxCpuStat(): ?array
    {
        $data = @file_get_contents('/proc/stat');
        if (!$data) {
            return null;
        }

        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            if (strpos($line, 'cpu ') === 0) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) < 5) {
                    return null;
                }

                $user = (int) $parts[1];
                $nice = (int) $parts[2];
                $system = (int) $parts[3];
                $idle = (int) $parts[4];
                $iowait = isset($parts[5]) ? (int) $parts[5] : 0;
                $irq = isset($parts[6]) ? (int) $parts[6] : 0;
                $softirq = isset($parts[7]) ? (int) $parts[7] : 0;
                $steal = isset($parts[8]) ? (int) $parts[8] : 0;

                $total = $user + $nice + $system + $idle + $iowait + $irq + $softirq + $steal;

                return [
                    'idle' => $idle + $iowait,
                    'total' => $total,
                ];
            }
        }

        return null;
    }

    private function getWindowsCpu(): array
    {
        $value = $this->getWindowsCpuFromWmic();
        if ($value === null) {
            $value = $this->getWindowsCpuFromProcessTime();
        }
        if ($value === null) {
            $value = $this->getWindowsCpuFromPowerShell();
        }
        if ($value === null) {
            $value = $this->getWindowsCpuFromTypeperf();
        }

        return ['percent' => $value];
    }

    private function getWindowsCpuFromWmic(): ?int
    {
        $output = [];
        @exec('wmic cpu get loadpercentage /value', $output);

        foreach ($output as $line) {
            if (preg_match('/LoadPercentage=(\d+)/i', $line, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function getWindowsCpuFromPowerShell(): ?int
    {
        $output = [];
        @exec('powershell -NoProfile -Command "(Get-CimInstance Win32_Processor | Select-Object -ExpandProperty LoadPercentage)"', $output);

        foreach ($output as $line) {
            if (preg_match('/^(\d+)$/', trim($line), $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function getWindowsCpuFromTypeperf(): ?int
    {
        $output = [];
        @exec('typeperf "\\Processor(_Total)\\% Processor Time" -sc 1', $output, $exitCode);
        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        $lines = array_reverse($output);
        foreach ($lines as $line) {
            $value = str_replace(',', '.', trim($line));
            if (preg_match('/^"?[^\"]*?([0-9]+(?:[\.,][0-9]+)?)"?$/', $value, $matches)) {
                return (int) round((float) str_replace(',', '.', $matches[1]));
            }
        }

        return null;
    }

    private function getWindowsCpuFromProcessTime(): ?int
    {
        $first = $this->getWindowsProcessCpuTotal();
        if ($first === null) {
            return null;
        }

        sleep(1);

        $second = $this->getWindowsProcessCpuTotal();
        if ($second === null) {
            return null;
        }

        $processors = $this->getWindowsLogicalProcessorCount();
        if ($processors <= 0) {
            return null;
        }

        $delta = $second - $first;
        $percent = ($delta / $processors) * 100;

        return $percent >= 0 ? (int) round($percent) : null;
    }

    private function getWindowsProcessCpuTotal(): ?float
    {
        $output = [];
        @exec('powershell -NoProfile -Command "(Get-Process | Measure-Object -Property CPU -Sum).Sum"', $output);

        foreach ($output as $line) {
            $value = str_replace(',', '.', trim($line));
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function getWindowsLogicalProcessorCount(): int
    {
        $output = [];
        @exec('powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystem).NumberOfLogicalProcessors"', $output);

        foreach ($output as $line) {
            if (is_numeric(trim($line))) {
                return (int) trim($line);
            }
        }

        return 0;
    }

    private function getWindowsMemory(): array
    {
        $values = $this->getWindowsMemoryFromWmic();
        if ($values === null) {
            $values = $this->getWindowsMemoryFromPowerShell();
        }

        if ($values !== null) {
            $total = $values['TotalVisibleMemorySize'] * 1024;
            $free = $values['FreePhysicalMemory'] * 1024;
            $used = $total - $free;
            return [
                'total' => $total,
                'available' => $free,
                'used' => $used,
                'percent' => $total > 0 ? round(($used / $total) * 100, 1) : null,
            ];
        }

        return ['total' => null, 'available' => null, 'used' => null, 'percent' => null];
    }

    private function getWindowsMemoryFromWmic(): ?array
    {
        $output = [];
        @exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /value', $output);
        $values = [];

        foreach ($output as $line) {
            if (preg_match('/^(FreePhysicalMemory|TotalVisibleMemorySize)=(\d+)/i', $line, $matches)) {
                $values[$matches[1]] = (int) $matches[2];
            }
        }

        if (isset($values['FreePhysicalMemory'], $values['TotalVisibleMemorySize'])) {
            return $values;
        }

        return null;
    }

    private function getWindowsMemoryFromPowerShell(): ?array
    {
        $output = [];
        @exec('powershell -NoProfile -Command "Get-CimInstance Win32_OperatingSystem | Select-Object FreePhysicalMemory,TotalVisibleMemorySize | ConvertTo-Json"', $output);
        $json = trim(implode("\n", $output));

        if (!$json) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $free = isset($data['FreePhysicalMemory']) ? (int) $data['FreePhysicalMemory'] : null;
        $total = isset($data['TotalVisibleMemorySize']) ? (int) $data['TotalVisibleMemorySize'] : null;

        if ($free !== null && $total !== null) {
            return ['FreePhysicalMemory' => $free, 'TotalVisibleMemorySize' => $total];
        }

        return null;
    }

    private function getLinuxDisk(): array
    {
        $path = '/';
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false) {
            return ['total' => null, 'free' => null, 'used' => null, 'percent' => null];
        }

        $used = $total - $free;
        return [
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'percent' => $total > 0 ? round(($used / $total) * 100, 1) : null,
        ];
    }

    private function getWindowsDisk(): array
    {
        $drive = getenv('SystemDrive') ?: 'C:';
        $path = $drive . '\\';
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false) {
            return ['total' => null, 'free' => null, 'used' => null, 'percent' => null];
        }

        $used = $total - $free;
        return [
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'percent' => $total > 0 ? round(($used / $total) * 100, 1) : null,
        ];
    }
}
