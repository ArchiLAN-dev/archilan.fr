<?php

declare(strict_types=1);

namespace App\Content\Application\Command;

use App\Content\Domain\Entity\HelpGuide;
use App\Content\Domain\Repository\HelpGuideRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Shared\Application\Support\PublicPagesRevalidation;
use Psr\Clock\ClockInterface;

/**
 * Creates, edits, orders and deletes the help guides (story 53.5). Only a draft can be deleted: a published guide is
 * unpublished first, so a link shared on Discord does not vanish by a slip. Every saved write drops the cache of the
 * public guide pages; a refused one changes nothing.
 */
final readonly class ManageHelpGuides
{
    private const array MESSAGES = [
        'help_guide_slug_invalid' => 'Une adresse de 2 à 60 caractères : minuscules, chiffres et tirets.',
        'help_guide_slug_reserved' => 'Cette adresse est réservée à une autre page.',
        'help_guide_title_invalid' => 'Un titre de 1 à 80 caractères.',
        'help_guide_summary_invalid' => 'Un résumé de 1 à 200 caractères.',
        'help_guide_body_invalid' => 'Un texte de 1 à 20 000 caractères.',
        'help_guide_position_invalid' => 'Position invalide.',
    ];

    public function __construct(
        private HelpGuideRepositoryInterface $guides,
        private ClockInterface $clock,
        private PublicPagesRevalidation $revalidation,
    ) {
    }

    /**
     * A new guide goes last.
     *
     * @return string the id of the new guide
     *
     * @throws ValidationException when a field is invalid
     * @throws ConflictException   when the slug is taken
     */
    public function create(HelpGuideDraft $draft): string
    {
        $position = array_reduce($this->guides->all(), static fn (int $max, HelpGuide $guide): int => max($max, $guide->getPosition() + 1), 0);
        try {
            $guide = HelpGuide::create(bin2hex(random_bytes(16)), $draft->slug, $draft->title, $draft->summary, $draft->body, $draft->published, $position, $this->clock->now());
        } catch (\DomainException $e) {
            throw $this->invalid($e);
        }
        $this->assertSlugFree($guide->getSlug(), null);
        $this->guides->save($guide);
        $this->revalidation->helpGuidesChanged($guide->getSlug());

        return $guide->getId();
    }

    /**
     * @throws NotFoundException   when the guide does not exist
     * @throws ValidationException when a field is invalid
     * @throws ConflictException   when the new slug is taken
     */
    public function edit(string $id, HelpGuideDraft $draft): void
    {
        $guide = $this->get($id);
        $previousSlug = $guide->getSlug();
        $this->assertSlugFree(trim($draft->slug), $guide->getId());
        try {
            $guide->edit($draft->slug, $draft->title, $draft->summary, $draft->body, $draft->published, $this->clock->now());
        } catch (\DomainException $e) {
            throw $this->invalid($e);
        }
        $this->guides->save($guide);
        $this->revalidation->helpGuidesChanged($previousSlug, $guide->getSlug());
    }

    /**
     * @throws NotFoundException when the guide does not exist
     * @throws ConflictException when the guide is published
     */
    public function delete(string $id): void
    {
        $guide = $this->get($id);
        if ($guide->isPublished()) {
            throw new ConflictException('Dépublie le guide avant de le supprimer.', 'help_guide_published');
        }
        $this->guides->remove($guide);
        $this->revalidation->helpGuidesChanged($guide->getSlug());
    }

    /**
     * The listed guides come first, in the given order; the unlisted ones follow in their current order. Every guide is
     * renumbered from 0, so no two share a position (v0.66.1: a partial list used to leave duplicates). Unknown ids
     * are skipped.
     *
     * @param list<string> $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $byId = [];
        foreach ($this->guides->all() as $guide) {
            $byId[$guide->getId()] = $guide;
        }
        $ordered = [];
        foreach ($orderedIds as $id) {
            if (isset($byId[$id])) {
                $ordered[$id] = $byId[$id];
            }
        }
        foreach ($byId as $id => $guide) {
            $ordered[$id] ??= $guide;
        }

        $now = $this->clock->now();
        $position = 0;
        foreach ($ordered as $guide) {
            $guide->moveTo($position, $now);
            ++$position;
        }
        $this->guides->saveAll(array_values($ordered));
        $this->revalidation->helpGuidesChanged();
    }

    /** @throws ConflictException when another guide has the slug */
    private function assertSlugFree(string $slug, ?string $ownId): void
    {
        $holder = $this->guides->findBySlug($slug);
        if ($holder instanceof HelpGuide && $holder->getId() !== $ownId) {
            throw new ConflictException('Un autre guide a déjà cette adresse.', 'help_guide_slug_taken');
        }
    }

    private function get(string $id): HelpGuide
    {
        $guide = $this->guides->find($id);
        if (!$guide instanceof HelpGuide) {
            throw new NotFoundException('Guide introuvable.', 'help_guide_not_found');
        }

        return $guide;
    }

    private function invalid(\DomainException $e): ValidationException
    {
        return new ValidationException(self::MESSAGES[$e->getMessage()] ?? 'Guide invalide.', [], $e->getMessage());
    }
}
