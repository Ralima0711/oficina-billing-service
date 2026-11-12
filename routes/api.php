<?php

use App\Http\Controllers\OrcamentoController;
use App\Http\Controllers\PagamentoController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::post('/orcamentos', [OrcamentoController::class, 'store']);
Route::get('/orcamentos/{id}', [OrcamentoController::class, 'show']);

// Decisao do cliente sobre o orcamento. O contrato de eventos declara
// orcamento.aprovado e orcamento.recusado como evento vindo do Billing, e e
// daqui que eles saem: esta rota grava a decisao e o evento no outbox, na
// mesma transacao, e o OS Service anda a saga ao consumir o evento.
Route::post('/orcamentos/{id}/decisao', [OrcamentoController::class, 'decidir']);
Route::post('/orcamentos/{id}/pagamentos', [PagamentoController::class, 'store']);
Route::get('/pagamentos/{id}', [PagamentoController::class, 'show']);
