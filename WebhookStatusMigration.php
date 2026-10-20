<?php

/**
 * @file WebhookStatusMigration.php
 *
 * @brief Creates the table backing WebhookStatus (last send attempt per publication).
 */

namespace APP\plugins\generic\webhook;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class WebhookStatusMigration extends Migration
{
    public function up(): void
    {
        // Idempotent: Plugin::getInstallMigration() only runs automatically on a
        // fresh OJS install. On an existing installation this runs again whenever
        // `tools/installPluginVersion.php` is re-run after a plugin update.
        if (Schema::hasTable('webhook_publication_status')) {
            return;
        }

        Schema::create('webhook_publication_status', function (Blueprint $table) {
            $table->bigInteger('publication_id');
            $table->foreign('publication_id', 'webhook_publication_status_publication_id')
                ->references('publication_id')
                ->on('publications')
                ->onDelete('cascade');
            $table->unique(['publication_id'], 'webhook_publication_status_pkey');

            $table->string('event', 32);
            $table->datetime('sent_at');
            $table->boolean('success');
            // The Zeitschriftenserver's own identifier/URL for the created record.
            $table->string('zs_id', 255)->nullable();
            $table->string('zs_url', 2048)->nullable();
            $table->text('warnings')->nullable();
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::drop('webhook_publication_status');
    }
}
