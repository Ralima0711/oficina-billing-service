<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orcamentos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('os_id');
            $table->uuid('cliente_id');
            $table->text('descricao_problema');
            $table->json('veiculo');
            $table->decimal('valor_total', 12, 2);
            $table->string('moeda', 3)->default('BRL');
            $table->timestamp('valido_ate');
            $table->string('status', 32)->default('gerado');
            $table->timestamps();

            $table->index('os_id');
        });

        Schema::create('orcamento_itens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('orcamento_id')->constrained('orcamentos')->cascadeOnDelete();
            $table->string('descricao');
            $table->string('tipo', 16);
            $table->unsignedInteger('quantidade');
            $table->decimal('valor_unitario', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orcamento_itens');
        Schema::dropIfExists('orcamentos');
    }
};
