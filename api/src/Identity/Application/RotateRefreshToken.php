<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\RefreshTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class RotateRefreshToken
{
    /**
     * A token re-presented within this many seconds of its own rotation is treated as a
     * benign retry (lost refresh response, wake-from-sleep race) rather than a stolen-token
     * reuse - it is re-rotated in the same family instead of revoking the session.
     */
    private const int REUSE_GRACE_SECONDS = 30;

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
            // Benign retry within the grace window: re-rotate in the SAME family, no reuse trip.
            if ($existing->wasRotatedWithinGrace($now, self::REUSE_GRACE_SECONDS)) {
                return $this->reissue($existing, $now, $userAgent, $request, markParentRotated: false, logEvent: 'auth.refresh_token_grace_retry');
            }

            // Genuine reuse of an old token -> revoke only THIS family (this login lineage),
            // not every session the user has open on other devices.
            $this->refreshTokenRepository->revokeFamily($existing->getFamilyId() ?? $existing->getId());
            $this->logger->warning('auth.refresh_token_reuse', [
                'userId' => $existing->getUserId(),
                'familyId' => $existing->getFamilyId(),
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

        return $this->reissue($existing, $now, $userAgent, $request, markParentRotated: true, logEvent: null);
    }

    private function reissue(RefreshToken $existing, \DateTimeImmutable $now, ?string $userAgent, Request $request, bool $markParentRotated, ?string $logEvent): RotationResult
    {
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
            $existing->getFamilyId(),
        );

        $this->refreshTokenRepository->persist($newEntity);

        if ($markParentRotated) {
            $existing->markRotated($newEntity->getTokenHash(), $now);
        }
        $this->refreshTokenRepository->flush();

        if (null !== $logEvent) {
            $this->logger->info($logEvent, [
                'userId' => $user->getId(),
                'familyId' => $existing->getFamilyId(),
                'ip' => $request->getClientIp(),
            ]);
        }

        return RotationResult::rotated($user->getId(), $newRawToken, $rememberMe);
    }
}
