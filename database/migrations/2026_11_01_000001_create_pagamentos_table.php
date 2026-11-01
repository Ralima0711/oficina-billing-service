<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagamentos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('orcamento_id')->constrained('orcamentos')->cascadeOnDelete();
            $table->uuid('os_id');
            $table->string('preference_id');
            $table->string('payment_id')->nullable();
            $table->string('status', 16)->default('pendente');
            $table->decimal('valor', 12, 2);
            $table->string('moeda', 3)->default('BRL');
            $table->string('metodo', 16)->nullable();
            $table->string('motivo')->nullable();
            $table->text('sandbox_init_point')->nullable();
            $table->timestamp('confirmado_em')->nullable();
            $table->timestamps();

            $table->index('os_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamentos');
    }
};
