<?php

use App\Mensageria\PublicadorEventos;
use App\Models\Orcamento;
use App\Models\Pagamento;
use App\Services\ProcessarGerarOrcamento;
use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Hook\AfterSuite;
use Behat\Hook\BeforeScenario;
use Behat\Hook\BeforeSuite;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Behat\Testwork\Hook\Scope\AfterSuiteScope;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Tests\Support\PublicadorFake;
use Tests\Support\Traceparent;

/**
 * O Behat sobe o Laravel de verdade e leva cada cenário pela API, no mesmo
 * desenho do OS Service. A diferença é o que se afirma: orçamento, decisão e
 * pagamento deste serviço.
 *
 * O padrão é SQLite em memória, que é o banco da suite PHPUnit. Postgres só
 * com banco terminado em _test ou _teste — o migrate:fresh derruba as tabelas
 * da conexão ativa, e foi assim que o banco de desenvolvimento do OS morreu
 * em 11/11.
 *
 *   vendor/bin/behat
 */
final class FeatureContext implements Context
{
    private const SAGA = '44444444-4444-4444-4444-444444444444';

    private const OS = '11111111-1111-1111-1111-111111111111';

    private const CLIENTE = '22222222-2222-2222-2222-222222222222';

    private const MENSAGEM = '33333333-3333-3333-3333-333333333333';

    private const TRACEPARENT = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';

    private static ?Application $app = null;

    private static ?PublicadorFake $publicador = null;

    private ?TestResponse $resposta = null;

    private string $orcamentoId = '';

    private string $pagamentoId = '';

    #[BeforeSuite]
    public static function subirAplicacao(BeforeSuiteScope $scope): void
    {
        self::aplicacao();

        if (DB::connection()->getDriverName() === 'pgsql') {
            $banco = (string) DB::connection()->getDatabaseName();

            if (! str_ends_with($banco, '_test') && ! str_ends_with($banco, '_teste')) {
                throw new DomainException(
                    "RECUSADO: o Behat ia rodar migrate:fresh no banco '{$banco}', que nao "
                    ."termina em '_test' nem '_teste'. Aponte para um banco dedicado "
                    .'(DB_DATABASE=billing_service_test) ou rode sem exportar DB_CONNECTION, '
                    .'que cai no SQLite em memoria.'
                );
            }
        }

        self::$app->make(Kernel::class)
            ->call('migrate:fresh', ['--force' => true]);

        // A decisão publica na hora, e o singleton de verdade abre o RabbitMQ
        // nessa hora. O cenário afirma o que saiu; o fake guarda o evento sem
        // falar com o broker.
        self::$publicador = new PublicadorFake;
        self::$app->instance(PublicadorEventos::class, self::$publicador);
    }

    #[AfterSuite]
    public static function desligarAplicacao(AfterSuiteScope $scope): void
    {
        self::$app = null;
        self::$publicador = null;
    }

    #[BeforeScenario]
    public function abrirTransacao(): void
    {
        DB::beginTransaction();
        self::$publicador->eventos = [];
        Http::swap(new Factory);
        config([
            'services.mercadopago.access_token' => (string) env('MP_ACCESS_TOKEN', 'TEST-token'),
        ]);
    }

    #[AfterScenario]
    public function desfazerTransacao(): void
    {
        DB::rollBack();
        $this->resposta = null;
        $this->orcamentoId = '';
        $this->pagamentoId = '';
    }

    // ── Dado ───────────────────────────────────────────────────────

    #[Given('um orçamento gerado pela saga')]
    public function dadoUmOrcamentoGeradoPelaSaga(): void
    {
        self::aplicacao()->make(ProcessarGerarOrcamento::class)->executar($this->mensagem());

        $this->orcamentoId = (string) Orcamento::query()->value('id');
        Assert::assertNotSame('', $this->orcamentoId);
    }

    #[Given('um orçamento gerado')]
    public function dadoUmOrcamentoGerado(): void
    {
        $this->quandoGeroUmOrcamentoComOsItensDeExemplo();
        Assert::assertSame(201, $this->resposta->status(), (string) $this->resposta->getContent());
    }

