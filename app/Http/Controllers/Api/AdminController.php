<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Services\Catalog\CatalogExporter;
use App\Services\MovieBox\SubjectType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $limit = min(1000, max(1, (int) $request->input('limit', 100)));
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

    protected function authorizeAdmin(Request $request): void
    {
        abort_unless((bool) ($request->user()?->is_admin), 403, 'Réservé aux administrateurs.');
    }
}
