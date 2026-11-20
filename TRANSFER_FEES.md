# Transfer fees and platform revenue ledger

## Policy

- Wallet to wallet: 1% of the requested transfer amount.
- Office transfer: 2% of the requested amount.
- The sender pays the fee on top; the wallet recipient or office collection receives the full requested amount.
- Currency scale is configured in `config/money.php`. XOF is whole-unit; other currencies default to two decimal places.
- Fees use integer minor units and round half up to the currency's smallest unit.

## Ledger behavior

Transfer balance changes, transfer records, customer transactions, admin wallet fee credits and financial journal entries execute in the same database transaction. A revenue admin wallet is created for each currency on first fee posting and linked through `platform_revenue_accounts`. The journal records a sender debit and principal/revenue credits in the same currency. Office cancellations and failures refund the sender, debit fee revenue and append a reversing journal. Journal records should only be corrected through new reversals.

Send endpoints accept `Idempotency-Key` (or `idempotency_key` in the request body). Keys are scoped to the sender and transfer table; repeating a key with a different payload returns a validation error. Existing clients may omit the key.

## API response shapes

The quote routes remain `POST /api/v1/wallet-transfer/quote` and `POST /api/v1/office-transfers/quote`; each keeps the existing `success` envelope and returns `data.amount`, `data.fee`, `data.total`, and `data.currency`. For an amount of 10,000 NGN, wallet quote values are `10000.00`, `100.00`, and `10100.00`. For an amount of 10,000 XOF, office quote values are `10000`, `200`, and `10200`.

Send endpoints retain their existing envelope and include the same amount, fee, total, currency and reference fields. An idempotent replay returns those values with `idempotent_replay: true`.

Admin routes under the existing admin middleware:

- `GET /api/admin/admin-wallet/revenue`: revenue wallet balances grouped per currency.
- `GET /api/admin/admin-wallet/reconciliation`: revenue wallet balance against the corresponding journal balance.

## Deployment

Run `php artisan migrate --force` to add idempotency fields, revenue account links and financial journal tables. Confirm the database backup and deploy with PHP 8.4.1 or newer, matching the bundled Composer dependencies. Run `php artisan test` after installing dependencies for that PHP version.
