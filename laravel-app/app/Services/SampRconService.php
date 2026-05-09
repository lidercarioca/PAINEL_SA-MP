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
        $socket = @fsockopen("udp://{$ip}", $port, $errno, $errstr, $timeout);
        if (!$socket) {
            return null;
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
            return null;
        }

        if (substr($response, 0, 4) === 'SAMP') {
            return rtrim(substr($response, 10), "\0");
        }

        return rtrim($response, "\0");
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
