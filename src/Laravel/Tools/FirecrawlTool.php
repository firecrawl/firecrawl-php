<?php

declare(strict_types=1);

namespace Firecrawl\Laravel\Tools;

use Firecrawl\Client\FirecrawlClient;
use Firecrawl\Exceptions\FirecrawlException;
use Firecrawl\Models\Document;
use Illuminate\Container\Container;
use Laravel\Ai\Contracts\Tool;

abstract class FirecrawlTool implements Tool
{
    // Prefixed with an underscore because the API's integration validator
    // accepts any value starting with `_` on every deployed version, letting
    // these tools work against lagging or self-hosted APIs with no deploy
    // ordering requirement.
    protected const INTEGRATION = '_laravel-ai';

    public function __construct(
        private ?FirecrawlClient $client = null,
    ) {}

    protected function client(): FirecrawlClient
    {
        if ($this->client === null) {
            /** @var FirecrawlClient $resolved */
            $resolved = Container::getInstance()->make(FirecrawlClient::class);
            $this->client = $resolved;
        }

        return $this->client;
    }

    /**
     * Run a Firecrawl call, returning failures as strings so the model can
     * see the error and recover instead of aborting the whole agent run.
     * Non-SDK failures (e.g. container resolution, JSON encoding) are also
     * converted to strings so `handle()` never throws.
     *
     * @param callable(): string $callback
     */
    protected function guard(callable $callback): string
    {
        try {
            return $callback();
        } catch (FirecrawlException $exception) {
            return 'Firecrawl request failed: ' . $exception->getMessage();
        } catch (\Throwable $exception) {
            return 'Tool execution failed: ' . $exception->getMessage();
        }
    }

    /** @param array<int|string, mixed> $data */
    protected function toJson(array $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    protected function documentContent(Document $document): string
    {
        $content = $document->getMarkdown()
            ?? $document->getSummary()
            ?? $document->getHtml()
            ?? '';

        if ($content === '') {
            return 'No content was returned for this page.';
        }

        $warning = $document->getWarning();

        return $warning !== null ? "Warning: {$warning}\n\n{$content}" : $content;
    }
}
