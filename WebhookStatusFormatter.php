<?php

/**
 * @file WebhookStatusFormatter.php
 *
 * @brief Pure formatting helper used by WebhookPlugin::formatStatusHtml().
 */

namespace APP\plugins\generic\webhook;

class WebhookStatusFormatter
{
    /**
     * Reduce a webhook response's `warnings` array (`[{"code": ..., "message": ...}]`)
     * to a comma-separated list of codes, dropping entries without one. Returns an
     * empty string if there are none worth displaying.
     *
     * Deliberately free of any OJS/PKP dependency so it can be unit-tested without
     * bootstrapping OJS (see WebhookUrlValidator).
     */
    public static function warningCodes(array $warnings): string
    {
        return implode(', ', array_filter(array_map(
            fn ($warning) => $warning['code'] ?? null,
            $warnings
        )));
    }
}
