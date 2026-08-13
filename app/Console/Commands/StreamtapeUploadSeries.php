<?php

namespace App\Console\Commands;

use App\Models\StreamtapeLink;
use App\Services\MovieBox\MovieBoxClient;
use App\Services\Streamtape\StreamtapeService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Upload every episode of a series to Streamtape, sorted into folders:
 * "{Series}/{S1}/episode1.mp4", "{Series}/{S2}/…", etc.
 *
 * Runs detached from the web request (`exec ... &` from the admin panel) and
 * reports progress by writing one StreamtapeLink row per episode.
 */
class StreamtapeUploadSeries extends Command
{
    protected $signature = 'streamtape:upload-series
        {subjectId : the series subject id}
        {--title= : display title}
        {--rootFolder= : folder name (default: clean title)}
        {--parent=Séries : parent folder (empty = account root)}
        {--quality=0 : preferred resolution, 0 = best}
        {--batch= : batch id}';

    protected $description = 'Upload every episode of a series into Streamtape folders (series/S{season}).';

    public function handle(MovieBoxClient $client, StreamtapeService $streamtape): int
    {
        $subjectId = $this->argument('subjectId');
        $batch = (string) ($this->option('batch') ?: Str::uuid());
        $quality = (int) $this->option('quality');

        $title = (string) $this->option('title');
        $rootFolder = (string) $this->option('rootFolder');
        if ($rootFolder === '') {
            // "From [Version française]" -> "From"
            $rootFolder = trim(preg_replace('/\s*[\[\(].*?[\]\)]\s*/u', ' ', $title ?: $subjectId));
            $rootFolder = trim((string) preg_replace('/\s+/u', ' ', $rootFolder));
        }
        $parent = (string) $this->option('parent');

        $this->info("Batch {$batch}: {$title} → « {$parent}/{$rootFolder} »");

        $episodes = self::detectEpisodes($client, $subjectId);
        $total = collect($episodes)->flatten()->count();
        $this->info('Épisodes trouvés : '.$total);

        if ($episodes === []) {
            $this->error('Aucun épisode trouvé.');

            return self::FAILURE;
        }

        $parentId = $parent !== '' ? $streamtape->resolveFolderId($parent) : '';
        $rootId = $streamtape->resolveFolderId($rootFolder, $parentId);
        $seasonIds = [];
        foreach (array_keys($episodes) as $season) {
            $seasonIds[$season] = $streamtape->resolveFolderId("S{$season}", $rootId);
            $this->info("Dossier « {$parent}/{$rootFolder}/S{$season} » prêt.");
        }

        // Episodes already sent (done row with a final link) are skipped, so a
        // re-run only fills the gaps (e.g. a season added later upstream).
        $alreadyDone = StreamtapeLink::query()
            ->where('subject_id', $subjectId)
            ->whereNotNull('streamtape_url')
            ->get(['season', 'episode'])
            ->map(fn (StreamtapeLink $l) => $l->season.'-'.$l->episode)
            ->all();

        $done = 0;
        $failed = 0;
        $skipped = 0;
        foreach ($episodes as $season => $eps) {
            $folderId = $seasonIds[$season];
            foreach ($eps as $episode) {
                if (in_array("{$season}-{$episode}", $alreadyDone, true)) {
                    $skipped++;
                    $this->info("S{$season}E{$episode} déjà envoyé — ignoré.");

                    continue;
                }

                try {
                    $url = $this->resolveEpisodeUrl($client, $subjectId, $season, $episode, $quality);
                    if ($url === null) {
                        $this->warn("S{$season}E{$episode} : aucune source — ignoré.");
                        $failed++;

                        continue;
                    }

                    $name = $this->fileName($title ?: $subjectId, $season, $episode, $quality);
                    $upload = $streamtape->remoteAdd($url, $name, $this->headers(), $folderId);

                    StreamtapeLink::create([
                        'batch_id' => $batch,
                        'total' => $total,
                        'subject_id' => $subjectId,
                        'subject_type' => 2,
                        'title' => $title ?: $subjectId,
                        'season' => $season,
                        'episode' => $episode,
                        'resolution' => $quality > 0 ? $quality : $this->lastResolution,
                        'folder' => $parent !== '' ? $parent.'/'.$rootFolder.'/S'.$season : $rootFolder.'/S'.$season,
                        'source_url' => $url,
                        'file_id' => $upload['id'],
                        'status' => 'new',
                    ]);
                    $done++;
                    $this->info("S{$season}E{$episode} envoyé (".$this->lastResolution.'p).');
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("S{$season}E{$episode} : {$e->getMessage()}");
                    report($e);
                }

                if (($done + $failed) % 5 === 0) {
                    $this->info("… {$done} envoyés, {$failed} échoués, {$skipped} déjà présents sur {$total}");
                }
            }
        }

        $this->info("=== Batch {$batch} terminé : {$done} envoyés, {$failed} échoués, {$skipped} ignorés ===");

        return self::SUCCESS;
    }

