# oficina-billing-service

Microsserviço de orçamento e pagamento da Fase 4 (Grupo 77). Laravel 12, Postgres próprio, sem acessar o banco de outro serviço.

O payload segue o contrato em `oficina-infra-fase4` (`GerarOrcamento` / `orcamento.gerado`).

## O que esta versão faz

- `POST /api/orcamentos` grava o orçamento e devolve o corpo do evento `orcamento.gerado`.
- `GET /api/orcamentos/{id}` consulta o que foi gravado.
- Swagger em `/api/documentation`.
- Preço unitário inicial: peça `95.00`, serviço `180.00`. O comando não traz valor; o billing calcula. Validade: 7 dias.

Mercado Pago (preferência e consulta) e o consumidor da fila `billing.gerar-orcamento` entram no passo seguinte. O Access Token fica em `MP_ACCESS_TOKEN` e não vai para o Git.

## Subir com o Postgres da infra

No repo `oficina-infra-fase4`:

```bash
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
