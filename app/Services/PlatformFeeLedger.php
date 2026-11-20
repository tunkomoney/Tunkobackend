
<?php

namespace App\Services;

use App\Models\AdminWallet;
use App\Models\AdminWalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PlatformFeeLedger
{
    /**
     * Post a transfer fee into the platform revenue wallet
     * and create the corresponding financial journal.
     *
     * IMPORTANT:
     * This method must execute inside the same database
     * transaction as the original wallet transfer.
     */
    public function post(
        string $reference,
        string $currency,
        int $principalUnits,
        int $feeUnits,
        string $source
    ): void {
        if ($principalUnits <= 0) {
            throw new RuntimeException(
                'Transfer principal must be greater than zero.'
            );
        }

        if ($feeUnits < 0) {
            throw new RuntimeException(
                'Transfer fee cannot be negative.'
            );
        }

        if ($feeUnits === 0) {
            return;
        }

        $currency = strtoupper(trim($currency));

        if ($reference === '') {
            throw new RuntimeException(
                'Financial journal reference is required.'
            );
        }

        if (!in_array($source, [
            'wallet_transfer',
            'office_transfer',
        ], true)) {
            throw new RuntimeException(
                'Unsupported transfer revenue source.'
            );
        }

        /*
         * --------------------------------------------------
         * Ensure this method participates in a transaction.
         * --------------------------------------------------
         */

        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'Platform fee posting must run inside a database transaction.'
            );
        }

        /*
         * --------------------------------------------------
         * Check for duplicate financial journal references.
         * --------------------------------------------------
         */

        $existingJournal = DB::table('financial_journals')
            ->where('reference', $reference)
            ->lockForUpdate()
            ->exists();

        if ($existingJournal) {
            throw new RuntimeException(
                "Financial journal already exists for reference {$reference}."
            );
        }

        /*
         * --------------------------------------------------
         * Convert fee units into currency decimal format.
         * --------------------------------------------------
         */

        $feeAmount = TransferMoney::decimal(
            $feeUnits,
            $currency
        );

        /*
         * --------------------------------------------------
         * Find the platform revenue account.
         * --------------------------------------------------
         */

        $account = DB::table('platform_revenue_accounts')
            ->where('currency', $currency)
            ->lockForUpdate()
            ->first();

        $wallet = null;

        if ($account) {
            $wallet = AdminWallet::query()
                ->lockForUpdate()
                ->find($account->admin_wallet_id);

            if (!$wallet) {
                throw new RuntimeException(
                    "Platform revenue account for {$currency} is invalid."
                );
            }
        }

        /*
         * --------------------------------------------------
         * Create revenue wallet when it does not exist.
         * --------------------------------------------------
         */

        if (!$wallet) {
            $wallet = AdminWallet::create([
                'wallet_name' => "Tunko {$currency} Platform Revenue",

                'wallet_number' => 'REV-'
                    . $currency
                    . '-'
                    . strtoupper(Str::random(12)),

                'currency' => $currency,

                'balance' => '0.00',

                'status' => 'active',

                'description' =>
                    "Transfer fee revenue account for {$currency}",

                'account_type' => 'platform_revenue',
            ]);

            DB::table('platform_revenue_accounts')->insert([
                'currency' => $currency,

                'admin_wallet_id' => $wallet->id,

                'created_at' => now(),

                'updated_at' => now(),
            ]);
        }

        /*
         * --------------------------------------------------
         * Validate revenue wallet.
         * --------------------------------------------------
         */

        if (strtoupper($wallet->currency) !== $currency) {
            throw new RuntimeException(
                'Revenue wallet currency does not match transfer currency.'
            );
        }

        if (!$wallet->isActive()) {
            throw new RuntimeException(
                "Platform revenue wallet for {$currency} is inactive."
            );
        }

        /*
         * --------------------------------------------------
         * Credit platform revenue wallet.
         * --------------------------------------------------
         */

        $balanceBefore = (string) $wallet->balance;

        $balanceBeforeUnits = TransferMoney::units(
            $balanceBefore,
            $currency
        );

        $balanceAfter = TransferMoney::decimal(
            $balanceBeforeUnits + $feeUnits,
            $currency
        );

        $wallet->balance = $balanceAfter;

        $wallet->save();

        /*
         * --------------------------------------------------
         * Record platform wallet transaction.
         * --------------------------------------------------
         */

        AdminWalletTransaction::create([
            'admin_wallet_id' => $wallet->id,

            'reference' => 'FEE-' . $reference,

            'type' => 'credit',

            'amount' => $feeAmount,

            'balance_before' => $balanceBefore,

            'balance_after' => $balanceAfter,

            'currency' => $currency,

            'source' => $source,

            'description' =>
                "Transfer fee revenue for {$reference}",
        ]);

        /*
         * --------------------------------------------------
         * Create financial journal.
         * --------------------------------------------------
         */

        $journalId = DB::table('financial_journals')
            ->insertGetId([
                'reference' => $reference,

                'currency' => $currency,

                'source' => $source,

                'created_at' => now(),

                'updated_at' => now(),
            ]);

        /*
         * --------------------------------------------------
         * Build balanced double-entry journal.
         *
         * Sender:
         *   Debit = principal + fee
         *
         * Recipient / office:
         *   Credit = principal
         *
         * Platform revenue:
         *   Credit = fee
         * --------------------------------------------------
         */

        $senderDebit = TransferMoney::decimal(
            $principalUnits + $feeUnits,
            $currency
        );

        $recipientCredit = TransferMoney::decimal(
            $principalUnits,
            $currency
        );

        $timestamp = now();

        DB::table('financial_journal_entries')->insert([
            [
                'journal_id' => $journalId,

                'account_type' => 'sender_wallet',

                'account_id' => null,

                'side' => 'debit',

                'amount' => $senderDebit,

                'currency' => $currency,

                'created_at' => $timestamp,

                'updated_at' => $timestamp,
            ],
            [
                'journal_id' => $journalId,

                'account_type' => $source === 'office_transfer'
                    ? 'office_clearing'
                    : 'recipient_wallet',

                'account_id' => null,

                'side' => 'credit',

                'amount' => $recipientCredit,

                'currency' => $currency,

                'created_at' => $timestamp,

                'updated_at' => $timestamp,
            ],
            [
                'journal_id' => $journalId,

                'account_type' => 'platform_revenue',

                'account_id' => $wallet->id,

                'side' => 'credit',

                'amount' => $feeAmount,

                'currency' => $currency,

                'created_at' => $timestamp,

                'updated_at' => $timestamp,
            ],
        ]);
    }
}
