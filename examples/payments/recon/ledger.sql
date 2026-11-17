-- What the ledger says went through the provider: every posting on cash:psp, signed as
-- a debit (a deposit is positive, a refund negative), with the provider's payment id
-- from the transaction's meta. recon sums the rows per payment.
SELECT t.meta::json ->> 'payment' AS payment,
    CASE WHEN p.side = 'D' THEN p.amount ELSE -p.amount END AS amount,
    0 AS fee,
    a.currency
FROM ledger_postings p
JOIN ledger_transactions t ON t.id = p.transaction_id
JOIN ledger_accounts a ON a.code = p.account
WHERE p.account = 'cash:psp'
ORDER BY p.sequence
