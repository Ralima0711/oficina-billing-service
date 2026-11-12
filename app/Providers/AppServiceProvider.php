<?php

namespace App\Providers;

use App\Mensageria\ConexaoRabbit;
use App\Mensageria\PublicadorEventos;
use App\Mensageria\PublicadorRabbitMq;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * PublicadorEventos e uma interface, e ate 12/11 ela nao tinha
         * binding nenhum no container: o unico lugar que a registrava era o
         * `fila:consumir`, com $this->laravel->instance(). Funcionava porque
         * so o worker publicava.
         *
         * No instante em que uma rota HTTP precisa publicar — a decisao do
         * cliente sobre o orcamento — resolver CaixaDeSaida pelo container
         * estoura com "Target [PublicadorEventos] is not instantiable", e o
         * endpoint devolve 500. Os testes nao pegariam isso sozinhos: eles
         * injetam o PublicadorFake antes, entao passariam com a aplicacao
         * quebrada.
         *
         * O singleton e preguicoso de proposito: a closure so roda quando
         * alguem resolve PublicadorEventos. Uma requisicao que so le ou gera
         * orcamento nunca abre conexao com o broker.
         */
        $this->app->singleton(PublicadorEventos::class, function () {
            $conexao = ConexaoRabbit::abrir();

            return new PublicadorRabbitMq(
                $conexao->channel(),
                (string) config('mensageria.exchange_eventos'),
            );
        });
    }

    public function boot(): void
    {
        //
    }
}
