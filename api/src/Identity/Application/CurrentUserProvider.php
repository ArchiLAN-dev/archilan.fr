<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use Symfony\Component\HttpFoundation\Request;

final readonly class CurrentUserProvider
{
    public function __construct(
        private AuthenticateUser $authenticateUser,
        private AuthSessionSigner $authSessionSigner,
    ) {
    }

    public function userFromRequest(Request $request): ?User
    {
        $cookieValue = $request->cookies->get(AuthSessionSigner::COOKIE_NAME);

        if (!is_string($cookieValue)) {
            return null;
        }

        $userId = $this->authSessionSigner->verify($cookieValue);

        if (null === $userId) {
            return null;
        }

        return $this->authenticateUser->findUserById($userId);
    }
}
