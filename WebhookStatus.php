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

class WebhookStatus
{
    protected const TABLE = 'webhook_publication_status';

    public static function get(int $publicationId): ?array
    {
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

    public static function recordSuccess(int $publicationId, string $event, ?string $zsId, ?string $zsUrl, array $warnings): void
    {
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
