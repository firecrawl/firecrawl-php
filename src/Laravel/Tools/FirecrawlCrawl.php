<?php

declare(strict_types=1);

namespace Firecrawl\Laravel\Tools;

use Firecrawl\Exceptions\FirecrawlException;
use Firecrawl\Models\CrawlJob;
use Firecrawl\Models\CrawlOptions;
use Firecrawl\Models\ScrapeOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

class FirecrawlCrawl extends FirecrawlTool
{
    /**
     * One wall-clock deadline covering the start request and all polling.
     * Kept below typical queue worker timeouts (Laravel defaults to 60
     * seconds); override to wait longer.
     */
    protected int $timeoutSeconds = 55;

    protected int $pollIntervalSeconds = 2;

    /** Per-page output ceiling; override to change. */
    protected int $pageCharacterLimit = 15000;

    public function name(): string
    {
        return 'firecrawl_crawl';
    }

    public function description(): string
    {
        return 'Crawl a website with Firecrawl starting from a URL, following its links. Returns a '
            . 'JSON object with the crawl status and a pages array of {url, markdown} objects. This '
            . 'is a slower, multi-page operation. Prefer firecrawl_scrape when you only need one '
            . 'known page, and keep the page limit small. Waits up to about a minute.';
    }

    public function handle(Request $request): string
    {
        return $this->guard(function () use ($request): string {
            $limit = min(max($request->integer('limit') ?: 5, 1), 25);
            $deadline = time() + $this->timeoutSeconds;

            $start = $this->client()->startCrawl(
                (string) $request->string('url'),
                CrawlOptions::with(
                    limit: $limit,
                    scrapeOptions: ScrapeOptions::with(formats: ['markdown']),
                    integration: self::INTEGRATION,
                    idempotencyKey: bin2hex(random_bytes(16)),
                ),
            );

            $jobId = $start->getId();
            if ($jobId === null || $jobId === '') {
                throw new FirecrawlException('Crawl start did not return a job ID.');
            }

            $job = $this->client()->getCrawlStatus($jobId);

            while (!$job->isDone()) {
                if (time() >= $deadline) {
                    return "The crawl did not finish within {$this->timeoutSeconds} seconds. It may still "
                        . 'complete on the server. Use a smaller limit, or scrape key pages individually '
                        . 'with firecrawl_scrape.';
                }

                sleep($this->pollIntervalSeconds);
                $job = $this->client()->getCrawlStatus($jobId);
            }

            return $this->toJson($this->formatJob($job));
        });
    }

    /**
     * The status field keeps failed or cancelled crawls visible even when
     * partial pages came back. Pagination cursors are reported, not
     * followed, so the result stays one bounded response.
     *
     * @return array<string, mixed>
     */
    private function formatJob(CrawlJob $job): array
    {
        $pages = [];
        $used = 0;
        $omitted = 0;

        foreach ($job->getData() as $document) {
            $markdown = $this->truncate($this->documentContent($document), $this->pageCharacterLimit);

            if ($pages !== [] && $used + mb_strlen($markdown) > $this->outputCharacterBudget) {
                $omitted++;
                continue;
            }

            $used += mb_strlen($markdown);
            $pages[] = [
                'url' => $document->getMetadata()['sourceURL']
                    ?? $document->getMetadata()['url']
                    ?? null,
                'markdown' => $markdown,
            ];
        }

        $result = [
            'status' => $job->getStatus(),
            'completed' => $job->getCompleted(),
            'total' => $job->getTotal(),
            'pages' => $pages,
        ];

        if ($omitted > 0) {
            $result['omittedPages'] = $omitted;
        }

        if ($job->getNext() !== null && $job->getNext() !== '') {
            $result['note'] = 'More pages exist on the server than fit in this response.';
        }

        return $result;
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
