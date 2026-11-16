<?php

namespace App\Service;

/** Formule écrite par l'export Excel (jamais une saisie de l'utilisateur) ; « {r} » : numéro de la ligne de la cellule. */
final class XlsxFormula
{
    public function __construct(public readonly string $formula)
    {
    }
}
