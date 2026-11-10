<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mensagens_saida', function (Blueprint $table) {
            $table->unique('mensagem_entrada_id');
        });
    }

    public function down(): void
    {
        Schema::table('mensagens_saida', function (Blueprint $table) {
            $table->dropUnique(['mensagem_entrada_id']);
        });
    }
};
