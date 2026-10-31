<?php

declare(strict_types=1);

namespace App\Notes\Infrastructure\Doctrine;

use App\Notes\Domain\Entity\AdminNote;
use App\Notes\Domain\Entity\NoteFollowUp;
use App\Notes\Domain\Entity\NoteRevision;
use App\Notes\Domain\Repository\AdminNoteRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAdminNoteRepository implements AdminNoteRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $id): ?AdminNote
    {
        return $this->entityManager->find(AdminNote::class, $id);
    }

    public function save(AdminNote $note, NoteFollowUp|NoteRevision|null $record = null): void
    {
        $this->entityManager->persist($note);
        if (null !== $record) {
            $this->entityManager->persist($record);
        }
        $this->entityManager->flush();
    }
}
