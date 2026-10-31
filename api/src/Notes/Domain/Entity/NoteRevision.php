<?php

declare(strict_types=1);

namespace App\Notes\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/** The text a note had before an edit, and who changed it (story 46.1). */
#[ORM\Entity]
#[ORM\Table(name: 'admin_note_revision')]
#[ORM\Index(name: 'idx_admin_note_revision_note', columns: ['note_id', 'edited_at'])]
final class NoteRevision
{
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'note_id', type: 'string', length: 32)]
        private string $noteId,
        #[ORM\Column(name: 'previous_body', type: 'text')]
        private string $previousBody,
        #[ORM\Column(name: 'editor_id', type: 'string', length: 32)]
        private string $editorId,
        #[ORM\Column(name: 'edited_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $editedAt,
    ) {
    }

    public static function record(string $id, string $noteId, string $previousBody, string $editorId, \DateTimeImmutable $now): self
    {
        return new self($id, $noteId, $previousBody, $editorId, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getNoteId(): string
    {
        return $this->noteId;
    }

    public function getPreviousBody(): string
    {
        return $this->previousBody;
    }

    public function getEditorId(): string
    {
        return $this->editorId;
    }

    public function getEditedAt(): \DateTimeImmutable
    {
        return $this->editedAt;
    }
}
