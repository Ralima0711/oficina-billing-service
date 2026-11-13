<?php

namespace Tests\Support;

final class Traceparent
{
    public static function traceId(?string $valor): ?string
    {
        return self::partes($valor)[0] ?? null;
    }

    public static function spanId(?string $valor): ?string
    {
        return self::partes($valor)[1] ?? null;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function partes(?string $valor): ?array
    {
        if (! is_string($valor) || ! preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', $valor, $encontrado)) {
            return null;
        }

        return [$encontrado[1], $encontrado[2]];
    }
}
