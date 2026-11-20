<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\WalletTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;

class WalletTransferService
{
    /*
    |--------------------------------------------------------------------------
    | Verify Recipient
    |--------------------------------------------------------------------------
    */

    public function verifyRecipient(
        User $sender,
        array $data
    ): array {

        $recipient = $this->findRecipient(
            $data['recipient']
        );

        if (!$recipient) {
            throw new Exception(
                'Recipient not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent Self Transfer
        |--------------------------------------------------------------------------
        */

        if (
            $recipient->id ===
            $sender->id
        ) {
            throw new Exception(
                'You cannot transfer to yourself.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Recipient Wallet
        |--------------------------------------------------------------------------
        */

        if (!$recipient->wallet) {
            throw new Exception(
                'Recipient wallet not found.'
            );
        }

        if (!$recipient->wallet->is_active) {
            throw new Exception(
                'Recipient wallet is unavailable.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Return Complete Recipient
        |--------------------------------------------------------------------------
        */

        return $this->recipientData(
            $recipient
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Quote
    |--------------------------------------------------------------------------
    */

    public function quote(
        User $sender,
        array $data
    ): array {

        $sender->load('wallet');

        if (!$sender->wallet) {
            throw new Exception(
                'Sender wallet not found.'
            );
        }

        $recipient = $this->verifyRecipient(
            $sender,
            [
                'recipient' =>
                    $data['recipient'],
            ]
        );

        $currency = strtoupper($sender->wallet->currency);
        $amountUnits = TransferMoney::units($data['amount'], $currency);
        $amount = TransferMoney::decimal($amountUnits, $currency);

        /*
        |--------------------------------------------------------------------------
        | Transfer Fee
        |--------------------------------------------------------------------------
        */

        $feeUnits = TransferMoney::fee($amountUnits, 1);
        $fee = TransferMoney::decimal($feeUnits, $currency);
        $total = TransferMoney::decimal($amountUnits + $feeUnits, $currency);

        return [

            'recipient' =>
                $recipient,

            'amount' =>
                $amount,

            'fee' =>
                $fee,

            'total' =>
                $total,

            'currency' =>
                $sender->wallet->currency,

        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Send Money
    |--------------------------------------------------------------------------
    */

    public function send(
        User $sender,
        array $data
    ): array {

        /*
        |--------------------------------------------------------------------------
        | Load Sender Wallet
        |--------------------------------------------------------------------------
        */

        $sender->load('wallet');

        if (!$sender->wallet) {
            throw new Exception(
                'Sender wallet not found.'
            );
        }

        if (!$sender->wallet->is_active) {
            throw new Exception(
                'Your wallet is inactive.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Verify PIN
        |--------------------------------------------------------------------------
        */

        if (
            empty(
                $sender->transaction_pin
            ) ||
            !Hash::check(
                $data['pin'],
                $sender->transaction_pin
            )
        ) {
            throw new Exception(
                'Invalid transaction PIN.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve Recipient Again
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | We intentionally search again here.
        |
        | The Flutter application may have verified
        | the recipient several seconds earlier.
        |
        | The final transfer must never trust the
        | client-side recipient object.
        |
        */

        $recipient = $this->findRecipient(
            $data['recipient']
        );

        if (!$recipient) {
            throw new Exception(
                'Recipient not found.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Prevent Self Transfer
        |--------------------------------------------------------------------------
        */

        if (
            $recipient->id ===
            $sender->id
        ) {
            throw new Exception(
                'You cannot transfer to yourself.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Recipient Wallet
        |--------------------------------------------------------------------------
        */

        if (!$recipient->wallet) {
            throw new Exception(
                'Recipient wallet not found.'
            );
        }

        if (!$recipient->wallet->is_active) {
            throw new Exception(
                'Recipient wallet is inactive.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Amount
        |--------------------------------------------------------------------------
        */

        $currency = strtoupper($sender->wallet->currency);
        $amountUnits = TransferMoney::units($data['amount'], $currency);
        $amount = TransferMoney::decimal($amountUnits, $currency);

        /*
        |--------------------------------------------------------------------------
        | Fee
        |--------------------------------------------------------------------------
        */

        $feeUnits = TransferMoney::fee($amountUnits, 1);
        $fee = TransferMoney::decimal($feeUnits, $currency);
        $totalUnits = $amountUnits + $feeUnits;
        $total = TransferMoney::decimal($totalUnits, $currency);

        if (strtoupper($recipient->wallet->currency) !== $currency) {
            throw new Exception('Transfers require matching wallet currencies.');
        }

        $idempotencyKey = $data['idempotency_key'] ?? null;
        $requestHash = hash('sha256', json_encode([
            'recipient_id' => $recipient->id,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $data['description'] ?? null,
        ], JSON_THROW_ON_ERROR));
        if ($idempotencyKey) {
            $existing = WalletTransfer::where('sender_id', $sender->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if (!hash_equals((string) $existing->request_hash, $requestHash)) {
                    throw new Exception('Idempotency key was already used for a different transfer.');
                }
                return ['reference' => $existing->reference, 'status' => $existing->status,
                    'amount' => $existing->amount, 'fee' => $existing->fee, 'total' => $existing->total,
                    'currency' => $existing->currency, 'idempotent_replay' => true];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Initial Balance Check
        |--------------------------------------------------------------------------
        */

        if (
            TransferMoney::units((string) $sender->wallet->balance, $currency)
            < $totalUnits
        ) {
            throw new Exception(
                'Insufficient wallet balance.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Reference
        |--------------------------------------------------------------------------
        */

        $reference =
            $this->generateReference();

        /*
        |--------------------------------------------------------------------------
        | Atomic Transfer
        |--------------------------------------------------------------------------
        */

        return DB::transaction(
            function () use (
                $sender,
                $recipient,
                $amount,
                $fee,
                $feeUnits,
                $amountUnits,
                $total,
                $totalUnits,
                $currency,
                $reference,
                $idempotencyKey,
                $requestHash,
                $data
            ) {

                /*
                |--------------------------------------------------------------------------
                | Lock Sender Wallet
                |--------------------------------------------------------------------------
                */

                $senderWallet =
                    Wallet::lockForUpdate()
                        ->find(
                            $sender->wallet->id
                        );

                if (!$senderWallet) {
                    throw new Exception(
                        'Sender wallet not found.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Lock Recipient Wallet
                |--------------------------------------------------------------------------
                */

                $recipientWallet =
                    Wallet::lockForUpdate()
                        ->find(
                            $recipient->wallet->id
                        );

                if (!$recipientWallet) {
                    throw new Exception(
                        'Recipient wallet not found.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Recheck Wallet Status
                |--------------------------------------------------------------------------
                */

                if (
                    !$senderWallet->is_active
                ) {
                    throw new Exception(
                        'Your wallet is inactive.'
                    );
                }

                if (
                    !$recipientWallet->is_active
                ) {
                    throw new Exception(
                        'Recipient wallet is inactive.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Final Balance Check
                |--------------------------------------------------------------------------
                */

                if (
                    TransferMoney::units((string) $senderWallet->balance, $currency)
                    < $totalUnits
                ) {
                    throw new Exception(
                        'Insufficient wallet balance.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Debit Sender
                |--------------------------------------------------------------------------
                */

                $senderWallet->balance = TransferMoney::decimal(
                    TransferMoney::units((string) $senderWallet->balance, $currency) - $totalUnits,
                    $currency
                );

                $senderWallet->save();

                /*
                |--------------------------------------------------------------------------
                | Credit Recipient
                |--------------------------------------------------------------------------
                */

                $recipientWallet->balance = TransferMoney::decimal(
                    TransferMoney::units((string) $recipientWallet->balance, $currency) + $amountUnits,
                    $currency
                );

                $recipientWallet->save();

                /*
                |--------------------------------------------------------------------------
                | Create Wallet Transfer
                |--------------------------------------------------------------------------
                */

                $transfer =
                    WalletTransfer::create([

                        'reference' =>
                            $reference,
                        'idempotency_key' => $idempotencyKey,
                        'request_hash' => $requestHash,

                        'sender_id' =>
                            $sender->id,

                        'recipient_id' =>
                            $recipient->id,

                        'sender_wallet_id' =>
                            $senderWallet->id,

                        'recipient_wallet_id' =>
                            $recipientWallet->id,

                        'amount' =>
                            $amount,

                        'fee' =>
                            $fee,

                        'total' =>
                            $total,

                        'currency' =>
                            $senderWallet->currency,

                        'status' =>
                            'completed',

                        'description' =>
                            $data['description']
                            ?? null,

                        'completed_at' =>
                            now(),

                    ]);

                /*
                |--------------------------------------------------------------------------
                | Sender Transaction
                |--------------------------------------------------------------------------
                */

                Transaction::create([

                    'user_id' =>
                        $sender->id,

                    'reference' =>
                        $reference,

                    'type' =>
                        'transfer',

                    'title' =>
                        'Money Transfer',

                    'amount' =>
                        $amount,

                    'fee' =>
                        $fee,

                    'total' =>
                        $total,

                    'currency' =>
                        $senderWallet->currency,

                    'status' =>
                        'completed',

                    'description' =>
                        'Transfer to ' .
                        $recipient->full_name,

                    'meta' => [

                        'direction' =>
                            'debit',

                        'recipient_id' =>
                            $recipient->id,

                        'recipient_name' =>
                            $recipient->full_name,

                        'recipient_phone' =>
                            $recipient->phone,

                        'recipient_wallet' =>
                            $recipientWallet
                                ->wallet_number,

                        'currency' =>
                            $senderWallet->currency,

                    ],

                ]);

                app(PlatformFeeLedger::class)->post(
                    $reference,
                    $currency,
                    $amountUnits,
                    $feeUnits,
                    'wallet_transfer'
                );

                /*
                |--------------------------------------------------------------------------
                | Recipient Transaction
                |--------------------------------------------------------------------------
                */

                Transaction::create([

                    'user_id' =>
                        $recipient->id,

                    'reference' =>
                        $reference,

                    'type' =>
                        'transfer',

                    'title' =>
                        'Money Received',

                    'amount' =>
                        $amount,

                    'fee' =>
                        0,

                    'total' =>
                        $amount,

                    'currency' =>
                        $recipientWallet->currency,

                    'status' =>
                        'completed',

                    'description' =>
                        'Received from ' .
                        $sender->full_name,

                    'meta' => [

                        'direction' =>
                            'credit',

                        'sender_id' =>
                            $sender->id,

                        'sender_name' =>
                            $sender->full_name,

                        'sender_phone' =>
                            $sender->phone,

                        'sender_wallet' =>
                            $senderWallet
                                ->wallet_number,

                        'currency' =>
                            $recipientWallet->currency,

                    ],

                ]);

                /*
                |--------------------------------------------------------------------------
                | Save Beneficiary
                |--------------------------------------------------------------------------
                */

                $this->saveBeneficiary(
                    $sender,
                    $recipient,
                    $recipientWallet
                );

                /*
                |--------------------------------------------------------------------------
                | Refresh Wallets
                |--------------------------------------------------------------------------
                */

                $senderWallet->refresh();

                $recipientWallet->refresh();

                /*
                |--------------------------------------------------------------------------
                | Return Transfer Result
                |--------------------------------------------------------------------------
                */

                return [

                    'reference' =>
                        $reference,

                    'transaction_id' =>
                        $transfer->id,

                    'status' =>
                        'completed',

                    'amount' =>
                        $amount,

                    'send_amount' =>
                        $amount,

                    'receive_amount' =>
                        $amount,

                    'fee' =>
                        $fee,

                    'total' =>
                        $total,

                    'exchange_rate' =>
                        1,

                    'currency' =>
                        $senderWallet->currency,

                    'description' =>
                        $data['description']
                        ?? null,

                    'wallet_balance' =>
                        (float)
                            $senderWallet->balance,

                    'sender' =>
                        $this->senderData(
                            $sender,
                            $senderWallet
                        ),

                    'recipient' =>
                        $this->recipientData(
                            $recipient,
                            $recipientWallet
                        ),

                    'completed_at' =>
                        $transfer->completed_at,

                ];
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Find Recipient
    |--------------------------------------------------------------------------
    */

    protected function findRecipient(
        string $value
    ): ?User {

        $value =
            trim($value);

        if ($value === '') {
            return null;
        }

        return User::query()

            ->where(function ($query) use ($value) {

                $query

                    ->where(
                        'phone',
                        $value
                    )

                    ->orWhere(
                        'username',
                        $value
                    )

                    ->orWhere(
                        'email',
                        $value
                    )

                    ->orWhereHas(
                        'wallet',
                        function ($walletQuery)
                            use ($value) {

                            $walletQuery->where(
                                'wallet_number',
                                $value
                            );

                        }
                    );

            })

            ->with('wallet')

            ->first();
    }


    /*
    |--------------------------------------------------------------------------
    | Recipient Response
    |--------------------------------------------------------------------------
    */

    protected function recipientData(
        User $recipient,
        ?Wallet $wallet = null
    ): array {

        $wallet ??=
            $recipient->wallet;

        return [

            'id' =>
                $recipient->id,

            'first_name' =>
                $recipient->first_name,

            'last_name' =>
                $recipient->last_name,

            'name' =>
                $recipient->full_name,

            'full_name' =>
                $recipient->full_name,

            'email' =>
                $recipient->email,

            'phone' =>
                $recipient->phone,

            'address' =>
                $recipient->address
                ?? null,

            'country' =>
                $recipient->country
                ?? null,

            'wallet_number' =>
                $wallet?->wallet_number,

            'currency' =>
                $wallet?->currency,

            'is_verified' =>
                (bool)
                    $recipient->is_verified,

        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Sender Response
    |--------------------------------------------------------------------------
    */

    protected function senderData(
        User $sender,
        Wallet $wallet
    ): array {

        return [

            'id' =>
                $sender->id,

            'first_name' =>
                $sender->first_name,

            'last_name' =>
                $sender->last_name,

            'name' =>
                $sender->full_name,

            'full_name' =>
                $sender->full_name,

            'email' =>
                $sender->email,

            'phone' =>
                $sender->phone,

            'address' =>
                $sender->address
                ?? null,

            'country' =>
                $sender->country
                ?? null,

            'wallet_number' =>
                $wallet->wallet_number,

            'currency' =>
                $wallet->currency,

        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Save Beneficiary
    |--------------------------------------------------------------------------
    */

    protected function saveBeneficiary(
        User $sender,
        User $recipient,
        Wallet $recipientWallet
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Use existing Recipient model if available.
        |--------------------------------------------------------------------------
        */

        if (
            class_exists(
                \App\Models\Recipient::class
            )
        ) {

            \App\Models\Recipient::firstOrCreate(

                [

                    'user_id' =>
                        $sender->id,

                    'wallet_number' =>
                        $recipientWallet
                            ->wallet_number,

                ],

                [

                    'name' =>
                        $recipient->full_name,

                    'phone' =>
                        $recipient->phone,

                    'country' =>
                        $recipient->country
                        ?? null,

                    'currency' =>
                        $recipientWallet
                            ->currency,

                ]

            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    */

    public function history(
        User $user
    ) {

        return WalletTransfer::query()

            ->where(function ($query) use ($user) {

                $query
                    ->where(
                        'sender_id',
                        $user->id
                    )
                    ->orWhere(
                        'recipient_id',
                        $user->id
                    );

            })

            ->with([
                'sender',
                'recipient',
            ])

            ->latest()

            ->paginate(20);
    }


    /*
    |--------------------------------------------------------------------------
    | Receipt
    |--------------------------------------------------------------------------
    */

    public function receipt(
        string $reference,
        ?User $user = null
    ): array {

        $query =
            WalletTransfer::query()

                ->with([
                    'sender.wallet',
                    'recipient.wallet',
                ])

                ->where(
                    'reference',
                    $reference
                );

        /*
        |--------------------------------------------------------------------------
        | Security
        |--------------------------------------------------------------------------
        |
        | If a user is supplied, only the sender or recipient
        | can access the receipt.
        |
        */

        if ($user) {

            $query->where(function ($q) use ($user) {

                $q->where(
                    'sender_id',
                    $user->id
                )

                ->orWhere(
                    'recipient_id',
                    $user->id
                );

            });
        }

        $transfer =
            $query->first();

        if (!$transfer) {

            throw new Exception(
                'Transfer receipt not found.'
            );
        }

        return [

            'reference' =>
                $transfer->reference,

            'status' =>
                $transfer->status,

            'created_at' =>
                optional(
                    $transfer->completed_at
                )->toDateTimeString(),

            'date' =>
                optional(
                    $transfer->completed_at
                )->format(
                    'd M Y H:i'
                ),

            'description' =>
                $transfer->description,

            'amount' =>
                (float)
                    $transfer->amount,

            'send_amount' =>
                (float)
                    $transfer->amount,

            'receive_amount' =>
                (float)
                    $transfer->amount,

            'fee' =>
                (float)
                    $transfer->fee,

            'total' =>
                (float)
                    $transfer->total,

            'exchange_rate' =>
                1,

            'currency' =>
                $transfer->currency,

            'sender' => [

                'id' =>
                    $transfer->sender->id,

                'first_name' =>
                    $transfer->sender->first_name,

                'last_name' =>
                    $transfer->sender->last_name,

                'name' =>
                    $transfer->sender->full_name,

                'email' =>
                    $transfer->sender->email,

                'phone' =>
                    $transfer->sender->phone,

                'address' =>
                    $transfer->sender->address
                    ?? null,

                'country' =>
                    $transfer->sender->country
                    ?? null,

                'wallet_number' =>
                    optional(
                        $transfer->sender->wallet
                    )->wallet_number,

            ],

            'recipient' => [

                'id' =>
                    $transfer->recipient->id,

                'first_name' =>
                    $transfer->recipient->first_name,

                'last_name' =>
                    $transfer->recipient->last_name,

                'name' =>
                    $transfer->recipient->full_name,

                'email' =>
                    $transfer->recipient->email,

                'phone' =>
                    $transfer->recipient->phone,

                'address' =>
                    $transfer->recipient->address
                    ?? null,

                'country' =>
                    $transfer->recipient->country
                    ?? null,

                'wallet_number' =>
                    optional(
                        $transfer->recipient->wallet
                    )->wallet_number,

            ],

        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Beneficiaries
    |--------------------------------------------------------------------------
    */

    public function beneficiaries(
        User $user
    ) {

        return WalletTransfer::query()

            ->where(
                'sender_id',
                $user->id
            )

            ->with([
                'recipient.wallet',
            ])

            ->latest()

            ->get()

            ->unique(
                'recipient_id'
            )

            ->values()

            ->map(function (
                WalletTransfer $transfer
            ) {

                $recipient =
                    $transfer->recipient;

                return [

                    'id' =>
                        $recipient->id,

                    'first_name' =>
                        $recipient->first_name,

                    'last_name' =>
                        $recipient->last_name,

                    'name' =>
                        $recipient->full_name,

                    'phone' =>
                        $recipient->phone,

                    'email' =>
                        $recipient->email,

                    'address' =>
                        $recipient->address
                        ?? null,

                    'country' =>
                        $recipient->country
                        ?? null,

                    'wallet_number' =>
                        optional(
                            $recipient->wallet
                        )->wallet_number,

                    'currency' =>
                        optional(
                            $recipient->wallet
                        )->currency,

                ];

            })

            ->values();
    }


    /*
    |--------------------------------------------------------------------------
    | Calculate Fee
    |--------------------------------------------------------------------------
    */

    protected function calculateFee(float $amount): float
    {
        return round($amount * 0.01, 2);
    }


    /*
    |--------------------------------------------------------------------------
    | Generate Reference
    |--------------------------------------------------------------------------
    */

    protected function generateReference(): string
    {
        return 'WT' .
            Carbon::now()->format(
                'YmdHis'
            ) .
            strtoupper(
                Str::random(6)
            );
    }
}
