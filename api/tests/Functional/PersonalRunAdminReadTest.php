<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;

/**
 * Lecture d'une run privée par un admin.
 *
 * La fiche membre du backoffice liste les runs d'un joueur et les lie vers `/runs/{id}`
 * (story 36.4), et sait déjà en arrêter une (36.6). La lecture, elle, refusait tout appelant qui
 * n'était ni propriétaire ni participant : le lien de sa propre interface répondait « Run
 * introuvable ». Le spoiler d'une run privée est ouvert aux admins depuis 16.8, donc cette lecture
 * reste en deçà de ce que le backoffice permettait déjà.
 */
final class PersonalRunAdminReadTest extends FunctionalTestCase
{
    public function testAdminReadsAnotherMembersRun(): void
    {
        $alice = $this->createUser('alice-run@example.org');
        $run = $this->createRun($alice->getId(), 'Partie d\'Alice');
        $this->loginAs($this->createAdmin());

        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame($run->getId(), $data['id']);
        self::assertSame('Partie d\'Alice', $data['title']);
    }

    public function testAdminDoesNotBecomeTheOwner(): void
    {
        $alice = $this->createUser('alice-run@example.org');
        $run = $this->createRun($alice->getId(), 'Partie d\'Alice');
        $this->loginAs($this->createAdmin());

        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        // `isOwner` ouvre côté front les réglages, l'override de configuration, le renommage,
        // l'overlay, le spoiler et le lien d'invitation. Lire n'est pas posséder.
        self::assertFalse($data['isOwner']);
        self::assertNull($data['inviteToken']);
    }

    public function testAMemberOutsideTheRunIsStillRefused(): void
    {
        $alice = $this->createUser('alice-run@example.org');
        $bob = $this->createUser('bob-run@example.org');
        $run = $this->createRun($alice->getId(), 'Partie d\'Alice');
        $this->loginAs($bob);

        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerStillReadsTheirOwnRun(): void
    {
        $alice = $this->createUser('alice-run@example.org');
        $run = $this->createRun($alice->getId(), 'Partie d\'Alice');
        $this->loginAs($alice);

        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertTrue($data['isOwner']);
    }

    private function createAdmin(): User
    {
        return $this->createUser('admin-run@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
    }

    private function createRun(string $ownerId, string $title): Run
    {
        $run = Run::create($ownerId, $title, new \DateTimeImmutable('2026-05-12T10:00:00+00:00'));
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }
}
