<?php

declare(strict_types=1);

namespace Tests\Unit\Business\Services;

use App\Business\Presentation\VibeValue;
use App\Business\Services\VibeContextPolicy;
use PHPUnit\Framework\TestCase;

final class VibeContextPolicyTest extends TestCase
{
    public function testItRecursivelyEscapesUntrustedTextAndAttributes(): void
    {
        $protected = (new VibeContextPolicy())->protect([
            'label' => '<Admin>',
            'attribute' => 'value" onfocus="alert(1)',
        ]);

        $this->assertSame('&lt;Admin&gt;', $protected['label']);
        $this->assertSame('value&quot; onfocus=&quot;alert(1)', $protected['attribute']);
    }

    /** @dataProvider allowedUrlProvider */
    public function testItAllowsExplicitSafeUrlValues(string $url, string $expected): void
    {
        $protected = (new VibeContextPolicy())->protect(VibeValue::url($url));

        $this->assertSame($expected, $protected);
    }

    /** @return array<string, array{string, string}> */
    public static function allowedUrlProvider(): array
    {
        return [
            'relative' => ['/admin/users?a=1&b=2', '/admin/users?a=1&amp;b=2'],
            'fragment' => ['#features', '#features'],
            'https' => ['https://example.com/path', 'https://example.com/path'],
            'mail' => ['mailto:team@example.com', 'mailto:team@example.com'],
            'phone' => ['tel:+15551234567', 'tel:+15551234567'],
        ];
    }

    /** @dataProvider rejectedUrlProvider */
    public function testItRejectsUnsafeUrlValues(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new VibeContextPolicy())->protect(VibeValue::url($url));
    }

    /** @return array<string, array{string}> */
    public static function rejectedUrlProvider(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'mixed case javascript' => ['JaVaScRiPt:alert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'protocol relative' => ['//example.com/path'],
            'control' => ["https://example.com/\nscript"],
        ];
    }

    public function testItEncodesScriptValuesAsNonExecutableJsonLiterals(): void
    {
        $protected = (new VibeContextPolicy())->protect(
            VibeValue::script("'</script><script>alert(1)</script>")
        );

        $this->assertStringStartsWith('"', $protected);
        $this->assertStringEndsWith('"', $protected);
        $this->assertStringContainsString('\\u0027', $protected);
        $this->assertStringContainsString('\\u003C\\/script\\u003E', $protected);
        $this->assertStringNotContainsString('</script>', $protected);
    }

    public function testItOnlyPassesExplicitStringMarkupWithoutEncoding(): void
    {
        $markup = '<nav aria-label="Primary">Safe composition</nav>';

        $this->assertSame(
            $markup,
            (new VibeContextPolicy())->protect(VibeValue::trustedMarkup($markup))
        );
    }
}
