<?php

declare(strict_types=1);

namespace Kraite\Core\Jobs\Atomic\Position;

use Kraite\Core\Abstracts\BaseQueueableJob;
use Kraite\Core\Models\Position;
use RuntimeException;

/**
 * UpdatePositionStatusJob (Atomic)
 *
 * Generic status updater that calls the appropriate updateTo*() method
 * on the Position model based on the requested status.
 *
 * Used by CancelPositionJob and ClosePositionJob workflows.
 *
 * Supported statuses:
 * - cancelling, closing, syncing, closed, cancelled, failed
 * - active, watching, waping
 *
 * Optional $onlyFromStatus guard: when provided, the transition only fires
 * if the current status matches one of the allowed values. Accepts either a
 * single string (strict guard) or an array of acceptable prior statuses
 * (broader guard — used e.g. by ApplyWapJob to accept both 'active' and
 * 'syncing' as valid starting points when a LIMIT fill detected mid-sync
 * would otherwise race against the sync's own complete()-path flip back
 * to 'active'). Prevents concurrent workflows (e.g. a WAP revert-to-active
 * racing a close workflow's transition to 'closing') from clobbering each
 * other's state. When the guard mismatches, the job no-ops.
 */
final class UpdatePositionStatusJob extends BaseQueueableJob
{
    public Position $position;

    public string $status;

    public ?string $message;

    /** @var string|array<int, string>|null */
    public string|array|null $onlyFromStatus;

    /**
     * @param  string|array<int, string>|null  $onlyFromStatus
     */
    public function __construct(
        int $positionId,
        string $status,
        ?string $message = null,
        string|array|null $onlyFromStatus = null,
    ) {
        $this->position = Position::findOrFail($positionId);
        $this->status = $status;
        $this->message = $message;
        $this->onlyFromStatus = $onlyFromStatus;
    }

    public function relatable()
    {
        return $this->position;
    }

    public function compute()
    {
        $position = $this->position;
        $previousStatus = $position->status;

        // Conditional guard: only flip when current status matches one of
        // the declared preconditions. Lets WAP / sync / similar workflows
        // revert without stomping over a later workflow that has already
        // advanced the position into a different non-terminal state (e.g.
        // 'closing'). Accepts a string or an array of acceptable prior
        // statuses.
        if ($this->onlyFromStatus !== null) {
            $allowed = is_array($this->onlyFromStatus)
                ? $this->onlyFromStatus
                : [$this->onlyFromStatus];

            if (! in_array($previousStatus, $allowed, true)) {
                $expectation = implode(separator: "','", array: $allowed);

                return [
                    'position_id' => $position->id,
                    'previous_status' => $previousStatus,
                    'requested_status' => $this->status,
                    'skipped' => true,
                    'reason' => "guard requires status in ['{$expectation}'], got '{$previousStatus}'",
                ];
            }
        }

        switch ($this->status) {
            case 'cancelling':
                $position->updateToCancelling();
                break;
            case 'closing':
                $position->updateToClosing();
                break;
            case 'syncing':
                $position->updateToSyncing();
                break;
            case 'closed':
                $position->updateToClosed();
                break;
            case 'cancelled':
                $position->updateToCancelled($this->message);
                break;
            case 'failed':
                $position->updateToFailed($this->message);
                break;
            case 'active':
                $position->updateToActive();
                break;
            case 'watching':
                $position->updateToWatching();
                break;
            case 'waping':
                $position->updateToWaping();
                break;
            default:
                throw new RuntimeException("Unknown position status: {$this->status}");
        }

        return [
            'position_id' => $position->id,
            'previous_status' => $previousStatus,
            'new_status' => $this->status,
            'message' => $this->message,
        ];
    }
}
