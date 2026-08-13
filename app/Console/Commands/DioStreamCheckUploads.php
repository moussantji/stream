<?php

namespace App\Console\Commands;

use App\Models\StreamtapeLink;
use App\Services\DioStream\DioStreamClient;
use App\Services\Streamtape\StreamtapeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Verifies the DioStream → Streamtape batch uploads and repairs the broken
 * ones.
 *
 * DioStream stream URLs are short-lived session tokens: when Streamtape's
 * own fetcher starts late (or the CDN drops the connection mid-transfer),
 * the uploaded file comes out truncated (typically < 20 MB) or disappears
 * entirely — Streamtape still reports the remote job, so nothing flags it.
 *
 * This command polls the pending rows and, for every file that is missing or
 * smaller than --min-bytes, re-downloads the MP4 locally with a FRESH token
 * (curl with resume + retries) and re-uploads the complete file with
 * uploadLocal. Streamtape still does its own conversion afterwards.
 */
class DioStreamCheckUploads extends Command
{
    protected $signature = 'diostream:check-uploads
        {--batch= : only rows of this batch id}
        {--max=50 : max rows to process in one run}
        {--grace-min=15 : minutes after send before judging a still-pending row}
        {--min-bytes=20971520 : files below this size are considered truncated}
        {--download-retries=3 : local download attempts (fresh token each time)}
        {--watch : keep polling (single pass otherwise)}';

    protected $description = 'Vérifie les uploads DioStream→Streamtape et re-télécharge les fichiers coupés.';

    public function handle(DioStreamClient $dio, StreamtapeService $streamtape): int
    {
        if (! $streamtape->isConfigured()) {
            $this->error('Streamtape non configuré (STREAMTAPE_LOGIN / STREAMTAPE_KEY).');

            return self::FAILURE;
        }

        $minBytes = max(0, (int) $this->option('min-bytes'));
        $max = max(1, (int) $this->option('max'));

        do {
            $processed = 0;
            foreach ($this->pendingRows($max) as $row) {
                $processed++;
                $this->checkRow($row, $dio, $streamtape, $minBytes);
            }

            if ($processed === 0) {
                $this->info('Aucune ligne en attente.');

                return self::SUCCESS;
            }
            $this->info("Pass terminé : {$processed} lignes traitées.");
            if (! (bool) $this->option('watch')) {
                break;
            }
            sleep(20);
        } while (true);

        return self::SUCCESS;
    }

    protected function checkRow(
        StreamtapeLink $row,
        DioStreamClient $dio,
        StreamtapeService $streamtape,
        int $minBytes
    ): void {
        $label = $row->title.' ['.$row->language.']';

        // Not sent yet (no file id) — nothing to verify.
        if ($row->file_id === null || $row->file_id === '') {
            return;
        }

        $status = null;
        try {
            $status = $streamtape->remoteStatus($row->file_id);
        } catch (Throwable $e) {
            $this->warn("  {$label} : erreur API {$e->getMessage()}");

            return;
        }

        $state = $status['status'];
        $finishedAt = $row->finished_at ?? $row->updated_at;
        $elapsed = $finishedAt === null ? 0 : (int) now()->diffInMinutes($finishedAt);

        if (in_array($state, ['downloading', 'waiting', 'new', 'queued', ''], true)) {
            // Give Streamtape time; only judge rows that have been stuck for
            // the whole grace period.
            if ($elapsed < (int) $this->option('grace-min')) {
                return;
            }
            // Stuck in "downloading" for a long time: the CDN probably
            // stopped feeding data — re-upload.
            $this->repair($row, $dio, $streamtape, $minBytes, 'Téléchargement Streamtape bloqué');

            return;
        }

        if ($state === 'failed' || $state === 'error' || $state === 'missing') {
            $this->repair($row, $dio, $streamtape, $minBytes, 'Échec du téléchargement Streamtape');

            return;
        }

        // "finished": Streamtape got something. Check it is real and big
        // enough (its conversion happens later; a truncated file never
        // converts into a playable result).
        $linkId = '';
        if (preg_match('#/v/([A-Za-z0-9]+)#', (string) ($status['streamtape_url'] ?? ''), $m)) {
            $linkId = $m[1];
        } else {
            $this->repair($row, $dio, $streamtape, $minBytes, 'Aucun lien Streamtape à la fin du transfert');

            return;
        }

        $size = $this->streamtapeSize($streamtape, $linkId, $row);
        if ($size !== null && $size >= $minBytes) {
            $row->status = 'done';
            $row->streamtape_url = $status['streamtape_url'];
            $row->bytes_loaded = $status['bytes_loaded'];
            $row->bytes_total = $status['bytes_total'];
            $row->error = null;
            $row->save();
            $this->info("  {$label} OK (".round($size / 1048576, 1)." MB).");

            return;
        }

        if ($size !== null) {
            $this->repair($row, $dio, $streamtape, $minBytes, sprintf('Fichier tronqué : %.1f MB', $size / 1048576));
        } else {
            $this->repair($row, $dio, $streamtape, $minBytes, 'Fichier introuvable chez Streamtape');
        }
    }

