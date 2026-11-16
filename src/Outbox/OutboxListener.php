<?php

declare(strict_types=1);

namespace IanFoxDev\Ledger\Outbox;

use IanFoxDev\Ledger\Posting;
use IanFoxDev\Ledger\Transaction;
use IanFoxDev\Ledger\TransactionListener;
use IanFoxDev\Outbox\Message;
use IanFoxDev\Outbox\Recorder;

/**
 * Records one event per posting in ianfoxdev/outbox, in the transaction that writes the
 * posting. The aggregate is the account, so the relay keeps the events of an account in
 * the order of its postings, and a consumer that remembers the last sequence it applied
 * can drop a duplicate and notice a gap.
 *
 * Requires ianfoxdev/outbox, and an Outbox built on the same connection as the ledger.
 */
final readonly class OutboxListener implements TransactionListener
{
    public const string EVENT = 'ledger.account.posted';

    /**
     * @param (\Closure(Posting, Transaction): bool)|null $only record a posting only when this returns true,
     *                                                         for example to skip hold and lot accounts
     */
    public function __construct(
        private Recorder $recorder,
        private string $aggregateType = 'ledger.account',
        private ?\Closure $only = null,
    ) {}

    public function posted(Transaction $transaction): void
    {
        $messages = [];
        foreach ($transaction->postings as $posting) {
            if ($this->only !== null && !($this->only)($posting, $transaction)) {
                continue;
            }
            // Amounts are strings: they can be longer than a JSON number holds exactly.
            $messages[] = Message::json(self::EVENT, $this->aggregateType, $posting->account, [
                'account' => $posting->account,
                'sequence' => $posting->sequence,
                'side' => $posting->side->value,
                'amount' => (string) $posting->amount,
                'balance_after' => (string) $posting->balanceAfter,
                'transaction' => [
                    'id' => $transaction->id,
                    'key' => $transaction->key,
                    'type' => $transaction->type,
                    'meta' => $transaction->meta === [] ? new \stdClass() : $transaction->meta,
                    'reverses' => $transaction->reverses,
                    'created_at' => $transaction->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                ],
            ]);
        }
        if ($messages !== []) {
            $this->recorder->record(...$messages);
        }
    }
}
