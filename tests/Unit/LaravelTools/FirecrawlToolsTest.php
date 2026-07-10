<?php

declare(strict_types=1);

use Firecrawl\Laravel\Tools\FirecrawlCrawl;
use Firecrawl\Laravel\Tools\FirecrawlMap;
use Firecrawl\Laravel\Tools\FirecrawlScrape;
use Firecrawl\Laravel\Tools\FirecrawlSearch;
use Firecrawl\Laravel\Tools\FirecrawlTools;
use GuzzleHttp\Psr7\Response;
use Laravel\Ai\Tools\Request;

it('returns one instance of each core tool', function (): void {
    $tools = FirecrawlTools::all();

    expect($tools)->toHaveCount(4);
    expect($tools[0])->toBeInstanceOf(FirecrawlScrape::class);
    expect($tools[1])->toBeInstanceOf(FirecrawlSearch::class);
    expect($tools[2])->toBeInstanceOf(FirecrawlMap::class);
    expect($tools[3])->toBeInstanceOf(FirecrawlCrawl::class);
});

it('passes an explicit client through to every tool', function (): void {
    $client = fakeFirecrawlClient([
        new Response(200, [], json_encode(['success' => true, 'data' => ['markdown' => '# Hi']])),
    ]);

    [$scrape] = FirecrawlTools::all($client);

    // Uses the injected client (no container involved) — would throw otherwise.
    expect($scrape->handle(new Request(['url' => 'https://example.com'])))->toBe('# Hi');
});