    /**
     * Re-send the title to Streamtape with a fresh token (direct remote
     * upload, no local download). The row is reused; on repeated failure it
     * is marked failed.
     */
    protected function repair(
        StreamtapeLink $row,
        DioStreamClient $dio,
        StreamtapeService $streamtape,
        int $minBytes,
        string $reason
    ): void {
        $attempts = (int) $row->convert_attempts;
        if ($attempts >= (int) $this->option('download-retries')) {
            $row->status = 'failed';
            $row->error = "{$reason} (après {$attempts} relances).";
            $row->save();
            $this->error("  {$row->title} [{$row->language}] : {$row->error}");

            return;
        }

        $this->warn("  {$row->title} [{$row->language}] : {$reason} → re-envoi direct ({$attempts}/".$this->option('download-retries').').');

        try {
            $source = $this->resolveFreshUrl($row, $dio);

            $folderId = '';
            if (is_string($row->folder) && $row->folder !== '') {
                try {
                    $folderId = $streamtape->resolveFolderPath($row->folder)['id'];
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $name = $this->fileName($row);
            $headers = [
                'User-Agent: '.(string) config('diostream.user_agent'),
                'Accept: */*',
                'Referer: '.(string) config('diostream.referer'),
                'Origin: '.rtrim((string) config('diostream.referer'), '/'),
            ];
            $upload = $streamtape->remoteAdd($source['url'], $name, $headers, $folderId ?: null);

            $row->file_id = $upload['id'];
            $row->source_url = $source['url'];
            $row->status = 'new';
            $row->streamtape_url = null;
            $row->error = null;
            $row->bytes_loaded = null;
            $row->bytes_total = null;
            $row->finished_at = null;
            $row->convert_attempts = $attempts + 1;
            $row->save();

            $this->info("  {$row->title} [{$row->language}] : renvoyé directement (token frais).");
        } catch (Throwable $e) {
            report($e);
            $this->error("  {$row->title} [{$row->language}] : re-envoi impossible ({$e->getMessage()}).");
        }
    }

    /** Fresh MP4 (or HLS-as-fallback) URL for the row's subject, in the row's language. */
    protected function resolveFreshUrl(StreamtapeLink $row, DioStreamClient $dio): array
    {
        $subject = (string) $row->subject_id;
        $lang = (string) $row->language;

        if ((int) $row->subject_type === 7 && str_starts_with($subject, 'anime:')) {
            $parts = explode(':', $subject);
            $ani = $parts[1] ?? '';
            $episode = (int) ($parts[2] ?? 0);
            $mode = $parts[3] ?? 'vostfr';
            $sources = $mode === 'vf' ? ['erebus', 'heracles'] : ['rhea', 'metis', 'hyperion', 'asteria', 'tethys', 'iapetus', 'calypso', 'aether'];

            foreach ($sources as $sourceKey) {
                $payload = $dio->animeStreamFrom($ani, null, $episode, $sourceKey);
                $chosen = $this->pickSource($payload, $mode === 'vf' ? 'french' : 'japanese');
                if ($chosen !== null) {
                    return $chosen;
                }
            }

            throw new \RuntimeException('aucune source anime pour '.$ani.' E'.$episode);
        }

        $isFr = in_array(strtolower($lang), ['fr', 'french', 'vf'], true);
        $sources = $isFr ? ['hephaestus', 'styx', 'theia', 'cronus', 'coeus'] : ['helios', 'leto', 'moviesapi', 'apollo', 'crius', 'poseidon', 'theia'];
        $season = (int) $row->season;
        $episode = (int) $row->episode;

        foreach ($sources as $sourceKey) {
            $payload = $season > 0 && $episode > 0
                ? $dio->tvStreamFrom($subject, $season, $episode, $sourceKey)
                : $dio->movieStreamFrom($subject, $sourceKey);
            $chosen = $this->pickSource($payload, $isFr ? 'french' : 'english');
            if ($chosen !== null) {
                return $chosen;
            }
        }

        throw new \RuntimeException('aucune source pour le titre '.$subject);
    }

    /**
     * Best source matching the wanted language: MP4 preferred (remote-able),
     * HLS accepted as fallback (converted locally with ffmpeg).
     *
     * @param  array<string,mixed>  $payload
     * @return array{url:string,type:string,resolution:int}|null
     */
    protected function pickSource(array $payload, string $wantLang): ?array
    {
        $best = null;
        $bestScore = PHP_INT_MAX;

        foreach (($payload['providers'] ?? []) as $provider) {
            foreach (($provider['sources'] ?? []) as $s) {
                if (! is_array($s) || empty($s['url'])) {
                    continue;
                }
                $lang = strtolower((string) ($s['language'] ?? ''));
                if (! str_contains($lang, $wantLang)) {
                    continue;
                }
                $type = strtolower((string) ($s['type'] ?? ''));
                if (! in_array($type, ['mp4', 'hls'], true)) {
                    continue;
                }
                $resolution = 0;
                if (preg_match('/(\d{3,4})/', (string) ($s['quality'] ?? ''), $m)) {
                    $resolution = (int) $m[1];
                }
                $score = ($type === 'mp4' ? 0 : 1000000) - $resolution;
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $best = ['url' => (string) $s['url'], 'type' => $type, 'resolution' => $resolution];
                }
            }
        }

        return $best;
    }

    /** File size on Streamtape by link id, or null when not found. */
    protected function streamtapeSize(StreamtapeService $streamtape, string $linkId, StreamtapeLink $row): ?int
    {
        try {
            return $streamtape->storedSize($linkId, (string) $row->folder);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function fileName(StreamtapeLink $row): string
    {
        $clean = trim((string) preg_replace('/[^\p{L}\p{N}\s._-]+/u', '', (string) $row->title));
        $suffix = (int) $row->subject_type === 7 ? ' ['.strtoupper((string) $row->language).']' : '';
        if ((int) $row->episode > 0) {
            return $clean.' S'.$row->season.'E'.$row->episode.$suffix.'.mp4';
        }

        return ($clean !== '' ? $clean : 'Movie').$suffix.'.mp4';
    }

    /** @return \Illuminate\Support\Collection<int, StreamtapeLink> */
    protected function pendingRows(int $max)
    {
        $query = StreamtapeLink::query()
            ->where('status', '!=', 'done')
            ->where('status', '!=', 'failed')
            ->whereNotNull('file_id')
            ->where('source_url', 'like', '%streamguide.cfd%')
            ->orderBy('id');

        if ((string) $this->option('batch') !== '') {
            $query->where('batch_id', (string) $this->option('batch'));
        }

        return $query->limit($max)->get();
    }
}
