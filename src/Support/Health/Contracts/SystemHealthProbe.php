<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health\Contracts;

interface SystemHealthProbe
{
    public function checkMarkPriceFreshness(): int;

    public function checkIndicatorFreshness(): int;

    public function checkAccountBalanceFreshness(): int;

    public function checkDaemonHeartbeat(): int;

    public function checkDispatcherTickRate(): int;

    public function checkSchedulerLiveness(): int;

    public function checkFailedJobsOverflow(): int;

    public function checkDatabaseConnection(): int;

    public function checkRedisConnection(): int;

    public function checkHorizonQueueDepth(): int;

    public function checkOrphanReconciliation(): int;

    public function checkDiskPressure(): int;

    public function checkStaleSyncingPositions(): int;

    public function checkUserDataDeadLetters(): int;

    public function checkUserDataStreamDispatchMode(): int;

    public function checkFleetMetricsSilence(): int;

    public function checkMaintenanceModeStuck(): int;
}
