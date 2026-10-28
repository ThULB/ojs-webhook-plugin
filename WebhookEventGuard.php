<?php

/**
 * @file WebhookEventGuard.php
 *
 * @brief Pure check used by SendWebhookJob to drop a queued webhook whose event no
 * longer matches the publication's current state.
 */

namespace APP\plugins\generic\webhook;

class WebhookEventGuard
{
    /**
     * Whether a queued webhook for $event has been overtaken by a later state change and must not
     * be sent anymore.
     *
     * The event is fixed when the job is dispatched, but a retry can run up to 30 minutes later
     * (see SendWebhookJob::backoff()) - after a newer job for the same publication has already been
     * delivered. Without this check, a delayed "published" retry arriving after a successful
     * "unpublished" would make the Zeitschriftenserver treat the article as live again while OJS
     * has it unpublished.
     *
     * - "production" / "published": stale once the publication is no longer in the state they
     *   announce (published already / not published anymore). A "published" webhook for the same
     *   publication supersedes "production", since it carries the complete payload.
     * - "unpublished": stale once the publication has been published again.
     * - "updated": stale only after an unpublish has been recorded for the publication - an
     *   "updated" for a publication that is still in production is legitimate.
     *
     * Deliberately free of any OJS/PKP dependency so it can be unit-tested without bootstrapping
     * OJS (see WebhookUrlValidator).
     */
    public static function isStale(string $event, bool $isPublished, ?string $lastRecordedEvent): bool
    {
        return match ($event) {
            'production' => $isPublished,
            'published' => !$isPublished,
            'unpublished' => $isPublished,
            'updated' => !$isPublished && $lastRecordedEvent === 'unpublished',
            default => false,
        };
    }
}
