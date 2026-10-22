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
     * Maps a warning `code` (as sent by the Zeitschriftenserver) to this plugin's locale key for
     * its translated label, so the editorial office sees a sentence instead of a bare code. A code
     * the receiving end added after this plugin was last updated simply has no entry here - callers
     * should fall back to displaying the raw code in that case.
     */
    private const LOCALE_KEYS = [
        'NO_ISSUE' => 'plugins.generic.webhook.warning.noIssue',
        'AMBIGUOUS_TITLE' => 'plugins.generic.webhook.warning.ambiguousTitle',
        'UNKNOWN_RUBRIC' => 'plugins.generic.webhook.warning.unknownRubric',
        'NO_PERSONS' => 'plugins.generic.webhook.warning.noPersons',
        'UNLINKED_AUTHORS' => 'plugins.generic.webhook.warning.unlinkedAuthors',
        'INVALID_ORCID' => 'plugins.generic.webhook.warning.invalidOrcid',
        'FOREIGN_ORCID' => 'plugins.generic.webhook.warning.foreignOrcid',
        'UNVERIFIED_ORCID' => 'plugins.generic.webhook.warning.unverifiedOrcid',
        'NO_PAGES' => 'plugins.generic.webhook.warning.noPages',
        'DOI_DISABLED' => 'plugins.generic.webhook.warning.doiDisabled',
        'DOI_FAILED' => 'plugins.generic.webhook.warning.doiFailed',
        'DOI_MISMATCH' => 'plugins.generic.webhook.warning.doiMismatch',
        'UNKNOWN_SUBMISSION' => 'plugins.generic.webhook.warning.unknownSubmission',
        'UNPUBLISHED_IN_OJS' => 'plugins.generic.webhook.warning.unpublishedInOjs',
    ];

    /**
     * The locale key for a warning code's translated label, or null if this plugin does not (yet)
     * recognize the code.
     *
     * Deliberately free of any OJS/PKP dependency (no `__()` call here) so it can be unit-tested
     * without bootstrapping OJS (see WebhookUrlValidator) - the actual translation happens in
     * WebhookPlugin::formatStatusHtml(), which already runs inside a bootstrapped request.
     */
    public static function localeKeyForCode(string $code): ?string
    {
        return self::LOCALE_KEYS[$code] ?? null;
    }

    /**
     * Reduce a webhook response's `warnings` array (`[{"code": ..., "detail": ...}]`) to the
     * entries worth displaying, each normalized to its `code` and English `detail` (not `message` -
     * the field the Zeitschriftenserver actually sends is `detail`). Entries without a `code` are
     * dropped.
     */
    public static function normalizeWarnings(array $warnings): array
    {
        return array_values(array_filter(array_map(
            fn ($warning) => isset($warning['code']) ? ['code' => $warning['code'], 'detail' => $warning['detail'] ?? null] : null,
            $warnings
        )));
    }
}
