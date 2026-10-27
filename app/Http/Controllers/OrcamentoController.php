<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrcamentoRequest;
use App\Models\Orcamento;
use App\Services\GerarOrcamento;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Info(
    version: '0.1.0',
    title: 'Oficina Billing Service',
    description: 'Orçamento e pagamento da Fase 4. Payload alinhado ao contrato de eventos v1.0.'
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

        return response()->json($this->evento($orcamento), 201);
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

        return response()->json($this->evento($orcamento));
    }

    private function evento(Orcamento $orcamento): array
    {
        return [
            'osId' => $orcamento->os_id,
            'orcamentoId' => $orcamento->id,
            'valorTotal' => (float) $orcamento->valor_total,
            'moeda' => $orcamento->moeda,
            'validoAte' => $orcamento->valido_ate->utc()->format('Y-m-d\TH:i:s\Z'),
            'itens' => $orcamento->itens->map(fn ($item) => [
                'descricao' => $item->descricao,
                'tipo' => $item->tipo,
                'quantidade' => $item->quantidade,
                'valorUnitario' => (float) $item->valor_unitario,
            ])->all(),
        ];
    }
}
