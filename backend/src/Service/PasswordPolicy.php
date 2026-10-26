<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\PasswordStrengthValidator;

/** Règles des mots de passe : 12 caractères, robustesse au moins moyenne, sans l'adresse e-mail. */
class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public function __construct(private UserPasswordHasherInterface $hasher)
    {
    }

    public function check(User $user, string $password): void
    {
        $local = mb_strtolower(strstr($user->email, '@', true) ?: $user->email);
        $problem = match (true) {
            mb_strlen($password) < self::MIN_LENGTH => 'Le mot de passe doit faire au moins '.self::MIN_LENGTH.' caractères.',
            mb_strlen($password) > 4096 => 'Mot de passe trop long.',
            mb_strlen($local) >= 3 && str_contains(mb_strtolower($password), $local) => 'Le mot de passe ne doit pas contenir votre adresse e-mail.',
            PasswordStrengthValidator::estimateStrength($password) < 2 => 'Mot de passe trop prévisible : allongez-le ou mélangez mots, chiffres et symboles (une phrase de 4 mots convient).',
            default => null,
        };
        if ($problem) {
            throw new BadRequestHttpException($problem);
        }
    }

    public function set(User $user, string $password): void
    {
        $this->check($user, $password);
        $user->password = $this->hasher->hashPassword($user, $password);
    }

    /** Mot de passe aléatoire inutilisable (compte invité qui n'a pas encore choisi le sien). */
    public function lock(User $user): void
    {
        $user->password = $this->hasher->hashPassword($user, bin2hex(random_bytes(32)));
    }
}
