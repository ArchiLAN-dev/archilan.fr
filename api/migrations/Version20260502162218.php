<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260502162218 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE events_events (id VARCHAR(32) NOT NULL, title VARCHAR(120) NOT NULL, description TEXT NOT NULL, type VARCHAR(60) NOT NULL, status VARCHAR(20) NOT NULL, starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, venue VARCHAR(160) NOT NULL, capacity INT NOT NULL, confirmed_registrations INT NOT NULL, registration_opens_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, registration_closes_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, is_public BOOLEAN NOT NULL, private_access_password_hash VARCHAR(255) DEFAULT NULL, game_selection_enabled BOOLEAN NOT NULL, game_selection_config JSON NOT NULL, vod_url VARCHAR(500) DEFAULT NULL, recap_post_slug VARCHAR(120) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, game_selection_max INT DEFAULT NULL, capacity_notification_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, helloasso_form_slug VARCHAR(120) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE events_private_access_logs (id VARCHAR(32) NOT NULL, event_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, granted BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE game_selection_games (id VARCHAR(32) NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL, description TEXT NOT NULL, cover_image_alt VARCHAR(160) NOT NULL, cover_image_credit VARCHAR(160) NOT NULL, availability VARCHAR(32) NOT NULL, supported_event_types JSON NOT NULL, randomizer_options JSON NOT NULL, option_schema_version INT NOT NULL, archipelago_game_name VARCHAR(120) DEFAULT NULL, default_yaml_values JSON NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_selection_games_slug ON game_selection_games (slug)');
        $this->addSql('CREATE TABLE identity_account_deletion_audits (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, email_hash VARCHAR(64) NOT NULL, reason VARCHAR(120) NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE identity_admin_account_creation_audits (id VARCHAR(32) NOT NULL, created_user_id VARCHAR(32) NOT NULL, creator_user_id VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_admin_account_creation_audits_created_user_id ON identity_admin_account_creation_audits (created_user_id)');
        $this->addSql('CREATE INDEX idx_identity_admin_account_creation_audits_creator_user_id ON identity_admin_account_creation_audits (creator_user_id)');
        $this->addSql('CREATE TABLE identity_privacy_rights_requests (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, right_type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, handling_mode VARCHAR(30) NOT NULL, details TEXT DEFAULT NULL, submitted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_privacy_rights_requests_user_id ON identity_privacy_rights_requests (user_id)');
        $this->addSql('CREATE INDEX idx_identity_privacy_rights_requests_status ON identity_privacy_rights_requests (status)');
        $this->addSql('CREATE TABLE identity_role_change_audits (id VARCHAR(32) NOT NULL, target_user_id VARCHAR(32) NOT NULL, admin_user_id VARCHAR(32) NOT NULL, previous_role VARCHAR(20) NOT NULL, new_role VARCHAR(20) NOT NULL, changed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_role_change_audits_target_user_id ON identity_role_change_audits (target_user_id)');
        $this->addSql('CREATE INDEX idx_identity_role_change_audits_admin_user_id ON identity_role_change_audits (admin_user_id)');
        $this->addSql('CREATE TABLE identity_users (id VARCHAR(32) NOT NULL, email VARCHAR(180) NOT NULL, email_canonical VARCHAR(180) NOT NULL, display_name VARCHAR(80) DEFAULT NULL, password_hash VARCHAR(255) NOT NULL, roles JSON NOT NULL, cgu_accepted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, cgu_accepted_version VARCHAR(20) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_users_email_canonical ON identity_users (email_canonical)');
        $this->addSql('CREATE TABLE payments_helloasso_orders (id VARCHAR(32) NOT NULL, helloasso_order_id INT NOT NULL, form_type VARCHAR(60) NOT NULL, form_slug VARCHAR(120) NOT NULL, status VARCHAR(40) NOT NULL, amount_cents INT NOT NULL, payer_email VARCHAR(200) DEFAULT NULL, payer_first_name VARCHAR(100) DEFAULT NULL, payer_last_name VARCHAR(100) DEFAULT NULL, paid_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, synced_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B6AE2125D1E09C22 ON payments_helloasso_orders (helloasso_order_id)');
        $this->addSql('CREATE TABLE payments_helloasso_sync_logs (id VARCHAR(32) NOT NULL, form_slug VARCHAR(120) NOT NULL, attempt_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, success BOOLEAN NOT NULL, error_message TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE registrations_admin_messages (id VARCHAR(32) NOT NULL, event_id VARCHAR(32) NOT NULL, registration_id VARCHAR(32) NOT NULL, admin_id VARCHAR(32) NOT NULL, subject VARCHAR(160) NOT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_registrations_admin_messages_registration ON registrations_admin_messages (registration_id, sent_at)');
        $this->addSql('CREATE TABLE registrations_registrations (id VARCHAR(32) NOT NULL, event_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, game_slots JSON NOT NULL, submitted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_registrations_event_user ON registrations_registrations (event_id, user_id)');
        $this->addSql('CREATE TABLE sessions_sessions (id VARCHAR(36) NOT NULL, event_id VARCHAR(36) NOT NULL, status VARCHAR(20) NOT NULL, host VARCHAR(255) DEFAULT NULL, port INT DEFAULT NULL, password VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, stopped_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, notified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE sessions_slots (id VARCHAR(36) NOT NULL, session_id VARCHAR(36) NOT NULL, registration_id VARCHAR(36) NOT NULL, game_id VARCHAR(36) NOT NULL, slot_name VARCHAR(16) NOT NULL, slot_order INT NOT NULL, slot_id VARCHAR(36) DEFAULT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE events_events');
        $this->addSql('DROP TABLE events_private_access_logs');
        $this->addSql('DROP TABLE game_selection_games');
        $this->addSql('DROP TABLE identity_account_deletion_audits');
        $this->addSql('DROP TABLE identity_admin_account_creation_audits');
        $this->addSql('DROP TABLE identity_privacy_rights_requests');
        $this->addSql('DROP TABLE identity_role_change_audits');
        $this->addSql('DROP TABLE identity_users');
        $this->addSql('DROP TABLE payments_helloasso_orders');
        $this->addSql('DROP TABLE payments_helloasso_sync_logs');
        $this->addSql('DROP TABLE registrations_admin_messages');
        $this->addSql('DROP TABLE registrations_registrations');
        $this->addSql('DROP TABLE sessions_sessions');
        $this->addSql('DROP TABLE sessions_slots');
    }
}
