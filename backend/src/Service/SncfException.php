<?php

namespace App\Service;

class SncfException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 502)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
