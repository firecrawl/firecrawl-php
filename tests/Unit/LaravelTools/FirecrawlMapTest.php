<?php

declare(strict_types=1);

use Firecrawl\Laravel\Tools\FirecrawlMap;
use GuzzleHttp\Psr7\Response;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\Request;

it('maps a site and returns discovered URLs as JSON', function (): void {
    $history = new ArrayObject();
    $client = fakeFirecrawlClient([
        new Response(200, [], json_encode([
            'success' => true,
            'data' => [
                'links' => [
                    ['url' => 'https://example.com/', 'title' => 'Home'],
                    'https://example.com/pricing',
                ],
            ],
        ])),
    ], $history);

    $result = (new FirecrawlMap($client))->handle(new Request(['url' => 'https://example.com']));

    expect(json_decode($result, true))->toBe([
        ['url' => 'https://example.com/', 'title' => 'Home'],
        ['url' => 'https://example.com/pricing'],
    ]);

    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body['url'])->toBe('https://example.com');
    expect($body['limit'])->toBe(100);
    expect($body['integration'])->toBe('_laravel-ai');
    expect($body)->not->toHaveKey('search');
});

it('passes the search filter through when provided', function (): void {
    $history = new ArrayObject();
    $client = fakeFirecrawlClient([
        new Response(200, [], json_encode(['success' => true, 'data' => ['links' => []]])),
    ], $history);

    (new FirecrawlMap($client))->handle(new Request(['url' => 'https://example.com', 'search' => 'docs']));

    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body['search'])->toBe('docs');
});

it('clamps an explicit limit of 5000 down to 500', function (): void {
    $history = new ArrayObject();
    $client = fakeFirecrawlClient([
        new Response(200, [], json_encode(['success' => true, 'data' => ['links' => []]])),
    ], $history);

    (new FirecrawlMap($client))->handle(new Request(['url' => 'https://example.com', 'limit' => 5000]));

    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body['limit'])->toBe(500);
});

it('reports when no URLs were discovered', function (): void {
    $client = fakeFirecrawlClient([
        new Response(200, [], json_encode(['success' => true, 'data' => ['links' => []]])),
    ]);

    $result = (new FirecrawlMap($client))->handle(new Request(['url' => 'https://example.com']));

    expect($result)->toBe('No URLs discovered.');
});

it('surfaces a success:false envelope as an error instead of empty results', function (): void {
    $client = fakeFirecrawlClient([
        new Response(200, [], json_encode([
            'success' => false,
            'error' => 'DNS resolution failed for host: nosuchdomain.example',
        ])),
    ]);

    $result = (new FirecrawlMap($client))->handle(new Request(['url' => 'https://nosuchdomain.example']));

    expect($result)->toStartWith('Firecrawl request failed: DNS resolution failed');
});

it('exposes name and a schema with required url', function (): void {
    $tool = new FirecrawlMap(fakeFirecrawlClient([]));

    expect($tool->name())->toBe('firecrawl_map');

    $types = $tool->schema(new JsonSchemaTypeFactory());
    expect($types)->toHaveKeys(['url', 'search', 'limit']);

    $jsonSchema = (new ObjectSchema($types))->toSchema();
    expect($jsonSchema['required'])->toContain('url');
});
