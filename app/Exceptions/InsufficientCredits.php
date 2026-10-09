<?php

namespace App\Exceptions;

use RuntimeException;

/** A reseller tried to spend more credits than they have (CreditService::charge). */
class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly int $needed, public readonly int $balance)
    {
        parent::__construct("Needs {$needed} credits, has {$balance}.");
    }
}
