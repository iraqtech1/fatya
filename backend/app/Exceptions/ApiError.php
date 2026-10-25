<?php

namespace App\Exceptions;

use RuntimeException;

class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
