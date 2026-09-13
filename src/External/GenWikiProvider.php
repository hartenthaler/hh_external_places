<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\ExternalPlacesModule\External;

use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Domain\ExternalIdentifier;
use Hartenthaler\Webtrees\Module\ExternalPlacesModule\Http\HttpTransport;
use Throwable;

/** Identifier adapter for numeric GenWiki MediaWiki page IDs. */
final class GenWikiProvider implements ExternalProvider
{
    public const AUTHORITY_URI = 'https://wiki.genealogy.net/?curid=';
    private const API = 'https://wiki.genealogy.net/api.php';

    public function __construct(private readonly ?HttpTransport $http = null, private readonly ExternalProviderCache $cache = new ExternalProviderCache()) {}
    public function key(): string { return 'genwiki'; }
    public function label(): string { return 'GenWiki'; }
    public function authorityUri(): string { return self::AUTHORITY_URI; }
    public function identifier(string $value): ?ExternalIdentifier
    {
        $value = trim($value);
        if (str_starts_with($value, self::AUTHORITY_URI)) { $value = substr($value, strlen(self::AUTHORITY_URI)); }
        if (preg_match('/^[1-9][0-9]{0,11}$/', $value) !== 1) { return null; }
        return new ExternalIdentifier($this->key(), $value, self::AUTHORITY_URI, self::AUTHORITY_URI . rawurlencode($value));
    }
    public function fetch(ExternalIdentifier $identifier, string $language): ?ExternalInformation
    {
        $key = 'page|' . $identifier->value . '|' . strtolower($language);
        $payload = $this->cache->read($this->key(), $key);
        if ($payload === null) {
            // GenWiki does not expose MediaWiki's TextExtracts extension.
            // Request the first revision and derive a short plain-text
            // introduction locally instead.
            $payload = $this->request(['action' => 'query', 'pageids' => $identifier->value, 'prop' => 'revisions|info', 'rvprop' => 'content', 'rvslots' => 'main', 'rvlimit' => '1', 'redirects' => '1', 'format' => 'json', 'formatversion' => '2']);
            if ($payload === null) { return null; }
            $this->cache->write($this->key(), $key, $payload);
        }
        $page = $payload['query']['pages'][0] ?? null;
        if (!is_array($page) || array_key_exists('missing', $page)) { return null; }
        $title = is_string($page['title'] ?? null) ? trim($page['title']) : null;
        $wikitext = $page['revisions'][0]['slots']['main']['content'] ?? null;
        $extract = is_string($wikitext) ? $this->intro($wikitext) : null;
        return new ExternalInformation($this->key(), $identifier->value, $identifier->url, $title, $extract !== '' ? $extract : null, null, [], [], [], [], [], [], [], null);
    }

    /** @return list<array{id:string,label:string,url:string,description:?string}> */
    public function search(string $term, string $language = 'en'): array
    {
        $term = trim($term);
        if ($term === '' || mb_strlen($term) > 240) { return []; }
        $key = 'search|' . strtolower($language) . '|' . $term;
        $payload = $this->cache->read($this->key(), $key);
        if ($payload === null) {
            $payload = $this->request(['action' => 'query', 'list' => 'search', 'srsearch' => $term, 'srnamespace' => '0', 'srlimit' => '20', 'format' => 'json', 'formatversion' => '2']);
            if ($payload === null) { return []; }
            $this->cache->write($this->key(), $key, $payload);
        }
        $results = [];
        foreach (($payload['query']['search'] ?? []) as $row) {
            if (!is_array($row) || !is_scalar($row['pageid'] ?? null) || !is_string($row['title'] ?? null)) { continue; }
            $identifier = $this->identifier((string) $row['pageid']);
            if ($identifier === null) { continue; }
            $results[] = ['id' => $identifier->value, 'label' => trim(strip_tags($row['title'])), 'url' => $identifier->url, 'description' => null];
        }
        return $results;
    }

    /** @param array<string,string> $query @return array<string,mixed>|null */
    private function request(array $query): ?array
    {
        try {
            $response = ($this->http ?? HttpTransport::default())->request('GET', self::API, $query, ['Accept' => 'application/json', 'User-Agent' => 'webtrees External Places/0.4 (+https://github.com/hartenthaler/hh_external_places)'], 10.0);
            if ($response === null || $response->getStatusCode() !== 200) { return null; }
            $body = $response->getBody()->getContents();
            if (strlen($body) > 500_000) { return null; }
            $data = json_decode($body, true, 20, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (Throwable) { return null; }
    }

    private function intro(string $wikitext): string
    {
        $source = preg_replace('/\{\{#vardefine:[^|}]+\|[^}]+\}\}/u', '', $wikitext) ?? $wikitext;
        $source = preg_replace('/\{\{Begriffsklärung[^}]*\}\}/iu', '', $source) ?? $source;
        $source = preg_replace('/<!--.*?-->/s', '', $source) ?? $source;
        $paragraph = preg_split('/\R\s*\R/u', trim($source), 2)[0] ?? '';
        // Stop before embedded wiki tables or image gallery markup; these
        // are not part of the article's introductory prose.
        $paragraph = preg_split('/\{\||\bthumb\|/iu', $paragraph, 2)[0] ?? $paragraph;
        // Remove closed comments first; only an unterminated comment marker
        // may indicate that the remainder is non-prose template markup.
        $paragraph = preg_replace('/<!--.*?-->/s', '', $paragraph) ?? $paragraph;
        if (str_contains($paragraph, '<!--')) {
            $beforeComment = explode('<!--', $paragraph, 2)[0];
            if (trim($beforeComment) !== '') { $paragraph = $beforeComment; }
        }
        $paragraph = str_replace('-->', '', $paragraph);
        $variables = [];
        if (preg_match_all('/\{\{#vardefine:([^|}]+)\|([^}]+)\}\}/u', $wikitext, $definitions, PREG_SET_ORDER) > 0) {
            foreach ($definitions as $definition) { $variables[trim($definition[1])] = trim($definition[2]); }
        }
        foreach ($variables as $name => $value) { $paragraph = str_replace('{{#var:' . $name . '}}', $value, $paragraph); }
        $paragraph = preg_replace('/\[\[(?:File|Bild|Image):.*?\]\]/isu', '', $paragraph) ?? $paragraph;
        $paragraph = preg_replace('/\{\|.*?\|\}/su', '', $paragraph) ?? $paragraph;
        $paragraph = preg_replace('/\{\{.*?\}\}/s', '', $paragraph) ?? $paragraph;
        $paragraph = preg_replace_callback('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/u', static fn (array $match): string => trim($match[2] ?? '') !== '' ? $match[2] : $match[1], $paragraph) ?? $paragraph;
        $paragraph = preg_replace('/\[https?:\/\/[^\s\]]+\s+([^\]]+)\]/u', '$1', $paragraph) ?? $paragraph;
        $paragraph = preg_replace('/<ref[^>]*>.*?<\/ref>|<[^>]+>/isu', '', $paragraph) ?? $paragraph;
        $paragraph = preg_replace('/^=+.*?=+\s*$/mu', '', $paragraph) ?? $paragraph;
        $paragraph = str_replace(["'''", "''"], '', $paragraph);
        return trim(preg_replace('/\s+/u', ' ', $paragraph) ?? $paragraph);
    }
}
