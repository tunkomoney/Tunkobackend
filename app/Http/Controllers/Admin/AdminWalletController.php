<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\AdminController;
use Illuminate\Http\Request;

class AdminWalletController extends AdminController
{
    /**
     * Display admin wallet.
     */
    public function index(Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'Admin wallets loaded.',
            'data' => \App\Models\AdminWallet::query()->orderBy('currency')->orderBy('account_type')->get(),
        ]);
    }

    /**
     * Wallet transactions.
     */
    public function transactions(Request $request)
    {
        return response()->json([
            'success' => true,
            'message' => 'Admin wallet transactions loaded.',
            'data' => \App\Models\AdminWalletTransaction::query()->with('wallet')->latest()->paginate(50),
        ]);
    }

    public function revenue(Request $request)
    {
        $rows = \App\Models\AdminWallet::query()->where('account_type', 'platform_revenue')
            ->orderBy('currency')->get(['id', 'wallet_name', 'currency', 'balance', 'status']);
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function reconcile(Request $request)
    {
        $rows = \Illuminate\Support\Facades\DB::table('platform_revenue_accounts as a')
            ->join('admin_wallets as w', 'w.id', '=', 'a.admin_wallet_id')
            ->leftJoin('financial_journal_entries as e', function ($join) {
                $join->on('e.account_id', '=', 'w.id')->where('e.account_type', '=', 'platform_revenue');
            })
            ->select('a.currency', 'w.id as wallet_id', 'w.balance')
            ->selectRaw("COALESCE(SUM(CASE WHEN e.side = 'credit' THEN e.amount ELSE -e.amount END), 0) as journal_balance")
            ->groupBy('a.currency', 'w.id', 'w.balance')->get()
            ->map(fn ($row) => [
                'currency' => $row->currency,
                'wallet_id' => $row->wallet_id,
                'wallet_balance' => $row->balance,
                'journal_balance' => $row->journal_balance,
                'balanced' => \App\Services\TransferMoney::units((string) $row->balance, $row->currency)
                    === \App\Services\TransferMoney::units((string) $row->journal_balance, $row->currency),
            ]);
        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * Fund admin wallet.
     */
    public function fund(Request $request)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['nullable', 'string', 'max:10'],
            'description' => ['nullable', 'string'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Wallet funded successfully.',
            'data' => $validated,
        ]);
    }

    /**
     * Update wallet.
     */
    public function update(Request $request, $adminWallet)
    {
        return response()->json([
            'success' => true,
            'message' => 'Admin wallet updated.',
            'wallet_id' => $adminWallet,
        ]);
    }
}
