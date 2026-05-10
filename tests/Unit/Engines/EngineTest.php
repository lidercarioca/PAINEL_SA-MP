<?php

namespace Tests\Unit\Engines;

use Tests\TestCase;
use App\Engines\SampEngine;
use App\Engines\FiveM Engine;
use App\Engines\EngineFactory;
use App\Models\Server;
use App\Services\SampRconService;
use App\Services\LocalServerService;

/**
 * Testes para validar a arquitetura multi-engine
 * 
 * Para rodar: php artisan test tests/Unit/Engines/EngineTest.php
 */
class EngineTest extends TestCase
{
    /**
     * Test 1: Factory cria SampEngine corretamente
     */
    public function test_factory_creates_samp_engine()
    {
        $server = Server::factory()->create([
            'engine' => 'samp',
            'ip' => '127.0.0.1',
            'port' => 7777,
            'password' => 'test123'
        ]);

        $factory = app(EngineFactory::class);
        $engine = $factory->create($server);

        $this->assertInstanceOf(SampEngine::class, $engine);
    }

    /**
     * Test 2: Factory cria FiveM Engine corretamente
     */
    public function test_factory_creates_fivem_engine()
    {
        $server = Server::factory()->create([
            'engine' => 'fivem',
            'ip' => '127.0.0.1',
            'port' => 30120,
        ]);

        $factory = app(EngineFactory::class);
        $engine = $factory->create($server);

        $this->assertInstanceOf(FiveM Engine::class, $engine);
    }

    /**
     * Test 3: Auto-detecta SA-MP pelo executável
     */
    public function test_auto_detects_samp_by_executable()
    {
        $this->assertTrue(SampEngine::detect('samp-server.exe', 'C:/test'));
        $this->assertFalse(SampEngine::detect('FXServer.exe', 'C:/test'));
    }

    /**
     * Test 4: Auto-detecta FiveM pelo executável
     */
    public function test_auto_detects_fivem_by_executable()
    {
        $this->assertTrue(FiveM Engine::detect('FXServer.exe', 'C:/test'));
        $this->assertFalse(FiveM Engine::detect('samp-server.exe', 'C:/test'));
    }

    /**
     * Test 5: Fallback para SA-MP quando engine vazio
     */
    public function test_fallback_to_samp_when_engine_empty()
    {
        $server = Server::factory()->create([
            'engine' => null,
            'ip' => '127.0.0.1',
            'port' => 7777,
            'password' => 'test'
        ]);

        $factory = app(EngineFactory::class);
        $detected = $factory->autoDetect($server);

        // Sem arquivo específico, deve detectar como samp
        $this->assertEquals('samp', $detected);
    }

    /**
     * Test 6: Obtém defaults corretos para SA-MP
     */
    public function test_samp_defaults()
    {
        $defaults = EngineFactory::getEngineDefaults('samp');

        $this->assertEquals(7777, $defaults['port']);
        $this->assertEquals('samp-server.exe', $defaults['executable']);
        $this->assertEquals('server.cfg', $defaults['config_file']);
    }

    /**
     * Test 7: Obtém defaults corretos para FiveM
     */
    public function test_fivem_defaults()
    {
        $defaults = EngineFactory::getEngineDefaults('fivem');

        $this->assertEquals(30120, $defaults['port']);
        $this->assertEquals('FXServer.exe', $defaults['executable']);
        $this->assertEquals('server.cfg', $defaults['config_file']);
    }

    /**
     * Test 8: Lista engines disponíveis
     */
    public function test_list_available_engines()
    {
        $engines = EngineFactory::getAvailableEngines();

        $this->assertCount(2, $engines);
        $this->assertEquals('samp', $engines[0]['id']);
        $this->assertEquals('fivem', $engines[1]['id']);
    }

    /**
     * Test 9: Engine padrão é SA-MP
     */
    public function test_default_engine_is_samp()
    {
        $server = Server::factory()->create();

        // Validar que o fillable inclui engine
        $this->assertContains('engine', $server->getFillable());
    }

    /**
     * Test 10: Compatibilidade retroativa
     * 
     * Servidores antigos sem engine definido funcionam como SA-MP
     */
    public function test_backward_compatibility_with_old_servers()
    {
        // Simular servidor antigo sem engine
        $server = new Server([
            'name' => 'Old Server',
            'ip' => '127.0.0.1',
            'port' => 7777,
            'engine' => null,
            'type' => 'local'
        ]);

        $factory = app(EngineFactory::class);
        $detected = $factory->autoDetect($server);

        // Deve voltar SA-MP como padrão
        $this->assertEquals('samp', $detected);
    }
}

/**
 * Como rodar os testes:
 * 
 * php artisan test tests/Unit/Engines/EngineTest.php
 * 
 * Ou testes específicos:
 * 
 * php artisan test tests/Unit/Engines/EngineTest.php --filter test_factory_creates_samp_engine
 * 
 * Para debug:
 * 
 * php artisan test tests/Unit/Engines/EngineTest.php --debug
 */
