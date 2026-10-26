<?php

namespace App\Service\Orfeo;

/** Erreur d'appel à Orfeo, avec un message lisible et le code HTTP à renvoyer. */
class OrfeoException extends \RuntimeException
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
