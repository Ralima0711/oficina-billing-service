# oficina-billing-service

Microsserviço de **Orçamento e Pagamento** do Tech Challenge SOAT Fase 4 — Grupo 77.
Laravel 12, Postgres próprio, sem acessar o banco de outro serviço.

## Responsabilidades

| Operação | Observação |
|---|---|
| Gerar orçamento | Consome comando do orquestrador |
| Enviar orçamento para aprovação | Validade de **7 dias** |
| Registrar aprovação ou recusa | Publica evento |
| Criar cobrança e verificar pagamento | Integração com o **Mercado Pago** |
| Anular orçamento | Compensação |
| Estornar pagamento | Compensação |

## O que esta versão faz

- `POST /api/orcamentos` grava o orçamento e devolve o corpo do evento `orcamento.gerado`.
- `GET /api/orcamentos/{id}` consulta o que foi gravado.
- Swagger em `/api/documentation`.
- **O preço não vem no comando.** O billing consulta o catálogo local `catalogo_precos`,
  com os nomes e valores que a API da Fase 3 já usa (peça, insumo e serviço). Insumo entra
  como `peca`, porque o contrato só tem `peca` e `servico`. Item desconhecido responde 422.

Mercado Pago (preferência e consulta) e o consumidor da fila `billing.gerar-orcamento`
entram no passo seguinte. O Access Token fica em `MP_ACCESS_TOKEN` e não vai para o Git.

## Banco

**PostgreSQL.** Orçamento e pagamento exigem integridade relacional (ADR-0009).
Nenhum outro serviço acessa este banco.

## Mensageria

**Consome comandos** de `billing.gerar-orcamento`, `billing.cobrar-pagamento`,
`billing.anular-orcamento` e `billing.estornar-pagamento`.

**Publica eventos** no exchange `oficina.eventos`: `orcamento.gerado`,
`orcamento.aprovado`, `orcamento.recusado`, `aprovacao.expirada`,
`pagamento.confirmado` e `pagamento.recusado`.

### O timeout de aprovação não é job

A fila `billing.aguardando-aprovacao` tem TTL de 7 dias e nenhum consumidor. Ao expirar,
o próprio RabbitMQ encaminha a mensagem para `billing.aprovacao-expirada`, e a Saga
compensa: anula o orçamento e leva a OS para `CANCELADA`.

### Ordem dos passos

O pagamento é cobrado **antes** da execução. É a *Pessimistic View* do ADR-0011 — não se
presta serviço contra um estado que ainda pode mudar. Não inverta sem revisar o ADR.

## Como rodar

O Postgres vem da infra compartilhada:

```bash
# no repo oficina-infra-fase4
docker compose up -d billing-postgres
```

Aqui:

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

O `.env.example` aponta para `127.0.0.1:5433`, banco `billing_service`.

Os testes usam SQLite em memória e não precisam do Docker:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan test
```

## Documentação

| Documento | O que é |
|---|---|
| `docs/contrato-eventos-fase4.md` | Contrato v1.0 — **em caso de divergência, ele manda** |
| `docs/adrs-fase4.md` | ADR-0007 a ADR-0012 |
| `docs/asyncapi.yaml` | Contrato AsyncAPI dos eventos |
| `docs/guia-testes-cobertura.md` | Pirâmide de testes e meta de 80% |
| `docs/arquitetura-fase4.png` | Diagrama da arquitetura final |

## Regras do repositório

- `main` protegida: PR obrigatório com 1 aprovação
- **PR sem teste não é aprovado**
- Meta de cobertura: **80%**, com quality gate no SonarQube
- Credenciais do Mercado Pago entram por **secret**, nunca no código

---

*Grupo 77 · FIAP SOAT Pós-Tech · Fase 4*
