<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pagamentos', function (Blueprint $table) {
            $table->string('preference_id')->nullable()->change();
            $table->unsignedSmallInteger('tentativas')->default(0);
        });

        DB::statement("CREATE UNIQUE INDEX pagamentos_orcamento_ativo_unique ON pagamentos (orcamento_id) WHERE status IN ('pendente', 'confirmado')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX pagamentos_orcamento_ativo_unique');

        Schema::table('pagamentos', function (Blueprint $table) {
            $table->dropColumn('tentativas');
        });
    }
};
