<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260515163534 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admin_creation_audit (id VARCHAR(32) NOT NULL, created_user_id VARCHAR(32) NOT NULL, creator_user_id VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_admin_account_creation_audits_created_user_id ON admin_creation_audit (created_user_id)');
        $this->addSql('CREATE INDEX idx_identity_admin_account_creation_audits_creator_user_id ON admin_creation_audit (creator_user_id)');
        $this->addSql('CREATE TABLE deletion_audit (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, email_hash VARCHAR(64) NOT NULL, reason VARCHAR(120) NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE email_confirmation_token (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, confirmed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_email_confirmation_tokens_user ON email_confirmation_token (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_email_confirmation_tokens_hash ON email_confirmation_token (token_hash)');
        $this->addSql('CREATE TABLE event (id VARCHAR(32) NOT NULL, title VARCHAR(120) NOT NULL, description TEXT NOT NULL, status VARCHAR(20) NOT NULL, starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, venue VARCHAR(160) NOT NULL, capacity INT NOT NULL, registration_opens_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, registration_closes_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, is_public BOOLEAN NOT NULL, private_access_password_hash VARCHAR(255) DEFAULT NULL, game_selection_enabled BOOLEAN NOT NULL, game_selection_config JSON NOT NULL, vod_url VARCHAR(500) DEFAULT NULL, recap_post_slug VARCHAR(120) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, game_selection_max INT DEFAULT NULL, capacity_notification_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, helloasso_form_slug VARCHAR(120) DEFAULT NULL, cover_image_url VARCHAR(2048) DEFAULT NULL, photo_gallery JSON DEFAULT NULL, cover_image_key VARCHAR(500) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE event_private_access_log (id VARCHAR(32) NOT NULL, event_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, granted BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE game (id VARCHAR(32) NOT NULL, name VARCHAR(120) NOT NULL, slug VARCHAR(120) NOT NULL, description TEXT NOT NULL, cover_image_url TEXT DEFAULT NULL, cover_image_alt VARCHAR(160) NOT NULL, cover_image_credit VARCHAR(160) NOT NULL, availability VARCHAR(32) NOT NULL, archipelago_game_name VARCHAR(120) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, apworld_storage_key VARCHAR(500) DEFAULT NULL, apworld_hash VARCHAR(64) DEFAULT NULL, apworld_uploaded_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, default_yaml TEXT DEFAULT NULL, apworld_minio_key VARCHAR(500) DEFAULT NULL, availability_locked BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_games_slug ON game (slug)');
        $this->addSql('CREATE TABLE game_catalog_sync (catalog_sheet_name VARCHAR(160) DEFAULT NULL, apworld_source_url VARCHAR(500) DEFAULT NULL, apworld_deployed_version VARCHAR(50) DEFAULT NULL, apworld_latest_version VARCHAR(50) DEFAULT NULL, apworld_checked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, apworld_release_url VARCHAR(500) DEFAULT NULL, igdb_id INT DEFAULT NULL, adult_content BOOLEAN DEFAULT false NOT NULL, bundled_with_ap BOOLEAN DEFAULT false NOT NULL, game_id VARCHAR(32) NOT NULL, PRIMARY KEY (game_id))');
        $this->addSql('CREATE TABLE game_request (id VARCHAR(32) NOT NULL, game_name VARCHAR(255) NOT NULL, normalized_name VARCHAR(255) NOT NULL, user_id VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_game_requests_user_game ON game_request (user_id, normalized_name)');
        $this->addSql('CREATE TABLE hello_asso_order (id VARCHAR(32) NOT NULL, helloasso_order_id INT NOT NULL, form_type VARCHAR(60) NOT NULL, form_slug VARCHAR(120) NOT NULL, status VARCHAR(40) NOT NULL, amount_cents INT NOT NULL, payer_email VARCHAR(200) DEFAULT NULL, payer_first_name VARCHAR(100) DEFAULT NULL, payer_last_name VARCHAR(100) DEFAULT NULL, paid_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, synced_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3248E149D1E09C22 ON hello_asso_order (helloasso_order_id)');
        $this->addSql('CREATE TABLE hello_asso_sync_log (id VARCHAR(32) NOT NULL, form_slug VARCHAR(120) NOT NULL, attempt_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, success BOOLEAN NOT NULL, error_message TEXT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE ignored_catalog_entry (name VARCHAR(160) NOT NULL, ignored_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (name))');
        $this->addSql('CREATE TABLE password_reset_token (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_password_reset_tokens_user ON password_reset_token (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_password_reset_tokens_hash ON password_reset_token (token_hash)');
        $this->addSql('CREATE TABLE post (id VARCHAR(32) NOT NULL, slug VARCHAR(120) NOT NULL, title VARCHAR(200) NOT NULL, type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, excerpt TEXT NOT NULL, body JSON NOT NULL, reading_time VARCHAR(20) NOT NULL, related_event_slug VARCHAR(120) DEFAULT NULL, vod_url VARCHAR(500) DEFAULT NULL, cover_image_url VARCHAR(2048) DEFAULT NULL, published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, cover_image_key VARCHAR(500) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5A8A6C8D989D9B62 ON post (slug)');
        $this->addSql('CREATE TABLE privacy_rights_request (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, right_type VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, handling_mode VARCHAR(30) NOT NULL, details TEXT DEFAULT NULL, submitted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_privacy_rights_requests_user_id ON privacy_rights_request (user_id)');
        $this->addSql('CREATE INDEX idx_identity_privacy_rights_requests_status ON privacy_rights_request (status)');
        $this->addSql('CREATE TABLE refresh_token (id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_agent VARCHAR(255) DEFAULT NULL, revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, remember_me BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_refresh_tokens_user_revoked ON refresh_token (user_id, revoked_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_refresh_tokens_token_hash ON refresh_token (token_hash)');
        $this->addSql('CREATE TABLE registration (id VARCHAR(32) NOT NULL, event_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, game_slots JSON NOT NULL, submitted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_event_registrations_event_user ON registration (event_id, user_id)');
        $this->addSql('CREATE TABLE registration_admin_message (id VARCHAR(32) NOT NULL, event_id VARCHAR(32) NOT NULL, registration_id VARCHAR(32) NOT NULL, admin_id VARCHAR(32) NOT NULL, subject VARCHAR(160) NOT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_registrations_admin_messages_registration ON registration_admin_message (registration_id, sent_at)');
        $this->addSql('CREATE TABLE role_change_audit (id VARCHAR(32) NOT NULL, target_user_id VARCHAR(32) NOT NULL, admin_user_id VARCHAR(32) NOT NULL, previous_role VARCHAR(20) NOT NULL, new_role VARCHAR(20) NOT NULL, changed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_role_change_audits_target_user_id ON role_change_audit (target_user_id)');
        $this->addSql('CREATE INDEX idx_identity_role_change_audits_admin_user_id ON role_change_audit (admin_user_id)');
        $this->addSql('CREATE TABLE run (id VARCHAR(32) NOT NULL, owner_id VARCHAR(32) NOT NULL, title VARCHAR(120) NOT NULL, status VARCHAR(20) NOT NULL, invite_token VARCHAR(64) NOT NULL, game_selection_config JSON DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, connection_host VARCHAR(255) DEFAULT NULL, connection_port INT DEFAULT NULL, connection_password VARCHAR(120) DEFAULT NULL, session_id VARCHAR(32) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5076A4C05242FFC4 ON run (invite_token)');
        $this->addSql('CREATE TABLE run_audit_log (id VARCHAR(36) NOT NULL, run_id VARCHAR(36) NOT NULL, admin_user_id VARCHAR(36) NOT NULL, action VARCHAR(50) NOT NULL, payload JSON DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE run_participant (personal_run_id VARCHAR(32) NOT NULL, user_id VARCHAR(32) NOT NULL, joined_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, game_slots JSON NOT NULL, PRIMARY KEY (personal_run_id, user_id))');
        $this->addSql('CREATE TABLE session (id VARCHAR(36) NOT NULL, event_id VARCHAR(36) NOT NULL, status VARCHAR(20) NOT NULL, host VARCHAR(255) DEFAULT NULL, port INT DEFAULT NULL, password VARCHAR(255) DEFAULT NULL, server_password VARCHAR(255) DEFAULT NULL, bridge_port INT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, stopped_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, notified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, validation_errors JSON DEFAULT NULL, finished_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, last_logs TEXT DEFAULT NULL, archived_save_path VARCHAR(512) DEFAULT NULL, archived_spoiler_path VARCHAR(512) DEFAULT NULL, runner_id VARCHAR(255) DEFAULT NULL, last_heartbeat_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, last_activity_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, last_save_key VARCHAR(500) DEFAULT NULL, paused_without_save BOOLEAN DEFAULT false NOT NULL, restart_failed BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE session_slot (id VARCHAR(36) NOT NULL, session_id VARCHAR(36) NOT NULL, registration_id VARCHAR(36) NOT NULL, game_id VARCHAR(36) NOT NULL, slot_name VARCHAR(16) NOT NULL, slot_order INT NOT NULL, slot_id VARCHAR(36) DEFAULT NULL, checks_done INT NOT NULL, items_received INT NOT NULL, goal_reached_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, was_released BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE "user" (id VARCHAR(32) NOT NULL, email VARCHAR(180) NOT NULL, email_canonical VARCHAR(180) NOT NULL, display_name VARCHAR(80) DEFAULT NULL, password_hash VARCHAR(255) NOT NULL, roles JSON NOT NULL, cgu_accepted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, cgu_accepted_version VARCHAR(20) NOT NULL, slug VARCHAR(80) DEFAULT NULL, email_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_users_email_canonical ON "user" (email_canonical)');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_users_slug ON "user" (slug)');
        $this->addSql('ALTER TABLE game_catalog_sync ADD CONSTRAINT FK_B74E7162E48FD905 FOREIGN KEY (game_id) REFERENCES game (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_catalog_sync DROP CONSTRAINT FK_B74E7162E48FD905');
        $this->addSql('DROP TABLE admin_creation_audit');
        $this->addSql('DROP TABLE deletion_audit');
        $this->addSql('DROP TABLE email_confirmation_token');
        $this->addSql('DROP TABLE event');
        $this->addSql('DROP TABLE event_private_access_log');
        $this->addSql('DROP TABLE game');
        $this->addSql('DROP TABLE game_catalog_sync');
        $this->addSql('DROP TABLE game_request');
        $this->addSql('DROP TABLE hello_asso_order');
        $this->addSql('DROP TABLE hello_asso_sync_log');
        $this->addSql('DROP TABLE ignored_catalog_entry');
        $this->addSql('DROP TABLE password_reset_token');
        $this->addSql('DROP TABLE post');
        $this->addSql('DROP TABLE privacy_rights_request');
        $this->addSql('DROP TABLE refresh_token');
        $this->addSql('DROP TABLE registration');
        $this->addSql('DROP TABLE registration_admin_message');
        $this->addSql('DROP TABLE role_change_audit');
        $this->addSql('DROP TABLE run');
        $this->addSql('DROP TABLE run_audit_log');
        $this->addSql('DROP TABLE run_participant');
        $this->addSql('DROP TABLE session');
        $this->addSql('DROP TABLE session_slot');
        $this->addSql('DROP TABLE "user"');
    }
}
