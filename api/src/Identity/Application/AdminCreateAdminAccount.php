<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AdminAccountCreationAudit;
use App\Identity\Domain\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final readonly class AdminCreateAdminAccount
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private LoggerInterface $logger,
        private SlugGenerator $slugGenerator,
    ) {
    }

    /**
     * @return array{user?: array{id: string, email: string, displayName: string|null, role: string, roles: list<string>, status: string, createdAt: string, updatedAt: string, deletedAt: string|null}, errors: array<string, list<string>>}
     */
    public function create(User $creator, string $email, string $password, ?string $displayName): array
    {
        $errors = $this->validate($email, $password, $displayName);

        if ([] !== $errors) {
            return ['errors' => $errors];
        }

        $emailCanonical = mb_strtolower(trim($email));

        if ($this->emailExists($emailCanonical)) {
            return ['errors' => ['email' => ['Un compte existe déjà avec cette adresse email.']]];
        }

        $now = new \DateTimeImmutable();
        $passwordHash = $this->passwordHasher->hashPassword(
            new class implements PasswordAuthenticatedUserInterface {
                public function getPassword(): ?string
                {
                    return null;
                }
            },
            $password,
        );
        $trimmedDisplayName = null === $displayName ? null : trim($displayName);
        $slugSource = null !== $trimmedDisplayName && '' !== $trimmedDisplayName
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
            $this->entityManager->persist($admin);
            $this->entityManager->persist(AdminAccountCreationAudit::record($admin->getId(), $creator->getId(), $now));
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return ['errors' => ['email' => ['Un compte existe déjà avec cette adresse email.']]];
        }

        $this->logger->info('admin.account_created', ['adminId' => $admin->getId(), 'creatorId' => $creator->getId()]);

        return ['user' => $this->userPayload($admin), 'errors' => []];
    }

    private function emailExists(string $emailCanonical): bool
    {
        return $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['emailCanonical' => $emailCanonical]) instanceof User;
    }

    /**
     * @return array<string, list<string>>
     */
    private function validate(string $email, string $password, ?string $displayName): array
    {
        $errors = new ValidationErrors();

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors->add('email', 'Saisis une adresse email valide.');
        }

        if (mb_strlen($password) < 12) {
            $errors->add('password', 'Le mot de passe doit contenir au moins 12 caractères.');
        }

        if (null === $displayName || '' === trim($displayName)) {
            $errors->add('displayName', 'Le nom affiché est requis pour un compte admin.');
        } elseif (mb_strlen(trim($displayName)) > 80) {
            $errors->add('displayName', 'Le nom affiché doit contenir 80 caractères maximum.');
        }

        return $errors->toArray();
    }

    /**
     * @return array{id: string, email: string, displayName: string|null, role: string, roles: list<string>, status: string, createdAt: string, updatedAt: string, deletedAt: string|null}
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'role' => 'admin',
            'roles' => $user->getRoles(),
            'status' => $user->isDeleted() ? 'deleted' : 'active',
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'deletedAt' => $user->getDeletedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
