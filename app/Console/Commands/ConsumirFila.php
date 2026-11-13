<?php

namespace App\Console\Commands;

use App\Exceptions\CobrancaException;
use App\Exceptions\MensagemIlegivel;
use App\Mensageria\ConexaoRabbit;
use App\Mensageria\ConsumidorDeComando;
use App\Mensageria\Envelope;
use App\Mensageria\MessageTracing;
use App\Mensageria\PublicadorEventos;
use App\Mensageria\PublicadorRabbitMq;
use App\Services\ProcessarAnularOrcamento;
use App\Services\ProcessarCobrarPagamento;
use App\Services\ProcessarEstornarPagamento;
use App\Services\ProcessarGerarOrcamento;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use JsonException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

class ConsumirFila extends Command
{
    protected $signature = 'fila:consumir
                            {--fila=* : Consome só estas filas (padrão: todas as de comando)}
                            {--once : Consome uma mensagem e encerra}';

    protected $description = 'Consome os comandos de billing e publica os eventos no exchange oficina.eventos';

    public function handle(): int
    {
        $filas = $this->filasEscolhidas([
            (string) config('mensageria.filas.gerar_orcamento') => ProcessarGerarOrcamento::class,
            (string) config('mensageria.filas.cobrar_pagamento') => ProcessarCobrarPagamento::class,
            (string) config('mensageria.filas.anular_orcamento') => ProcessarAnularOrcamento::class,
            (string) config('mensageria.filas.estornar_pagamento') => ProcessarEstornarPagamento::class,
        ]);

        if ($filas === []) {
            return self::FAILURE;
        }

        $conexao = ConexaoRabbit::abrir();
        $canal = $conexao->channel();
        $encerrar = false;

        $this->laravel->instance(PublicadorEventos::class, new PublicadorRabbitMq(
            $canal,
            (string) config('mensageria.exchange_eventos'),
        ));

        foreach (array_keys($filas) as $fila) {
            try {
                $canal->queue_declare($fila, true);
            } catch (AMQPProtocolChannelException $e) {
                $this->error("A fila {$fila} não existe neste broker. A topologia vem de rabbitmq/definitions.json no oficina-infra-fase4.");
                $this->error($e->getMessage());
                $conexao->close();

                return self::FAILURE;
            }
        }

        $this->escutarParada($canal, $encerrar);
        $canal->basic_qos(0, 1, false);
        $once = (bool) $this->option('once');
        $processou = false;

        foreach ($filas as $fila => $processador) {
            $processar = $this->laravel->make($processador);
            $canal->basic_consume(
                $fila,
                '',
                false,
                false,
                false,
                false,
                function (AMQPMessage $mensagem) use ($processar, $canal, $once, $fila, &$processou) {
                    $this->tratar($mensagem, $processar, $canal, $fila);
                    $processou = $once;
                },
            );
        }

        try {
            while ($canal->is_consuming()) {
                $canal->wait();
                if ($processou) {
                    break;
                }
            }
        } catch (Throwable $e) {
            if (! $encerrar) {
                throw $e;
            }
        } finally {
            try {
                $conexao->close();
            } catch (Throwable) {
                // A conexão já pode ter sido fechada pelo sinal de parada.
            }
        }

        return self::SUCCESS;
    }

    /**
     * Filtra as filas pelo --fila, para dar um processo a cada uma.
     *
     * O acompanhamento do pagamento faz polling dentro do callback: com os padrões
     * (30s de intervalo, 20 tentativas) o pior caso são dez minutos. Como o prefetch
     * é 1 e os dois basic_consume vivem no mesmo canal, um pagamento lento segura a
     * fila de gerar-orcamento atrás dele por todo esse tempo. Não trava a saga — o
     * consultar() desiste e publica pagamento.recusado com motivo timeout — mas é
     * head-of-line blocking, e na demo parece travamento.
     *
     * Sem --fila o processo consome todas as filas de comando.
     *
     * @param  array<string, class-string>  $filas
     * @return array<string, class-string>
     */
    private function filasEscolhidas(array $filas): array
    {
        $escolhidas = array_filter(array_map('trim', (array) $this->option('fila')));

        if ($escolhidas === []) {
            return $filas;
        }

        $desconhecidas = array_diff($escolhidas, array_keys($filas));

        if ($desconhecidas !== []) {
            $this->error('Fila desconhecida: '.implode(', ', $desconhecidas));
            $this->line('Filas de comando: '.implode(', ', array_keys($filas)));

            return [];
        }

        return array_intersect_key($filas, array_flip($escolhidas));
    }

    private function tratar(AMQPMessage $mensagem, ConsumidorDeComando $processar, AMQPChannel $canal, string $fila): void
    {
        try {
            $dados = json_decode($mensagem->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($dados)) {
                throw new MensagemIlegivel('corpo não é um objeto JSON.');
            }

            $headers = $this->headersAmqp($mensagem);
            $dados = Envelope::priorizarTraceparent($dados, $headers['traceparent'] ?? null);
            $osId = is_array($dados['body'] ?? null) ? ($dados['body']['osId'] ?? null) : null;

            MessageTracing::consumir(
                $fila,
                $headers,
                function () use ($processar, $dados): void {
                    $processar->executar($dados);
                },
                is_string($osId) ? ['os.id' => $osId] : [],
            );
            $canal->basic_ack($mensagem->getDeliveryTag());
        } catch (MensagemIlegivel $e) {
            $this->warn($e->getMessage());
            $canal->basic_nack($mensagem->getDeliveryTag(), false, false);
        } catch (QueryException|AMQPRuntimeException|CobrancaException $e) {
            report($e);
            sleep(1);
            $canal->basic_nack($mensagem->getDeliveryTag(), false, true);
        } catch (JsonException $e) {
            $this->warn('JSON inválido.');
            $canal->basic_nack($mensagem->getDeliveryTag(), false, false);
        } catch (Throwable $e) {
            report($e);
            $canal->basic_nack($mensagem->getDeliveryTag(), false, false);
        }
    }

    /**
     * @return array<string, string>
     */
    private function headersAmqp(AMQPMessage $mensagem): array
    {
        $headers = $mensagem->get('application_headers');
        if (! $headers instanceof AMQPTable) {
            return [];
        }

        $dados = $headers->getNativeData();
        $saida = [];

        foreach (['traceparent', 'tracestate'] as $chave) {
            $valor = $dados[$chave] ?? null;
            if (is_string($valor) && $valor !== '') {
                $saida[$chave] = $valor;
            }
        }

        return $saida;
    }

    private function escutarParada(AMQPChannel $canal, bool &$encerrar): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $parar = function () use (&$encerrar, $canal) {
            $encerrar = true;

            try {
                $canal->close();
            } catch (Throwable) {
                // O wait() em curso observa o canal fechado e o processo encerra.
            }
        };

        pcntl_signal(SIGTERM, $parar);
        pcntl_signal(SIGINT, $parar);
    }
}
