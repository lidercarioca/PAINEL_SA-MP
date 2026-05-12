<?php

namespace App\Services;

class SampRconService
{
    public function sendCommand(string $ip, int $port, string $password, string $command): bool
    {
        $socket = @fsockopen("udp://{$ip}", $port, $errno, $errstr, 2);
        if (!$socket) {
            return false;
        }

        $packet = $this->buildPacket($ip, $port, $password, $command);
        fwrite($socket, $packet);
        fclose($socket);

        return true;
    }

    public function sendCommandWithResponse(string $ip, int $port, string $password, string $command, int $timeout = 2): ?string
    {
        $result = $this->sendCommandWithResponseDetailed($ip, $port, $password, $command, $timeout);
        return $result['raw_response'];
    }

    public function sendCommandWithResponseDetailed(string $ip, int $port, string $password, string $command, int $timeout = 2): array
    {
        $socket = @fsockopen("udp://{$ip}", $port, $errno, $errstr, $timeout);
        if (!$socket) {
            return [
                'status' => 'connection_failed',
                'raw_response' => null,
                'error' => $errstr ?: 'Falha ao conectar',
            ];
        }

        $packet = $this->buildPacket($ip, $port, $password, $command);
        fwrite($socket, $packet);

        stream_set_timeout($socket, $timeout, 0);
        $response = '';

        while (!feof($socket)) {
            $chunk = @fread($socket, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            $info = stream_get_meta_data($socket);
            if ($info['timed_out']) {
                break;
            }
        }

        fclose($socket);

        if ($response === '') {
            return [
                'status' => 'empty_response',
                'raw_response' => null,
                'error' => 'Resposta vazia do RCON',
            ];
        }

        if (substr($response, 0, 4) === 'SAMP') {
            $rawResponse = rtrim(substr($response, 10), "\0");
        } else {
            $rawResponse = rtrim($response, "\0");
        }

        return [
            'status' => 'ok',
            'raw_response' => $rawResponse,
            'error' => null,
        ];
    }

    protected function buildPacket(string $ip, int $port, string $password, string $command): string
    {
        $packet = 'SAMP';
        $packet .= pack('N', ip2long($ip));
        $packet .= pack('n', $port);
        $packet .= 'rcon';
        $packet .= chr(strlen($password)) . $password;
        $packet .= chr(strlen($command)) . $command;

        return $packet;
    }
}
