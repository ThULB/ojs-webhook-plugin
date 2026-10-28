<?php

namespace APP\plugins\generic\webhook\Tests;

use APP\plugins\generic\webhook\WebhookEventGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebhookEventGuardTest extends TestCase
{
    #[DataProvider('eventProvider')]
    public function testIsStale(string $event, bool $isPublished, ?string $lastRecordedEvent, bool $expected): void
    {
        $this->assertSame($expected, WebhookEventGuard::isStale($event, $isPublished, $lastRecordedEvent));
    }

    public static function eventProvider(): array
    {
        return [
            'production, still in production' => ['production', false, null, false],
            'production, published meanwhile' => ['production', true, 'published', true],
            'published, still published' => ['published', true, 'production', false],
            'published retry after unpublish' => ['published', false, 'unpublished', true],
            'unpublished, still unpublished' => ['unpublished', false, 'published', false],
            'unpublished retry after republish' => ['unpublished', true, 'published', true],
            'updated, published' => ['updated', true, 'published', false],
            'updated, still in production' => ['updated', false, 'production', false],
            'updated, never sent before' => ['updated', false, null, false],
            'updated retry after unpublish' => ['updated', false, 'unpublished', true],
            'unknown event' => ['something', false, null, false],
        ];
    }
}
