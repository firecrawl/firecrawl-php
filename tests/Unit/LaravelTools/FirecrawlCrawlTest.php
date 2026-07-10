<?php

declare(strict_types=1);

use Firecrawl\Laravel\Tools\FirecrawlCrawl;
use GuzzleHttp\Psr7\Response;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\Request;

it('crawls a site and returns pages as JSON', function (): void {
    $history = new ArrayObject();
    $client = fakeFirecrawlClient([
        // POST /v2/crawl: start the job
        new Response(200, [], json_encode(['success' => true, 'id' => 'job-1'])),
        // GET /v2/crawl/job-1: first poll, already completed
        new Response(200, [], json_encode([
            'success' => true,
            'status' => 'completed',
            'total' => 1,
            'completed' => 1,
            'data' => [
                ['markdown' => '# Page 1', 'metadata' => ['sourceURL' => 'https://example.com/p1']],
            ],
        ])),
    ], $history);

    $result = (new FirecrawlCrawl($client))->handle(new Request(['url' => 'https://example.com']));

    expect(json_decode($result, true))->toBe([
        ['url' => 'https://example.com/p1', 'markdown' => '# Page 1'],
    ]);

    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body['url'])->toBe('https://example.com');
    expect($body['limit'])->toBe(5);
    expect($body['scrapeOptions']['formats'])->toBe(['markdown']);
    expect($body['integration'])->toBe('laravel-ai');
});

it('clamps the page limit between 1 and 25', function (): void {
    $history = new ArrayObject();
    $client = fakeFirecrawlClient([
        new Response(200, [], json_encode(['success' => true, 'id' => 'job-1'])),
        new Response(200, [], json_encode([
            'success' => true, 'status' => 'completed', 'total' => 0, 'completed' => 0, 'data' => [],
        ])),
    ], $history);

    (new FirecrawlCrawl($client))->handle(new Request(['url' => 'https://example.com', 'limit' => 500]));

    $body = json_decode((string) $history[0]['request']->getBody(), true);
    expect($body['limit'])->toBe(25);
});

it('reports crawls that finish without pages', function (): void {
    $client = fakeFirecrawlClient([
        new Response(200, [], json_encode(['success' => true, 'id' => 'job-1'])),
        new Response(200, [], json_encode([
            'success' => true, 'status' => 'failed', 'total' => 0, 'completed' => 0, 'data' => [],
        ])),
    ]);

    $result = (new FirecrawlCrawl($client))->handle(new Request(['url' => 'https://example.com']));

    expect($result)->toBe('Crawl finished with status [failed] but returned no pages.');
});

it('returns API failures as readable strings instead of throwing', function (): void {
    $client = fakeFirecrawlClient([
        new Response(400, [], json_encode(['error' => 'Invalid URL'])),
    ]);

    $result = (new FirecrawlCrawl($client))->handle(new Request(['url' => 'not-a-url']));

    expect($result)->toStartWith('Firecrawl request failed:');
});

it('exposes name and a schema with required url and optional limit', function (): void {
    $tool = new FirecrawlCrawl(fakeFirecrawlClient([]));

    expect($tool->name())->toBe('firecrawl_crawl');

    $types = $tool->schema(new JsonSchemaTypeFactory());
    expect($types)->toHaveKeys(['url', 'limit']);

    $jsonSchema = (new ObjectSchema($types))->toSchema();
    expect($jsonSchema['required'])->toContain('url');
    expect($jsonSchema['required'])->not->toContain('limit');
});
