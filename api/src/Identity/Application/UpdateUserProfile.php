<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class UpdateUserProfile
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{user?: User, errors: array<string, list<string>>}
     */
    public function update(User $user, mixed $displayName): array
    {
        $errors = $this->validate($displayName);

        if ([] !== $errors) {
            return ['errors' => $errors];
        }

        $user->updateProfile(is_string($displayName) ? $displayName : null, new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->logger->debug('user.profile_updated', ['userId' => $user->getId()]);

        return ['user' => $user, 'errors' => []];
    }

    /**
     * @return array<string, list<string>>
     */
    private function validate(mixed $displayName): array
    {
        $errors = new ValidationErrors();

        if (null !== $displayName && !is_string($displayName)) {
            $errors->add('displayName', 'Le nom affiché doit être du texte.');

            return $errors->toArray();
        }

        if (is_string($displayName) && mb_strlen(trim($displayName)) > 80) {
            $errors->add('displayName', 'Le nom affiché doit contenir 80 caractères maximum.');
        }

        return $errors->toArray();
    }
}
