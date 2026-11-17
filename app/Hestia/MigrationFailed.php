<?php

namespace App\Hestia;

use RuntimeException;

final class MigrationFailed extends RuntimeException
{
    /**
     * @param  list<string>  $details  printed on their own lines, below the message
     */
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
