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
 * if the current status matches. Prevents concurrent workflows (e.g. a WAP
 * revert-to-active racing a close workflow's transition to 'closing') from
 * clobbering each other's state. When guard mismatches, the job no-ops.
 */
final class UpdatePositionStatusJob extends BaseQueueableJob
{
    public Position $position;

    public string $status;

    public ?string $message;

    public ?string $onlyFromStatus;

    public function __construct(
        int $positionId,
        string $status,
        ?string $message = null,
        ?string $onlyFromStatus = null,
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

        // Conditional guard: only flip when current status matches the
        // declared precondition. Lets WAP / sync / similar workflows revert
        // without stomping over a later workflow that has already advanced
        // the position into a different non-terminal state (e.g. 'closing').
        if ($this->onlyFromStatus !== null && $previousStatus !== $this->onlyFromStatus) {
            return [
                'position_id' => $position->id,
                'previous_status' => $previousStatus,
                'requested_status' => $this->status,
                'skipped' => true,
                'reason' => "guard requires status='{$this->onlyFromStatus}', got '{$previousStatus}'",
            ];
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
