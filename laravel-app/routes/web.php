<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

$buildPath = base_path('frontend/build');

Route::get('/static/{path}', function ($path) use ($buildPath) {
    $file = $buildPath . '/static/' . $path;

    if (!File::exists($file)) {
        abort(404);
    }

    $extension = pathinfo($file, PATHINFO_EXTENSION);
    $mimeTypes = [
        'js' => 'application/javascript',
        'css' => 'text/css',
        'map' => 'application/json',
        'json' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'ico' => 'image/x-icon',
    ];

    $contentType = $mimeTypes[$extension] ?? File::mimeType($file) ?? 'application/octet-stream';

    return response(File::get($file), 200, [
        'Content-Type' => $contentType,
        'Cache-Control' => 'public, max-age=31536000, immutable',
    ]);
})->where('path', '.*');

Route::get('/asset-manifest.json', function () use ($buildPath) {
    $file = $buildPath . '/asset-manifest.json';
    if (!File::exists($file)) {
        abort(404);
    }
    return response(File::get($file), 200, ['Content-Type' => 'application/json']);
});

Route::get('/favicon.ico', function () use ($buildPath) {
    $file = $buildPath . '/favicon.ico';
    if (!File::exists($file)) {
        abort(404);
    }
    return response(File::get($file), 200, ['Content-Type' => 'image/x-icon']);
});

Route::get('/manifest.json', function () use ($buildPath) {
    $file = $buildPath . '/manifest.json';
    if (!File::exists($file)) {
        abort(404);
    }
    return response(File::get($file), 200, ['Content-Type' => 'application/json']);
});

Route::get('/{any?}', function () use ($buildPath) {
    $file = $buildPath . '/index.html';
    if (!File::exists($file)) {
        abort(404, 'Frontend build não encontrado. Rode npm run build em frontend.');
    }

    return response(File::get($file), 200, ['Content-Type' => 'text/html']);
})->where('any', '.*');
