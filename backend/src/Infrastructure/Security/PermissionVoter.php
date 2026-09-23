<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Infrastructure\Persistence\Entity\Identity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, mixed> */
final class PermissionVoter extends Voter
{
    public const ATTRIBUTE = 'PERMISSION';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return $user->hasPermission($subject);
    }
}
