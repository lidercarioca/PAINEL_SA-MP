<?php

namespace App\Engines;

interface EngineInterface
{
    /**
     * Inicia o servidor
     * 
     * @param string $folder Caminho da pasta do servidor
     * @return bool
     */
    public function start(string $folder): bool;

    /**
     * Para o servidor
     * 
     * @return bool
     */
    public function stop(): bool;

    /**
     * Reinicia o servidor
     * 
     * @param string $folder Caminho da pasta do servidor
     * @return bool
     */
    public function restart(string $folder): bool;

    /**
     * Obtém os logs do servidor
     * 
     * @param string $folder Caminho da pasta do servidor
     * @return string
     */
    public function getLogs(string $folder): string;

    /**
     * Envia comando ao servidor
     * 
     * @param string $command
     * @return bool|string
     */
    public function sendCommand(string $command);

    /**
     * Obtém status do servidor
     * 
     * @param string $folder Caminho da pasta do servidor
     * @param int|null $port Porta a ser usada para verificação (opcional)
     * @return array
     */
    public function getStatus(string $folder, ?int $port = null): array;

    /**
     * Detecta automaticamente se é este engine
     * 
     * @param string $executable Arquivo executável do servidor
     * @param string $folder Pasta do servidor
     * @return bool
     */
    public static function detect(string $executable, string $folder): bool;
}
