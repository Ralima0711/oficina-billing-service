<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A decisão do cliente chega dias depois, num POST que tem o próprio trace.
 * O evento orcamento.aprovado / orcamento.recusado precisa nascer no trace da
 * saga, não no da requisição. O traceparent do comando GerarOrcamento fica
 * guardado aqui e vira o pai do span PRODUCER na hora de publicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->string('traceparent', 128)->nullable()->after('saga_id');
        });
    }

    public function down(): void
    {
        Schema::table('orcamentos', function (Blueprint $table) {
            $table->dropColumn('traceparent');
        });
    }
};
