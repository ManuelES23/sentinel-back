<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'SENTINEL 3.0 API',
        'version' => '1.0.0',
        'status' => 'running',
        'documentation' => '/api',
    ]);
});
