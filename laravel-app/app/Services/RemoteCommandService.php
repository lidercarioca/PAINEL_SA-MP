<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use phpseclib3\Net\SSH2;

class RemoteCommandService
{
    public function executeLocal(array $command, int $timeout = 10): array
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->run();

        return [
            'success' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
        ];
    }

    public function executeSsh(string $host, string $username, string $password, string $command): array
    {
        $ssh = new SSH2($host);
        if (!$ssh->login($username, $password)) {
            return [
                'success' => false,
                'output' => '',
                'error' => 'SSH authentication failed',
            ];
        }

        $output = $ssh->exec($command);

        return [
            'success' => true,
            'output' => $output,
            'error' => '',
        ];
    }
}
