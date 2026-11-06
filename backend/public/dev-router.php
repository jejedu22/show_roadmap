<?php

// Routeur pour le serveur PHP intégré (dev uniquement) : fichiers statiques d'abord, sinon Symfony.
// php -S 127.0.0.1:8000 -t public public/dev-router.php
$file = __DIR__.parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ('/' !== $_SERVER['REQUEST_URI'] && is_file($file)) {
    return false;
}
// Adresse avec extension (« export.xlsx ») : le serveur intégré désigne sinon ce routeur comme script, ce que Symfony Runtime refuse
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/index.php';
require __DIR__.'/index.php';
