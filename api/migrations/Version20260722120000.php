<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A new community profile is born public (story 30.28).
 *
 * Only the column DEFAULT moves. Existing rows are deliberately left as they are: nothing in the data
 * distinguishes a `members` that its owner chose from a `members` that was merely the previous default,
 * so a blanket UPDATE would publish profiles whose owner had restricted them on purpose. Members who
 * want the new behaviour can switch it themselves in their settings.
 *
 * This is why there is no `UPDATE community_profile SET audience = 'public'` here, and why adding one
 * later would be a privacy change rather than a follow-up cleanup.
 */
final class Version20260722120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community profile: default audience becomes public for new rows (story 30.28)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE community_profile ALTER COLUMN audience SET DEFAULT 'public'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE community_profile ALTER COLUMN audience SET DEFAULT 'members'");
    }
}
