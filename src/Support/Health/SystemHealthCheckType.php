<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health;

use Kraite\Core\Support\Health\Contracts\SystemHealthProbe;

enum SystemHealthCheckType: string
{
    case MarkPriceFreshness = 'checkMarkPriceFreshness';
    case IndicatorFreshness = 'checkIndicatorFreshness';
    case AccountBalanceFreshness = 'checkAccountBalanceFreshness';
    case DaemonHeartbeat = 'checkDaemonHeartbeat';
    case DispatcherTickRate = 'checkDispatcherTickRate';
    case SchedulerLiveness = 'checkSchedulerLiveness';
    case FailedJobsOverflow = 'checkFailedJobsOverflow';
    case DatabaseConnection = 'checkDatabaseConnection';
    case RedisConnection = 'checkRedisConnection';
    case HorizonQueueDepth = 'checkHorizonQueueDepth';
    case OrphanReconciliation = 'checkOrphanReconciliation';
    case DiskPressure = 'checkDiskPressure';
    case StaleSyncingPositions = 'checkStaleSyncingPositions';
    case UserDataDeadLetters = 'checkUserDataDeadLetters';
    case UserDataStreamDispatchMode = 'checkUserDataStreamDispatchMode';
    case RuntimeUnitStatus = 'checkRuntimeUnitStatus';
    case FleetMetricsSilence = 'checkFleetMetricsSilence';
    case PublicEndpoints = 'checkPublicEndpoints';
    case MaintenanceModeStuck = 'checkMaintenanceModeStuck';

    /** @return list<self> */
    public static function standardCases(): array
    {
        $types = [];

        foreach (self::cases() as $type) {
            if ($type !== self::MaintenanceModeStuck) {
                $types[] = $type;
            }
        }

        return $types;
    }

    public function isDispatcherDependent(): bool
    {
        return $this === self::IndicatorFreshness
            || $this === self::AccountBalanceFreshness;
    }

    public function run(SystemHealthProbe $probe): int
    {
        return match ($this) {
            self::MarkPriceFreshness => $probe->checkMarkPriceFreshness(),
            self::IndicatorFreshness => $probe->checkIndicatorFreshness(),
            self::AccountBalanceFreshness => $probe->checkAccountBalanceFreshness(),
            self::DaemonHeartbeat => $probe->checkDaemonHeartbeat(),
            self::DispatcherTickRate => $probe->checkDispatcherTickRate(),
            self::SchedulerLiveness => $probe->checkSchedulerLiveness(),
            self::FailedJobsOverflow => $probe->checkFailedJobsOverflow(),
            self::DatabaseConnection => $probe->checkDatabaseConnection(),
            self::RedisConnection => $probe->checkRedisConnection(),
            self::HorizonQueueDepth => $probe->checkHorizonQueueDepth(),
            self::OrphanReconciliation => $probe->checkOrphanReconciliation(),
            self::DiskPressure => $probe->checkDiskPressure(),
            self::StaleSyncingPositions => $probe->checkStaleSyncingPositions(),
            self::UserDataDeadLetters => $probe->checkUserDataDeadLetters(),
            self::UserDataStreamDispatchMode => $probe->checkUserDataStreamDispatchMode(),
            self::RuntimeUnitStatus => $probe->checkRuntimeUnitStatus(),
            self::FleetMetricsSilence => $probe->checkFleetMetricsSilence(),
            self::PublicEndpoints => $probe->checkPublicEndpoints(),
            self::MaintenanceModeStuck => $probe->checkMaintenanceModeStuck(),
        };
    }
}
