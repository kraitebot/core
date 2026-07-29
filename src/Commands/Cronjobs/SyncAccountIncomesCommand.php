<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Cronjobs;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Models\Account;
use StepDispatcher\Support\BaseCommand;
use Throwable;

/**
 * SyncAccountIncomesCommand
 *
 * Mirrors the exchange's income ledger — realized PnL, commission and funding
 * — into `account_incomes`, stamped with the moment the exchange booked each
 * record.
 *
 * Why this exists: `positions.pnl` files a trade's whole result under the day
 * it closed, which is right for a trade and wrong for a day. A position opened
 * one evening and closed the next morning carries its opening commission and
 * overnight funding into the closing day, while the exchange books each the
 * moment it charged them. Daily figures read from this ledger instead, so a
 * day here covers the same money as a day on the exchange statement.
 *
 * Rate-limit shape, which matters: this asks for the whole account in one
 * paginated call, never symbol-by-symbol. Fanning out per symbol against a
 * live account is what tripped Binance's 2,400/minute IP limit on 2026-07-29
 * — a limit the trading engine shares, so a careless sync here degrades
 * trading.
 */
class SyncAccountIncomesCommand extends BaseCommand
{
    /** Binance serves at most 1,000 income records per call. */
    private const PAGE_SIZE = 1000;

    /** Binance retains roughly three months of income history. */
    private const BACKFILL_MONTHS = 3;

    /**
     * Re-ask for a little before the last record already stored. Exchanges can
     * settle a booking slightly after the instant they stamp it, and the
     * unique key makes an overlapping re-read idempotent.
     */
    private const OVERLAP_MINUTES = 10;

    /** Stop a runaway page walk long before it can hammer the exchange. */
    private const MAX_PAGES_PER_RUN = 40;

    protected $signature = 'kraite:cron-sync-account-incomes
                            {--account= : Restrict the sync to one account id}
                            {--output : Display command output (silent by default)}';

    protected $description = 'Mirrors exchange income records (realized PnL, commission, funding) with their booking times.';

    public function handle(): int
    {
        $accounts = Account::query()
            ->when($this->option('account'), fn ($query) => $query->where('id', (int) $this->option('account')))
            ->get();

        foreach ($accounts as $account) {
            try {
                $this->syncAccount($account);
            } catch (Throwable $exception) {
                // One account's exchange trouble must not stop the others, and
                // must never take the scheduler down with it.
                $this->report("income sync failed for account {$account->id}: ".$exception->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function syncAccount(Account $account): void
    {
        $now = CarbonImmutable::now();
        $syncedFrom = $account->incomes_synced_from;

        // First run backfills as far as the exchange will serve; later runs
        // resume just before the newest record already stored.
        $from = $syncedFrom === null
            ? $now->subMonths(self::BACKFILL_MONTHS)
            : $this->resumePoint($account, $now);

        $cursor = $from;
        $stored = 0;

        for ($page = 0; $page < self::MAX_PAGES_PER_RUN; $page++) {
            $records = $this->fetchIncomePage(
                $account,
                $cursor->getTimestampMs(),
                $now->getTimestampMs(),
            );

            if ($records === []) {
                break;
            }

            $stored += $this->store($account, $records);

            if (count($records) < self::PAGE_SIZE) {
                break;
            }

            // Walk forward from the newest record in this page. The +1ms stops
            // the last record being re-served forever on a full page.
            $latest = max(array_map(static fn (array $r): int => (int) ($r['time'] ?? 0), $records));

            if ($latest <= 0) {
                break;
            }

            $cursor = CarbonImmutable::createFromTimestampMs($latest + 1);
        }

        // Only widen the authoritative window; never narrow it, or a later run
        // would strand days that earlier runs had already covered.
        $account->incomes_synced_from = $syncedFrom === null
            ? $from
            : min($syncedFrom, $from);
        $account->save();

        $this->report("account {$account->id}: {$stored} income records synced from {$from->toDateTimeString()}");
    }

    /**
     * Cron commands here are silent by default — the scheduler runs them every
     * few minutes and nobody wants that in a log. Also null-safe, so the sync
     * can be driven directly without a console attached.
     */
    private function report(string $message): void
    {
        if ($this->output === null || ! $this->option('output')) {
            return;
        }

        $this->line($message);
    }

    /**
     * One page of the exchange's income ledger. The single seam between this
     * command and the exchange, so the paging, idempotency and watermark rules
     * can be proven without touching a live account or its rate limit.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchIncomePage(Account $account, int $startTime, int $endTime): array
    {
        $records = $account->apiQueryAccountIncome($startTime, $endTime, self::PAGE_SIZE)->result;

        return is_array($records) ? $records : [];
    }

    /**
     * Where an incremental run should resume: shortly before the newest record
     * already stored, or the existing authoritative start when nothing is
     * stored yet.
     */
    private function resumePoint(Account $account, CarbonImmutable $now): CarbonImmutable
    {
        $latest = DB::table('account_incomes')
            ->where('account_id', $account->id)
            ->max('occurred_at');

        if ($latest === null) {
            return CarbonImmutable::parse((string) $account->incomes_synced_from);
        }

        return CarbonImmutable::parse((string) $latest)
            ->subMinutes(self::OVERLAP_MINUTES)
            ->min($now);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function store(Account $account, array $records): int
    {
        $rows = [];
        $now = now();

        foreach ($records as $record) {
            $tranId = (string) ($record['tranId'] ?? '');
            $incomeType = (string) ($record['incomeType'] ?? '');
            $time = (int) ($record['time'] ?? 0);

            if ($tranId === '' || $incomeType === '' || $time <= 0) {
                continue;
            }

            $rows[] = [
                'account_id' => $account->id,
                'tran_id' => $tranId,
                'income_type' => $incomeType,
                'symbol' => ($record['symbol'] ?? '') !== '' ? (string) $record['symbol'] : null,
                'income' => (string) ($record['income'] ?? '0'),
                'asset' => ($record['asset'] ?? '') !== '' ? (string) $record['asset'] : null,
                'occurred_at' => CarbonImmutable::createFromTimestampMs($time)->toDateTimeString(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        // Upsert on the exchange's own identity so an overlapping re-read
        // corrects a record rather than duplicating it.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('account_incomes')->upsert(
                $chunk,
                ['account_id', 'tran_id', 'income_type', 'symbol'],
                ['income', 'asset', 'occurred_at', 'updated_at'],
            );
        }

        return count($rows);
    }
}
