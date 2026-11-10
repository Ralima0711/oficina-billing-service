# Billing Service — API e os dois workers de fila na mesma imagem.
#
# As tres extensoes abaixo nao vem na imagem oficial do PHP:
#
#   pdo_pgsql  o banco do servico e Postgres (ADR-0007)
#   sockets    php-amqplib/php-amqplib v3.7.5 declara "ext-sockets": "*" no
#              composer.lock. Sem ela o `composer install` deste Dockerfile
#              falha na checagem de plataforma e o build nao conclui — o codigo
#              usar AMQPStreamConnection nao muda isso: o composer barra antes.
#   pcntl      ConsumirFila registra SIGTERM/SIGINT para terminar a mensagem em
#              curso em vez de morrer no meio dela. O codigo esta guardado por
#              function_exists(), entao sem pcntl ele nao quebra — so nao faz
#              nada, e o rollout do Kubernetes corta o worker no meio do ACK.

FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install pdo_pgsql sockets pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

EXPOSE 8080
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
