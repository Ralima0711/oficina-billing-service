<?php

namespace Tests\Feature;

use App\Mensageria\PublicadorEventos;
use App\Mensageria\PublicadorRabbitMq;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\TestCase;

class ConexaoBrokerTest extends TestCase
{
    public function test_publicador_do_container_abre_um_canal_so_e_usa_o_exchange_configurado(): void
    {
        config(['mensageria.exchange_eventos' => 'oficina.eventos.teste']);

        $canal = Mockery::mock(AMQPChannel::class);
        $canal->shouldReceive('basic_publish')
            ->once()
            ->with(Mockery::type(AMQPMessage::class), 'oficina.eventos.teste', 'orcamento.aprovado');

        $conexao = Mockery::mock(AbstractConnection::class);
        $conexao->shouldReceive('channel')->once()->andReturn($canal);
        $this->app->instance(AbstractConnection::class, $conexao);

        $publicador = $this->app->make(PublicadorEventos::class);

        $this->assertInstanceOf(PublicadorRabbitMq::class, $publicador);
        $this->assertSame($publicador, $this->app->make(PublicadorEventos::class));

        $publicador->publicar('orcamento.aprovado', ['header' => ['messageId' => 'x'], 'body' => []], null);
    }

    public function test_conexao_rabbit_conecta_no_host_e_porta_configurados(): void
    {
        // Porta 1 em loopback: nada escuta ali, a recusa e imediata.
        config([
            'mensageria.host' => '127.0.0.1',
            'mensageria.port' => 1,
        ]);

        try {
            $this->app->make(AbstractConnection::class);
            $this->fail('sem broker na porta 1 a conexao deveria falhar');
        } catch (AMQPIOException $e) {
            $this->assertStringContainsString('127.0.0.1:1', $e->getMessage());
        }
    }
}
