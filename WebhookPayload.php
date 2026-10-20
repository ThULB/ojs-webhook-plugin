<?php

/**
 * @file WebhookPayload.php
 *
 * @brief Pure payload-formatting helpers used by SendWebhookJob.
 */

namespace APP\plugins\generic\webhook;

class WebhookPayload
{
    /**
     * Normalize an OJS date string to a plain Y-m-d string, or null if $date is
     * empty or cannot be parsed.
     *
     * Deliberately free of any OJS/PKP dependency so it can be unit-tested
     * without bootstrapping OJS (see WebhookUrlValidator).
     */
    public static function formatDate(?string $date): ?string
    {
        if (!$date) {
            return null;
        }

        $timestamp = strtotime($date);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    /**
     * Reduce the controlled-vocabulary keyword entries (`[{"name": ..., "source": ...,
     * "identifier": ...}]`) to a plain list of strings, as agreed with the
     * Zeitschriftenserver side. Entries without a `name` are dropped.
     */
    public static function reduceKeywords(array $keywords): array
    {
        return array_values(array_filter(array_map(
            fn ($keyword) => $keyword['name'] ?? null,
            $keywords
        )));
    }

    /**
     * The HMAC-SHA256 signature value for $body keyed with $secret, or null if no
     * secret is configured. The secret itself never crosses the wire - only this
     * signature does, as the X-Webhook-Signature header's value (prefixed with
     * "sha256=" by the caller).
     */
    public static function signatureValue(string $body, ?string $secret): ?string
    {
        if (!$secret) {
            return null;
        }

        return hash_hmac('sha256', $body, $secret);
    }
}
