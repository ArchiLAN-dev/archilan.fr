<?php

declare(strict_types=1);

namespace App\Content\Application\Command;

use App\Content\Domain\Entity\TrackedLink;
use App\Content\Domain\Repository\TrackedLinkRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Creates and edits the tracked links (story 55.1). No deletion: a link may be printed or posted already, so it is
 * turned off instead, and its counts stay.
 */
final readonly class ManageTrackedLinks
{
    private const array MESSAGES = [
        'tracked_link_code_invalid' => 'Un code de 3 à 40 caractères : minuscules, chiffres et tirets, sans tiret au début ni à la fin.',
        'tracked_link_label_invalid' => 'Un libellé de 1 à 120 caractères.',
        'tracked_link_target_invalid' => 'Une page du site qui commence par / (par exemple /evenements/archilan-4), ou une adresse en https://.',
    ];

    public function __construct(
        private TrackedLinkRepositoryInterface $links,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return string the id of the new link
     *
     * @throws ValidationException when a field is invalid
     * @throws ConflictException   when the code is taken
     */
    public function create(TrackedLinkDraft $draft): string
    {
        $code = trim($draft->code);
        try {
            $link = TrackedLink::create(bin2hex(random_bytes(16)), $code, $draft->label, $draft->target, $this->clock->now());
        } catch (\DomainException $e) {
            throw $this->invalid($e);
        }
        if ($this->links->findByCode($code) instanceof TrackedLink) {
            throw new ConflictException('Ce code est déjà pris.', 'tracked_link_code_taken');
        }
        if (!$draft->active) {
            $link->edit($draft->label, $draft->target, false, $this->clock->now());
        }
        $this->links->save($link);

        return $link->getId();
    }

    /**
     * @throws NotFoundException   when the link does not exist
     * @throws ValidationException when a field is invalid
     */
    public function edit(string $id, TrackedLinkDraft $draft): void
    {
        $link = $this->links->find($id);
        if (!$link instanceof TrackedLink) {
            throw new NotFoundException('Lien introuvable.', 'tracked_link_not_found');
        }
        try {
            $link->edit($draft->label, $draft->target, $draft->active, $this->clock->now());
        } catch (\DomainException $e) {
            throw $this->invalid($e);
        }
        $this->links->save($link);
    }

    private function invalid(\DomainException $e): ValidationException
    {
        return new ValidationException(self::MESSAGES[$e->getMessage()] ?? 'Lien invalide.', [], $e->getMessage());
    }
}
