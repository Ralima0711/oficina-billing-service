<?php

use App\Http\Controllers\OrcamentoController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::post('/orcamentos', [OrcamentoController::class, 'store']);
Route::get('/orcamentos/{id}', [OrcamentoController::class, 'show']);
