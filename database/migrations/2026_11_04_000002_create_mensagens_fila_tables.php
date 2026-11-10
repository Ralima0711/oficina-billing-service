<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mensagens_processadas', function (Blueprint $table) {
            $table->uuid('message_id')->primary();
            $table->string('tipo', 64);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('mensagens_saida', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('mensagem_entrada_id')->constrained('mensagens_processadas', 'message_id');
            $table->string('routing_key', 64);
            $table->json('envelope');
            $table->timestamp('publicado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensagens_saida');
        Schema::dropIfExists('mensagens_processadas');
    }
};
