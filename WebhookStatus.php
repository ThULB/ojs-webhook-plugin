<?php

/**
 * @file WebhookStatus.php
 *
 * @brief Persists and reads back the last webhook send attempt per publication,
 * so it can be shown to journal managers (sent/DOI/warnings/errors) and used to
 * decide what event a manual resend should use.
 */

namespace APP\plugins\generic\webhook;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WebhookStatus
{
    protected const TABLE = 'webhook_publication_status';

    protected static bool $tableExists = false;

    /**
     * Whether the status table has been created yet. Its migration only runs automatically on a
     * fresh OJS install (see README "Requirements"); on an existing installation it needs a manual
     * `installPluginVersion.php` run that is easy to miss. get() is called synchronously from
     * core MetadataChanged/Galley/Form hooks, so querying a missing table there would break
     * metadata saves, galley edits and the Identifiers tab for the whole journal - degrade to
     * "no status recorded" instead, and surface the missing step in the plugin description.
     */
    public static function tableExists(): bool
    {
        // Only a positive result is cached: a long-running queue worker should pick up the table
        // as soon as the migration has been run, without needing a restart.
        if (!static::$tableExists) {
            static::$tableExists = Schema::hasTable(self::TABLE);
        }

        return static::$tableExists;
    }

    public static function get(int $publicationId): ?array
    {
        if (!static::tableExists()) {
            return null;
        }

        $row = DB::table(self::TABLE)->where('publication_id', $publicationId)->first();
        if (!$row) {
            return null;
        }

        return [
            'event' => $row->event,
            'sentAt' => $row->sent_at,
            'success' => (bool) $row->success,
            'zsId' => $row->zs_id,
            'zsUrl' => $row->zs_url,
            'warnings' => $row->warnings ? json_decode($row->warnings, true) : [],
            'error' => $row->error,
        ];
    }

    /**
     * Called after the webhook was already delivered - so a missing table must not throw here
     * either, or the job would be retried and re-send a request the receiving end already
     * processed.
     */
    public static function recordSuccess(int $publicationId, string $event, ?string $zsId, ?string $zsUrl, array $warnings): void
    {
        if (!static::tableExists()) {
            error_log("WebhookStatus: table " . self::TABLE . " is missing, not recording success of \"{$event}\" webhook for publication {$publicationId}. Run tools/installPluginVersion.php for this plugin.");
            return;
        }

        DB::table(self::TABLE)->updateOrInsert(
            ['publication_id' => $publicationId],
            [
                'event' => $event,
                'sent_at' => date('Y-m-d H:i:s'),
                'success' => true,
                'zs_id' => $zsId,
                'zs_url' => $zsUrl,
                'warnings' => $warnings ? json_encode($warnings) : null,
                'error' => null,
            ]
        );
    }

    /**
     * Records a final failure (after all of SendWebhookJob's retries are
     * exhausted). Deliberately leaves zs_id/zs_url/warnings untouched - they
     * still reflect the last successful response, if any, since this attempt
     * produced no new response to replace them with.
     */
    public static function recordFailure(int $publicationId, string $event, string $error): void
    {
        if (!static::tableExists()) {
            error_log("WebhookStatus: table " . self::TABLE . " is missing, not recording failure of \"{$event}\" webhook for publication {$publicationId}: {$error}");
            return;
        }

        DB::table(self::TABLE)->updateOrInsert(
            ['publication_id' => $publicationId],
            [
                'event' => $event,
                'sent_at' => date('Y-m-d H:i:s'),
                'success' => false,
                'error' => $error,
            ]
        );
    }
}
