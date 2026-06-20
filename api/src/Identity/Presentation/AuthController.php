<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\AuthenticateUser;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Application\CurrentUserProvider;
use App\Identity\Application\MemberDisplayNameQueryInterface;
use App\Identity\Application\RefreshTokenFactory;
use App\Identity\Application\RotateRefreshToken;
use App\Identity\Domain\RefreshTokenRepositoryInterface;
use App\Identity\Domain\User;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AuthController
{
    public const REFRESH_COOKIE_NAME = '__Secure-archilan_refresh';
    public const REFRESH_COOKIE_PATH = '/api/v1/auth/refresh';
    public const REFRESH_COOKIE_SCOPE = '/api/v1/auth';

    public function __construct(
        private AuthenticateUser $authenticateUser,
        private AuthSessionSigner $authSessionSigner,
        private CurrentUserProvider $currentUserProvider,
        private RefreshTokenFactory $refreshTokenFactory,
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
        private RotateRefreshToken $rotateRefreshToken,
        private MemberDisplayNameQueryInterface $memberDisplayNames,
    ) {
    }

    #[Route('/api/v1/auth/login', name: 'api_identity_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->authError();
        }

        if (!is_array($payload)) {
            return $this->authError();
        }

        $user = $this->authenticateUser->authenticate(
            is_string($payload['email'] ?? null) ? $payload['email'] : '',
            is_string($payload['password'] ?? null) ? $payload['password'] : '',
        );

        if (!$user instanceof User) {
            return $this->authError();
        }

        $rememberMe = true === ($payload['rememberMe'] ?? true);
        $now = new \DateTimeImmutable();
        ['rawToken' => $rawToken, 'entity' => $refreshToken] = $this->refreshTokenFactory->issue(
            $user->getId(),
            $now,
            $request->headers->get('User-Agent'),
            $rememberMe,
        );
        $this->refreshTokenRepository->save($refreshToken);

        $response = new JsonResponse([
            'data' => $this->userPayload($user),
            'meta' => [
                'message' => 'Connecté.',
            ],
        ]);
        $response->headers->setCookie($this->sessionCookie($this->authSessionSigner->sign($user->getId())));
        $response->headers->setCookie($this->refreshCookie($rawToken, $rememberMe));

        return $response;
    }

    #[Route('/api/v1/auth/me', name: 'api_identity_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->currentUserProvider->userFromRequest($request);

        if (!$user instanceof User) {
            return new JsonResponse([
                'error' => [
                    'code' => 'unauthenticated',
                    'message' => 'Authentification requise.',
                    'details' => [],
                ],
            ], 401);
        }

        return new JsonResponse([
            'data' => $this->userPayload($user),
            'meta' => [],
        ]);
    }

    #[Route('/api/v1/auth/refresh', name: 'api_identity_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $rawToken = $request->cookies->get(self::REFRESH_COOKIE_NAME);

        if (!is_string($rawToken) || '' === $rawToken) {
            return $this->refreshError('invalid_refresh_token');
        }

        $now = new \DateTimeImmutable();
        $result = $this->rotateRefreshToken->rotate(
            $rawToken,
            $now,
            $request->headers->get('User-Agent'),
            $request,
        );

        if ('rotated' === $result->outcome && null !== $result->userId && null !== $result->rawRefreshToken) {
            $response = new JsonResponse(null, 204);
            $response->headers->setCookie($this->sessionCookie($this->authSessionSigner->sign($result->userId)));
            $response->headers->setCookie($this->refreshCookie($result->rawRefreshToken, $result->rememberMe));

            return $response;
        }

        $errorCode = 'reuse_detected' === $result->outcome ? 'token_reuse_detected' : 'invalid_refresh_token';

        return $this->refreshError($errorCode);
    }

    #[Route('/api/v1/auth/logout', name: 'api_identity_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $rawToken = $request->cookies->get(self::REFRESH_COOKIE_NAME);

        if (is_string($rawToken) && '' !== $rawToken) {
            $token = $this->refreshTokenRepository->findByTokenHash(hash('sha256', $rawToken));
            if (null !== $token) {
                $token->revoke(new \DateTimeImmutable());
                $this->refreshTokenRepository->flush();
            }
        }

        $response = new JsonResponse(null, 204);
        $response->headers->clearCookie(
            AuthSessionSigner::COOKIE_NAME,
            '/',
            null,
            true,
            true,
            Cookie::SAMESITE_LAX,
        );
        $response->headers->clearCookie(
            self::REFRESH_COOKIE_NAME,
            self::REFRESH_COOKIE_SCOPE,
            null,
            true,
            true,
            Cookie::SAMESITE_LAX,
        );

        return $response;
    }

    /**
     * @return array{id: string, email: string, displayName: string|null, steamProfile: string|null, roles: list<string>, emailVerifiedAt: string|null, createdAt: string, updatedAt: string}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            // The pseudo shown across the app is the community display-name override, falling back to the
            // account display name when no override is set.
            'displayName' => $this->memberDisplayNames->displayNameFor($user->getId()) ?? $user->getDisplayName(),
            'steamProfile' => $user->getSteamProfile(),
            'roles' => $user->getRoles(),
            'emailVerifiedAt' => $user->getEmailVerifiedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function sessionCookie(string $value): Cookie
    {
        return Cookie::create(AuthSessionSigner::COOKIE_NAME)
            ->withValue($value)
            ->withExpires(time() + AuthSessionSigner::ACCESS_TOKEN_TTL)
            ->withPath('/')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    private function refreshCookie(string $value, bool $rememberMe = true): Cookie
    {
        $ttlDays = $rememberMe ? RefreshTokenFactory::TOKEN_TTL_LONG_DAYS : RefreshTokenFactory::TOKEN_TTL_SHORT_DAYS;

        return Cookie::create(self::REFRESH_COOKIE_NAME)
            ->withValue($value)
            ->withExpires(time() + $ttlDays * 86400)
            ->withPath(self::REFRESH_COOKIE_SCOPE)
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    private function authError(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'invalid_credentials',
                'message' => 'Email ou mot de passe incorrect.',
                'details' => [],
            ],
        ], 401);
    }

    private function refreshError(string $code): JsonResponse
    {
        $response = new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => 'Session expirée.',
                'details' => [],
            ],
        ], 401);
        $response->headers->clearCookie(AuthSessionSigner::COOKIE_NAME, '/', null, true, true, Cookie::SAMESITE_LAX);
        $response->headers->clearCookie(self::REFRESH_COOKIE_NAME, self::REFRESH_COOKIE_SCOPE, null, true, true, Cookie::SAMESITE_LAX);

        return $response;
    }
}
