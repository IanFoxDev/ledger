<?php

declare(strict_types=1);

namespace Example\Payments\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Reads the report recon wrote after PaymentsTest, bin/provider-report.php and
 * docker compose run recon. Everything reconciles except the deposits whose callback
 * the provider never sent: the provider has them, the ledger does not.
 */
#[Group('recon')]
final class ReconTest extends TestCase
{
    public function testOnlyTheLostDepositsAreMissingInTheLedger(): void
    {
        $file = __DIR__ . '/../out/report.json';
        self::assertFileExists($file, 'run recon first, see README.md');
        $report = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);

        $counts = [];
        foreach ($report['counts'] as $count) {
            if ($count['count'] > 0) {
                $counts[$count['category']] = $count['count'];
            }
        }
        self::assertSame(['missing_in_ledger'], array_keys($counts), json_encode($report['counts'], \JSON_THROW_ON_ERROR));
        self::assertGreaterThanOrEqual(3, $report['matched'], 'the deposits that got their callbacks reconcile');

        foreach ($report['differences'] as $difference) {
            $payment = json_decode((string) file_get_contents('http://psp:8090/v1/payments/' . $difference['key'][0]), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($payment);
            self::assertStringStartsWith('lost-', $payment['reference']);
            self::assertNull($difference['ledger']);
        }
    }
}
