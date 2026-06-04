<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Application\DiscordBotClientInterface;
use App\Identity\Application\DiscordBotStatusQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalDiscordBotStatusQuery implements DiscordBotStatusQueryInterface
{
    private const BOT_INVITE_URL = 'https://discord.com/oauth2/authorize';
    private const MANAGE_ROLES_PERMISSION = 268435456;

    public function __construct(
        private DiscordBotClientInterface $discordBotClient,
        private Connection $connection,
        private string $clientId,
        private string $guildId,
        private string $roleIdAdmin,
        private string $roleIdMember,
    ) {
    }

    /**
     * @return array{botOnline: bool, guildName: string|null, memberCount: int|null, activeMemberCount: int, managedRoleIds: list<string>, inviteUrl: string|null}
     */
    public function query(): array
    {
        $managedRoleIds = $this->managedRoleIds();
        $inviteUrl = $this->buildInviteUrl();
        $activeMemberCount = $this->countActiveMembers();

        try {
            $info = $this->discordBotClient->fetchGuildInfo($this->guildId);

            $guildName = is_string($info['name'] ?? null) ? $info['name'] : null;
            $memberCount = is_int($info['approximate_member_count'] ?? null) ? $info['approximate_member_count'] : null;

            return [
                'botOnline' => true,
                'guildName' => $guildName,
                'memberCount' => $memberCount,
                'activeMemberCount' => $activeMemberCount,
                'managedRoleIds' => $managedRoleIds,
                'inviteUrl' => $inviteUrl,
            ];
        } catch (\Throwable) {
            return [
                'botOnline' => false,
                'guildName' => null,
                'memberCount' => null,
                'activeMemberCount' => $activeMemberCount,
                'managedRoleIds' => $managedRoleIds,
                'inviteUrl' => $inviteUrl,
            ];
        }
    }

    private function countActiveMembers(): int
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $qb = $this->connection->createQueryBuilder();
        $raw = $qb
            ->select('COUNT(m.id)')
            ->from('memberships', 'm')
            ->where($qb->expr()->eq('m.status', ':status'))
            ->andWhere($qb->expr()->gte('m.expires_at', ':now'))
            ->setParameter('status', 'active')
            ->setParameter('now', $now)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * @return list<string>
     */
    private function managedRoleIds(): array
    {
        return array_values(array_filter(
            [$this->roleIdAdmin, $this->roleIdMember],
            static fn (string $id): bool => '' !== $id,
        ));
    }

    private function buildInviteUrl(): ?string
    {
        if ('' === $this->clientId) {
            return null;
        }

        $params = [
            'client_id' => $this->clientId,
            'permissions' => (string) self::MANAGE_ROLES_PERMISSION,
            'scope' => 'bot applications.commands',
        ];

        if ('' !== $this->guildId) {
            $params['guild_id'] = $this->guildId;
            $params['disable_guild_select'] = 'true';
        }

        return self::BOT_INVITE_URL.'?'.http_build_query($params);
    }
}
