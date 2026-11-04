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
- `POST /api/orcamentos/{id}/pagamentos` cria a preferência no sandbox do Mercado Pago e devolve o `sandboxInitPoint`.
- `GET /api/pagamentos/{id}` consulta o pagamento e devolve `pagamento.confirmado`, `pagamento.recusado` ou `pendente`.
- `php artisan fila:consumir` consome `billing.gerar-orcamento` e publica `orcamento.gerado` ou `orcamento.falhou` no exchange `oficina.eventos`.
- Swagger em `/api/documentation`.
- **O preço não vem no comando.** O billing consulta o catálogo local `catalogo_precos`,
  com os nomes e valores que a API da Fase 3 já usa (peça, insumo e serviço). Insumo entra
  como `peca`, porque o contrato só tem `peca` e `servico`. Item desconhecido responde 422.
  `itensDiagnosticados` é obrigatório, com pelo menos um item (contrato v1.1). Sem itens, a API responde 422. No veículo, só a placa é obrigatória.

O Access Token de teste fica em `MP_ACCESS_TOKEN` e não vai para o Git. A consulta
ao Mercado Pago é por polling: a cada `MP_POLL_INTERVAL_SECONDS` (30) segundos, no
máximo `MP_POLL_MAX_ATTEMPTS` (20) vezes. Esgotado o limite, o pagamento fica
`recusado` com motivo `timeout`. A fila `billing.cobrar-pagamento` entra no passo seguinte.

Sem itens, item fora do catálogo ou campo essencial inválido, o consumidor publica
`orcamento.falhou` (`sem_itens_a_orcar`, `item_fora_do_catalogo` ou `erro_interno`)
e não grava orçamento. A repetição do mesmo `messageId` não gera outro efeito.
O ACK só sai depois do commit e da publicação. A topologia do RabbitMQ não é
declarada aqui: ela vem de `rabbitmq/definitions.json` no `oficina-infra-fase4`.

## Banco

**PostgreSQL.** Orçamento e pagamento exigem integridade relacional (ADR-0009).
Nenhum outro serviço acessa este banco.

## Mensageria

**Consome comandos** de `billing.gerar-orcamento`, `billing.cobrar-pagamento`,
`billing.anular-orcamento` e `billing.estornar-pagamento`.

**Publica eventos** no exchange `oficina.eventos`: `orcamento.gerado`,
`orcamento.falhou`, `orcamento.aprovado`, `orcamento.recusado`, `aprovacao.expirada`,
`pagamento.confirmado` e `pagamento.recusado`.

Nesta versão o worker consome só `billing.gerar-orcamento`. Cobrança, anulação,
estorno e expiração de aprovação ainda não têm consumidor.

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
