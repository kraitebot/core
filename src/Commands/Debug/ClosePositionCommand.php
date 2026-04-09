<?php

declare(strict_types=1);

namespace Kraite\Core\Commands\Debug;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\BaseCommand;

/**
 * Debug command to trigger the ClosePositionJob workflow.
 *
 * Used to test orderly position closing (cancel orders first, then close).
 */
final class ClosePositionCommand extends BaseCommand
{
    protected $signature = 'kraite:debug-close-position
                            {position_id : The position ID to close}
                            {--clean : Truncate steps, model_logs, and api_request_logs before running}';

    protected $description = 'Trigger the ClosePositionJob workflow for a position (orderly close)';

    public function handle(): int
    {
        $positionId = (int) $this->argument('position_id');

        /** @var Position|null $position */
        $position = Position::find($positionId);

        if (! $position) {
            $this->error("Position #{$positionId} not found");

            return self::FAILURE;
        }

        $this->info("Position #{$position->id}");
        $this->info("  Token: {$position->exchangeSymbol->parsed_trading_pair}");
        $this->info("  Direction: {$position->direction}");
        $this->info("  Status: {$position->status}");

        if ($this->option('clean')) {
            $this->truncateTables();
        }

        Step::create([
            'class' => ClosePositionJob::class,
            'arguments' => [
                'positionId' => $position->id,
                'message' => 'Debug: manual close position trigger',
            ],
            'child_block_uuid' => (string) Str::uuid(),
        ]);

        $this->newLine();
        $this->info('Dispatched ClosePositionJob');
        $this->comment('Steps will be processed by the dispatcher (supervisor).');

        return self::SUCCESS;
    }

    private function truncateTables(): void
    {
        $this->newLine();
        $this->warn('Truncating tables...');

        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('steps')->truncate();
        DB::table('model_logs')->truncate();
        DB::table('api_request_logs')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->info('  Truncated: steps, model_logs, api_request_logs');
    }
}
