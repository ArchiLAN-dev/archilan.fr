<?php

declare(strict_types=1);

namespace App\Content\Application\Command;

use App\Content\Domain\Entity\HomeAnnouncement;
use App\Content\Domain\Repository\HomeAnnouncementRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Exception\ValidationException;
use App\Shared\Application\Support\PublicMediaUrlResolver;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates, edits and deletes the announcements opened as a modal on the home page, and their image (story 10.14).
 * Two announcements never share a moment: an overlapping window is refused, so the home page has at most one.
 */
final readonly class ManageHomeAnnouncements
{
    private const array MESSAGES = [
        'home_announcement_title_invalid' => 'Un titre de 1 à 120 caractères.',
        'home_announcement_body_invalid' => 'Un texte de 600 caractères au plus.',
        'home_announcement_cta_label_invalid' => 'Un libellé de bouton de 1 à 40 caractères.',
        'home_announcement_cta_url_invalid' => 'Le lien du bouton commence par / (une page du site) ou https://.',
        'home_announcement_window_invalid' => "La fin vient après le début, et n'est pas déjà passée.",
    ];

    public function __construct(
        private HomeAnnouncementRepositoryInterface $announcements,
        private MinioStorageInterface $minioStorage,
        private PublicMediaUrlResolver $publicMedia,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return string the id of the new announcement
     *
     * @throws ValidationException when a field is invalid or the window overlaps another announcement
     */
    public function create(HomeAnnouncementDraft $draft): string
    {
        [$startsAt, $endsAt] = $this->window($draft, null);
        try {
            $announcement = HomeAnnouncement::create(bin2hex(random_bytes(16)), $draft->title, $draft->body, $draft->ctaLabel, $draft->ctaUrl, $startsAt, $endsAt, $this->clock->now());
        } catch (\DomainException $e) {
            throw $this->invalid($e);
        }
        $this->announcements->save($announcement);

        return $announcement->getId();
    }

    /**
     * @throws NotFoundException   when the announcement does not exist
     * @throws ValidationException when a field is invalid or the window overlaps another announcement
     */
    public function edit(string $id, HomeAnnouncementDraft $draft): void
    {
        $announcement = $this->get($id);
        [$startsAt, $endsAt] = $this->window($draft, $id);
        try {
            $announcement->edit($draft->title, $draft->body, $draft->ctaLabel, $draft->ctaUrl, $startsAt, $endsAt, $this->clock->now());
        } catch (\DomainException $e) {
            throw $this->invalid($e);
        }
        $this->announcements->save($announcement);
    }

    /** @throws NotFoundException when the announcement does not exist */
    public function delete(string $id): void
    {
        $this->announcements->remove($this->get($id));
    }

    /**
     * A new key per upload, so a cached copy of the previous image never shows under the new one.
     *
     * @throws NotFoundException           when the announcement does not exist
     * @throws ServiceUnavailableException when the object storage upload fails
     */
    public function attachImage(string $id, string $extension, string $contents): void
    {
        $announcement = $this->get($id);
        $key = sprintf('home-announcements/%s/image-%s.%s', $id, bin2hex(random_bytes(4)), $extension);
        try {
            $this->minioStorage->upload($this->publicMedia->bucket(), $key, $contents);
        } catch (\Throwable $exception) {
            $this->logger->error('Object storage upload failed for a home announcement image.', [
                'bucket' => $this->publicMedia->bucket(),
                'key' => $key,
                'exception' => $exception,
            ]);

            throw new ServiceUnavailableException('Le stockage est indisponible.', 'storage_unavailable');
        }
        $announcement->attachImage($key, $this->clock->now());
        $this->announcements->save($announcement);
    }

    /** @throws NotFoundException when the announcement does not exist */
    public function removeImage(string $id): void
    {
        $announcement = $this->get($id);
        $announcement->removeImage($this->clock->now());
        $this->announcements->save($announcement);
    }

    private function get(string $id): HomeAnnouncement
    {
        $announcement = $this->announcements->find($id);
        if (!$announcement instanceof HomeAnnouncement) {
            throw new NotFoundException('Annonce introuvable.', 'home_announcement_not_found');
        }

        return $announcement;
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     *
     * @throws ValidationException when a date is missing or the window overlaps another announcement
     */
    private function window(HomeAnnouncementDraft $draft, ?string $exceptId): array
    {
        if (null === $draft->startsAt || null === $draft->endsAt) {
            throw new ValidationException('Une date de début et une date de fin.', [], 'home_announcement_window_required');
        }
        foreach ($this->announcements->all() as $other) {
            if ($other->getId() !== $exceptId && $other->overlaps($draft->startsAt, $draft->endsAt)) {
                throw new ValidationException(sprintf('Ces dates chevauchent l\'annonce « %s ».', $other->getTitle()), [], 'home_announcement_overlap');
            }
        }

        return [$draft->startsAt, $draft->endsAt];
    }

    private function invalid(\DomainException $e): ValidationException
    {
        return new ValidationException(self::MESSAGES[$e->getMessage()] ?? 'Annonce invalide.', [], $e->getMessage());
    }
}
