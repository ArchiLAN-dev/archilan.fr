<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\RefreshTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class RotateRefreshToken
{
    public function __construct(
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private RefreshTokenFactory $refreshTokenFactory,
        private AuthenticateUser $authenticateUser,
        private LoggerInterface $logger,
    ) {
    }

    public function rotate(string $rawToken, \DateTimeImmutable $now, ?string $userAgent, Request $request): RotationResult
    {
        $hash = hash('sha256', $rawToken);
        $existing = $this->refreshTokenRepository->findByTokenHash($hash);

        if (null === $existing) {
            return RotationResult::invalid();
        }

        if ($existing->isRevoked()) {
            $this->refreshTokenRepository->revokeAllForUser($existing->getUserId());
            $this->logger->warning('auth.refresh_token_reuse', [
                'userId' => $existing->getUserId(),
                'ip' => $request->getClientIp(),
                'userAgent' => $userAgent,
                'path' => $request->getPathInfo(),
            ]);

            return RotationResult::reuseDetected($existing->getUserId());
        }

        if ($existing->isExpired($now)) {
            $existing->revoke($now);
            $this->refreshTokenRepository->flush();

            return RotationResult::invalid();
        }

        $user = $this->authenticateUser->findUserById($existing->getUserId());

        if (null === $user) {
            return RotationResult::invalid();
        }

        $rememberMe = $existing->isRememberMe();
        ['rawToken' => $newRawToken, 'entity' => $newEntity] = $this->refreshTokenFactory->issue(
            $user->getId(),
            $now,
            $userAgent,
            $rememberMe,
        );

        $this->refreshTokenRepository->persist($newEntity);

        $existing->revoke($now);
        $this->refreshTokenRepository->flush();

        return RotationResult::rotated($user->getId(), $newRawToken, $rememberMe);
    }
}
