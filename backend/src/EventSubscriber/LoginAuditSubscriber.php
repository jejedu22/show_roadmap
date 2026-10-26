<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\Audit;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/** Dernière connexion et journal des connexions (réussies et échouées). */
class LoginAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(private EntityManagerInterface $em, private Audit $audit)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onSuccess', LoginFailureEvent::class => 'onFailure'];
    }

    public function onSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }
        $user->lastLoginAt = new \DateTimeImmutable();
        $this->audit->log('login.success', $user->email, ['via' => $event->getRequest()->getPathInfo()], actor: $user->email, flush: true);
    }

    public function onFailure(LoginFailureEvent $event): void
    {
        $email = mb_strtolower(mb_substr((string) (json_decode($event->getRequest()->getContent(), true)['email'] ?? ''), 0, 180));
        $this->audit->log('login.failure', $email, ['raison' => $event->getException()->getMessageKey()], actor: '', flush: true);
    }
}
