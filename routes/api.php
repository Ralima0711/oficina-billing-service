<?php

use App\Http\Controllers\OrcamentoController;
use App\Http\Controllers\PagamentoController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::post('/orcamentos', [OrcamentoController::class, 'store']);
Route::get('/orcamentos/{id}', [OrcamentoController::class, 'show']);
Route::post('/orcamentos/{id}/pagamentos', [PagamentoController::class, 'store']);
Route::get('/pagamentos/{id}', [PagamentoController::class, 'show']);
