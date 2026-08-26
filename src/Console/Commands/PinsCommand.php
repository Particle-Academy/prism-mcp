<?php

declare(strict_types=1);

namespace Prism\Mcp\Console\Commands;

use Illuminate\Console\Command;
use Prism\Mcp\Exceptions\McpException;
use Prism\Mcp\McpManager;
use Prism\Mcp\Support\ToolDefinition;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * `prism-mcp:pins` — read a server's tool definitions and print their digests.
 *
 * This replaces a `tinker` loop the README used to ask people to type out. The
 * loop worked, but pinning is the one defence against a tool being rewritten
 * under you that actually holds, and a defence whose first step is "open a REPL
 * and write a foreach" is one people skip.
 *
 * IT DOES NOT OFFER ANYTHING TO A MODEL. It reads definitions through the raw
 * client, deliberately: you run this BEFORE the tools are trusted, in order to
 * decide what to trust. Requiring a trust declaration first would make the
 * command useless for its only purpose.
 */
#[AsCommand(
    name: 'prism-mcp:pins',
    description: 'Show a configured MCP server\'s tool digests, ready to paste into a trust block'
)]
class PinsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'prism-mcp:pins
                            {server : The server name, as configured under prism-mcp.servers}
                            {--tool=* : Limit output to these tool names}
                            {--json : Emit machine-readable JSON instead of a table}';

    public function handle(McpManager $mcp): int
    {
        $server = (string) $this->argument('server');

        try {
            $definitions = $mcp->server($server)->client()->definitions();
        } catch (McpException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        /** @var list<string> $only */
        $only = (array) $this->option('tool');

        if ($only !== []) {
            $definitions = array_values(array_filter(
                $definitions,
                fn (ToolDefinition $d): bool => in_array($d->name, $only, true),
            ));

            foreach (array_diff($only, array_map(fn (ToolDefinition $d): string => $d->name, $definitions)) as $missing) {
                // Named and not found is worth saying. Silently returning fewer
                // rows than were asked for is how somebody pins three of four
                // tools and believes they pinned all of them.
                $this->components->warn("The server [{$server}] does not publish a tool named [{$this->clean($missing)}].");
            }
        }

        if ($definitions === []) {
            $this->components->warn("The server [{$server}] published no tools.");

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_combine(
                    array_map(fn (ToolDefinition $d): string => $d->name, $definitions),
                    array_map(fn (ToolDefinition $d): string => $d->digest(), $definitions),
                ),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Tool', 'Digest', 'Description'],
            array_map(fn (ToolDefinition $d): array => [
                $this->clean($d->name),
                $d->digest(),
                $this->clean(mb_strimwidth($d->description, 0, 60, '…')),
            ], $definitions),
        );

        $this->components->info('Paste into config/prism-mcp.php under this server:');
        $this->newLine();

        $this->line("        'trust' => [");
        $this->line('            '."'tools' => [".implode(', ', array_map(
            fn (ToolDefinition $d): string => "'".$this->clean($d->name)."'",
            $definitions,
        )).'],');
        $this->line("            'pins' => [");

        foreach ($definitions as $definition) {
            $this->line(sprintf(
                "                '%s' => '%s',",
                $this->clean($definition->name),
                $definition->digest(),
            ));
        }

        $this->line('            ],');
        $this->line('        ],');
        $this->newLine();

        $this->components->warn(
            'Read a description before you pin it. A digest records that a definition has not '
            .'changed; it says nothing about whether it was safe to begin with.'
        );

        return self::SUCCESS;
    }

    /**
     * Strip control characters from server-authored text before it reaches a terminal.
     *
     * Everything printed here except the digest was written by whoever operates
     * that server, and this package's whole premise is that they are not
     * trusted. A terminal renders ANSI escapes, so a description carrying them
     * could repaint this table — moving the cursor, recolouring a line, hiding
     * a row. The output of a command whose entire job is "look at this before
     * you trust it" must not be paintable by the party being inspected.
     */
    protected function clean(string $value): string
    {
        return (string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $value);
    }
}
