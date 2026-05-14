<?php

namespace App\Enums;

enum WorkflowType: string
{
    case LiveTick = 'live_tick';
    case ManualEntry = 'manual_entry';
}
