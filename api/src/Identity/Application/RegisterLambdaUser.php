<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final readonly class RegisterLambdaUser
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{user?: User, errors: array<string, list<string>>}
     */
    public function register(string $email, string $password, bool $acceptedCgu): array
    {
        $errors = $this->validate($email, $password, $acceptedCgu);
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
        $user = User::registerLambda($email, $emailCanonical, $passwordHash, $now);

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return ['errors' => ['email' => ['Un compte existe déjà avec cette adresse email.']]];
        }

        $this->logger->info('user.registered', ['userId' => $user->getId()]);

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
    private function validate(string $email, string $password, bool $acceptedCgu): array
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

        return $errors->toArray();
    }
}
