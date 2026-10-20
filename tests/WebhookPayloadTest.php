<?php

namespace APP\plugins\generic\webhook\Tests;

use APP\plugins\generic\webhook\WebhookPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebhookPayloadTest extends TestCase
{
    #[DataProvider('dateProvider')]
    public function testFormatDate(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, WebhookPayload::formatDate($input));
    }

    public static function dateProvider(): array
    {
        return [
            'null' => [null, null],
            'empty string' => ['', null],
            'date only' => ['2024-05-01', '2024-05-01'],
            'date and time' => ['2024-05-01 10:30:00', '2024-05-01'],
            'ISO 8601 with timezone' => ['2024-05-01T10:30:00+02:00', '2024-05-01'],
            'unparseable string' => ['not a date', null],
        ];
    }

    #[DataProvider('keywordsProvider')]
    public function testReduceKeywords(array $input, array $expected): void
    {
        $this->assertSame($expected, WebhookPayload::reduceKeywords($input));
    }

    public static function keywordsProvider(): array
    {
        return [
            'empty' => [[], []],
            'plain names' => [
                [['name' => 'Foo'], ['name' => 'Bar']],
                ['Foo', 'Bar'],
            ],
            'with source and identifier' => [
                [['name' => 'Foo', 'source' => 'gnd', 'identifier' => '123']],
                ['Foo'],
            ],
            'entries without a name are dropped and the result reindexed' => [
                [['source' => 'gnd'], ['name' => 'Bar']],
                ['Bar'],
            ],
        ];
    }

    public function testSignatureValueIsNullWithoutSecret(): void
    {
        $this->assertNull(WebhookPayload::signatureValue('{"a":1}', null));
    }

    public function testSignatureValueIsNullWithEmptySecret(): void
    {
        $this->assertNull(WebhookPayload::signatureValue('{"a":1}', ''));
    }

    public function testSignatureValueMatchesHmacSha256(): void
    {
        $body = '{"event":"published"}';
        $secret = 'super-secret';

        $this->assertSame(
            hash_hmac('sha256', $body, $secret),
            WebhookPayload::signatureValue($body, $secret)
        );
    }

    public function testSignatureValueDiffersForDifferentBodies(): void
    {
        $secret = 'super-secret';

        $this->assertNotSame(
            WebhookPayload::signatureValue('{"event":"published"}', $secret),
            WebhookPayload::signatureValue('{"event":"unpublished"}', $secret)
        );
    }

    public function testSignatureValueDiffersForDifferentSecrets(): void
    {
        $body = '{"event":"published"}';

        $this->assertNotSame(
            WebhookPayload::signatureValue($body, 'secret-one'),
            WebhookPayload::signatureValue($body, 'secret-two')
        );
    }
}
