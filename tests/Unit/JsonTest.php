<?php

declare(strict_types=1);

use IdempotentImport\Json;

it('encodes with sorted keys and two-space indent', function (): void {
    $out = Json::encode(['b' => 1, 'a' => 2]);
    expect($out)->toBe("{\n  \"a\": 2,\n  \"b\": 1\n}\n");
});

it('preserves list ordering', function (): void {
    $out = Json::decode(Json::encode(['x' => [3, 1, 2]]));
    expect($out['x'])->toBe([3, 1, 2]);
});

it('round-trips nested structures', function (): void {
    $data = ['meta' => ['k' => ['v1', 'v2'], 'a' => [['id' => 5]]]];
    expect(Json::decode(Json::encode($data)))->toEqual($data);
});

it('reads a file', function (): void {
    $dir = tmpdir();
    file_put_contents("{$dir}/x.json", '{"a":1}');
    expect(Json::readFile("{$dir}/x.json"))->toBe(['a' => 1]);
});

it('throws on malformed json', function (): void {
    Json::decode('{not json');
})->throws(JsonException::class);
