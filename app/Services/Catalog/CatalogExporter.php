<?php

namespace App\Services\Catalog;

use App\Services\MovieBox\MovieBoxClient;
use Throwable;

/**
 * Produces a plain-text export of catalog titles with their downloadable
 * (resource) links — one block per title, one line per file/episode.
 *
 * Used by the `catalog:export-links` command (full export to a file) and the
 * admin export endpoint (bounded, streamed download).
 */
class CatalogExporter
{
    public function __construct(protected MovieBoxClient $client) {}

    /**
     * Yield the export text line-by-line for a list of items.
     *
     * @param  iterable<array{subjectId:string,title:string,subjectType?:int}>  $items
     * @return \Generator<string>
     */
    public function lines(iterable $items): \Generator
    {
        foreach ($items as $item) {
            $subjectId = (string) ($item['subjectId'] ?? '');
            if ($subjectId === '') {
                continue;
            }
            $title = trim((string) ($item['title'] ?? 'Untitled'));

            yield "### {$title}  [{$subjectId}]";

            $files = $this->files($subjectId);
            if ($files === []) {
                yield '    (aucun lien de téléchargement disponible)';
            } else {
                foreach ($files as $f) {
                    $label = $f['se'] > 0
                        ? sprintf('S%02dE%02d', $f['se'], $f['ep'])
                        : 'Film';
                    $res = $f['resolution'] > 0 ? $f['resolution'].'p' : 'auto';
                    yield "    {$label} | {$res} | {$f['url']}";
                }
            }

            yield '';
        }
    }

    /**
     * All downloadable files for a subject, de-duplicated and ordered by
     * season / episode / resolution.
     *
     * @return array<int,array{se:int,ep:int,resolution:int,url:string}>
     */
    public function files(string $subjectId): array
    {
        $out = [];
        $seen = [];
        $page = 1;
        $maxPages = 60;

        do {
            try {
                $res = $this->client->resource($subjectId, 1080, $page, 20);
            } catch (Throwable $e) {
                report($e);
                break;
            }

            $list = is_array($res['list'] ?? null) ? $res['list'] : [];
            foreach ($list as $it) {
                if (! is_array($it) || empty($it['resourceLink'])) {
                    continue;
                }
                $se = (int) ($it['se'] ?? 0);
                $ep = (int) ($it['ep'] ?? 0);
                $resolution = (int) ($it['resolution'] ?? 0);
                $key = "$se-$ep-$resolution";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = ['se' => $se, 'ep' => $ep, 'resolution' => $resolution, 'url' => (string) $it['resourceLink']];
            }

            $hasMore = (bool) ($res['pager']['hasMore'] ?? false);
            $page++;
        } while ($hasMore && $page <= $maxPages);

        usort($out, fn ($a, $b) => [$a['se'], $a['ep'], -$a['resolution']] <=> [$b['se'], $b['ep'], -$b['resolution']]);

        return $out;
    }
}
