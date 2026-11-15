<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * O link de checkout que funciona com credencial de usuario de teste do
 * Mercado Pago e o init_point (www.mercadopago.com.br). O sandbox_init_point
 * responde "Ops, ocorreu um erro" nesse tipo de credencial - verificado em
 * 15/11 com o comprador de teste. Coluna nova e nullable: pagamentos antigos
 * continuam validos e ganham o link na proxima preferencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagamentos', function (Blueprint $table) {
            $table->text('init_point')->nullable()->after('sandbox_init_point');
        });
    }

    public function down(): void
    {
        Schema::table('pagamentos', function (Blueprint $table) {
            $table->dropColumn('init_point');
        });
    }
};
