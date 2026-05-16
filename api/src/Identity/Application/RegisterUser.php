<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final readonly class RegisterUser
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private LoggerInterface $logger,
        private SlugGenerator $slugGenerator,
        private SendEmailConfirmation $sendEmailConfirmation,
    ) {
    }

    /**
     * @return array{user?: User, errors: array<string, list<string>>}
     */
    public function register(string $email, string $password, bool $acceptedCgu, ?string $displayName = null): array
    {
        $errors = $this->validate($email, $password, $acceptedCgu, $displayName);
        $emailCanonical = self::canonicalizeEmail($email);

        if (!isset($errors['email']) && $this->emailExists($emailCanonical)) {
            $errors['email'][] = 'Un compte existe déjà avec cette adresse email.';
        }

        if ([] !== $errors) {
            return ['errors' => $errors];
        }

        $now = new \DateTimeImmutable();
        // The 'auto' hasher is bound to PasswordAuthenticatedUserInterface; no full entity needed here.
        $passwordHash = $this->passwordHasher->hashPassword(
            new class implements PasswordAuthenticatedUserInterface {
                public function getPassword(): ?string
                {
                    return null;
                }
            },
            $password,
        );
        $emailLocalPart = (string) strstr($emailCanonical, '@', true);
        $slug = $this->slugGenerator->generateForUser('' !== $emailLocalPart ? $emailLocalPart : $emailCanonical);
        $user = User::register($email, $emailCanonical, $passwordHash, $now, $slug, $displayName);

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return ['errors' => ['email' => ['Un compte existe déjà avec cette adresse email.']]];
        }

        $this->logger->info('user.registered', ['userId' => $user->getId()]);

        $this->sendEmailConfirmation->sendFor($user->getId(), $user->getEmail(), $user->getDisplayName(), $now);

        return ['user' => $user, 'errors' => []];
    }

    public static function canonicalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
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
    private function validate(string $email, string $password, bool $acceptedCgu, ?string $displayName): array
    {
        $errors = new ValidationErrors();

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors->add('email', 'Saisis une adresse email valide.');
        }

        if (mb_strlen($password) < 12) {
            $errors->add('password', 'Le mot de passe doit contenir au moins 12 caractères.');
        }

        if (!$acceptedCgu) {
            $errors->add('acceptedCgu', 'Tu dois accepter les CGU pour créer un compte.');
        }

        if (null !== $displayName && mb_strlen(trim($displayName)) > 80) {
            $errors->add('displayName', 'Le nom affiché doit contenir 80 caractères maximum.');
        }

        return $errors->toArray();
    }
}