    /** @var int */
    protected int $lastResolution = 0;

    /**
     * Detect every episode of the series, probing the H5 play endpoint season
     * by season (the resource listing often misses episodes / whole seasons).
     *
     * @return array<int,array<int,int>> season => [episodes]
     */
    public static function detectEpisodes(MovieBoxClient $client, string $subjectId): array
    {
        $self = new self;
        $out = [];

        // Seed with the resource listing (cheap, gives the season count).
        $page = 1;
        do {
            $res = $client->resource($subjectId, 1080, $page, 20);
            foreach (is_array($res['list'] ?? null) ? $res['list'] : [] as $it) {
                if (! is_array($it) || empty($it['resourceLink'])) {
                    continue;
                }
                $se = (int) ($it['se'] ?? 0);
                $ep = (int) ($it['ep'] ?? 0);
                if ($se > 0 && $ep > 0) {
                    $out[$se][$ep] = $ep;
                }
            }
            $hasMore = (bool) ($res['pager']['hasMore'] ?? false);
            $page++;
        } while ($hasMore && $page <= 40);

        // Extend each known season (and probe a few more) with h5Play: an
        // episode exists when the play endpoint returns at least one
        // downloadable stream. Probes stop after 4 consecutive empty answers.
        $probeSeasons = $out === [] ? range(1, 5) : array_values(array_unique(array_merge(
            array_keys($out),
            range(1, max(3, max(array_keys($out)) + 1))
        )));
        foreach ($probeSeasons as $se) {
            $misses = 0;
            $found = $out[$se] ?? [];
            for ($ep = 1; $ep <= 40; $ep++) {
                if (isset($out[$se][$ep])) {
                    $misses = 0;
                    continue;
                }
                if ($self->probeEpisode($client, $subjectId, $se, $ep)) {
                    $found[$ep] = $ep;
                    $misses = 0;
                } else {
                    $misses++;
                    if ($misses >= 4) {
                        break;
                    }
                }
            }
            if ($found !== []) {
                $out[$se] = $found;
            }
        }

        ksort($out);
        foreach ($out as &$eps) {
            ksort($eps);
        }

        return $out;
    }

    protected function resolveEpisodeUrl(MovieBoxClient $client, string $subjectId, int $season, int $episode, int $quality): ?string
    {
        $downloads = $this->playDownloads($client, $subjectId, $season, $episode);
        $this->lastResolution = 0;
        $best = null;
        $bestScore = PHP_INT_MAX;
        foreach ($downloads as $d) {
            if (! is_array($d) || empty($d['url'])) {
                continue;
            }
            $res = (int) ($d['resolution'] ?? 0);
            // The CDN refuses Streamtape's remote fetches on /bt/ ticket and
            // /tran-audio/ (audio-only placeholder) URLs: penalise them so a
            // /resource/ source is always preferred when present.
            $score = $quality > 0 ? abs($res - $quality) : -$res;
            $path = (string) parse_url((string) $d['url'], PHP_URL_PATH);
            if (str_contains($path, '/bt/') || str_contains($path, '/tran-audio/')) {
                $score += 100000;
            }
            if ($score < $bestScore) {
                $best = $d;
                $bestScore = $score;
                $this->lastResolution = $res;
            }
        }

        return $best !== null ? (string) $best['url'] : null;
    }

