<?php

namespace APP\plugins\generic\webhook\Tests;

use APP\plugins\generic\webhook\WebhookStatusFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebhookStatusFormatterTest extends TestCase
{
    #[DataProvider('knownCodeProvider')]
    public function testLocaleKeyForCodeResolvesKnownCodes(string $code, string $expectedKey): void
    {
        $this->assertSame($expectedKey, WebhookStatusFormatter::localeKeyForCode($code));
    }

    public static function knownCodeProvider(): array
    {
        return [
            'NO_ISSUE' => ['NO_ISSUE', 'plugins.generic.webhook.warning.noIssue'],
            'AMBIGUOUS_TITLE' => ['AMBIGUOUS_TITLE', 'plugins.generic.webhook.warning.ambiguousTitle'],
            'UNKNOWN_RUBRIC' => ['UNKNOWN_RUBRIC', 'plugins.generic.webhook.warning.unknownRubric'],
            'NO_PERSONS' => ['NO_PERSONS', 'plugins.generic.webhook.warning.noPersons'],
            'UNLINKED_AUTHORS' => ['UNLINKED_AUTHORS', 'plugins.generic.webhook.warning.unlinkedAuthors'],
            'INVALID_ORCID' => ['INVALID_ORCID', 'plugins.generic.webhook.warning.invalidOrcid'],
            'FOREIGN_ORCID' => ['FOREIGN_ORCID', 'plugins.generic.webhook.warning.foreignOrcid'],
            'UNVERIFIED_ORCID' => ['UNVERIFIED_ORCID', 'plugins.generic.webhook.warning.unverifiedOrcid'],
            'NO_PAGES' => ['NO_PAGES', 'plugins.generic.webhook.warning.noPages'],
            'DOI_DISABLED' => ['DOI_DISABLED', 'plugins.generic.webhook.warning.doiDisabled'],
            'DOI_FAILED' => ['DOI_FAILED', 'plugins.generic.webhook.warning.doiFailed'],
            'DOI_MISMATCH' => ['DOI_MISMATCH', 'plugins.generic.webhook.warning.doiMismatch'],
            'UNKNOWN_SUBMISSION' => ['UNKNOWN_SUBMISSION', 'plugins.generic.webhook.warning.unknownSubmission'],
            'UNPUBLISHED_IN_OJS' => ['UNPUBLISHED_IN_OJS', 'plugins.generic.webhook.warning.unpublishedInOjs'],
        ];
    }

    public function testLocaleKeyForCodeReturnsNullForAnUnknownCode(): void
    {
        $this->assertNull(WebhookStatusFormatter::localeKeyForCode('SOME_FUTURE_CODE'));
    }

    #[DataProvider('warningsProvider')]
    public function testNormalizeWarnings(array $input, array $expected): void
    {
        $this->assertSame($expected, WebhookStatusFormatter::normalizeWarnings($input));
    }

    public static function warningsProvider(): array
    {
        return [
            'empty' => [[], []],
            'single warning with detail' => [
                [['code' => 'NO_PAGES', 'detail' => 'No page range, the payload carries none.']],
                [['code' => 'NO_PAGES', 'detail' => 'No page range, the payload carries none.']],
            ],
            'detail defaults to null when absent' => [
                [['code' => 'NO_PERSONS']],
                [['code' => 'NO_PERSONS', 'detail' => null]],
            ],
            'a message field is not detail and is ignored' => [
                [['code' => 'NO_PAGES', 'message' => 'No pages given']],
                [['code' => 'NO_PAGES', 'detail' => null]],
            ],
            'entries without a code are dropped and the result reindexed' => [
                [['detail' => 'no code here'], ['code' => 'AMBIGUOUS_TITLE', 'detail' => 'Updated 123.']],
                [['code' => 'AMBIGUOUS_TITLE', 'detail' => 'Updated 123.']],
            ],
            'all entries without a code yields an empty array' => [
                [['detail' => 'no code here']],
                [],
            ],
        ];
    }
}
