<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedTitle;
use App\Models\CatalogItem;
use App\Services\Catalog\CatalogExporter;
use App\Services\MovieBox\SubjectType;
use App\Support\ContentFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminController extends Controller
{
    public function __construct(protected CatalogExporter $exporter) {}

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
