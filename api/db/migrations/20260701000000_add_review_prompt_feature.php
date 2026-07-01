<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * "Zanechat recenzi" block on the client dashboard. Per-client state lives on the
 * clients row (admin can switch the prompt off once a review lands, and a client
 * "later" click snoozes it). The Google Business Profile link is a single company-wide
 * value kept in the new app_settings key/value store.
 */
final class AddReviewPromptFeature extends AbstractMigration
{
    public function up(): void
    {
        $this->table('clients')
            ->addColumn('review_prompt_enabled', 'boolean', [
                'default' => true,
                'null' => false,
                'after' => 'whatsapp_group_url',
                'comment' => 'Admin switch: show the Google review prompt to this client. Turned off once a review is confirmed.',
            ])
            ->addColumn('review_prompt_snoozed_until', 'date', [
                'null' => true,
                'default' => null,
                'after' => 'review_prompt_enabled',
                'comment' => 'Client clicked "later": hide the prompt until this date (re-appears after ~14 days).',
            ])
            ->addColumn('review_prompt_rating', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'after' => 'review_prompt_snoozed_until',
                'comment' => 'Last star rating the client selected (1-5); 4-5 routes to Google, 1-3 to an internal complaint.',
            ])
            ->addColumn('review_prompt_completed_at', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'review_prompt_rating',
                'comment' => 'When the client engaged with the prompt (rated + routed); stops the prompt from re-appearing.',
            ])
            ->update();

        $settings = $this->table('app_settings', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $settings
            ->addColumn('id', 'integer', [
                'signed' => false,
                'identity' => true,
            ])
            ->addColumn('setting_key', 'string', [
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('setting_value', 'text', [
                'null' => true,
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->addIndex(['setting_key'], [
                'unique' => true,
                'name' => 'uk_setting_key',
            ])
            ->create();

        // Seed the key so the admin settings form has a row to edit. Empty until the
        // owner pastes the Google Business Profile review link; the prompt stays hidden
        // while it is empty.
        $this->execute(
            "INSERT INTO app_settings (setting_key, setting_value, created_at, updated_at)
             VALUES ('google_review_url', NULL, NOW(), NOW())"
        );
    }

    public function down(): void
    {
        $this->table('app_settings')->drop()->save();

        $this->table('clients')
            ->removeColumn('review_prompt_enabled')
            ->removeColumn('review_prompt_snoozed_until')
            ->removeColumn('review_prompt_rating')
            ->removeColumn('review_prompt_completed_at')
            ->update();
    }
}
