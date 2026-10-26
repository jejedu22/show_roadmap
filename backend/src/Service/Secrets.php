<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Chiffrement des secrets stockés en base (clés d'API de services tiers), avec une clé dérivée d'APP_SECRET. */
class Secrets
{
    private string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $appSecret)
    {
        $this->key = sodium_crypto_generichash('show-roadmap/secrets|'.$appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'v1:'.base64_encode($nonce.sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    /** null si le secret est illisible (APP_SECRET changé depuis l'enregistrement). */
    public function decrypt(?string $stored): ?string
    {
        if (!$stored || !str_starts_with($stored, 'v1:') || false === ($raw = base64_decode(substr($stored, 3), true))
            || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key);

        return false === $plain ? null : $plain;
    }
}
