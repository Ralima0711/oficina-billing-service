<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O orcamento passa a guardar a saga que o pediu.
 *
 * Sem esta coluna, a decisao do cliente nao tem como voltar para a saga: o
 * evento orcamento.aprovado precisa de header.sagaId, e o Orchestrator do OS
 * descarta todo evento cuja saga ele nao reconhece como EM_ANDAMENTO
 * (Orchestrator::sagaAtiva). Ate aqui o sagaId so existia em transito, no
 * header do comando GerarOrcamento, e se perdia quando o comando terminava.
 *
 * Nullable de proposito: o POST /api/orcamentos gera orcamento sem saga
 * nenhuma (e porta de teste e de orcamento avulso). Orcamento sem saga nao
 * pode ser decidido pela API — o controller devolve 409 nesse caso, em vez de
 * publicar um evento que ninguem consumiria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->uuid('saga_id')->nullable()->after('os_id');
            $table->index('saga_id');
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropIndex(['saga_id']);
            $table->dropColumn('saga_id');
        });
    }
};
