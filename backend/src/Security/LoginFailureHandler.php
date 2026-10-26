<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/** Échec de connexion : messages en français, 429 en cas de trop nombreuses tentatives. */
class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return match (true) {
            $exception instanceof TooManyLoginAttemptsAuthenticationException => new JsonResponse(['error' => 'Trop de tentatives, réessayez dans quelques minutes.'], 429),
            $exception instanceof CustomUserMessageAccountStatusException => new JsonResponse(['error' => $exception->getMessageKey()], 401),
            default => new JsonResponse(['error' => 'Identifiants incorrects.'], 401),
        };
    }
}
