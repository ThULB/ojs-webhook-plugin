<?php

/**
 * @file WebhookUrlValidator.php
 *
 * @brief Validates that a webhook URL is a syntactically valid http(s) URL.
 */

namespace APP\plugins\generic\webhook;

class WebhookUrlValidator
{
    /**
     * Whether $url is a syntactically valid http(s) URL.
     *
     * Deliberately free of any OJS/PKP dependency (no parent class, no framework
     * calls) so it can be unit-tested without bootstrapping OJS, and shared by
     * WebhookPlugin's enable-gate and WebhookSettingsForm's field validator so
     * both stay in sync.
     */
    public static function isValid(mixed $url): bool
    {
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // Scheme is case-insensitive per RFC 3986 - normalize before comparing.
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}