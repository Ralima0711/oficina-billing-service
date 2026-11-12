<?php

namespace App\Http\Controllers;

use App\Exceptions\DecisaoNaoAplicavel;
use App\Http\Requests\DecidirOrcamentoRequest;
use App\Http\Requests\StoreOrcamentoRequest;
use App\Models\Orcamento;
use App\Services\GerarOrcamento;
use App\Services\RegistrarDecisaoOrcamento;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: '0.1.0',
    title: 'Oficina Billing Service',
    description: 'Orçamento e pagamento da Fase 4. Payload alinhado ao contrato de eventos v1.1.'
)]
#[OA\Server(url: '/', description: 'Esta aplicação')]
class OrcamentoController extends Controller
{
    public function __construct(private readonly GerarOrcamento $gerar) {}

    #[OA\Post(
        path: '/api/orcamentos',
        operationId: 'gerarOrcamento',
        summary: 'Gera um orçamento a partir do comando GerarOrcamento',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['osId', 'clienteId', 'veiculo', 'descricaoProblema', 'itensDiagnosticados'],
                properties: [
                    new OA\Property(property: 'osId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'clienteId', type: 'string', format: 'uuid'),
                    new OA\Property(
                        property: 'veiculo',
                        required: ['placa'],
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'placa', type: 'string', example: 'DEM1A23'),
                            new OA\Property(property: 'modelo', type: 'string'),
                            new OA\Property(property: 'ano', type: 'integer', example: 2020),
                        ]
                    ),
                    new OA\Property(property: 'descricaoProblema', type: 'string'),
                    new OA\Property(
                        property: 'itensDiagnosticados',
                        type: 'array',
                        minItems: 1,
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'descricao', type: 'string'),
                                new OA\Property(property: 'tipo', type: 'string', enum: ['peca', 'servico']),
                                new OA\Property(property: 'quantidade', type: 'integer', example: 1),
                            ]
                        )
                    ),
                ]
            )
        ),
        tags: ['Orcamentos'],
        responses: [
            new OA\Response(response: 201, description: 'Orçamento gerado'),
            new OA\Response(response: 422, description: 'Payload inválido'),
        ]
    )]
    public function store(StoreOrcamentoRequest $request): JsonResponse
    {
        $orcamento = $this->gerar->executar($request->validated());

        return response()->json($orcamento->paraEvento(), 201);
    }

    #[OA\Post(
        path: '/api/orcamentos/{id}/decisao',
        operationId: 'decidirOrcamento',
        summary: 'Registra a decisao do cliente e publica orcamento.aprovado ou orcamento.recusado',
        description: 'Porta de entrada da decisao humana da saga. O evento sai pelo outbox; '.
            'o OS Service o consome em os.saga.eventos e a saga anda sozinha.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['decisao'],
                properties: [
                    new OA\Property(property: 'decisao', type: 'string', enum: ['aprovar', 'recusar']),
                    new OA\Property(property: 'motivo', type: 'string', description: 'So na recusa.'),
                ]
            )
        ),
        tags: ['Orcamentos'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 202, description: 'Decisao registrada e evento publicado'),
            new OA\Response(response: 404, description: 'Orcamento nao encontrado'),
            new OA\Response(response: 409, description: 'Orcamento ja decidido, vencido, anulado ou sem saga'),
            new OA\Response(response: 422, description: 'Payload invalido'),
        ]
    )]
    /*
     * RegistrarDecisaoOrcamento entra por injecao de METODO, nao pelo
     * construtor. No construtor, toda requisicao a /api/orcamentos — inclusive
     * o GET — passaria a resolver CaixaDeSaida e, atras dela, o publicador do
     * RabbitMQ. Gerar e consultar orcamento nao falam com broker nenhum, e nao
     * devem depender de um.
     *
     * Nao ha guarda nenhuma aqui: todas rodam dentro da transacao do servico,
     * sobre a linha com lockForUpdate. Ler o status aqui fora e decidir la
     * dentro deixa duas chamadas simultaneas passarem as duas — e, como cada
     * uma gera um messageId proprio, a idempotencia da saga nao as junta.
     */
    public function decidir(
        DecidirOrcamentoRequest $request,
        RegistrarDecisaoOrcamento $decisao,
        string $id,
    ): JsonResponse {
        try {
            $decisaoId = $decisao->executar($id, $request->aprovou(), $request->motivo());
        } catch (DecisaoNaoAplicavel $e) {
            if ($e->motivo === DecisaoNaoAplicavel::NAO_ENCONTRADO) {
                return response()->json(['erro' => $e->getMessage()], 404);
            }

            // Uma decisao pode ter comitado sem publicar: a transacao grava o
            // status e a linha do outbox, e a publicacao sai depois do commit.
            // Se o processo morreu no meio, e ESTA chamada — a repeticao do
            // cliente depois do 500 — a unica que pode desfazer a perda.
            // Recusar e so recusar confirmaria a saga parada em
            // AGUARDANDO_APROVACAO ate o prazo de sete dias expirar.
            if ($e->motivo === DecisaoNaoAplicavel::JA_DECIDIDO) {
                $decisao->republicarPendentes($e->decisaoId);
            }

            return response()->json(array_filter([
                'erro' => $e->getMessage(),
                'motivo' => $e->motivo,
                'status' => $e->status,
                'decisaoId' => $e->decisaoId,
                'validoAte' => $e->validoAte,
            ], fn ($valor) => $valor !== null), 409);
        }

        $orcamento = Orcamento::query()->findOrFail($id);

        // 202 e nao 200: o que esta pronto e o registro da decisao. O efeito na
        // OS vem depois, por mensagem.
        return response()->json([
            'decisaoId' => $decisaoId,
            'orcamentoId' => $orcamento->id,
            'osId' => $orcamento->os_id,
            'status' => $orcamento->status,
        ], 202);
    }

    #[OA\Get(
        path: '/api/orcamentos/{id}',
        operationId: 'consultarOrcamento',
        summary: 'Consulta um orçamento pelo id',
        tags: ['Orcamentos'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Orçamento encontrado'),
            new OA\Response(response: 404, description: 'Orçamento não encontrado'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $orcamento = Orcamento::query()->with('itens')->findOrFail($id);

        return response()->json($orcamento->paraEvento());
    }
}
