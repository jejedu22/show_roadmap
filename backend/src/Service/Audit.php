<?php

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/** Écrit dans le journal d'audit (enregistré au prochain flush de l'appelant, ou tout de suite avec $flush). */
class Audit
{
    public function __construct(private EntityManagerInterface $em, private Security $security, private RequestStack $requests)
    {
    }

    public function log(string $action, string $target = '', array $details = [], ?string $actor = null, bool $flush = false): void
    {
        $entry = new AuditLog();
        $user = $this->security->getUser();
        $entry->actor = mb_substr($actor ?? ($user instanceof User ? $user->email : ''), 0, 180);
        $entry->action = $action;
        $entry->target = mb_substr($target, 0, 255);
        $entry->details = $details;
        $entry->ip = (string) $this->requests->getMainRequest()?->getClientIp();
        $this->em->persist($entry);
        if ($flush) {
            $this->em->flush();
        }
    }
}
