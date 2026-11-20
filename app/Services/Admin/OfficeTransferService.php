<?php

namespace App\Services\Admin;

use App\Models\OfficeTransfer;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\AdminWallet;
use App\Models\AdminWalletTransaction;
use App\Services\TransferMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class OfficeTransferService
{
    public function index(array $filters)
    {
        return OfficeTransfer::query()
            ->with(['sender', 'sourceOffice', 'destinationOffice', 'processedByStaff'])
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    $q->where('reference', 'like', "%{$search}%")
                        ->orWhere('recipient_phone', 'like', "%{$search}%")
                        ->orWhere('recipient_first_name', 'like', "%{$search}%")
                        ->orWhere('recipient_last_name', 'like', "%{$search}%");
                });
            })
            ->when(isset($filters['office_id']), fn ($q) => $q->where('destination_office_id', $filters['office_id']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($filters['per_page'] ?? 20);
    }

    public function show(OfficeTransfer $transfer): OfficeTransfer
    {
        return $transfer->load(['sender', 'sourceOffice', 'destinationOffice', 'destinationCountry', 'processedByStaff']);
    }

    public function updateStatus(OfficeTransfer $transfer, string $status, ?string $reason = null): OfficeTransfer
    {
        return DB::transaction(function () use ($transfer, $status, $reason) {
            $transfer = OfficeTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            $this->assertTransition($transfer->status, $status);

            if (in_array($status, ['cancelled', 'failed'], true)) {
                $this->refundCustomer($transfer, $reason ?: 'Office transfer cancelled.');
            }

            $transfer->status = $status;
            if ($reason) {
                $transfer->description = $reason;
            }
            if ($status === 'processing' && !$transfer->processing_started_at) {
                $transfer->processing_started_at = now();
            }
            if ($status === 'completed') {
                $transfer->completed_at = now();
            }
            $transfer->save();

            return $transfer->fresh(['sender', 'sourceOffice', 'destinationOffice', 'processedByStaff']);
        });
    }

    protected function assertTransition(string $from, string $to): void
    {
        $allowed = [
            'pending' => ['processing', 'cancelled', 'failed'],
            'processing' => ['completed', 'cancelled', 'failed'],
            'completed' => [],
            'cancelled' => [],
            'failed' => [],
        ];

        if (!in_array($to, $allowed[$from] ?? [], true)) {
            throw new Exception("Invalid office transfer status transition: {$from} → {$to}.");
        }
    }

    protected function refundCustomer(OfficeTransfer $transfer, string $reason): void
    {
        $alreadyRefunded = Transaction::query()
            ->where('type', 'refund')
            ->where('meta->office_transfer_id', $transfer->id)
            ->exists();

        if ($alreadyRefunded) {
            return;
        }

        $wallet = Wallet::query()
            ->where('user_id', $transfer->sender_id)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            throw new Exception('Customer wallet not found for refund.');
        }

        $currency = strtoupper($transfer->currency);
        $wallet->balance = TransferMoney::decimal(
            TransferMoney::units((string) $wallet->balance, $currency)
                + TransferMoney::units((string) $transfer->total, $currency),
            $currency
        );
        $wallet->save();

        $revenue = AdminWallet::query()->where('currency', $currency)
            ->where('account_type', 'platform_revenue')->lockForUpdate()->first();
        if ($revenue && TransferMoney::units((string) $transfer->fee, $currency) > 0) {
            $feeUnits = TransferMoney::units((string) $transfer->fee, $currency);
            $before = (string) $revenue->balance;
            $beforeUnits = TransferMoney::units($before, $currency);
            if ($beforeUnits < $feeUnits) {
                throw new Exception('Platform revenue balance is insufficient to reverse the office transfer fee.');
            }
            $after = TransferMoney::decimal($beforeUnits - $feeUnits, $currency);
            $revenue->balance = $after;
            $revenue->save();
            AdminWalletTransaction::create([
                'admin_wallet_id' => $revenue->id,
                'reference' => 'REV-FEE-' . $transfer->reference,
                'type' => 'debit',
                'amount' => $transfer->fee,
                'balance_before' => $before,
                'balance_after' => $after,
                'currency' => $currency,
                'source' => 'office_transfer_reversal',
                'description' => 'Fee reversal for ' . $transfer->reference,
            ]);
        }

        $originalJournal = DB::table('financial_journals')->where('reference', $transfer->reference)->first();
        if ($originalJournal) {
            $reversalReference = 'REV-' . $transfer->reference;
            if (!DB::table('financial_journals')->where('reference', $reversalReference)->exists()) {
                $reversalId = DB::table('financial_journals')->insertGetId([
                    'reference' => $reversalReference,
                    'currency' => $currency,
                    'source' => 'office_transfer_reversal',
                    'reversal_of' => $transfer->reference,
                    'description' => $reason,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $entries = DB::table('financial_journal_entries')->where('journal_id', $originalJournal->id)->get();
                foreach ($entries as $entry) {
                    DB::table('financial_journal_entries')->insert([
                        'journal_id' => $reversalId,
                        'account_type' => $entry->account_type,
                        'account_id' => $entry->account_id,
                        'side' => $entry->side === 'debit' ? 'credit' : 'debit',
                        'amount' => $entry->amount,
                        'currency' => $entry->currency,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        Transaction::create([
            'user_id' => $transfer->sender_id,
            'reference' => 'OFR-' . strtoupper(Str::random(16)),
            'type' => 'refund',
            'amount' => $transfer->total,
            'fee' => 0,
            'total' => $transfer->total,
            'status' => 'completed',
            'description' => $reason,
            'meta' => [
                'direction' => 'credit',
                'transfer_type' => 'office_transfer_refund',
                'office_transfer_id' => $transfer->id,
                'office_transfer_reference' => $transfer->reference,
            ],
        ]);
    }
}
