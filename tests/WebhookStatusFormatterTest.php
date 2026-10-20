<?php

namespace APP\plugins\generic\webhook\Tests;

use APP\plugins\generic\webhook\WebhookStatusFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebhookStatusFormatterTest extends TestCase
{
    #[DataProvider('warningsProvider')]
    public function testWarningCodes(array $input, string $expected): void
    {
        $this->assertSame($expected, WebhookStatusFormatter::warningCodes($input));
    }

    public static function warningsProvider(): array
    {
        return [
            'empty' => [[], ''],
            'single code' => [
                [['code' => 'NO_PAGES', 'message' => 'No pages given']],
                'NO_PAGES',
            ],
            'multiple codes' => [
                [['code' => 'NO_PAGES'], ['code' => 'NO_PERSONS']],
                'NO_PAGES, NO_PERSONS',
            ],
            'entries without a code are dropped' => [
                [['message' => 'no code here'], ['code' => 'AMBIGUOUS_TITLE']],
                'AMBIGUOUS_TITLE',
            ],
            'all entries without a code yields empty string' => [
                [['message' => 'no code here']],
                '',
            ],
        ];
    }
}
