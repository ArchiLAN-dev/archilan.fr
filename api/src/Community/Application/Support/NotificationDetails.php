<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Domain\Entity\Notification;
use App\Community\Domain\Repository\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\Repository\FriendshipRepositoryInterface;
use App\Community\Domain\ValueObject\CosmeticReward;
use App\Shared\Application\Support\PublicMediaUrlResolver;

/**
 * Story 30.48: what a notification shows besides its stored payload, resolved at read time - the achievement's name,
 * description and image, the preview of a cosmetic won, the friend request still waiting for an answer. Nothing is
 * stored: a renamed achievement reads with its new name, a deleted one falls back on the payload.
 */
final readonly class NotificationDetails
{
    public function __construct(
        private AchievementDefinitionRepositoryInterface $achievements,
        private AchievementImageUrlResolver $achievementImages,
        private FriendshipRepositoryInterface $friendships,
        private AvatarFrameCatalog $frames,
        private ProfileBannerCatalog $banners,
        private ProfileTitleCatalog $titles,
        private PublicMediaUrlResolver $publicMedia,
    ) {
    }

    /**
     * @param list<Notification> $notifications
     *
     * @return array<string, array<string, mixed>> the details of each notification that has some, by its id
     */
    public function of(string $recipientId, array $notifications): array
    {
        $types = array_map(static fn (Notification $n): string => $n->getType(), $notifications);
        $achievements = in_array(Notification::TYPE_ACHIEVEMENT_UNLOCKED, $types, true) ? $this->achievementsByKey() : [];
        $requests = in_array(Notification::TYPE_FRIEND_REQUEST_RECEIVED, $types, true) ? $this->pendingRequestsByRequester($recipientId) : [];
        $cosmetics = in_array(Notification::TYPE_COSMETIC_UNLOCKED, $types, true) ? $this->cosmeticPreviews() : [];

        $details = [];
        foreach ($notifications as $notification) {
            $payload = $notification->getPayload();
            $detail = match ($notification->getType()) {
                Notification::TYPE_ACHIEVEMENT_UNLOCKED => $achievements[self::text($payload, 'achievementKey')] ?? null,
                Notification::TYPE_FRIEND_REQUEST_RECEIVED => $requests[self::text($payload, 'fromUserId')] ?? null,
                Notification::TYPE_COSMETIC_UNLOCKED => $cosmetics[self::text($payload, 'type').':'.self::text($payload, 'key')] ?? null,
                default => null,
            };
            if (null !== $detail) {
                $details[$notification->getId()] = $detail;
            }
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function text(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<string, array{achievement: array{name: string, description: string, imageUrl: string|null}}>
     */
    private function achievementsByKey(): array
    {
        $byKey = [];
        foreach ($this->achievements->all() as $definition) {
            $byKey[$definition->getKey()] = ['achievement' => [
                'name' => $definition->getName(),
                'description' => $definition->getDescription(),
                'imageUrl' => $this->achievementImages->resolve($definition->getCustomImageKey()),
            ]];
        }

        return $byKey;
    }

    /**
     * Only a request still pending can be answered from the notification.
     *
     * @return array<string, array{friendRequest: array{id: string}}>
     */
    private function pendingRequestsByRequester(string $recipientId): array
    {
        $byRequester = [];
        foreach ($this->friendships->findIncomingPending($recipientId) as $friendship) {
            $byRequester[$friendship->getRequesterId()] = ['friendRequest' => ['id' => $friendship->getId()]];
        }

        return $byRequester;
    }

    /**
     * A built-in frame or banner has no file here: the frontend draws it from its own catalog.
     *
     * @return array<string, array{cosmetic: array<string, string|null>}>
     */
    private function cosmeticPreviews(): array
    {
        $previews = [];
        foreach ($this->frames->videoFrames() as $frame) {
            $previews[CosmeticReward::FRAME.':'.$frame['key']] = ['cosmetic' => [
                'name' => $frame['label'],
                'image' => null === $frame['files'] ? null : $this->publicMedia->resolve($frame['files']['poster']),
            ]];
        }
        foreach ($this->banners->banners() as $banner) {
            $previews[CosmeticReward::BANNER.':'.$banner['key']] = ['cosmetic' => [
                'name' => $banner['label'],
                'image' => null === $banner['files'] ? null : $this->publicMedia->resolve($banner['files']['image']),
            ]];
        }
        foreach ($this->titles->titles() as $title) {
            $previews[CosmeticReward::TITLE.':'.$title['key']] = ['cosmetic' => [
                'name' => $title['label'],
                'rarity' => $title['rarity'],
                'icon' => $title['icon'],
            ]];
        }

        return $previews;
    }
}
