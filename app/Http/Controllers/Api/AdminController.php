<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\StreamtapeUploadSeries;
use App\Http\Controllers\Controller;
use App\Models\BlockedTitle;
use App\Models\CatalogItem;
use App\Models\StreamtapeLink;
use App\Models\StreamtapeFolder;
use App\Services\Catalog\CatalogExporter;
use App\Services\MovieBox\MovieBoxClient;
use App\Services\MovieBox\SubjectType;
use App\Services\Streamtape\StreamtapeService;
use App\Support\ContentFilter;
use App\Support\VersionFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    public function __construct(
        protected CatalogExporter $exporter,
        protected MovieBoxClient $client,
        protected StreamtapeService $streamtape,
    ) {}

    /** Catalog counts for the admin dashboard. */
    public function stats(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            $total = CatalogItem::count();
            $movies = CatalogItem::where('subject_type', SubjectType::MOVIES->value)->count();
            $series = CatalogItem::where('subject_type', SubjectType::TV_SERIES->value)->count();
        } catch (\Throwable $e) {
            report($e);
            $total = $movies = $series = 0;
        }

        return response()->json(['data' => [
            'total' => $total,
            'movies' => $movies,
            'series' => $series,
            'other' => max(0, $total - $movies - $series),
        ]]);
    }

    /**
     * Stream a .txt export of titles + download links. Bounded by ?limit
     * (default 100) so the web request stays responsive; use the
     * `catalog:export-links` command for a full unattended export.
     */
    public function exportLinks(Request $request): StreamedResponse
    {
        $this->authorizeAdmin($request);

        // limit=0 (or blank) means "everything persisted" (bounded by a hard cap).
        $raw = (int) $request->input('limit', 100);
        $limit = $raw <= 0 ? 100000 : min(100000, $raw);
        $type = SubjectType::resolve($request->input('type', 'all'));

        $query = CatalogItem::query()->orderBy('id');
        if ($type !== SubjectType::ALL) {
            $query->where('subject_type', $type->value);
        }
        $query->limit($limit);

        $items = $query->get()->map(fn (CatalogItem $r) => [
            'subjectId' => $r->subject_id,
            'title' => $r->title,
            'subjectType' => $r->subject_type,
        ])->all();

        $filename = 'moviebox-links-'.date('Ymd-His').'.txt';

        return response()->stream(function () use ($items) {
            $header = "MovieBox — export des liens de téléchargement\n"
                ."Généré le ".now()->toDateTimeString()."\n"
                ."Titres: ".count($items)."\n"
                .str_repeat('=', 60)."\n\n";
            echo $header;
            @ob_flush();
            @flush();

            foreach ($this->exporter->lines($items) as $line) {
                echo $line."\n";
                @ob_flush();
                @flush();
            }
        }, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Trigger a deeper catalog import (more titles) in the background. The API
     * has no "list everything" endpoint, so the catalog is discovered by paging
     * through each category — this fetches more pages than the light boot import.
     */
    public function import(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $pages = min(40, max(1, (int) $request->input('pages', 15)));

        // Runs after the HTTP response is flushed — no queue worker required.
        dispatch(function () use ($pages) {
            Artisan::call('catalog:import', ['--pages' => $pages]);
        })->afterResponse();

        return response()->json(['data' => [
            'message' => "Import lancé en arrière-plan ({$pages} pages par catégorie). Recharge la page dans quelques minutes pour voir le total augmenter.",
            'pages' => $pages,
        ]]);
    }

    // -----------------------------------------------------------------
    // Streamtape remote uploads (admin panel)
    // -----------------------------------------------------------------

    /**
     * Search the local catalog by title (fast, no upstream call) for the
     * admin "send to Streamtape" form: cover + title per result, plus the
     * year/type/availability flags and any existing Streamtape links.
     */
    public function streamtapeSearch(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['data' => []]);
        }

        $rows = CatalogItem::query()
            ->where('title', 'like', '%'.$q.'%')
            ->orderBy('title')
            ->limit(40)
            ->get()
            ->filter(fn (CatalogItem $r) => VersionFilter::accepts((string) $r->title, (int) $r->subject_type));

        $linksBySubject = StreamtapeLink::query()
            ->whereIn('subject_id', $rows->pluck('subject_id'))
            ->orderByDesc('id')
            ->get()
            ->groupBy('subject_id');

        $items = $rows->filter(function (CatalogItem $r) use ($linksBySubject) {
            $links = $linksBySubject->get($r->subject_id, collect());

            // Hide titles already fully sent: a movie once it has a final
            // link; a series once every episode of its batch is done.
            $done = $links->filter(fn (StreamtapeLink $l) => $l->streamtape_url !== null)->count();
            if ($done === 0) {
                return true;
            }
            $total = (int) $links->max('total');
            if ((int) $r->subject_type === SubjectType::TV_SERIES->value) {
                return $total > 0 && $done < $total;
            }

            return false;
        })->map(function (CatalogItem $r) use ($linksBySubject) {
            $payload = is_array($r->payload) ? $r->payload : [];

            return [
                'subjectId' => $r->subject_id,
                'subjectType' => $r->subject_type,
                'title' => $r->title,
                'cover' => $r->cover,
                'year' => $payload['year'] ?? null,
                'typeLabel' => $payload['typeLabel'] ?? null,
                'french' => (bool) ($payload['french'] ?? false),
                'hasResource' => ($payload['hasResource'] ?? null) === true,
                'links' => $linksBySubject->get($r->subject_id, collect())
                    ->map(fn (StreamtapeLink $l) => $this->linkPayload($l))
                    ->values()
                    ->all(),
            ];
        });

        return response()->json(['data' => $items]);
    }

    /**
     * Available direct sources (resolution + size) for a title, so the admin
     * can pick the quality before sending. Cached briefly; the resolution is
     * a hint — the actual upload re-resolves and picks the closest match.
     */
    public function streamtapeSources(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'subjectId' => ['required', 'string'],
            'season' => ['nullable', 'integer', 'min:0'],
            'episode' => ['nullable', 'integer', 'min:0'],
        ]);

        $season = (int) ($validated['season'] ?? 0);
        $episode = (int) ($validated['episode'] ?? 0);
        $key = 'st:sources:'.$validated['subjectId'].':'.$season.':'.$episode;

        $sources = Cache::remember($key, 600, function () use ($validated, $season, $episode) {
            return array_map(fn ($s) => [
                'resolution' => $s['resolution'],
                'size' => $s['size'] ?? null,
                'codec' => $s['codec'] ?? null,
            ], $this->resolveDirectSources($validated['subjectId'], $season, $episode));
        });

        return response()->json(['data' => $sources]);
    }

    /**
     * Resolve the best direct MP4 for a title and start a Streamtape remote
     * upload of it (with the browser-like headers the media CDN requires).
     * The record is persisted so its progress can be polled later.
     */
    public function streamtapeUpload(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($this->streamtape->isConfigured(), 422, 'Identifiants Streamtape absents (.env : STREAMTAPE_LOGIN / STREAMTAPE_KEY).');

        $validated = $request->validate([
            'subjectId' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:300'],
            'subjectType' => ['nullable', 'integer'],
            'season' => ['nullable', 'integer', 'min:0'],
            'episode' => ['nullable', 'integer', 'min:0'],
            'resolution' => ['nullable', 'integer', 'min:0'],
            'folder' => ['nullable', 'string', 'max:150'],
        ]);

        $baseSubjectId = $validated['subjectId'];
        $season = (int) ($validated['season'] ?? 0);
        $episode = (int) ($validated['episode'] ?? 0);
        $subjectType = (int) ($validated['subjectType'] ?? 1);
        $title = trim((string) ($validated['title'] ?? $baseSubjectId));
        $isAnime = $subjectType === SubjectType::ANIME->value;

        // Resolve language versions to upload:
        //   Films / Séries → VF + EN
        //   Anime → VF + VO
        $versions = $this->resolveLanguageVersions($baseSubjectId, $title, $subjectType);

        // Folder: resolved once, shared across all language uploads.
        $folderName = trim((string) ($validated['folder'] ?? ''));
        $folderId = null;
        if ($folderName !== '') {
            if (strtolower($folderName) === 'auto') {
                $auto = $this->autoStreamtapeFolder($title, $subjectType, $season);
                $folderName = $auto['path'];
                $folderId = $auto['id'];
            } else {
                $folderId = $this->streamtape->resolveFolderId($folderName);
            }
        }

        $wanted = (int) ($validated['resolution'] ?? 0);
        $uploaded = [];

        foreach ($versions as $lang => $version) {
            $sid = $version['subjectId'];

            // Skip if already done for this language version.
            $existing = StreamtapeLink::query()
                ->where('subject_id', $sid)
                ->where('season', $season)
                ->where('episode', $episode)
                ->where('language', $lang)
                ->where('status', 'done')
                ->whereNotNull('streamtape_url')
                ->latest('id')
                ->first();
            if ($existing !== null) {
                $uploaded[] = $this->linkPayload($existing);
                continue;
            }

            $sources = $this->resolveDirectSources($sid, $season, $episode);
            if ($sources === []) {
                continue;
            }

            $picked = $this->pickSource($sources, $wanted);
            $name = $this->streamtapeName($title, $season, $episode, $picked['resolution'], $lang);

            try {
                $upload = $this->streamtape->remoteAdd($picked['url'], $name, $this->cdnHeaders(), $folderId);
            } catch (\Throwable $e) {
                report($e);
                continue;
            }

            $link = StreamtapeLink::create([
                'subject_id' => $sid,
                'subject_type' => $subjectType,
                'title' => $title,
                'season' => $season,
                'episode' => $episode,
                'resolution' => $picked['resolution'],
                'folder' => $folderName !== '' ? $folderName : null,
                'source_url' => $picked['url'],
                'file_id' => $upload['id'],
                'status' => 'new',
                'language' => $lang,
            ]);

            $uploaded[] = $this->linkPayload($link);
        }

        if ($uploaded === []) {
            return response()->json([
                'message' => 'Aucune vidéo trouvée pour ce titre (flux indisponible).',
            ], 404);
        }

        return response()->json(['data' => $uploaded[0], 'uploads' => $uploaded, 'count' => count($uploaded)], $uploaded[0]['status'] === 'done' ? 200 : 201);
    }

    /**
     * Refresh a remote upload's status from Streamtape. Once "done", the
     * final streamtape.com/v/{fileId} link is resolved and persisted.
     */
    public function streamtapeStatus(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $link = StreamtapeLink::findOrFail($id);

        if ($link->status !== 'done' && $link->status !== 'failed' && $link->file_id !== null) {
            try {
                $status = $this->streamtape->remoteStatus($link->file_id);
                $link->status = $this->normalizeStatus($status['status']);
                $link->bytes_loaded = $status['bytes_loaded'];
                $link->bytes_total = $status['bytes_total'];

                if ($status['streamtape_url'] !== null) {
                    // First time the transfer is seen as finished: remember
                    // when, so a stuck conversion can't keep resetting the
                    // grace period on every poll.
                    if ($link->finished_at === null) {
                        $link->finished_at = now();

                        // Streamtape ignores the `name` param of remotedl/add
                        // when the CDN supplies its own filename, so once the
                        // transfer completes, force the expected name
                        // ("Épisode X.mp4" for episodes; films keep their
                        // source name — one file per title).
                        if ($link->season > 0 || $link->episode > 0) {
                            try {
                                if (preg_match('#/v/([A-Za-z0-9]+)#', $status['streamtape_url'], $rm)) {
                                    $this->streamtape->renameFile($rm[1], 'Épisode '.$link->episode.' ['.$link->language.'].mp4');
                                }
                            } catch (\Throwable $e) {
                                report($e);
                            }
                        }
                    }
                    $link->streamtape_url = $status['streamtape_url'];
                    $link->error = null;

                    if ($link->status === 'done'
                        && preg_match('#/v/([A-Za-z0-9]+)#', $status['streamtape_url'], $m)) {
                        $this->assessConversion($link, $m[1]);
                    }
                } elseif ($link->status === 'failed') {
                    $link->error = $status['bytes_loaded'] > 0 && $status['bytes_loaded'] < 100000
                        ? 'Téléchargement distant refusé par le CDN source (page d\'erreur de '.$status['bytes_loaded'].' octets). Clique sur « Réessayer » : l\'URL est re-résolue et renvoyée.'
                        : 'Le téléchargement distant a échoué chez Streamtape. Clique sur « Réessayer » pour tenter à nouveau.';
                }
            } catch (\Throwable $e) {
                report($e);
                $link->status = 'failed';
                $link->error = $e->getMessage();
            }

            $link->save();
        }

        return response()->json(['data' => $this->linkPayload($link)]);
    }

    /**
     * Decide whether a finished transfer is actually playable on Streamtape.
     * Streamtape converts files *after* the remote transfer is marked
     * "finished", and only exposes a thumbnail once the file plays: a link
     * with converted=false (or a converted file without a splash) is usually
     * still converting or stuck, not broken. So instead of failing at the
     * first check we keep the link in a non-final "converting" state — for
     * the configured grace period, or while the file is listed among the
     * running converts — and only then declare a failure.
     */
    protected function assessConversion(StreamtapeLink $link, string $linkId): void
    {
        try {
            $info = $this->streamtape->fileInfo($linkId);
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        $converted = (bool) ($info['converted'] ?? false);
        $thumb = (string) ($info['thumb'] ?? '');

        // The file itself no longer exists on Streamtape (deleted / expired):
        // the thumbnail CDN may still serve a stale splash, so a thumb alone
        // is not proof — the API record is the source of truth. No fallback
        // either: a lower resolution of a deleted file is also gone.
        if ((int) ($info['status'] ?? 0) === 404) {
            $link->status = 'failed';
            $link->error = 'Le fichier n\'existe plus chez Streamtape (supprimé ?). Clique sur « Réessayer » pour relancer l\'upload.';

            return;
        }

        if ($converted && $thumb !== '' && $this->streamtape->hasThumb($thumb)) {
            $link->status = 'done';
            $link->error = null;

            return;
        }

        // Definitive failure: Streamtape itself lists the file as failed.
        if ($this->streamtape->isFailed($linkId)) {
            if (! $this->attemptConvertFallback($link)) {
                $link->status = 'failed';
                $link->error = 'Le fichier source est corrompu ou incompatible : Streamtape n\'a pas pu le convertir.';
            }

            return;
        }

        // Otherwise give the conversion time: Streamtape marks the remote
        // transfer "finished" before it converts, so converted=false right
        // after "finished" just means the conversion is still running. Keep
        // polling for the grace period — extended while the file is listed
        // among the running converts, but never forever (a stuck queue entry
        // must eventually fall back to a lower resolution).
        $graceMin = (int) config('streamtape.convert_grace_min', 60);
        $finishedAt = $link->finished_at ?? $link->updated_at;
        $elapsed = $finishedAt === null ? 0 : (int) now()->diffInMinutes($finishedAt);
        if ($elapsed < $graceMin
            || ($this->streamtape->isConverting($linkId) && $elapsed < $graceMin * 4)) {
            $link->status = 'converting';
            $link->error = null;

            return;
        }

        // Grace expired (or the convert queue entry is stuck): try the
        // next-lower resolution automatically before giving up.
        if ($this->attemptConvertFallback($link)) {
            return;
        }

        $link->status = 'failed';
        $link->error = $converted
            ? 'Le fichier est converti mais inutilisable chez Streamtape (aucune miniature générée) : la source est corrompue ou tronquée.'
            : 'Le fichier source est corrompu ou incompatible : Streamtape n\'a pas pu le convertir.';
    }

    /**
     * Re-resolve the source and restart the remote upload on the next-lower
     * resolution, reusing the row (status back to "new"). Returns false when
     * nothing lower is available or the fallback limit was reached.
     */
    protected function attemptConvertFallback(StreamtapeLink $link): bool
    {
        // First try to reuse an already working lower-resolution Streamtape
        // link for the same episode: some sources never convert at 1080p on
        // Streamtape's side, but a lower resolution of the same episode may
        // already be healthy — no point downloading it twice.
        if ($this->reuseExistingLowerLink($link)) {
            return true;
        }

        $attempts = (int) $link->convert_attempts;
        if ($attempts >= (int) config('streamtape.convert_max_fallbacks', 3)) {
            return false;
        }

        $current = (int) $link->resolution;
        $sources = $this->resolveDirectSources($link->subject_id, (int) $link->season, (int) $link->episode);
        $lower = array_values(array_filter($sources, fn (array $s) => (int) $s['resolution'] < $current));
        if ($lower === []) {
            return false;
        }

        // Sources are sorted by resolution desc: the first is the best
        // remaining option (e.g. 720p after a failed 1080p).
        $picked = $lower[0];

        $folderId = null;
        if (is_string($link->folder) && $link->folder !== '') {
            try {
                $folderId = $this->streamtape->resolveFolderPath($link->folder)['id'];
            } catch (\Throwable $e) {
                report($e);
            }
        }

        try {
            $name = $this->streamtapeName($link->title, (int) $link->season, (int) $link->episode, (int) $picked['resolution'], $link->language ?? 'vf');
            $upload = $this->streamtape->remoteAdd($picked['url'], $name, $this->cdnHeaders(), $folderId);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        $link->file_id = $upload['id'];
        $link->status = 'new';
        $link->source_url = $picked['url'];
        $link->resolution = (int) $picked['resolution'];
        $link->streamtape_url = null;
        $link->error = null;
        $link->bytes_loaded = null;
        $link->bytes_total = null;
        $link->finished_at = null;
        $link->convert_attempts = $attempts + 1;
        $link->save();

        return true;
    }

    /**
     * Point the row at the best already-healthy lower-resolution upload of
     * the same episode (same subject/season/episode), instead of downloading
     * another copy. Returns true when a usable link was adopted.
     */
    protected function reuseExistingLowerLink(StreamtapeLink $link): bool
    {
        $current = (int) $link->resolution;
        if ($current <= 0) {
            return false;
        }

        $existing = StreamtapeLink::query()
            ->where('subject_id', $link->subject_id)
            ->where('season', (int) $link->season)
            ->where('episode', (int) $link->episode)
            ->where('resolution', '<', $current)
            ->where('status', 'done')
            ->whereNotNull('streamtape_url')
            ->orderByDesc('resolution')
            ->first();

        if ($existing === null) {
            return false;
        }

        $link->status = 'done';
        $link->streamtape_url = $existing->streamtape_url;
        $link->resolution = (int) $existing->resolution;
        $link->error = null;

        return true;
    }

    /**
     * Move a finished upload's file into another folder ('auto' picks
     * Films/Séries by type; a named folder is created on demand).
     */
    public function streamtapeMove(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'folder' => ['nullable', 'string', 'max:150'],
        ]);

        $link = StreamtapeLink::findOrFail($id);
        abort_unless($link->streamtape_url !== null && preg_match('#/v/([A-Za-z0-9]+)#', $link->streamtape_url, $m), 422, 'Ce fichier n\'a pas encore de lien Streamtape (attends la fin de l\'upload).');

        $folderName = trim((string) ($validated['folder'] ?? ''));
        $folderId = $folderName !== '' ? $this->streamtape->resolveFolderId($folderName) : (string) config('streamtape.folder', '');
        if (strtolower($folderName) === 'auto') {
            $auto = $this->autoStreamtapeFolder($link->title, (int) $link->subject_type, (int) $link->season);
            $folderName = $auto['path'];
            $folderId = $auto['id'];
        }

        try {
            $moved = $this->streamtape->moveFile($m[1], $folderId);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 502);
        }
        abort_unless($moved, 502, 'Streamtape n\'a pas pu déplacer le fichier.');

        $link->folder = $folderName !== '' ? $folderName : null;
        $link->save();

        return response()->json(['data' => $this->linkPayload($link)]);
    }

    /**
     * Retry a failed (or stuck) remote upload: re-resolve the source URL
     * (preferring /resource/ over /bt/ tickets) and start a fresh Streamtape
     * fetch on the same folder, reusing the row so history stays clean.
     */
    public function streamtapeRetry(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($this->streamtape->isConfigured(), 422, 'Identifiants Streamtape absents (.env : STREAMTAPE_LOGIN / STREAMTAPE_KEY).');

        $link = StreamtapeLink::findOrFail($id);

        $sources = $this->resolveDirectSources($link->subject_id, (int) $link->season, (int) $link->episode);
        if ($sources === []) {
            return response()->json(['message' => 'Aucune source disponible pour réessayer (flux indisponible).'], 404);
        }

        $wanted = (int) $link->resolution;
        $picked = $this->pickSource($sources, $wanted);

        $folderId = null;
        if (is_string($link->folder) && $link->folder !== '') {
            $folderId = $this->streamtape->resolveFolderPath($link->folder)['id'];
        }

        try {
            $name = $this->streamtapeName($link->title, (int) $link->season, (int) $link->episode, $picked['resolution'], $link->language ?? 'vf');
            $upload = $this->streamtape->remoteAdd($picked['url'], $name, $this->cdnHeaders(), $folderId);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        $link->file_id = $upload['id'];
        $link->status = 'new';
        $link->source_url = $picked['url'];
        $link->resolution = $picked['resolution'];
        $link->streamtape_url = null;
        $link->error = null;
        $link->bytes_loaded = null;
        $link->bytes_total = null;
        $link->finished_at = null;
        $link->convert_attempts = 0;
        $link->save();

        return response()->json(['data' => $this->linkPayload($link)]);
    }

    /** Storage used on the Streamtape account (summed from file/listfolder). */
    public function streamtapeUsage(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        try {
            if ($request->boolean('refresh')) {
                \Illuminate\Support\Facades\Cache::forget('streamtape.storage_usage');
            }
            $usage = $this->streamtape->storageUsage();
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Impossible de lire l\'espace Streamtape : '.$e->getMessage()], 502);
        }

        return response()->json(['data' => $usage]);
    }

    /** Folders known locally (created through this app). */
    public function streamtapeFolders(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $folders = StreamtapeFolder::query()
            ->orderBy('name')
            ->get()
            ->map(fn (StreamtapeFolder $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'parent' => $f->parent,
                'folderId' => $f->folder_id,
            ]);

        return response()->json(['data' => $folders]);
    }

    /** Delete a known folder (API call + local record). */
    public function streamtapeDeleteFolder(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $folder = StreamtapeFolder::findOrFail($id);

        $deletedRemote = false;
        try {
            $deletedRemote = $this->streamtape->deleteFolder($folder->folder_id);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'L\'API Streamtape a refusé la suppression du dossier : '.$e->getMessage(),
            ], 502);
        }

        if (! $deletedRemote) {
            return response()->json([
                'message' => 'L\'API Streamtape n\'a pas pu supprimer ce dossier (restriction du compte ?). Supprime-le dans ton dashboard, puis re-essaie.',
            ], 502);
        }

        $folder->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Start a full-series upload: one folder per season under a root folder
     * named after the series, every episode queued remotely. The work runs
     * in a detached CLI process (the dev server is single-threaded); each
     * episode is persisted as a StreamtapeLink row so the panel can poll
     * progress by batch id.
     */
    public function streamtapeUploadSeries(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        abort_unless($this->streamtape->isConfigured(), 422, 'Identifiants Streamtape absents (.env : STREAMTAPE_LOGIN / STREAMTAPE_KEY).');

        $validated = $request->validate([
            'subjectId' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:300'],
            'rootFolder' => ['nullable', 'string', 'max:150'],
            'parent' => ['nullable', 'string', 'max:150'],
            'quality' => ['nullable', 'integer', 'min:0'],
        ]);

        $subjectId = $validated['subjectId'];
        $title = trim((string) ($validated['title'] ?? ''));
        $rootFolder = trim((string) ($validated['rootFolder'] ?? ''));

        // Quick structure scan to tell the UI how many episodes to expect.
        $episodes = StreamtapeUploadSeries::detectEpisodes($this->client, $subjectId);
        $count = collect($episodes)->flatten()->count();

        abort_if($count === 0, 404, 'Aucun épisode trouvé pour cette série (flux indisponible).');

        $batch = Str::uuid()->toString();

        $cmd = array_merge([
            PHP_BINARY, base_path('artisan'), 'streamtape:upload-series', $subjectId,
            '--batch='.$batch,
        ], [
            $title !== '' ? '--title='.$title : '',
            $rootFolder !== '' ? '--rootFolder='.$rootFolder : '',
            '--parent='.(string) ($validated['parent'] ?? 'Séries'),
            '--quality='.(int) ($validated['quality'] ?? 0),
        ]);
        $cmd = array_values(array_filter($cmd, fn ($c) => $c !== ''));
        $log = storage_path('logs/streamtape-batch-'.$batch.'.log');
        $process = proc_open(
            implode(' ', array_map('escapeshellarg', $cmd)).' > '.escapeshellarg($log).' 2>&1 &',
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            base_path()
        );
        if (is_resource($process)) {
            proc_close($process);
        }

        return response()->json(['data' => [
            'batch' => $batch,
            'total' => $count,
            'message' => "Envoi de la série lancé en arrière-plan ({$count} épisodes). Les épisodes apparaissent dans « Envois récents » au fur et à mesure.",
        ]], 202);
    }

    /** Recent Streamtape uploads (for the admin panel history). */
    public function streamtapeLinks(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $query = StreamtapeLink::query();
        $batch = trim((string) $request->query('batch', ''));
        if ($batch !== '') {
            $query->where('batch_id', $batch);
        }
        $links = $query
            ->orderBy('folder')
            ->orderBy('season')
            ->orderBy('episode')
            ->orderByDesc('id')
            ->limit(60)
            ->get()
            ->map(fn (StreamtapeLink $l) => $this->linkPayload($l));

        return response()->json(['data' => $links]);
    }

    /**
     * Remove an upload: deletes the file from Streamtape (when it exists)
     * and drops the local record.
     */
    public function streamtapeDelete(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        $link = StreamtapeLink::findOrFail($id);

        $removedRemote = false;
        if ($link->streamtape_url !== null && $link->file_id !== null && preg_match('#/v/([A-Za-z0-9]+)#', $link->streamtape_url, $m)) {
            $fileId = $m[1];
            if ($fileId !== '' && $fileId !== $link->file_id) {
                try {
                    $removedRemote = $this->streamtape->deleteFile($fileId);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $link->delete();

        return response()->json(['data' => ['deleted' => true, 'removedRemote' => $removedRemote]]);
    }

    /**
     * Auto folder for an upload: films go into a folder named after them
     * (qualifiers stripped, e.g. "The Tomorrow War"), series into
     * "Séries/{title}/S{season}". Folders are created on demand.
     *
     * @return array{path:string,id:string}
     */
    protected function autoStreamtapeFolder(string $title, int $subjectType, int $season): array
    {
        $clean = trim(preg_replace('/\s*[\[\(].*?[\]\)]\s*/u', ' ', $title));
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
        $name = $clean !== '' ? $clean : 'Vidéo';

        if ($subjectType === SubjectType::TV_SERIES->value) {
            $path = 'Séries/'.$name;
            if ($season > 0) {
                $path .= '/S'.$season;
            }

            return $this->streamtape->resolveFolderPath($path);
        }

        return $this->streamtape->resolveFolderPath('Films/'.$name);
    }

    /**
     * Raw, unproxied MP4 URLs for a subject/season/episode: the H5 play
     * streams first (direct bcdnxw CDN, headers-only auth), then the raw
     * MP4s from play-info as a fallback.
     *
     * @return array<int,array{url:string,resolution:int,codec:?string,size:?int}>
     */
    protected function resolveDirectSources(string $subjectId, int $season, int $episode): array
    {
        $out = [];

        // Mobile API first (api6 pool): the `resource` endpoint returns the
        // real original MP4s (bcdn.hakunaymatata.com/resource/...). The API
        // stores files in per-resolution tiers and only lists ONE tier per
        // request, so every tier must be probed: hardcoding a single
        // resolution hides whole episodes (e.g. From S4 VF has no 1080p
        // except S4E3 — the whole season only exists at 480p). The H5
        // downloads are only a fallback: their tran-audio/bt streams can be
        // audio-only placeholders and are rejected on the upload CDN.
        try {
            foreach ([1080, 720, 480, 360] as $tier) {
                $found = false;
                for ($page = 1; $page <= 5; $page++) {
                    $res = $this->client->resource($subjectId, $tier, $page, 20);
                    foreach (is_array($res['list'] ?? null) ? $res['list'] : [] as $item) {
                        if (! is_array($item) || empty($item['resourceLink'])) {
                            continue;
                        }
                        if ((int) ($item['se'] ?? 0) === $season && (int) ($item['ep'] ?? 0) === $episode) {
                            $out[] = [
                                'url' => (string) $item['resourceLink'],
                                'resolution' => (int) ($item['resolution'] ?? 0),
                                'codec' => $item['codecName'] ?? null,
                                'size' => isset($item['size']) ? (int) $item['size'] : null,
                                'source' => 'mobile',
                            ];
                            $found = true;
                        }
                    }
                    if ($found || ! ($res['pager']['hasMore'] ?? false)) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        if ($out === []) {
            try {
                $play = $this->client->h5Download($subjectId, $season, $episode);
                if ($play['downloads'] === []) {
                    $play = $this->client->h5Play($subjectId, $season, $episode);
                }
                foreach (is_array($play['downloads'] ?? null) ? $play['downloads'] : [] as $d) {
                    if (! is_array($d) || empty($d['url'])) {
                        continue;
                    }
                    $path = (string) parse_url((string) $d['url'], PHP_URL_PATH);
                    if (str_contains($path, '/tran-audio/') || str_contains($path, '/bt/')) {
                        continue;
                    }
                    $out[] = [
                        'url' => (string) $d['url'],
                        'resolution' => (int) ($d['resolution'] ?? 0),
                        'codec' => $d['codecName'] ?? null,
                        'size' => isset($d['size']) ? (int) $d['size'] : null,
                        'source' => 'h5',
                    ];
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($out === []) {
            try {
                $info = $this->client->playInfo($subjectId, $season, $episode);
                $streams = [];
                foreach (['streams', 'list', 'resources', 'playInfos', 'medias', 'urls', 'playInfo'] as $key) {
                    if (isset($info[$key]) && is_array($info[$key])) {
                        $streams = array_merge($streams, array_is_list($info[$key]) ? $info[$key] : [$info[$key]]);
                    }
                }
                foreach ($streams as $s) {
                    if (! is_array($s) || empty($s['url'])) {
                        continue;
                    }
                    $url = (string) $s['url'];
                    if (! str_contains(strtolower(parse_url($url, PHP_URL_PATH) ?: ''), '.mp4')) {
                        continue;
                    }
                    $out[] = [
                        'url' => $url,
                        'resolution' => (int) ($s['resolution'] ?? $s['resolutions'] ?? $s['quality'] ?? 0),
                        'codec' => $s['codecName'] ?? null,
                        'size' => isset($s['size']) ? (int) $s['size'] : null,
                        'source' => 'h5',
                    ];
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Deduplicate by resolution: the mobile API's /resource/ originals
        // (single-track French/English files) win over the H5 web downloads
        // (multi-track, 2-3x bigger); among equals, prefer H.264.
        $byKey = [];
        foreach ($out as $s) {
            $key = $s['resolution'] ?: $s['url'];
            $existing = $byKey[$key] ?? null;
            if ($existing === null) {
                $byKey[$key] = $s;
                continue;
            }
            $score = fn (array $x): int => (($x['source'] ?? '') === 'mobile' ? 2 : 1)
                + (str_contains(strtolower((string) $x['codec']), 'h264') ? 1 : 0);
            if ($score($s) > $score($existing)) {
                $byKey[$key] = $s;
            }
        }
        $out = array_values($byKey);
        usort($out, fn ($a, $b) => $b['resolution'] <=> $a['resolution']);

        return $out;
    }

    /**
     * Resolve language versions to upload:
     *  - Films / Séries : VF + EN
     *  - Anime (original=ja) : VF + VO
     *
     * Anime detection: the `original` dub has lanCode=ja (Japanese).
     * subjectType is unreliable (Blue Lock is type=2, not 7).
     *
     * @return array<string,array{subjectId:string, title:string}>
     */
    protected function resolveLanguageVersions(string $subjectId, string $title, int $subjectType): array
    {
        try {
            $detail = $this->client->itemDetails($subjectId);
            $dubs = is_array($detail) ? ($detail['dubs'] ?? []) : [];
        } catch (\Throwable) {
            return ['vf' => ['subjectId' => $subjectId, 'title' => $title]];
        }

        if (! is_array($dubs) || $dubs === []) {
            return ['vf' => ['subjectId' => $subjectId, 'title' => $title]];
        }

        // Detect anime: original audio is Japanese.
        $isAnime = false;
        foreach ($dubs as $dub) {
            if ((bool) ($dub['original'] ?? false) && strtolower((string) ($dub['lanCode'] ?? '')) === 'ja') {
                $isAnime = true;
                break;
            }
        }

        $result = [];

        // French dub (VF) — primary for all types
        foreach ($dubs as $dub) {
            $code = strtolower((string) ($dub['lanCode'] ?? ''));
            if (str_starts_with($code, 'fr') || $code === 'vff') {
                $result['vf'] = [
                    'subjectId' => (string) ($dub['subjectId'] ?? $subjectId),
                    'title' => $title,
                ];
                break;
            }
        }

        // Second version: EN (films/séries) or VO (anime)
        if ($isAnime) {
            // VO — the original Japanese track
            foreach ($dubs as $dub) {
                if ((bool) ($dub['original'] ?? false)) {
                    $result['vo'] = [
                        'subjectId' => (string) ($dub['subjectId'] ?? $subjectId),
                        'title' => $title,
                    ];
                    break;
                }
            }
        } else {
            // English dub
            foreach ($dubs as $dub) {
                $code = strtolower((string) ($dub['lanCode'] ?? ''));
                if (str_starts_with($code, 'en')) {
                    $result['en'] = [
                        'subjectId' => (string) ($dub['subjectId'] ?? $subjectId),
                        'title' => $title,
                    ];
                    break;
                }
            }
        }

        if ($result === []) {
            $result['vf'] = ['subjectId' => $subjectId, 'title' => $title];
        }

        return $result;
    }

    /**
     * @param  array<int,array{url:string,resolution:int,codec:?string,size:?int}>  $sources
     * @return array{url:string,resolution:int,codec:?string,size:?int}
     */
    protected function pickSource(array $sources, int $wanted): array
    {
        // Prefer /resource/ URLs: the CDN rejects Streamtape's remote fetch
        // on /bt/ ticket URLs (HTTP 429), /bt/ is only a last resort.
        $preferred = array_values(array_filter(
            $sources,
            fn (array $s) => ! str_contains((string) parse_url((string) $s['url'], PHP_URL_PATH), '/bt/')
        ));
        if ($preferred !== []) {
            $sources = $preferred;
        }

        if ($wanted <= 0) {
            return $sources[0];
        }

        $best = $sources[0];
        foreach ($sources as $s) {
            if ($s['resolution'] === $wanted) {
                return $s;
            }
            $bestDiff = abs($best['resolution'] - $wanted);
            $diff = abs($s['resolution'] - $wanted);
            if ($diff < $bestDiff) {
                $best = $s;
            }
        }

        return $best;
    }

    /**
     * Browser-like headers the media CDN expects on direct MP4 fetches. The
     * CDN rate-limits anonymous fetchers (HTTP 429) on some nodes, so the
     * app's own device identity (device_id + gaid, persisted per install)
     * is attached: a fetch presenting a known device passes as the app.
     */
    protected function cdnHeaders(): array
    {
        // Fresh identity per call: a new device_id/gaid on every upload so
        // the CDN cannot fingerprint repeated downloads from this app. The
        // bcdn CDN (mobile API resource links) rejects Origin/Referer, so
        // those are omitted — only the Android app UA + client info pass.
        $clientInfo = json_decode((string) config('moviebox.client_info'), true);
        if (! is_array($clientInfo)) {
            $clientInfo = [];
        }
        $clientInfo['device_id'] = strtolower(bin2hex(random_bytes(16)));
        $g = strtolower(bin2hex(random_bytes(16)));
        $clientInfo['gaid'] = substr($g, 0, 8).'-'.substr($g, 8, 4).'-'.substr($g, 12, 4).'-'.substr($g, 16, 4).'-'.substr($g, 20, 12);
        $clientInfo['timezone'] = (string) config('moviebox.timezone', 'Europe/Paris');

        return [
            'User-Agent: '.(string) config('moviebox.user_agent'),
            'X-Client-Info: '.json_encode($clientInfo),
            'X-Request-Lang: '.(string) config('moviebox.language', 'fr'),
            'X-Client-Status: 0',
        ];
    }

    /** Deterministic file name used for the Streamtape upload. */
    protected function streamtapeName(string $title, int $season, int $episode, int $resolution, string $lang = 'vf'): string
    {
        if ($season > 0 || $episode > 0) {
            return 'Épisode '.$episode.' ['.$lang.'].mp4';
        }
        $slug = Str::slug($title, '_') ?: 'video';
        $name = $slug.' ['.$lang.']';
        if ($resolution > 0) {
            $name .= "_$resolution".'p';
        }

        return $name.'.mp4';
    }

    /** Map the API's status strings onto a small stable set. */
    protected function normalizeStatus(string $status): string
    {
        $status = strtolower($status);
        if ($status === 'finished') {
            return 'done';
        }
        if (in_array($status, ['done', 'failed'], true)) {
            return $status;
        }
        if (in_array($status, ['error', 'cancelled', 'deleted', 'failed'], true)) {
            return 'failed';
        }
        if (in_array($status, ['new', 'running', 'downloading', 'converting', 'queued'], true)) {
            return $status;
        }

        return 'unknown';
    }

    /** @return array<string,mixed> */
    protected function linkPayload(StreamtapeLink $link): array
    {
        return [
            'id' => $link->id,
            'subjectId' => $link->subject_id,
            'subjectType' => $link->subject_type,
            'title' => $link->title,
            'season' => $link->season,
            'episode' => $link->episode,
            'resolution' => $link->resolution,
            'language' => $link->language ?? 'vf',
            'folder' => $link->folder,
            'streamtapeUrl' => $link->streamtape_url,
            'status' => $link->status,
            'error' => $link->error,
            'total' => $link->total,
            'bytesLoaded' => $link->bytes_loaded,
            'bytesTotal' => $link->bytes_total,
            'createdAt' => $link->created_at?->toIso8601String(),
        ];
    }

    // -----------------------------------------------------------------
    // Blocked titles (hidden from discovery, still reachable via search)
    // -----------------------------------------------------------------

    public function blockedTitles(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['data' => BlockedTitle::orderBy('term')->get(['id', 'term'])]);
    }

    public function addBlockedTitle(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'term' => ['required', 'string', 'min:2', 'max:200'],
        ]);

        $entry = BlockedTitle::firstOrCreate(['term' => trim($validated['term'])]);
        Cache::forget(ContentFilter::BLOCKED_CACHE_KEY);

        return response()->json(['data' => $entry->only('id', 'term')], 201);
    }

    public function deleteBlockedTitle(Request $request, int $id): JsonResponse
    {
        $this->authorizeAdmin($request);

        BlockedTitle::whereKey($id)->delete();
        Cache::forget(ContentFilter::BLOCKED_CACHE_KEY);

        return response()->json(['data' => ['deleted' => true]]);
    }

    protected function authorizeAdmin(Request $request): void
    {
        abort_unless((bool) ($request->user()?->is_admin), 403, 'Réservé aux administrateurs.');
    }
}