    #[Given('um pagamento pendente')]
    public function dadoUmPagamentoPendente(): void
    {
        $this->dadoUmOrcamentoGerado();
        $this->quandoCrioACobranca();
        Assert::assertSame(201, $this->resposta->status(), (string) $this->resposta->getContent());
        $this->pagamentoId = (string) $this->resposta->json('pagamentoId');
    }

    #[Given('o token do Mercado Pago não está configurado')]
    public function dadoOTokenDoMercadoPagoNaoEstaConfigurado(): void
    {
        config(['services.mercadopago.access_token' => '']);
    }

    // ── Quando ─────────────────────────────────────────────────────

    #[When('eu gero um orçamento com os itens de exemplo')]
    public function quandoGeroUmOrcamentoComOsItensDeExemplo(): void
    {
        // 2 parafusos a 1,50 e 1 alinhamento a 100,00. O total 103 é o do
        // contrato HTTP, e o preço vem do catálogo, não deste passo.
        $this->gerarAvulso([
            ['descricao' => 'Parafuso', 'tipo' => 'peca', 'quantidade' => 2],
            ['descricao' => 'Alinhamento', 'tipo' => 'servico', 'quantidade' => 1],
        ]);
    }

    #[When('eu gero um orçamento com um item fora do catálogo')]
    public function quandoGeroUmOrcamentoComUmItemForaDoCatalogo(): void
    {
        $this->gerarAvulso([
            ['descricao' => 'Pneu de corrida', 'tipo' => 'peca', 'quantidade' => 1],
        ]);
    }

    #[When('eu gero um orçamento sem itens')]
    public function quandoGeroUmOrcamentoSemItens(): void
    {
        $this->gerarAvulso([]);
    }

    #[When('eu consulto esse orçamento')]
    public function quandoEuConsultoEsseOrcamento(): void
    {
        $this->requisicao('GET', '/api/orcamentos/'.$this->orcamentoId);
    }

    #[When('eu aprovo esse orçamento')]
    #[When('eu aprovo esse orçamento de novo')]
    public function quandoEuAprovoEsseOrcamento(): void
    {
        $this->requisicao('POST', '/api/orcamentos/'.$this->orcamentoId.'/decisao', [
            'decisao' => 'aprovar',
        ]);
    }

    #[When('eu recuso esse orçamento com o motivo :motivo')]
    public function quandoEuRecusoEsseOrcamento(string $motivo): void
    {
        $this->requisicao('POST', '/api/orcamentos/'.$this->orcamentoId.'/decisao', [
            'decisao' => 'recusar',
            'motivo' => $motivo,
        ]);
    }

    #[When('a saga manda gerar orçamento de um item fora do catálogo')]
    public function quandoASagaMandaGerarOrcamentoDeUmItemForaDoCatalogo(): void
    {
        $mensagem = $this->mensagem();
        $mensagem['body']['itensDiagnosticados'] = [
            ['descricao' => 'Pneu de corrida', 'tipo' => 'peca', 'quantidade' => 1],
        ];

        self::aplicacao()->make(ProcessarGerarOrcamento::class)->executar($mensagem);
    }

    #[When('eu crio a cobrança desse orçamento')]
    #[When('eu crio a cobrança desse orçamento de novo')]
    public function quandoCrioACobranca(): void
    {
        $this->fingirPreferencia();
        $this->requisicao('POST', '/api/orcamentos/'.$this->orcamentoId.'/pagamentos');

        if ($this->pagamentoId === '' && is_string($this->resposta->json('pagamentoId'))) {
            $this->pagamentoId = $this->resposta->json('pagamentoId');
        }
    }

    #[When('o Mercado Pago informa o pagamento como aprovado')]
    public function quandoOMercadoPagoInformaOPagamentoComoAprovado(): void
    {
        Http::fake([
            'https://api.mercadopago.com/v1/payments/search*' => Http::response([
                'results' => [[
                    'id' => 999,
                    'status' => 'approved',
                    'transaction_amount' => 103.0,
                    'payment_type_id' => 'credit_card',
                    'payment_method_id' => 'visa',
                    'date_approved' => '2026-11-01T22:20:00.000-03:00',
                ]],
            ]),
        ]);
    }

