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

/*
 * One definition of "redirects off", shared with prism-human-plus and
 * prism-browser: the same rows, the same answers. Null is off; an integer is
 * the most hops to follow, never above five.
 */
it('reads an allow_redirects setting the way the HTTP client does, and never above five hops', function (mixed $setting, ?int $expected): void {
    expect(SameOriginRedirects::hopLimit($setting))->toBe($expected);
})->with([
    'true' => [true, 5],
    'false' => [false, null],
    'null' => [null, null],
    'zero' => [0, null],
    'one' => [1, null],
    'empty string' => ['', null],
    'string zero' => ['0', null],
    'string false' => ['false', null],
    'string true' => ['true', null],
    'object' => [new stdClass, null],
    'empty array' => [[], null],
    'no max' => [['strict' => true], 5],
    'zero max' => [['max' => 0], null],
    'null max' => [['max' => null], null],
    'negative max' => [['max' => -1], null],
    'string zero max' => [['max' => '0'], null],
    'false max' => [['max' => false], null],
    'true max' => [['max' => true], null],
    'text max' => [['max' => 'abc'], null],
    'fractional max' => [['max' => 1.5], null],
    'string negative max' => [['max' => '-1'], null],
    'max one' => [['max' => 1], 1],
    'max three' => [['max' => 3], 3],
    'string max three' => [['max' => '3'], 3],
    'max five' => [['max' => 5], 5],
    'max fifty' => [['max' => 50], 5],
    'string max with a leading space' => [['max' => ' 3'], null],
    'string max with a trailing newline' => [['max' => "3\n"], null],
    'string max past the integer range' => [['max' => '99999999999999999999'], 5],
]);
