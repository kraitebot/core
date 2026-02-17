<?php

declare(strict_types=1);

namespace Kraite\Core\Concerns\Order;

use Throwable;

trait HasStatuses
{
    public function updateToCancelled(?string $message = null)
    {
        $data = ['reference_status' => 'CANCELLED'];

        if ($message !== null) {
            $data['error_message'] = $message;
        }

        $this->updateSaving($data);
    }

    public function updateToFailed(string|Throwable $e)
    {
        if (is_string($e)) {
            $errorMessage = $e;
            $traceMessage = null;
        } else {
            $errorMessage = $e->getMessage().' (line '.$e->getLine().')';
            $traceMessage = $e->getTraceAsString();
        }

        $this->updateSaving([
            'reference_status' => 'FAILED',
            'error_message' => $errorMessage,
        ]);
    }
}
