<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Position;

trait HasStatuses
{
    public function isActive()
    {
        return ! in_array($this->status, ['closed', 'cancelled']);
    }

    public function isClosing()
    {
        return $this->status === 'closing';
    }

    public function isCancelled()
    {
        return $this->status === 'cancelled';
    }

    public function updateToWatching()
    {
        $this->updateSaving(['status' => 'watching']);
    }

    public function updateToWaping()
    {
        $this->updateSaving(['status' => 'waping']);
    }

    public function updateToOpening()
    {
        $this->updateSaving(['status' => 'opening']);
    }

    public function updateToCancelling()
    {
        $this->updateSaving(['status' => 'cancelling']);
    }

    public function updateToActive()
    {
        $this->updateSaving(['status' => 'active']);
    }

    public function updateToSyncing()
    {
        $this->updateSaving(['status' => 'syncing']);
    }

    public function updateToClosing()
    {
        $this->updateSaving(['status' => 'closing']);
    }

    public function updateToClosed()
    {
        $this->updateSaving([
            'closed_at' => now(),
            'status' => 'closed',
            'error_message' => null, // Clear any previous errors on successful close
        ]);
    }

    public function updateToCancelled(?string $message = null): void
    {
        $data = ['status' => 'cancelled'];

        if ($this->opened_at !== null) {
            $data['closed_at'] = now();
        }

        if ($message !== null) {
            $data['error_message'] = $message;
        }

        $this->updateSaving($data);
    }

    public function updateToFailed(?string $message = null): void
    {
        $data = ['status' => 'failed'];

        if ($this->opened_at !== null) {
            $data['closed_at'] = now();
        }

        if ($message !== null) {
            $data['error_message'] = $message;
        }

        $this->updateSaving($data);
    }
}
