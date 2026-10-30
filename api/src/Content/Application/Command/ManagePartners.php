<?php

declare(strict_types=1);

namespace App\Content\Application\Command;

use App\Content\Domain\Entity\Partner;
use App\Content\Domain\Repository\PartnerRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Exception\ValidationException;
use App\Shared\Application\Support\PublicMediaUrlResolver;
use App\Shared\Application\Support\PublicPagesRevalidation;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Creates, edits, orders and deletes the partners of the association, and their logo (story 45.1). A deleted
 * partner's logo stays in the bucket, as an announcement's image does. Every saved write drops the cache of the
 * public pages showing partners (story 45.2); a refused one changes nothing.
 */
final readonly class ManagePartners
{
    private const array MESSAGES = [
        'partner_name_invalid' => 'Un nom de 1 à 80 caractères.',
        'partner_description_invalid' => 'Une description de 160 caractères au plus.',
        'partner_url_invalid' => 'Le lien commence par https://.',
        'partner_position_invalid' => 'Position invalide.',
    ];

    public function __construct(
        private PartnerRepositoryInterface $partners,
        private MinioStorageInterface $minioStorage,
        private PublicMediaUrlResolver $publicMedia,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private PublicPagesRevalidation $revalidation,
    ) {
    }

    /**
     * A new partner goes last.
     *
     * @return string the id of the new partner
     *
     * @throws ValidationException when a field is invalid
     */
    public function create(PartnerDraft $draft): string
    {
        $position = array_reduce($this->partners->all(), static fn (int $max, Partner $partner): int => max($max, $partner->getPosition() + 1), 0);
        try {
            $partner = Partner::create(bin2hex(random_bytes(16)), $draft->name, $draft->description, $draft->url, $draft->active, $position, $this->clock->now());
        } catch (\DomainException $e) {
            throw $this->invalid($e);
        }
        $this->partners->save($partner);
        $this->revalidation->partnersChanged();

        return $partner->getId();
    }

    /**
     * @throws NotFoundException   when the partner does not exist
     * @throws ValidationException when a field is invalid
     */
    public function edit(string $id, PartnerDraft $draft): void
    {
        $partner = $this->get($id);
        try {
            $partner->edit($draft->name, $draft->description, $draft->url, $draft->active, $this->clock->now());
        } catch (\DomainException $e) {
            throw $this->invalid($e);
        }
        $this->partners->save($partner);
        $this->revalidation->partnersChanged();
    }

    /** @throws NotFoundException when the partner does not exist */
    public function delete(string $id): void
    {
        $this->partners->remove($this->get($id));
        $this->revalidation->partnersChanged();
    }

    /**
     * Each listed partner takes its rank as position; unknown ids are skipped, unlisted partners keep theirs.
     *
     * @param list<string> $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $byId = [];
        foreach ($this->partners->all() as $partner) {
            $byId[$partner->getId()] = $partner;
        }
        $now = $this->clock->now();
        $moved = [];
        $position = 0;
        foreach ($orderedIds as $id) {
            $partner = $byId[$id] ?? null;
            if ($partner instanceof Partner && !in_array($partner, $moved, true)) {
                $partner->moveTo($position, $now);
                $moved[] = $partner;
                ++$position;
            }
        }
        $this->partners->saveAll($moved);
        $this->revalidation->partnersChanged();
    }

    /**
     * A new key per upload, so a cached copy of the previous logo never shows under the new one.
     *
     * @throws NotFoundException           when the partner does not exist
     * @throws ServiceUnavailableException when the object storage upload fails
     */
    public function attachLogo(string $id, string $extension, string $contents): void
    {
        $partner = $this->get($id);
        $key = sprintf('content-logos/%s/logo-%s.%s', $id, bin2hex(random_bytes(4)), $extension);
        try {
            $this->minioStorage->upload($this->publicMedia->bucket(), $key, $contents);
        } catch (\Throwable $exception) {
            $this->logger->error('Object storage upload failed for a partner logo.', [
                'bucket' => $this->publicMedia->bucket(),
                'key' => $key,
                'exception' => $exception,
            ]);

            throw new ServiceUnavailableException('Le stockage est indisponible.', 'storage_unavailable');
        }
        $partner->attachLogo($key, $this->clock->now());
        $this->partners->save($partner);
        $this->revalidation->partnersChanged();
    }

    /** @throws NotFoundException when the partner does not exist */
    public function removeLogo(string $id): void
    {
        $partner = $this->get($id);
        $partner->removeLogo($this->clock->now());
        $this->partners->save($partner);
        $this->revalidation->partnersChanged();
    }

    private function get(string $id): Partner
    {
        $partner = $this->partners->find($id);
        if (!$partner instanceof Partner) {
            throw new NotFoundException('Partenaire introuvable.', 'partner_not_found');
        }

        return $partner;
    }

    private function invalid(\DomainException $e): ValidationException
    {
        return new ValidationException(self::MESSAGES[$e->getMessage()] ?? 'Partenaire invalide.', [], $e->getMessage());
    }
}
