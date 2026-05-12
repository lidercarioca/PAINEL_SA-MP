<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\RconController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\FiveMResourceController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\SecurityController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('api.token')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
    Route::post('/users/{id}/toggle-block', [UserController::class, 'toggleBlock']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::get('/users/{id}/activity', [UserController::class, 'activity']);

    Route::get('/servers', [ServerController::class, 'index']);
    Route::post('/servers/create', [ServerController::class, 'create']);
    Route::post('/servers/update', [ServerController::class, 'update']);
    Route::post('/servers/delete', [ServerController::class, 'delete']);
    Route::post('/servers/start', [ServerController::class, 'start']);
    Route::post('/servers/stop', [ServerController::class, 'stop']);
    Route::post('/servers/restart', [ServerController::class, 'restart']);
    Route::post('/servers/suspend', [ServerController::class, 'suspend']);
    Route::post('/servers/command', [ServerController::class, 'command']);
    Route::post('/servers/{serverId}/backup', [ServerController::class, 'createBackup']);
    Route::get('/servers/{serverId}/backups', [ServerController::class, 'listBackups']);
    Route::delete('/servers/{serverId}/backups/{backupName}', [ServerController::class, 'deleteBackup'])->where('backupName', '.*');
    Route::get('/servers/{serverId}/backups/{backupName}', [ServerController::class, 'downloadBackup'])->where('backupName', '.*');
    Route::post('/rcon/send', [RconController::class, 'send']);
    Route::get('/plans', [PlanController::class, 'index']);
    Route::post('/plans', [PlanController::class, 'store']);
    Route::put('/plans/{id}', [PlanController::class, 'update']);
    Route::delete('/plans/{id}', [PlanController::class, 'destroy']);
    Route::get('/security', [SecurityController::class, 'index']);

    Route::get('/resources', [ResourceController::class, 'index']);
    Route::get('/servers/{serverId}/players', [ServerController::class, 'players']);
    Route::get('/servers/{serverId}/stats', [ServerController::class, 'stats']);
    Route::get('/servers/{serverId}/status', [ServerController::class, 'status']);
    Route::get('/servers/{serverId}/fivem/resources', [FiveMResourceController::class, 'index']);
    Route::post('/servers/{serverId}/fivem/resources/action', [FiveMResourceController::class, 'action']);
    Route::post('/servers/{serverId}/fivem/resources/{action}', [FiveMResourceController::class, 'action'])
        ->where('action', 'ensure|start|stop|restart');
    Route::get('/fivem/resources/{serverId}', [FiveMResourceController::class, 'index']);
    Route::post('/fivem/resources/{serverId}/action', [FiveMResourceController::class, 'action']);
    Route::post('/fivem/resources/{serverId}/{action}', [FiveMResourceController::class, 'action'])
        ->where('action', 'ensure|start|stop|restart');
    Route::get('/servers/{serverId}/console-stream', [LogController::class, 'stream']);
    Route::get('/logs', [LogController::class, 'index']);
    Route::get('/files', [FileController::class, 'index']);
    Route::post('/files/upload', [FileController::class, 'upload']);
    Route::post('/files/folder', [FileController::class, 'createFolder']);
    Route::post('/upload', [FileController::class, 'upload']);
    Route::post('/rename', [FileController::class, 'rename']);
    Route::post('/move', [FileController::class, 'move']);
    Route::post('/compile', [FileController::class, 'compile']);
    Route::post('/restart-server', [ServerController::class, 'restart']);
    Route::get('/files/{name}', [FileController::class, 'show'])->where('name', '.*');
    Route::put('/files/{name}', [FileController::class, 'update'])->where('name', '.*');
    Route::delete('/files/{name}', [FileController::class, 'destroy'])->where('name', '.*');
});
