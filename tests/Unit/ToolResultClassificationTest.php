<?php

declare(strict_types=1);

use Prism\Mcp\Client\ToolResult;

it('G230 treats every present non-false error flag as failure', function (mixed $flag): void {
    $result = ToolResult::from('owned', ['content' => [['type' => 'text', 'text' => 'owned']], 'isError' => $flag]);
    expect($result->isError)->toBeTrue();
})->with([
    'null' => [null],
    'true' => [true],
    'zero' => [0],
    'one' => [1],
    'empty string' => [''],
    'false string' => ['false'],
    'true string' => ['true'],
    'empty list' => [[]],
    'empty object' => [(object) []],
]);

it('G230 keeps absent and literal false successful', function (): void {
    expect(ToolResult::from('owned', ['content' => []])->isError)->toBeFalse()
        ->and(ToolResult::from('owned', ['content' => [], 'isError' => false])->isError)->toBeFalse();
});
