<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Who this client says it is
    |--------------------------------------------------------------------------
    |
    | Sent as `clientInfo` on every request. The MCP spec is explicit that this
    | is self-reported and that nobody should make a security decision with it —
    | it is here to make a server's logs readable, not to prove anything.
    |
    */

    'client' => [
        'name' => env('PRISM_MCP_CLIENT_NAME', env('APP_NAME', 'prism-mcp')),
        'version' => '0.1.0',
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults every server inherits
    |--------------------------------------------------------------------------
    |
    | A server entry overrides any of these. They live here so an operator sets
    | a timeout once instead of on six servers and forgetting the seventh.
    |
    */

    'defaults' => [

        // Seconds. A slow server must never stall a generation — when this is
        // hit the call fails by name rather than hanging a worker.
        'timeout' => env('PRISM_MCP_TIMEOUT', 30),

        // Seconds to cache a server's tool list. `null` honours the `ttlMs` the
        // server sends (required of it since spec revision 2026-07-28), which is
        // the right answer in almost every case. `0` disables caching, and means
        // paying a network round trip in front of every single generation.
        'cache_ttl' => null,

        // The largest tool result, in bytes, that will be handed to the model.
        // Over this the call is REFUSED, not truncated: a silently cut result
        // reads to a model as a complete one. 256 KB.
        'max_result_bytes' => 262_144,

        // Wrap results in a delimiter that tells the model the text came from
        // outside the application and is data rather than instructions. A
        // mitigation, not a guarantee — see the trust boundary in the README.
        'frame_results' => true,

        // Which gate decides whether an actor may RUN a trusted tool.
        // One of: null (allow), 'deny', 'laravel', 'fms', or a class name.
        'gate' => env('PRISM_MCP_GATE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    |
    | Every server needs a `trust` block. Without one, asking for its tools
    | throws — deliberately, and not as a configuration nag.
    |
    | A tool's name, description, parameter descriptions and enum values are
    | written by whoever runs the server, and they reach the model as
    | INSTRUCTIONS. Declaring trust is the point at which somebody on this side
    | decides that is acceptable. Nothing else in this package makes that
    | decision for you.
    |
    |   'trust' => [
    |       // Either an explicit allowlist…
    |       'tools' => ['search_repositories', 'get_file_contents'],
    |
    |       // …or '*', meaning: whatever this server publishes, today and next
    |       // week, without telling you. Reasonable for a server you operate.
    |       // 'tools' => '*',
    |
    |       // Optional. Pin a tool to a digest you have read, so a server that
    |       // rewrites its description later is refused instead of obeyed.
    |       // Get the digest from: php artisan tinker
    |       //   PrismMcp::server('github')->client()->definitions()
    |       //     ->each(fn ($d) => dump($d->name, $d->digest()));
    |       'pins' => [
    |           // 'search_repositories' => 'sha256:…',
    |       ],
    |   ],
    |
    */

    'servers' => [

        // 'github' => [
        //     'transport' => 'http',
        //     'url' => env('MCP_GITHUB_URL'),
        //     'headers' => [
        //         'Authorization' => 'Bearer '.env('MCP_GITHUB_TOKEN', ''),
        //     ],
        //     'trust' => [
        //         'tools' => ['search_repositories', 'get_file_contents'],
        //     ],
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Gates
    |--------------------------------------------------------------------------
    |
    | Trust decides which tools the model is TOLD about. A gate decides whether
    | a given actor may actually run one. They are different questions and both
    | are worth answering once an application has more than one kind of user.
    |
    */

    'gates' => [

        // Laravel's Gate — authorization: "may this actor do this".
        // One ability for every remote tool, receiving ($server, $tool, $args),
        // so a third party adding a tool can never introduce an ability your
        // application has not defined.
        'laravel' => [
            'ability' => 'use-mcp-tool',
        ],

        // Fancy's Feature Management System — entitlement: "is this subject
        // granted this capability". Good for deciding which servers a tenant
        // may reach; the wrong tool for deciding whether a user may run a
        // destructive action, because a lapsed subscription should not be what
        // stops that. Requires particle-academy/laravel-fms (PHP 8.4, Laravel 13).
        'fms' => [
            'feature' => 'mcp.tools',

            // Ask entitlement per server ("mcp.tools.github") rather than per
            // tool. A tool name is chosen by the third party, so keying on it
            // would let a server name a feature your plan happens to grant.
            'per_server' => true,
        ],
    ],

];
