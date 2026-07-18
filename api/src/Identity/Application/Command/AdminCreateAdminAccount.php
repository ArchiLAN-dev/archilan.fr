<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Application\Support\SlugGenerator;
use App\Identity\Application\Support\ValidationErrors;
use App\Identity\Domain\Entity\AdminCreationAudit;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\AdminCreationAuditRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\ValidationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final readonly class AdminCreateAdminAccount
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private AdminCreationAuditRepositoryInterface $auditRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private LoggerInterface $logger,
        private SlugGenerator $slugGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ValidationException when the form is invalid
     */
    public function create(User $creator, string $email, string $password, string $displayName = ''): AdminUserView
    {
        $errors = $this->validate($email, $password, $displayName);

        if ([] !== $errors) {
            throw new ValidationException('Le formulaire contient des erreurs.', $errors);
        }

        $emailCanonical = mb_strtolower(trim($email));

        if ($this->emailExists($emailCanonical)) {
            throw new ValidationException('Le formulaire contient des erreurs.', ['email' => ['Un compte existe déjà avec cette adresse email.']]);
        }

        $now = $this->clock->now();
        $passwordHash = $this->passwordHasher->hashPassword(
            new class implements PasswordAuthenticatedUserInterface {
                public function getPassword(): ?string
                {
                    return null;
                }
            },
            $password,
        );
        $trimmedDisplayName = trim($displayName);
        $slugSource = '' !== $trimmedDisplayName
            ? $trimmedDisplayName
            : ((string) strstr($emailCanonical, '@', true) ?: $emailCanonical);
        $slug = $this->slugGenerator->generateForUser($slugSource);
        $admin = new User(
            bin2hex(random_bytes(16)),
            $email,
            $emailCanonical,
            $trimmedDisplayName,
            $passwordHash,
            ['ROLE_USER', 'ROLE_ADMIN'],
            $now,
            $now,
            $now,
            slug: $slug,
        );

        try {
            $this->auditRepository->saveAdminWithAudit($admin, AdminCreationAudit::record($admin->getId(), $creator->getId(), $now));
        } catch (UniqueConstraintViolationException) {
            throw new ValidationException('Le formulaire contient des erreurs.', ['email' => ['Un compte existe déjà avec cette adresse email.']]);
        }

        $this->logger->info('admin.account_created', ['adminId' => $admin->getId(), 'creatorId' => $creator->getId()]);

        return $this->userPayload($admin);
    }

    private function emailExists(string $emailCanonical): bool
    {
        return $this->userRepository->findByEmailCanonical($emailCanonical) instanceof User;
    }

    /**
     * @return array<string, list<string>>
     */
    private function validate(string $email, string $password, string $displayName): array
    {
        $errors = new ValidationErrors();

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors->add('email', 'Saisis une adresse email valide.');
        }

        if (mb_strlen($password) < 12) {
            $errors->add('password', 'Le mot de passe doit contenir au moins 12 caractères.');
        }

        if ('' === trim($displayName)) {
            $errors->add('displayName', 'Le nom affiché est requis pour un compte admin.');
        } elseif (mb_strlen(trim($displayName)) > 80) {
            $errors->add('displayName', 'Le nom affiché doit contenir 80 caractères maximum.');
        }

        return $errors->toArray();
    }

    private function userPayload(User $user): AdminUserView
    {
        return new AdminUserView(
            $user->getId(),
            $user->getEmail(),
            $user->getDisplayName(),
            'admin',
            $user->getRoles(),
            $user->isDeleted() ? 'deleted' : 'active',
            $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            $user->getDeletedAt()?->format(\DateTimeInterface::ATOM),
        );
    }
}
