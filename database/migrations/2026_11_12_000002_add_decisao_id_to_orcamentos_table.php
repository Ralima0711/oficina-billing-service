<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O orcamento passa a guardar qual decisao o decidiu.
 *
 * Sem isso, uma decisao que comitou e nao chegou a publicar fica orfa: a
 * linha em mensagens_saida existe com publicado_em nulo, e nada sabe o
 * mensagem_entrada_id dela para republicar. A segunda chamada cai no 409 de
 * "ja decidido" e confirma a perda em vez de corrigi-la.
 *
 * No consumo de fila esse caso se resolve sozinho porque o broker reentrega a
 * mesma messageId. Numa decisao que nasce no HTTP nao ha quem reentregue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->uuid('decisao_id')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropColumn('decisao_id');
        });
    }
};
