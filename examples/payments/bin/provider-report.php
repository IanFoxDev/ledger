<?php

declare(strict_types=1);

// Downloads the whole Balance report to exports/balance.csv, as a finance job does every
// morning. With Stripe it is a report run through the Reporting API; the sandbox
// serves the same CSV with one GET.

$url = getenv('PSP_URL') . '/_sandbox/reports/balance.csv';
$csv = file_get_contents($url);
if ($csv === false || !str_starts_with($csv, 'balance_transaction_id,')) {
    fwrite(\STDERR, "could not download $url\n");
    exit(1);
}
$file = __DIR__ . '/../exports/balance.csv';
if (!is_dir(\dirname($file))) {
    mkdir(\dirname($file));
}
file_put_contents($file, $csv);
printf("%s: %d rows\n", realpath($file), substr_count($csv, "\n") - 1);
