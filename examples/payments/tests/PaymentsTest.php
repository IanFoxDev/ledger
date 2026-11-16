<?php

declare(strict_types=1);

namespace Example\Payments\Tests;

use Example\Payments\Books;
use PHPUnit\Framework\TestCase;

/**
 * Runs inside the app container against the app, psp-sandbox and PostgreSQL. Each test
 * has its own user and reference, so nothing needs resetting between them. The checks
 * read the ledger tables directly, as a reconciliation job would.
 */
final class PaymentsTest extends TestCase
{
    private const string APP = 'http://127.0.0.1:8000';
    private const string PSP = 'http://psp:8090';

    private \PDO $pdo;
    private string $user;

    protected function setUp(): void
    {
        $this->pdo = new \PDO((string) getenv('DATABASE_DSN'));
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->user = bin2hex(random_bytes(4));
    }

    public function testDepositIsBookedWhenTheCallbackComes(): void
    {
        $payment = $this->deposit(50_00, 'dep-' . $this->user);

        $this->waitFor(fn(): bool => $this->balance() === 50_00, 'the deposit to be booked');
        self::assertSame(['deposit'], $this->types($payment));
        self::assertTrue($this->books()->ledger->verify()->ok());
    }

    /**
     * Five copies of payment.captured arrive at the same moment and run in parallel
     * threads. Each one posts under the key psp:<payment>:captured; the ledger writes one
     * and returns it to the other four, and every copy is answered 200.
     */
    public function testFiveParallelCopiesOfTheCallbackBookTheDepositOnce(): void
    {
        $payment = $this->deposit(30_00, 'dup-' . $this->user);

        $deliveries = $this->waitForDeliveries($payment, 5);
        foreach ($deliveries as $delivery) {
            self::assertSame('succeeded', $delivery['status']);
            self::assertSame(200, $delivery['attempts'][0]['status_code'] ?? null);
        }
        self::assertSame(30_00, $this->balance());
        self::assertSame(['deposit'], $this->types($payment));
    }

    /**
     * The refund is asked for while the capture callback is still held by the provider,
     * and both arrive newest first: refund.succeeded, then payment.captured. The refund
     * handler books the capture from GET before the refund; the capture callback then
     * finds its key taken with the same content.
     */
    public function testRefundCallbackBeforeTheCaptureCallback(): void
    {
        $payment = $this->deposit(40_00, 'ooo-' . $this->user);
        $this->waitFor(fn(): bool => $this->psp('GET', '/v1/payments/' . $payment)['status'] === 'captured', 'the capture');
        $this->call('POST', self::APP . '/refunds', ['payment' => $payment, 'amount' => 15_00, 'reference' => 'ooo-' . $this->user . '-r1']);

        $deliveries = $this->waitForDeliveries($payment, 2);
        $byTime = $deliveries;
        usort($byTime, static fn(array $a, array $b): int => strcmp((string) ($a['attempts'][0]['at'] ?? ''), (string) ($b['attempts'][0]['at'] ?? '')));
        self::assertSame(['refund.succeeded', 'payment.captured'], array_column($byTime, 'event_type'), 'the sandbox reversed the order');
        foreach ($deliveries as $delivery) {
            self::assertSame('succeeded', $delivery['status']);
            self::assertCount(1, $delivery['attempts'], 'no callback needed a retry');
        }
        self::assertSame(25_00, $this->balance());
        self::assertSame(['deposit', 'deposit.refund'], $this->types($payment));
        self::assertTrue($this->books()->ledger->verify()->ok());
    }

    /**
     * The provider captured the money and never said so. The ledger has nothing, and
     * nothing in the app will ever find out: this is the gap reconciliation closes.
     */
    public function testLostCallbackLeavesTheLedgerWithoutTheDeposit(): void
    {
        $payment = $this->deposit(20_00, 'lost-' . $this->user);
        $this->waitFor(fn(): bool => $this->psp('GET', '/v1/payments/' . $payment)['status'] === 'captured', 'the capture');
        usleep(1_000_000);

        self::assertSame('dropped', $this->psp('GET', '/_sandbox/payments/' . $payment . '/deliveries')['data'][0]['status'] ?? null);
        self::assertSame(0, $this->balance());
        self::assertSame([], $this->types($payment));
    }

    private function deposit(int $amount, string $reference): string
    {
        $payment = $this->call('POST', self::APP . '/deposits', ['user' => $this->user, 'amount' => $amount, 'reference' => $reference]);
        self::assertIsString($payment['id'] ?? null);

        return $payment['id'];
    }

    private function balance(): int
    {
        $statement = $this->pdo->prepare('SELECT balance_after FROM ledger_postings WHERE account = ? ORDER BY sequence DESC LIMIT 1');
        $statement->execute([Books::wallet($this->user)]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Types of the ledger transactions written for one payment, in the order written.
     *
     * @return list<string>
     */
    private function types(string $payment): array
    {
        $statement = $this->pdo->prepare('SELECT type FROM ledger_transactions WHERE meta LIKE ? ORDER BY id');
        $statement->execute(['%"payment":"' . $payment . '"%']);

        return array_map(strval(...), $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function books(): Books
    {
        return Books::connect((string) getenv('DATABASE_DSN'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function waitForDeliveries(string $payment, int $count): array
    {
        $deliveries = [];
        $this->waitFor(function () use ($payment, $count, &$deliveries): bool {
            $deliveries = $this->psp('GET', '/_sandbox/payments/' . $payment . '/deliveries')['data'] ?? [];
            $done = array_filter($deliveries, static fn(array $d): bool => $d['status'] !== 'pending');

            return \count($done) >= $count;
        }, "$count deliveries");
        self::assertCount($count, $deliveries);

        return $deliveries;
    }

    private function waitFor(\Closure $condition, string $what, float $seconds = 15): void
    {
        $until = microtime(true) + $seconds;
        while (!$condition()) {
            if (microtime(true) > $until) {
                self::fail("Timed out waiting for $what.");
            }
            usleep(100_000);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function psp(string $method, string $path): array
    {
        return $this->call($method, self::PSP . $path);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $url, ?array $body = null): array
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => ['Content-Type: application/json'],
            'content' => $body === null ? '' : json_encode($body, \JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
        ]]);
        $response = (string) file_get_contents($url, false, $context);
        $decoded = json_decode($response, true);
        self::assertIsArray($decoded, "$method $url: $response");

        return $decoded;
    }
}
