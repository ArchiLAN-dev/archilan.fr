<?php

declare(strict_types=1);

namespace App\Membership\Infrastructure;

use App\Identity\Domain\User;
use App\Membership\Application\ActiveMembershipQueryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants IS_MEMBER when the authenticated user has an active membership record.
 * Does not rely on ROLE_MEMBER - the memberships table is the source of truth.
 *
 * @extends Voter<string, mixed>
 */
final class MembershipVoter extends Voter
{
    public const IS_MEMBER = 'IS_MEMBER';

    public function __construct(private readonly ActiveMembershipQueryInterface $membershipQuery)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::IS_MEMBER === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->membershipQuery->hasActiveMembership($user->getId());
    }
}
