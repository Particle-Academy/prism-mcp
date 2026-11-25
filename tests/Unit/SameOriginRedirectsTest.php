<?php

declare(strict_types=1);

use Prism\Mcp\Transport\SameOriginRedirects;

/*
 * A URL with and without its scheme's default port is the same origin. The
 * loopback redirect tests cannot bind 80 or 443, so this equivalence is pinned
 * here instead.
 */

it('treats a URL with and without the default port as the same origin', function (string $bare, string $explicit): void {
    expect(SameOriginRedirects::origin($bare))
        ->not->toBeNull()
        ->toBe(SameOriginRedirects::origin($explicit));
})->with([
    'http' => ['http://mcp.example/mcp', 'http://mcp.example:80/other'],
    'https' => ['https://mcp.example/mcp', 'https://mcp.example:443/other'],
    'mixed case' => ['HTTPS://MCP.Example/mcp', 'https://mcp.example:443/'],
]);

it('treats the other scheme default port as a different origin', function (string $a, string $b): void {
    expect(SameOriginRedirects::origin($a))->not->toBe(SameOriginRedirects::origin($b));
})->with([
    'https on 80' => ['https://mcp.example/mcp', 'https://mcp.example:80/mcp'],
    'http on 443' => ['http://mcp.example/mcp', 'http://mcp.example:443/mcp'],
    'http vs https' => ['http://mcp.example/mcp', 'https://mcp.example/mcp'],
]);
