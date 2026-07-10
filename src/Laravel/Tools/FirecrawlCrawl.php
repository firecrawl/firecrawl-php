<?php

declare(strict_types=1);

namespace Firecrawl\Laravel\Tools;

use Firecrawl\Models\CrawlOptions;
use Firecrawl\Models\Document;
use Firecrawl\Models\ScrapeOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class FirecrawlCrawl extends FirecrawlTool
{
    public function name(): string
    {
        return 'firecrawl_crawl';
    }

    public function description(): string
    {
        return 'Crawl a website with Firecrawl starting from a URL, following its links and returning '
            . 'each crawled page as a {url, markdown} object in a JSON array. This is a slower, '
            . 'multi-page operation that waits for the crawl to finish. Prefer firecrawl_scrape when '
            . 'you only need one known page, and keep the page limit small.';
    }

    public function handle(Request $request): string
    {
        return $this->guard(function () use ($request): string {
            $limit = min(max($request->integer('limit') ?: 5, 1), 25);

            $job = $this->client()->crawl(
                (string) $request->string('url'),
                CrawlOptions::with(
                    limit: $limit,
                    scrapeOptions: ScrapeOptions::with(formats: ['markdown']),
                    integration: self::INTEGRATION,
                ),
            );

            $pages = array_map(fn (Document $document): array => [
                'url' => $document->getMetadata()['sourceURL']
                    ?? $document->getMetadata()['url']
                    ?? null,
                'markdown' => $this->documentContent($document),
            ], $job->getData());

            if ($pages === []) {
                return "Crawl finished with status [{$job->getStatus()}] but returned no pages.";
            }

            return $this->toJson($pages);
        });
    }

    /** @return array<string, \Illuminate\JsonSchema\Types\Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()
                ->description('The URL to start crawling from (e.g. https://example.com/docs).')
                ->required(),
            'limit' => $schema->integer()->min(1)->max(25)
                ->description('Maximum number of pages to crawl. Defaults to 5, capped at 25 to keep responses manageable.'),
        ];
    }
}
