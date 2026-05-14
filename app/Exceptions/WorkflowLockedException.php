<?php

namespace App\Exceptions;

use App\Models\Shift;

class WorkflowLockedException extends \RuntimeException
{
    public function __construct(
        private readonly Shift $shift,
        private readonly string $attemptedValue,
    ) {
        parent::__construct('Cannot change workflow_type after shift has started');
    }

    public function getShift(): Shift
    {
        return $this->shift;
    }

    public function getAttemptedValue(): string
    {
        return $this->attemptedValue;
    }
}
