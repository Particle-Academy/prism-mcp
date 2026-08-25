<?php

declare(strict_types=1);

namespace Prism\Mcp\Facades;

use Illuminate\Support\Facades\Facade;
use Prism\Mcp\McpManager;

/**
 * @method static \Prism\Mcp\Client\PendingConnection server(string $name)
 * @method static \Prism\Mcp\Client\PendingConnection client(string $url, array<string, mixed> $options = [])
 * @method static array<int, \Prism\Prism\Tool> tools()
 * @method static \Prism\Mcp\Contracts\ToolGate gate(?string $name)
 *
 * @see McpManager
 */
class PrismMcp extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return McpManager::class;
    }
}
