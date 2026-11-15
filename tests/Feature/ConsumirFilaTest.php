<?php

namespace Tests\Feature;

use App\Exceptions\CobrancaException;
use App\Mensageria\ConsumidorDeComando;
use App\Models\MensagemProcessada;
use App\Models\Orcamento;
use App\Services\ProcessarGerarOrcamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Mockery;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use Tests\Support\Traceparent;
use Tests\TestCase;

/**
 * O worker contra um broker de mentira: a conexao e o canal AMQP sao dubles,
 * e o wait() do canal entrega as mensagens que cada teste enfileira. O resto —
 * processadores, outbox, banco, PublicadorRabbitMq — e o codigo de verdade.
 */
class ConsumirFilaTest extends TestCase
{
    use RefreshDatabase;

    private const FILA_GERAR = 'billing.gerar-orcamento';

    private const TRACE_AMQP = '00-cccccccccccccccccccccccccccccccc-dddddddddddddddd-01';

    /** @var AMQPChannel&MockInterface */
    private MockInterface $canal;

    /** @var AbstractConnection&MockInterface */
    private MockInterface $conexao;

    /** @var array<string, callable> */
    private array $consumidores = [];

    /** @var list<array{0: string, 1: AMQPMessage}> */
    private array $entregas = [];

    /** @var list<array{tag: int}> */
    private array $acks = [];

    /** @var list<array{tag: int, requeue: bool}> */
    private array $nacks = [];

    /** @var list<array{mensagem: AMQPMessage, exchange: string, routingKey: string}> */
    private array $publicados = [];

    private bool $canalFechado = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->canal = Mockery::mock(AMQPChannel::class);
        $this->canal->shouldReceive('queue_declare')->byDefault();
        $this->canal->shouldReceive('basic_qos')->with(0, 1, false)->byDefault();
        $this->canal->shouldReceive('basic_consume')->andReturnUsing(
            function (string $fila, string $tag, bool $noLocal, bool $noAck, bool $exclusivo, bool $noWait, callable $callback): string {
                $this->assertFalse($noAck, 'o worker confirma manualmente; auto-ack perderia mensagem');
                $this->consumidores[$fila] = $callback;

                return 'ctag-'.$fila;
            },
        )->byDefault();
        $this->canal->shouldReceive('is_consuming')->andReturnUsing(fn () => $this->entregas !== [])->byDefault();
        $this->canal->shouldReceive('wait')->andReturnUsing(function (): void {
            [$fila, $mensagem] = array_shift($this->entregas);
            ($this->consumidores[$fila])($mensagem);
        })->byDefault();
        $this->canal->shouldReceive('basic_ack')->andReturnUsing(function ($tag): void {
            $this->acks[] = ['tag' => $tag];
        })->byDefault();
        $this->canal->shouldReceive('basic_nack')->andReturnUsing(function ($tag, $multiplo, $requeue): void {
            $this->assertFalse($multiplo);
            $this->nacks[] = ['tag' => $tag, 'requeue' => $requeue];
        })->byDefault();
        $this->canal->shouldReceive('basic_publish')->andReturnUsing(function (AMQPMessage $mensagem, string $exchange, string $routingKey): void {
            $this->publicados[] = compact('mensagem', 'exchange', 'routingKey');
        })->byDefault();
        $this->canal->shouldReceive('close')->andReturnUsing(function (): void {
            $this->canalFechado = true;
        })->byDefault();

        $this->conexao = Mockery::mock(AbstractConnection::class);
        $this->conexao->shouldReceive('channel')->andReturn($this->canal)->byDefault();
        $this->conexao->shouldReceive('close')->once()->byDefault();

