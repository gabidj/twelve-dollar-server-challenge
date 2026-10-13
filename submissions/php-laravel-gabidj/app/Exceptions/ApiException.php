<?php

namespace App\Exceptions;

use Exception;

final class ApiException extends Exception
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
