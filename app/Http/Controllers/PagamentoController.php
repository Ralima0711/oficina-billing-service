<?php

namespace App\Http\Controllers;

use App\Models\Orcamento;
use App\Models\Pagamento;
use App\Services\CobrarPagamento;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PagamentoController extends Controller
{
    public function __construct(private readonly CobrarPagamento $cobrar) {}

    #[OA\Post(
        path: '/api/orcamentos/{id}/pagamentos',
        operationId: 'criarPreferencia',
        summary: 'Cria a preferência de pagamento no sandbox do Mercado Pago',
        tags: ['Pagamentos'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Preferência criada'),
            new OA\Response(response: 200, description: 'Preferência já existente'),
            new OA\Response(response: 404, description: 'Orçamento não encontrado'),
            new OA\Response(response: 422, description: 'Orçamento sem valor'),
        ]
    )]
    public function store(string $id): JsonResponse
    {
        $orcamento = Orcamento::query()->findOrFail($id);
        $pagamento = $this->cobrar->iniciar($orcamento);

        return response()->json($this->corpo($pagamento), $pagamento->wasRecentlyCreated ? 201 : 200);
    }

    #[OA\Get(
        path: '/api/pagamentos/{id}',
        operationId: 'consultarPagamento',
        summary: 'Consulta o pagamento no Mercado Pago e devolve o corpo do evento',
        tags: ['Pagamentos'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Pendente, confirmado ou recusado'),
            new OA\Response(response: 404, description: 'Pagamento não encontrado'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $pagamento = Pagamento::query()->findOrFail($id);
        $pagamento = $this->cobrar->consultar($pagamento);

        return response()->json($this->corpo($pagamento));
    }

    private function corpo(Pagamento $pagamento): array
    {
        if ($pagamento->status === 'pendente') {
            return [
                'osId' => $pagamento->os_id,
                'pagamentoId' => $pagamento->id,
                'orcamentoId' => $pagamento->orcamento_id,
                'status' => 'pendente',
                'sandboxInitPoint' => $pagamento->sandbox_init_point,
            ];
        }

        return $pagamento->paraEvento();
    }
}