    /** @return array{url:string,resolution:int}[] */
    protected function playDownloads(MovieBoxClient $client, string $subjectId, int $season, int $episode): array
    {
        $downloads = [];

        // Mobile API first: the `resource` endpoint lists the real original
        // MP4s (bcdn /resource/ links), one entry per episode, but only ONE
        // resolution tier per request. A subject may have no file at the
        // top tier (e.g. From S4 VF only exists at 480p except S4E3), so
        // every tier is probed and the matches merged: no 1080p → the tier
        // below. The subject itself IS the language version, so no
        // per-language differentiation is applied.
        try {
            foreach ([1080, 720, 480, 360] as $tier) {
                $found = false;
                for ($page = 1; $page <= 5; $page++) {
                    $res = $client->resource($subjectId, $tier, $page, 20);
                    foreach (is_array($res['list'] ?? null) ? $res['list'] : [] as $item) {
                        if (! is_array($item) || empty($item['resourceLink'])) {
                            continue;
                        }
                        if ((int) ($item['se'] ?? 0) !== $season || (int) ($item['ep'] ?? 0) !== $episode) {
                            continue;
                        }
                        $downloads[] = [
                            'url' => (string) $item['resourceLink'],
                            'resolution' => (int) ($item['resolution'] ?? 0),
                        ];
                        $found = true;
                    }
                    if ($found || ! ($res['pager']['hasMore'] ?? false)) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        // Deduplicate by resolution: the same file may be listed at several
        // tiers; keep the first (mobile /resource/ originals).
        $byRes = [];
        foreach ($downloads as $d) {
            $key = $d['resolution'] ?: $d['url'];
            $byRes[$key] ??= $d;
        }
        $downloads = array_values($byRes);

        if ($downloads !== []) {
            return $downloads;
        }

        try {
            $play = $client->h5Download($subjectId, $season, $episode);
            if ($play['downloads'] === []) {
                $play = $client->h5Play($subjectId, $season, $episode);
            }
        } catch (\Throwable $e) {
            return [];
        }

        foreach (is_array($play['downloads'] ?? null) ? $play['downloads'] : [] as $d) {
            if (! is_array($d) || empty($d['url'])) {
                continue;
            }
            $path = (string) parse_url((string) $d['url'], PHP_URL_PATH);
            if (str_contains($path, '/tran-audio/') || str_contains($path, '/bt/')) {
                continue;
            }
            $downloads[] = [
                'url' => (string) $d['url'],
                'resolution' => (int) ($d['resolution'] ?? 0),
            ];
        }

        return $downloads;
    }

    protected function probeEpisode(MovieBoxClient $client, string $subjectId, int $season, int $episode): bool
    {
        return $this->playDownloads($client, $subjectId, $season, $episode) !== [];
    }

    protected function fileName(string $title, int $season, int $episode, int $quality): string
    {
        return 'Épisode '.$episode.'.mp4';
    }

    /** @return string[] */
    protected function headers(): array
    {
        // Fresh identity per upload (new device_id/gaid) so the CDN cannot
        // fingerprint repeated downloads. Origin/Referer are omitted: the
        // bcdn CDN (mobile API resource links) rejects them with HTTP 429.
        $g = strtolower(bin2hex(random_bytes(16)));

        return [
            'User-Agent: '.(string) config('moviebox.user_agent'),
            'X-Client-Info: '.json_encode([
                'device_id' => strtolower(bin2hex(random_bytes(16))),
                'gaid' => substr($g, 0, 8).'-'.substr($g, 8, 4).'-'.substr($g, 12, 4).'-'.substr($g, 16, 4).'-'.substr($g, 20, 12),
                'timezone' => (string) config('moviebox.timezone', 'Europe/Paris'),
            ]),
            'X-Request-Lang: '.(string) config('moviebox.language', 'fr'),
            'X-Client-Status: 0',
        ];
    }
}
