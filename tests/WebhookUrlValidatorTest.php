<?php

namespace APP\plugins\generic\webhook\Tests;

use APP\plugins\generic\webhook\WebhookUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebhookUrlValidatorTest extends TestCase
{
    #[DataProvider('validUrlProvider')]
    public function testAcceptsValidHttpUrls(string $url): void
    {
        $this->assertTrue(WebhookUrlValidator::isValid($url));
    }

    public static function validUrlProvider(): array
    {
        return [
            'https' => ['https://example.com/webhook'],
            'http' => ['http://example.com/webhook'],
            'with port' => ['http://example.com:8080/webhook'],
            'localhost' => ['http://localhost:3000/webhook'],
            'loopback IP' => ['http://127.0.0.1/webhook'],
            'uppercase scheme' => ['HTTP://example.com/webhook'],
            'mixed-case scheme' => ['Https://example.com/webhook'],
            'with query string' => ['https://example.com/webhook?token=abc'],
        ];
    }

    #[DataProvider('invalidUrlProvider')]
    public function testRejectsEverythingElse(mixed $url): void
    {
        $this->assertFalse(WebhookUrlValidator::isValid($url));
    }

    public static function invalidUrlProvider(): array
    {
        return [
            'empty string' => [''],
            'null' => [null],
            'not a string' => [123],
            'not a URL at all' => ['not a url'],
            'missing scheme' => ['example.com/webhook'],
            'ftp scheme' => ['ftp://example.com/file'],
            'file scheme' => ['file:///etc/passwd'],
            'javascript scheme' => ['javascript:alert(1)'],
            'dict scheme' => ['dict://example.com/'],
            'mailto scheme' => ['mailto:someone@example.com'],
        ];
    }
}