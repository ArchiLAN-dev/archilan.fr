<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Story 36.6: trace admin actions applied to another member's account (session revocation, forced email
 * verification). The other admin gestures already had a trail; these two had none.
 */
final class Version20260807120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create admin_user_action_audit (story 36.6)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE admin_user_action_audit (
                id VARCHAR(32) NOT NULL,
                target_user_id VARCHAR(32) NOT NULL,
                admin_user_id VARCHAR(32) NOT NULL,
                action VARCHAR(40) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_identity_admin_user_action_audits_target ON admin_user_action_audit (target_user_id)');
        $this->addSql('CREATE INDEX idx_identity_admin_user_action_audits_admin ON admin_user_action_audit (admin_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_user_action_audit');
    }
}
