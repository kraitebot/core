<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exchange's own income ledger: every realized-PnL, commission and
 * funding record, stamped with the moment the exchange booked it.
 *
 * `positions.pnl` answers "what did this trade earn in total" and is filed
 * under the day the trade closed. That is the right shape for a trade, and
 * the wrong shape for a day: a position opened one evening and closed the
 * next morning carries its opening commission and its overnight funding into
 * the closing day, while the exchange books each of those the moment it
 * charged them. On 2026-07-29 that put Kraite's daily figure at +11.51
 * against Binance's +9.83 for the same hours — same money, different day.
 *
 * Storing the events themselves lets daily figures be built from when money
 * actually moved. Position-level PnL keeps its own meaning and is untouched.
 *
 * `tran_id` is the exchange's transaction id and is what makes syncing
 * idempotent — a re-sync of an overlapping window updates rather than
 * duplicates. Binance can emit several records sharing one `tran_id` (a fill
 * producing both realized PnL and commission), so uniqueness spans the type
 * and symbol too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_incomes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('tran_id')->comment('Exchange transaction id — the idempotency key.');
            $table->string('income_type', 32)->comment('REALIZED_PNL, COMMISSION, FUNDING_FEE, TRANSFER, …');
            $table->string('symbol', 32)->nullable()->comment('Null for account-level records such as transfers.');
            $table->decimal('income', 20, 8);
            $table->string('asset', 16)->nullable();
            $table->dateTime('occurred_at')->comment('When the exchange booked it, UTC.');
            $table->timestamps();

            $table->unique(['account_id', 'tran_id', 'income_type', 'symbol'], 'account_incomes_exchange_unique');
            $table->index(['account_id', 'occurred_at'], 'account_incomes_account_time_index');
            $table->index(['account_id', 'income_type', 'occurred_at'], 'account_incomes_account_type_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_incomes');
    }
};