    #[When('eu consulto esse pagamento')]
    public function quandoEuConsultoEssePagamento(): void
    {
        $this->requisicao('GET', '/api/pagamentos/'.$this->pagamentoId);
    }

    // ── Então ──────────────────────────────────────────────────────

    #[Then('a resposta deve ter o código :codigo')]
    public function aRespostaDeveTerOCodigo(int $codigo): void
    {
        Assert::assertSame($codigo, $this->resposta->status(), (string) $this->resposta->getContent());
    }

    #[Then('o valor total do orçamento é :valor')]
    public function oValorTotalDoOrcamentoE(string $valor): void
    {
        Assert::assertEqualsWithDelta((float) $valor, (float) $this->resposta->json('valorTotal'), 0.001);
    }

    #[Then('a moeda do orçamento é :moeda')]
    public function aMoedaDoOrcamentoE(string $moeda): void
    {
        Assert::assertSame($moeda, $this->resposta->json('moeda'));
    }

    #[Then('o motivo da resposta é :motivo')]
    public function oMotivoDaRespostaE(string $motivo): void
    {
        Assert::assertSame($motivo, $this->resposta->json('motivo'));
    }

    #[Then('o status da resposta é :status')]
    public function oStatusDaRespostaE(string $status): void
    {
        Assert::assertSame($status, $this->resposta->json('status'));
    }

    #[Then('nenhum evento foi publicado')]
    public function nenhumEventoFoiPublicado(): void
    {
        Assert::assertSame([], self::$publicador->eventos);
    }

    #[Then('nenhum orçamento foi gravado')]
    public function nenhumOrcamentoFoiGravado(): void
    {
        Assert::assertSame(0, Orcamento::query()->count());
    }

    #[Then('foi publicado o evento :tipo na mesma saga')]
    public function foiPublicadoOEventoNaMesmaSaga(string $tipo): void
    {
        $evento = $this->eventoPublicado($tipo);

        Assert::assertSame(self::SAGA, $evento['envelope']['header']['sagaId']);
    }

    #[Then('o evento :tipo foi publicado uma vez')]
    public function oEventoFoiPublicadoUmaVez(string $tipo): void
    {
        $publicados = array_filter(
            self::$publicador->eventos,
            fn (array $evento) => $evento['routingKey'] === $tipo,
        );

        Assert::assertCount(1, $publicados);
    }

    #[Then('o trace do evento :tipo continua :traceId')]
    public function oTraceDoEventoContinua(string $tipo, string $traceId): void
    {
        $evento = $this->eventoPublicado($tipo);

        Assert::assertSame($traceId, Traceparent::traceId($evento['traceparent']));
        Assert::assertSame($evento['traceparent'], $evento['envelope']['header']['traceparent']);
    }

    #[Then('o motivo publicado em :tipo é :motivo')]
    public function oMotivoPublicadoE(string $tipo, string $motivo): void
    {
        Assert::assertSame($motivo, $this->eventoPublicado($tipo)['envelope']['body']['motivo']);
    }

    #[Then('a resposta traz o link de checkout')]
    public function aRespostaTrazOLinkDeCheckout(): void
    {
        // initPoint e o link que funciona com credencial de usuario de teste;
        // o do sandbox continua na resposta, mas nao e o que o cliente abre.
        Assert::assertStringContainsString(
            'www.mercadopago.com',
            (string) $this->resposta->json('initPoint'),
        );
        Assert::assertStringContainsString(
            'sandbox.mercadopago.com',
            (string) $this->resposta->json('sandboxInitPoint'),
        );
    }

    #[Then('existe um único pagamento')]
    public function existeUmUnicoPagamento(): void
    {
        Assert::assertSame(1, Pagamento::query()->count());
    }

