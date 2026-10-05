<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger;

/**
 * The content of a transaction request in a form that does not depend on the order of
 * legs or meta keys, and its SHA-256, which the idempotency check compares.
 */
final class Canonical
{
    /**
     * @param list<Leg> $legs
     * @param array<string, string|int|bool|null> $meta
     */
    public static function hash(string $type, array $legs, array $meta, ?int $reverses): string
    {
        $lines = array_map(static fn(Leg $l): string => $l->account . "\0" . $l->side->value . "\0" . $l->amount, $legs);
        sort($lines, \SORT_STRING);
        ksort($meta, \SORT_STRING);

        return hash('sha256', json_encode(
            ['type' => $type, 'legs' => $lines, 'meta' => $meta, 'reverses' => $reverses],
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        ));
    }
}
