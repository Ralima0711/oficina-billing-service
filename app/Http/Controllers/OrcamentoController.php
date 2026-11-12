<?php

namespace App\Http\Controllers;

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
     */
    public function decidir(
        DecidirOrcamentoRequest $request,
        RegistrarDecisaoOrcamento $decisao,
        string $id,
    ): JsonResponse
    {
        $orcamento = Orcamento::query()->find($id);

        if ($orcamento === null) {
            return response()->json(['erro' => 'Orcamento nao encontrado.'], 404);
        }

        // Orcamento sem saga veio do POST /api/orcamentos, que e porta avulsa.
        // Publicar a decisao dele geraria um evento que o Orchestrator do OS
        // descartaria em sagaAtiva() — melhor recusar aqui e dizer por que.
        if (! is_string($orcamento->saga_id) || $orcamento->saga_id === '') {
            return response()->json([
                'erro' => 'Orcamento sem saga: nao foi gerado pelo comando GerarOrcamento e nao ha saga para decidir.',
                'status' => $orcamento->status,
            ], 409);
        }

        if ($orcamento->status !== 'gerado') {
            return response()->json([
                'erro' => 'Orcamento ja decidido ou anulado.',
                'status' => $orcamento->status,
            ], 409);
        }

        if ($orcamento->valido_ate->isPast()) {
            return response()->json([
                'erro' => 'Prazo de aprovacao vencido.',
                'validoAte' => $orcamento->valido_ate->utc()->format('Y-m-d\TH:i:s\Z'),
                'status' => $orcamento->status,
            ], 409);
        }

        $decisaoId = $decisao->executar($orcamento, $request->aprovou(), $request->motivo());

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
