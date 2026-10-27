<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogo_precos', function (Blueprint $table) {
            $table->id();
            $table->string('descricao');
            $table->string('tipo', 16);
            $table->string('codigo')->nullable();
            $table->decimal('preco_unitario', 12, 2);
            $table->timestamps();

            $table->unique(['descricao', 'tipo']);
        });

        $agora = now();

        // Preços que já aparecem nos testes e no Swagger da API da Fase 3.
        // Peça e insumo viram "peca"; serviço (preco_base) vira "servico".
        DB::table('catalogo_precos')->insert([
            ['descricao' => 'Filtro de Óleo', 'tipo' => 'peca', 'codigo' => 'FO-001', 'preco_unitario' => 45.90, 'created_at' => $agora, 'updated_at' => $agora],
            ['descricao' => 'Parafuso', 'tipo' => 'peca', 'codigo' => 'PA-001', 'preco_unitario' => 1.50, 'created_at' => $agora, 'updated_at' => $agora],
            ['descricao' => 'Óleo Motor 5W30', 'tipo' => 'peca', 'codigo' => null, 'preco_unitario' => 25.90, 'created_at' => $agora, 'updated_at' => $agora],
            ['descricao' => 'Graxa', 'tipo' => 'peca', 'codigo' => null, 'preco_unitario' => 12.00, 'created_at' => $agora, 'updated_at' => $agora],
            ['descricao' => 'Troca de Óleo', 'tipo' => 'servico', 'codigo' => 'TROCA_OLEO_001', 'preco_unitario' => 150.00, 'created_at' => $agora, 'updated_at' => $agora],
            ['descricao' => 'Alinhamento', 'tipo' => 'servico', 'codigo' => 'ALIN_001', 'preco_unitario' => 100.00, 'created_at' => $agora, 'updated_at' => $agora],
            ['descricao' => 'Balanceamento', 'tipo' => 'servico', 'codigo' => 'BALAN_001', 'preco_unitario' => 80.00, 'created_at' => $agora, 'updated_at' => $agora],
            ['descricao' => 'Diagnóstico', 'tipo' => 'servico', 'codigo' => 'DIAG_001', 'preco_unitario' => 200.00, 'created_at' => $agora, 'updated_at' => $agora],
            ['descricao' => 'Diagnóstico Completo', 'tipo' => 'servico', 'codigo' => 'DIAG_001', 'preco_unitario' => 250.00, 'created_at' => $agora, 'updated_at' => $agora],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogo_precos');
    }
};
