<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Application\Support\ValidationErrors;
use App\Identity\Domain\Entity\PrivacyRightsRequest;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\PrivacyRightsRequestRepositoryInterface;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class CreatePrivacyRightsRequest
{
    public function __construct(
        private PrivacyRightsRequestRepositoryInterface $requestRepository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{id: string, rightType: string, status: string, handlingMode: string, submittedAt: string} the created request payload
     *
     * @throws ValidationException when the request is invalid
     */
    public function create(User $user, string $rightType, ?string $details): array
    {
        $errors = $this->validate($rightType, $details);

        if ([] !== $errors) {
            throw new ValidationException('La demande RGPD contient des erreurs.', $errors);
        }

        // No per-user rate limit: multiple requests for the same right type are allowed
        // (GDPR does not prohibit repeated requests). Backlog flooding is an accepted risk
        // until a throttling story is planned.
        $request = PrivacyRightsRequest::submit(
            $user->getId(),
            $rightType,
            $details,
            $this->clock->now(),
        );

        $this->requestRepository->save($request);

        $this->logger->info('privacy.request_created', ['userId' => $user->getId(), 'rightType' => $rightType, 'requestId' => $request->getId()]);

        return $this->payload($request);
    }

    /**
     * @return array<string, list<string>>
     */
    private function validate(string $rightType, ?string $details): array
    {
        $errors = new ValidationErrors();

        if (!in_array($rightType, PrivacyRightsRequest::supportedRights(), true)) {
            $errors->add('rightType', 'Choisis un droit RGPD valide.');
        }

        if (null !== $details && mb_strlen(trim($details)) > 1000) {
            $errors->add('details', 'La demande doit contenir 1000 caractères maximum.');
        }

        return $errors->toArray();
    }

    /**
     * @return array{id: string, rightType: string, status: string, handlingMode: string, submittedAt: string}
     */
    private function payload(PrivacyRightsRequest $request): array
    {
        return [
            'id' => $request->getId(),
            'rightType' => $request->getRightType(),
            'status' => $request->getStatus(),
            'handlingMode' => $request->getHandlingMode(),
            'submittedAt' => $request->getSubmittedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
