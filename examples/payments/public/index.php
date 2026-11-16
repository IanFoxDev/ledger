<?php

declare(strict_types=1);

// The whole app: a deposit page that sends the user to the provider, a refund button,
// and the callback handler that books what the provider says happened.
//
//   POST /deposits      {"user": "42", "amount": 5000, "reference": "dep-1"}
//   POST /refunds       {"payment": "pay_...", "amount": 2000, "reference": "ref-1"}
//   POST /psp/callback  signed by the provider

use Example\Payments\Books;
use Example\Payments\Psp;

require __DIR__ . '/../vendor/autoload.php';

$psp = new Psp((string) getenv('PSP_URL'), (string) getenv('PSP_WEBHOOK_SECRET'));
$respond = static function (int $status, mixed $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, \JSON_THROW_ON_ERROR), "\n";
};
$body = (string) file_get_contents('php://input');
$input = json_decode($body, true);
$input = \is_array($input) ? $input : [];
$route = ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), \PHP_URL_PATH);

try {
    $books = Books::connect((string) getenv('DATABASE_DSN'));
    switch ($route) {
        case 'POST /deposits':
            $user = (string) ($input['user'] ?? '');
            $books->openWallet($user);
            $respond(201, $psp->createPayment((int) ($input['amount'] ?? 0), Books::CURRENCY, (string) ($input['reference'] ?? ''), ['user' => $user]));
            break;

        case 'POST /refunds':
            // Nothing is booked yet: the refund is the provider's until it says it succeeded.
            $respond(201, $psp->refund((string) ($input['payment'] ?? ''), (int) ($input['amount'] ?? 0), (string) ($input['reference'] ?? '')));
            break;

        case 'POST /psp/callback':
            $headers = [];
            foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $name) {
                $headers[$name] = (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '');
            }
            if (!$psp->signed($body, $headers, time())) {
                $respond(400, ['error' => 'bad signature']);
                break;
            }
            $data = \is_array($input['data'] ?? null) ? $input['data'] : [];
            switch ($input['type'] ?? null) {
                case 'payment.captured':
                    $books->captured($data);
                    break;
                case 'refund.succeeded':
                    // Callbacks can come in any order. A refund callback before the capture
                    // callback would take money the ledger has not seen arrive. The payment
                    // as the provider has it now is the truth: book its capture first, under
                    // the key the capture callback will use when it comes.
                    $payment = $psp->payment((string) ($data['payment_id'] ?? ''));
                    $books->captured($payment);
                    $books->refunded($data, $payment);
                    break;
            }
            $respond(200, 'ok');
            break;

        default:
            $respond(404, ['error' => 'not found']);
    }
} catch (Throwable $e) {
    // A 500 makes the provider deliver the callback again.
    error_log($route . ': ' . $e::class . ': ' . $e->getMessage());
    $respond(500, ['error' => $e->getMessage()]);
}
