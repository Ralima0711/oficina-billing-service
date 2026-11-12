<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Guarda contra rodar a suite em cima de um banco de verdade.
     *
     * Toda feature deste repositorio usa RefreshDatabase, e o RefreshDatabase
     * roda `migrate:fresh`: DERRUBA todas as tabelas da conexao ativa, sem
     * perguntar e sem olhar para onde esta apontando.
     *
     * O phpunit.xml define DB_CONNECTION=sqlite, mas <env> do PHPUnit NAO
     * sobrepoe variavel de ambiente ja definida — e documentado. Dentro do
     * container do compose, que exporta DB_CONNECTION=pgsql e
     * DB_DATABASE=billing_service, um `php artisan test` distraido apagaria o
     * banco de desenvolvimento com os orcamentos e pagamentos dentro. Foi
     * exatamente isso que aconteceu com o OS Service em 11/11.
     *
     * Entao: Postgres so com banco terminado em _test ou _teste. O resto passa.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $banco = (string) DB::connection()->getDatabaseName();

        if (! str_ends_with($banco, '_test') && ! str_ends_with($banco, '_teste')) {
            $this->fail(
                "RECUSADO: a suite ia rodar migrate:fresh no banco '{$banco}', que nao termina "
                ."em '_test'. O RefreshDatabase DERRUBA todas as tabelas. Rode com "
                .'DB_CONNECTION=sqlite e DB_DATABASE=:memory:, que e para o que esta suite foi '
                ."escrita, ou aponte para um banco dedicado (DB_DATABASE={$banco}_test)."
            );
        }
    }
}
