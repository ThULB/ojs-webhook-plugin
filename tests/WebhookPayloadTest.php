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

    #[DataProvider('statusProvider')]
    public function testIsRetryableStatus(int $statusCode, bool $expected): void
    {
        $this->assertSame($expected, WebhookPayload::isRetryableStatus($statusCode));
    }

    public static function statusProvider(): array
    {
        return [
            'no status' => [0, true],
            '301 redirect' => [301, false],
            '400 bad request' => [400, false],
            '401 unauthorized' => [401, false],
            '404 not found' => [404, false],
            '408 request timeout' => [408, true],
            '422 unprocessable' => [422, false],
            '425 too early' => [425, true],
            '429 too many requests' => [429, true],
            '500 internal server error' => [500, true],
            '503 service unavailable' => [503, true],
        ];
    }

    #[DataProvider('responseProvider')]
    public function testNormalizeResponse(mixed $input, array $expected): void
    {
        $this->assertSame($expected, WebhookPayload::normalizeResponse($input));
    }

    public static function responseProvider(): array
    {
        $empty = ['doi' => null, 'id' => null, 'url' => null, 'warnings' => []];

        return [
            'invalid JSON (json_decode returns null)' => [null, $empty],
            'JSON string body' => ['accepted', $empty],
            'JSON boolean body' => [true, $empty],
            'JSON number body' => [1, $empty],
            'empty object' => [[], $empty],
            'complete response' => [
                [
                    'doi' => '10.1234/abc',
                    'id' => 'jportal_jparticle_00000001',
                    'url' => 'https://zs.example/receive/jportal_jparticle_00000001',
                    'warnings' => [['code' => 'NO_PAGES', 'detail' => 'No pages given']],
                ],
                [
                    'doi' => '10.1234/abc',
                    'id' => 'jportal_jparticle_00000001',
                    'url' => 'https://zs.example/receive/jportal_jparticle_00000001',
                    'warnings' => [['code' => 'NO_PAGES', 'detail' => 'No pages given']],
                ],
            ],
            'numeric id is cast to string' => [
                ['id' => 42],
                ['doi' => null, 'id' => '42', 'url' => null, 'warnings' => []],
            ],
            'fields of unexpected type become null' => [
                ['doi' => ['10.1234/abc'], 'id' => ['x' => 1], 'url' => false],
                $empty,
            ],
            'empty strings become null' => [
                ['doi' => '', 'id' => '', 'url' => ''],
                $empty,
            ],
            'warnings that are not a list are dropped' => [
                ['warnings' => 'NO_PAGES'],
                $empty,
            ],
            'malformed warning entries are dropped and the rest reindexed' => [
                ['warnings' => ['NO_PAGES', ['detail' => 'no code'], ['code' => ['x']], ['code' => 'NO_ISSUE']]],
                ['doi' => null, 'id' => null, 'url' => null, 'warnings' => [['code' => 'NO_ISSUE', 'detail' => null]]],
            ],
        ];
    }
}
