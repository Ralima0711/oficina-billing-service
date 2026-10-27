# oficina-billing-service

Microsserviço de **Orçamento e Pagamento** do Tech Challenge SOAT Fase 4 — Grupo 77.

## Responsabilidades

| Operação | Observação |
|---|---|
| Gerar orçamento | Consome comando do orquestrador |
| Enviar orçamento para aprovação | Validade de **7 dias** |
| Registrar aprovação ou recusa | Publica evento |
| Criar cobrança e verificar pagamento | Integração com o **Mercado Pago** |
| Anular orçamento | Compensação |
| Estornar pagamento | Compensação |

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

## Como rodar o ambiente

```bash
git clone https://github.com/Ralima0711/oficina-infra-fase4.git
cd oficina-infra-fase4 && docker compose up -d
```

O Postgres do Billing sobe na porta **5433**.

## Documentação

| Documento | O que é |
|---|---|
| `docs/contrato-eventos-fase4.md` | Contrato v1.0 — **em caso de divergência, ele manda** |
| `docs/adrs-fase4.md` | ADR-0007 a ADR-0012 |
| `docs/asyncapi.yaml` | Contrato AsyncAPI dos eventos |
| `docs/guia-testes-cobertura.md` | Pirâmide de testes e meta de 80% |

## Regras do repositório

- `main` protegida: PR obrigatório com 1 aprovação
- **PR sem teste não é aprovado**
- Meta de cobertura: **80%**, com quality gate no SonarQube
- Credenciais do Mercado Pago entram por **secret**, nunca no código

---

*Grupo 77 · FIAP SOAT Pós-Tech · Fase 4*