        $this->app->instance(AbstractConnection::class, $this->conexao);
    }

    protected function tearDown(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
        }

        parent::tearDown();
    }

    public function test_gera_orcamento_publica_no_exchange_e_confirma_a_mensagem(): void
    {
        $this->entregar(self::FILA_GERAR, $this->comandoGerarOrcamento(), 7, [
            'traceparent' => self::TRACE_AMQP,
            'tracestate' => 'nr=1',
        ]);

        $codigo = Artisan::call('fila:consumir', ['--fila' => [self::FILA_GERAR], '--once' => true]);

        $this->assertSame(0, $codigo);
        $this->assertSame([['tag' => 7]], $this->acks);
        $this->assertSame([], $this->nacks);
        $this->assertSame(1, Orcamento::query()->count());

        $this->assertCount(1, $this->publicados);
        $publicado = $this->publicados[0];
        $this->assertSame('oficina.eventos', $publicado['exchange']);
        $this->assertSame('orcamento.gerado', $publicado['routingKey']);

        $propriedades = $publicado['mensagem']->get_properties();
        $this->assertSame('application/json', $propriedades['content_type']);
        $this->assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $propriedades['delivery_mode']);
        $this->assertSame('orcamento.gerado', $propriedades['type']);

        $envelope = json_decode($publicado['mensagem']->getBody(), true);
        $this->assertSame($envelope['header']['messageId'], $propriedades['message_id']);
        $this->assertSame('33333333-3333-3333-3333-333333333333', $envelope['header']['causationId']);
        $this->assertSame(241.8, $envelope['body']['valorTotal']);

        // O traceparent do header AMQP prevalece sobre o do envelope: o evento
        // sai no trace cccc..., nao no aaaa... que veio dentro do corpo.
        $headers = $propriedades['application_headers']->getNativeData();
        $this->assertSame('cccccccccccccccccccccccccccccccc', Traceparent::traceId($headers['traceparent']));
        $this->assertSame($headers['traceparent'], $envelope['header']['traceparent']);
    }

    public function test_reentrega_do_mesmo_comando_e_confirmada_sem_novo_efeito(): void
    {
        $this->entregar(self::FILA_GERAR, $this->comandoGerarOrcamento(), 1);
        $this->entregar(self::FILA_GERAR, $this->comandoGerarOrcamento(), 2);

        // Sem --once o laco so para quando o canal deixa de consumir.
        $codigo = Artisan::call('fila:consumir', ['--fila' => [self::FILA_GERAR]]);

        $this->assertSame(0, $codigo);
        $this->assertSame([['tag' => 1], ['tag' => 2]], $this->acks);
        $this->assertSame(1, Orcamento::query()->count());
        $this->assertSame(1, MensagemProcessada::query()->count());
        $this->assertCount(1, $this->publicados);
    }

    public function test_sem_filtro_consome_as_quatro_filas_de_comando(): void
    {
        $this->canal->shouldReceive('queue_declare')->times(4)->with(Mockery::type('string'), true);
        $this->entregar(self::FILA_GERAR, $this->comandoGerarOrcamento(), 3);

        $codigo = Artisan::call('fila:consumir', ['--once' => true]);

        $this->assertSame(0, $codigo);
        $this->assertSame([
            'billing.gerar-orcamento',
            'billing.cobrar-pagamento',
            'billing.anular-orcamento',
            'billing.estornar-pagamento',
        ], array_keys($this->consumidores));
        $this->assertSame([['tag' => 3]], $this->acks);
    }

    public function test_fila_desconhecida_falha_sem_abrir_conexao(): void
    {
        $this->conexao->shouldNotReceive('channel');
        $this->conexao->shouldNotReceive('close');

        $codigo = Artisan::call('fila:consumir', ['--fila' => ['billing.nao-existe']]);

        $this->assertSame(1, $codigo);
        $saida = Artisan::output();
        $this->assertStringContainsString('Fila desconhecida: billing.nao-existe', $saida);
        $this->assertStringContainsString('billing.gerar-orcamento', $saida);
    }

    public function test_fila_ausente_no_broker_falha_e_fecha_a_conexao(): void
    {
        $this->canal->shouldReceive('queue_declare')
            ->andThrow(new AMQPProtocolChannelException(404, "NOT_FOUND - no queue 'billing.gerar-orcamento'", [50, 10]));
        $this->canal->shouldNotReceive('basic_consume');

        $codigo = Artisan::call('fila:consumir', ['--fila' => [self::FILA_GERAR]]);

        $this->assertSame(1, $codigo);
        $saida = Artisan::output();
        $this->assertStringContainsString('A fila billing.gerar-orcamento não existe neste broker', $saida);
        $this->assertStringContainsString('NOT_FOUND', $saida);
    }

    public function test_json_invalido_vai_para_a_dlq(): void
    {
        $this->entregarCorpo(self::FILA_GERAR, '{"header": ', 11);

        Artisan::call('fila:consumir', ['--fila' => [self::FILA_GERAR], '--once' => true]);

        $this->assertSame([['tag' => 11, 'requeue' => false]], $this->nacks);
        $this->assertSame([], $this->acks);
        $this->assertStringContainsString('JSON inválido.', Artisan::output());
    }

    public function test_corpo_que_nao_e_objeto_vai_para_a_dlq(): void
    {
        $this->entregarCorpo(self::FILA_GERAR, '"so uma string"', 12);

        Artisan::call('fila:consumir', ['--fila' => [self::FILA_GERAR], '--once' => true]);

        $this->assertSame([['tag' => 12, 'requeue' => false]], $this->nacks);
        $this->assertStringContainsString('corpo não é um objeto JSON.', Artisan::output());
    }

    public function test_mensagem_ilegivel_vai_para_a_dlq_sem_efeito(): void
    {
        $comando = $this->comandoGerarOrcamento();
        unset($comando['header']['messageId']);
        $this->entregar(self::FILA_GERAR, $comando, 13);

        Artisan::call('fila:consumir', ['--fila' => [self::FILA_GERAR], '--once' => true]);

        $this->assertSame([['tag' => 13, 'requeue' => false]], $this->nacks);
        $this->assertSame(0, Orcamento::query()->count());
        $this->assertSame([], $this->publicados);
        $this->assertStringContainsString('header.messageId ausente ou inválido.', Artisan::output());
    }

    public function test_falha_transitoria_devolve_a_mensagem_para_a_fila(): void
    {
        Exceptions::fake();
        $this->processadorQueLanca(new CobrancaException('Mercado Pago fora', 502));
        $this->entregar(self::FILA_GERAR, $this->comandoGerarOrcamento(), 14);

        Artisan::call('fila:consumir', ['--fila' => [self::FILA_GERAR], '--once' => true]);

        $this->assertSame([['tag' => 14, 'requeue' => true]], $this->nacks);
        Exceptions::assertReported(CobrancaException::class);
    }

    public function test_erro_inesperado_e_reportado_e_vai_para_a_dlq(): void
    {
        Exceptions::fake();
        $this->processadorQueLanca(new RuntimeException('bug'));
        $this->entregar(self::FILA_GERAR, $this->comandoGerarOrcamento(), 15);

        Artisan::call('fila:consumir', ['--fila' => [self::FILA_GERAR], '--once' => true]);

        $this->assertSame([['tag' => 15, 'requeue' => false]], $this->nacks);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'bug');
    }

    public function test_queda_do_broker_propaga_e_ainda_fecha_a_conexao(): void
    {
        $this->canal->shouldReceive('is_consuming')->andReturn(true);
        $this->canal->shouldReceive('wait')->andThrow(new AMQPConnectionClosedException('broker caiu'));

        try {
            Artisan::call('fila:consumir', ['--fila' => [self::FILA_GERAR]]);
            $this->fail('a queda do broker deveria derrubar o worker');
        } catch (AMQPConnectionClosedException $e) {
            $this->assertSame('broker caiu', $e->getMessage());
        }
    }

    public function test_sigterm_fecha_o_canal_e_encerra_com_sucesso(): void
    {
        if (! function_exists('pcntl_async_signals') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('precisa das extensoes pcntl e posix.');
        }

        $this->canal->shouldReceive('is_consuming')->andReturn(true);
        $this->canal->shouldReceive('wait')->andReturnUsing(function (): void {
            posix_kill(getmypid(), SIGTERM);

            for ($i = 0; $i < 200 && ! $this->canalFechado; $i++) {
                usleep(1000);
            }

            // O wait() em curso observa o canal fechado pelo handler.
            throw new AMQPConnectionClosedException('canal fechado');
        });

        $codigo = Artisan::call('fila:consumir', ['--fila' => [self::FILA_GERAR]]);

        $this->assertTrue($this->canalFechado, 'o handler de SIGTERM deveria fechar o canal');
        $this->assertSame(0, $codigo);
    }

    private function processadorQueLanca(\Throwable $erro): void
    {
        $this->app->instance(ProcessarGerarOrcamento::class, new class($erro) implements ConsumidorDeComando
        {
            public function __construct(private readonly \Throwable $erro) {}

            public function executar(array $mensagem): void
            {
                throw $this->erro;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $comando
     * @param  array<string, string>  $headers
     */
    private function entregar(string $fila, array $comando, int $tag, array $headers = []): void
    {
        $this->entregarCorpo($fila, json_encode($comando, JSON_THROW_ON_ERROR), $tag, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function entregarCorpo(string $fila, string $corpo, int $tag, array $headers = []): void
    {
        // Toda mensagem do contrato v1.1 chega com traceparent no header AMQP.
        $headers += ['traceparent' => self::TRACE_AMQP];
        $mensagem = new AMQPMessage($corpo, ['application_headers' => new AMQPTable($headers)]);
        $mensagem->setDeliveryTag($tag);

        $this->entregas[] = [$fila, $mensagem];
    }

    /**
     * @return array<string, mixed>
     */
    private function comandoGerarOrcamento(): array
    {
        return [
            'header' => [
                'messageId' => '33333333-3333-3333-3333-333333333333',
                'sagaId' => '44444444-4444-4444-4444-444444444444',
                'type' => 'GerarOrcamento',
                'occurredAt' => '2026-11-04T18:00:00Z',
                'traceparent' => '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01',
                'version' => 1,
            ],
            'body' => [
                'osId' => '11111111-1111-1111-1111-111111111111',
                'clienteId' => '22222222-2222-2222-2222-222222222222',
                'veiculo' => ['placa' => 'DEM1A23', 'modelo' => 'Gol', 'ano' => 2020],
                'descricaoProblema' => 'Barulho no freio',
                'itensDiagnosticados' => [
                    ['descricao' => 'Filtro de Óleo', 'tipo' => 'peca', 'quantidade' => 2],
                    ['descricao' => 'Troca de Óleo', 'tipo' => 'servico', 'quantidade' => 1],
                ],
            ],
        ];
    }
}