    #[Then('o valor pago é :valor')]
    public function oValorPagoE(string $valor): void
    {
        Assert::assertEqualsWithDelta((float) $valor, (float) $this->resposta->json('valorPago'), 0.001);
    }

    // ── Ajudantes ──────────────────────────────────────────────────

    private static function aplicacao(): Application
    {
        if (self::$app !== null) {
            return self::$app;
        }

        // O que o operador exportar no shell vence estes padrões. Escrever por
        // cima apagaria o banco de teste que ele apontou — e, pior, deixaria o
        // .env de desenvolvimento (Postgres real) vencer e o migrate:fresh
        // derrubá-lo. O phpdotenv não sobrepõe variável que já existe.
        $padroes = [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('b', 32)),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
            'MP_ACCESS_TOKEN' => 'TEST-token',
            'OTEL_EXPORTER_OTLP_ENDPOINT' => '',
            'OTEL_EXPORTER_OTLP_HEADERS' => '',
            'OTEL_SERVICE_NAME' => 'oficina-billing-service',
        ];

        foreach ($padroes as $nome => $valor) {
            if (getenv($nome) !== false) {
                continue;
            }

            putenv($nome.'='.$valor);
            $_ENV[$nome] = $valor;
            $_SERVER[$nome] = $valor;
        }

        self::$app = require __DIR__.'/../../bootstrap/app.php';
        self::$app->make(Kernel::class)->bootstrap();

        return self::$app;
    }

    /**
     * @param  array<int, array<string, mixed>>  $itens
     */
    private function gerarAvulso(array $itens): void
    {
        $this->requisicao('POST', '/api/orcamentos', [
            'osId' => self::OS,
            'clienteId' => self::CLIENTE,
            'veiculo' => ['placa' => 'DEM1A23', 'modelo' => 'Gol', 'ano' => 2020],
            'descricaoProblema' => 'Barulho no freio dianteiro',
            'itensDiagnosticados' => $itens,
        ]);

        if ($this->resposta->status() === 201) {
            $this->orcamentoId = (string) $this->resposta->json('orcamentoId');
        }
    }

    private function fingirPreferencia(): void
    {
        Http::fake([
            'https://api.mercadopago.com/checkout/preferences' => Http::response([
                'id' => 'pref-1',
                'init_point' => 'https://www.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1',
                'sandbox_init_point' => 'https://sandbox.mercadopago.com.br/checkout/v1/redirect?pref_id=pref-1',
            ], 201),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $corpo
     */
    private function requisicao(string $metodo, string $uri, ?array $corpo = null): void
    {
        $request = Request::create($uri, $metodo, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], $corpo === null ? null : json_encode($corpo));

        $this->resposta = new TestResponse(
            self::aplicacao()->make(HttpKernel::class)->handle($request)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mensagem(): array
    {
        return [
            'header' => [
                'messageId' => self::MENSAGEM,
                'sagaId' => self::SAGA,
                'causationId' => '55555555-5555-5555-5555-555555555555',
                'type' => 'GerarOrcamento',
                'occurredAt' => '2026-11-04T12:00:00Z',
                'traceparent' => self::TRACEPARENT,
                'version' => 1,
            ],
            'body' => [
                'osId' => self::OS,
                'clienteId' => self::CLIENTE,
                'veiculo' => ['placa' => 'DEM1A23', 'modelo' => 'Gol', 'ano' => 2020],
                'descricaoProblema' => 'Barulho no freio dianteiro',
                'itensDiagnosticados' => [
                    ['descricao' => 'Parafuso', 'tipo' => 'peca', 'quantidade' => 2],
                    ['descricao' => 'Alinhamento', 'tipo' => 'servico', 'quantidade' => 1],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventoPublicado(string $tipo): array
    {
        $achados = array_values(array_filter(
            self::$publicador->eventos,
            fn (array $evento) => $evento['routingKey'] === $tipo,
        ));

        Assert::assertNotEmpty($achados, "nenhum evento {$tipo} foi publicado");

        return $achados[array_key_last($achados)];
    }
}
