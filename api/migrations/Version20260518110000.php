<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class Version20260518110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add slug column to identity_users, backfill existing rows, add unique index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_users ADD slug VARCHAR(80) DEFAULT NULL');
    }

    public function postUp(Schema $schema): void
    {
        $slugger = new AsciiSlugger();
        $users = $this->connection->fetchAllAssociative(
            'SELECT id, display_name, email_canonical FROM identity_users ORDER BY id',
        );

        $usedSlugs = [];
        foreach ($users as $user) {
            $displayName = is_string($user['display_name'] ?? null) ? $user['display_name'] : null;
            $emailCanonical = is_string($user['email_canonical']) ? $user['email_canonical'] : '';
            $source = $displayName ?? ((string) strstr($emailCanonical, '@', true) ?: $emailCanonical);

            $normalized = (string) $slugger->slug($source)->lower();
            $base = '' !== $normalized ? mb_substr($normalized, 0, 75) : 'user';

            $slug = $base;
            $i = 2;
            while (in_array($slug, $usedSlugs, true)) {
                $slug = $base.'-'.$i;
                ++$i;
            }
            $usedSlugs[] = $slug;

            $this->connection->executeStatement(
                'UPDATE identity_users SET slug = ? WHERE id = ?',
                [$slug, $user['id']],
            );
        }

        $this->connection->executeStatement(
            'ALTER TABLE identity_users ALTER COLUMN slug SET NOT NULL',
        );
        $this->connection->executeStatement(
            'CREATE UNIQUE INDEX uniq_identity_users_slug ON identity_users (slug)',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_identity_users_slug');
        $this->addSql('ALTER TABLE identity_users DROP COLUMN slug');
    }
}
