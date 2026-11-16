<?php

declare(strict_types=1);

namespace Example\Payments;

/**
 * The few calls the app makes to the payment provider, and the check of its callback
 * signature (Standard Webhooks). Plain HTTP, so the example needs no client library.
 */
final readonly class Psp
{
    private string $key;

    public function __construct(private string $url, string $secret)
    {
        $key = base64_decode(preg_replace('/^whsec_/', '', $secret) ?? '', true);
        if ($key === false || $key === '') {
            throw new \InvalidArgumentException('PSP_WEBHOOK_SECRET must be base64, optionally prefixed with "whsec_".');
        }
        $this->key = $key;
    }

    /**
     * @param array<string, string> $metadata
     *
     * @return array<string, mixed>
     */
    public function createPayment(int $amount, string $currency, string $reference, array $metadata): array
    {
        return $this->request('POST', '/v1/payments', [
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $reference,
            'metadata' => $metadata,
        ], $reference);
    }

    /**
     * @return array<string, mixed>
     */
    public function refund(string $paymentId, int $amount, string $reference): array
    {
        return $this->request('POST', '/v1/payments/' . rawurlencode($paymentId) . '/refunds', [
            'amount' => $amount,
            'reference' => $reference,
        ], $reference);
    }

    /**
     * @return array<string, mixed>
     */
    public function payment(string $id): array
    {
        return $this->request('GET', '/v1/payments/' . rawurlencode($id));
    }

    /**
     * @param array<string, string> $headers lower-case names
     */
    public function signed(string $body, array $headers, int $now): bool
    {
        $id = $headers['webhook-id'] ?? '';
        $timestamp = $headers['webhook-timestamp'] ?? '';
        if ($id === '' || !ctype_digit($timestamp) || abs($now - (int) $timestamp) > 300) {
            return false;
        }
        $expected = 'v1,' . base64_encode(hash_hmac('sha256', "$id.$timestamp.$body", $this->key, true));
        foreach (explode(' ', $headers['webhook-signature'] ?? '') as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $headers = ['Content-Type: application/json', 'Authorization: Bearer sk_example'];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => $headers,
            'content' => $body === null ? '' : json_encode($body, \JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $response = file_get_contents($this->url . $path, false, $context);
        $status = (int) (explode(' ', http_get_last_response_headers()[0] ?? '')[1] ?? 0);
        $decoded = \is_string($response) ? json_decode($response, true) : null;
        if ($status < 200 || $status > 299 || !\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('%s %s answered %d: %s', $method, $path, $status, (string) $response));
        }

        return $decoded;
    }
}
