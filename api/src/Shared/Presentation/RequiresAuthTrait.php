<?php

declare(strict_types=1);

namespace App\Shared\Presentation;

use App\Identity\Domain\User;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @property ApiAccessGuard $apiAccessGuard
 */
trait RequiresAuthTrait
{
    protected function requireAuthenticatedUser(Request $request): User|JsonResponse
    {
        return $this->apiAccessGuard->requireUser($request);
    }

    protected function requireAuthenticatedAdmin(Request $request): User|JsonResponse
    {
        return $this->apiAccessGuard->requireAdmin($request);
    }
}
