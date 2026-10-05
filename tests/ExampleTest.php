<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Tests;

use PHPUnit\Framework\TestCase;

final class ExampleTest extends TestCase
{
    public function testTheSaasExamplePrintsWhatTheReadmeShows(): void
    {
        $output = shell_exec(escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../examples/saas/run.php'));

        self::assertStringEqualsFile(__DIR__ . '/../examples/saas/expected-output.txt', (string) $output);
    }
}
