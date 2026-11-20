<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wallet_transfers', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->unique(['sender_id', 'idempotency_key'], 'wallet_transfer_sender_idempotency_unique');
        });
        Schema::table('office_transfers', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->unique(['sender_id', 'idempotency_key'], 'office_transfer_sender_idempotency_unique');
        });
        Schema::table('admin_wallets', function (Blueprint $table) {
            $table->string('account_type', 40)->default('operating')->after('currency');
        });
        Schema::create('platform_revenue_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 10)->unique();
            $table->foreignId('admin_wallet_id')->unique()->constrained('admin_wallets')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('financial_journals', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('currency', 10);
            $table->string('source', 40);
            $table->string('reversal_of')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['currency', 'created_at']);
        });
        Schema::create('financial_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('financial_journals')->restrictOnDelete();
            $table->string('account_type', 40);
            $table->unsignedBigInteger('account_id')->nullable();
            $table->enum('side', ['debit', 'credit']);
            $table->decimal('amount', 20, 2);
            $table->string('currency', 10);
            $table->timestamps();
            $table->index(['account_type', 'account_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_journal_entries');
        Schema::dropIfExists('financial_journals');
        Schema::dropIfExists('platform_revenue_accounts');
        Schema::table('office_transfers', function (Blueprint $table) {
            $table->dropUnique('office_transfer_sender_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'request_hash']);
        });
        Schema::table('wallet_transfers', function (Blueprint $table) {
            $table->dropUnique('wallet_transfer_sender_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'request_hash']);
        });
        Schema::table('admin_wallets', fn (Blueprint $table) => $table->dropColumn('account_type'));
    }
};
