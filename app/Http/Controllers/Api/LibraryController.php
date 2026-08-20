<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\WatchHistory;
use App\Services\MovieBox\MovieBoxClient;
use App\Support\ContentFilter;
use App\Support\ItemNormalizer;
use App\Support\VersionFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LibraryController extends Controller
{
    public function __construct(protected MovieBoxClient $client)
    {
    }

    // -----------------------------------------------------------------
    // Favorites
    // -----------------------------------------------------------------

    public function favorites(Request $request): JsonResponse
    {
        $favorites = $request->user()->favorites()->latest()->get();

        // Purge censored entries (blocked keywords, "The Animation" hentai,
        // music clips…), then return the survivors.
        $kept = [];
        foreach ($favorites as $favorite) {
            if (ContentFilter::isBlocked(self::toItem($favorite))) {
                $favorite->delete();
                continue;
            }
            $kept[] = $this->preferFrenchRow($favorite);
        }

        return response()->json(['data' => $kept]);
    }

    public function storeFavorite(Request $request): JsonResponse
    {
        $data = $this->validateItem($request);

        // Censored titles (blocked keywords, "The Animation" hentai, music
        // clips…) are never added to favorites.
        if (ContentFilter::isBlocked(self::toItem((object) $data))) {
            abort(404, 'Content unavailable.');
        }

        $favorite = $request->user()->favorites()->updateOrCreate(
            ['subject_id' => $data['subject_id']],
            $data
        );

        return response()->json(['data' => $favorite], 201);
    }

    public function destroyFavorite(Request $request, string $subjectId): JsonResponse
    {
        $request->user()->favorites()->where('subject_id', $subjectId)->delete();

        return response()->json(['message' => 'Removed from favorites.']);
    }

    // -----------------------------------------------------------------
    // Watch history / continue watching
    // -----------------------------------------------------------------

    public function history(Request $request): JsonResponse
    {
        $history = $request->user()->watchHistories()
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            ->unique('subject_id')
            ->take(60)
            ->values();

        // Purge censored entries (blocked keywords, "The Animation" hentai,
        // music clips…), then return the survivors.
        $kept = [];
        foreach ($history as $entry) {
            if (ContentFilter::isBlocked(self::toItem($entry))) {
                WatchHistory::query()
                    ->where('user_id', $request->user()->id)
                    ->where('subject_id', $entry->subject_id)
                    ->delete();
                continue;
            }
            $kept[] = $this->preferFrenchRow($entry);
        }

        return response()->json(['data' => $kept]);
    }

    /** Upsert playback progress for a title/episode. */
    public function storeHistory(Request $request): JsonResponse
    {
        $data = $this->validateItem($request, [
            'season' => ['sometimes', 'integer', 'min:0'],
            'episode' => ['sometimes', 'integer', 'min:0'],
            'position_seconds' => ['sometimes', 'integer', 'min:0'],
            'duration_seconds' => ['sometimes', 'integer', 'min:0'],
        ]);

        $entry = $request->user()->watchHistories()->updateOrCreate(
            [
                'subject_id' => $data['subject_id'],
                'season' => $data['season'] ?? 0,
                'episode' => $data['episode'] ?? 0,
            ],
            $data
        );

        // Censored titles (blocked keywords, "The Animation" hentai, music
        // clips…) are never kept in watch history.
        if (ContentFilter::isBlocked(self::toItem((object) $entry->getAttributes()))) {
            $entry->delete();

            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $entry]);
    }

    public function destroyHistory(Request $request, string $subjectId): JsonResponse
    {
        $request->user()->watchHistories()->where('subject_id', $subjectId)->delete();

        return response()->json(['message' => 'Removed from history.']);
    }

    /**
     * A foreign-dub entry ([Hindi], [Bengali]…) is not blocked — but when a
     * French version of the same title exists in the catalog, the entry is
     * switched to that French version (title, cover, subject) so the account
     * displays and plays French instead of Hindi. When no separate French
     * version exists but the title does carry French audio, the "[Hindi]"
     * tag is simply rewritten as "[Français]".
     *
     * @param  \Illuminate\Database\Eloquent\Model  $row
     * @return \Illuminate\Database\Eloquent\Model
     */
    protected function preferFrenchRow($row)
    {
        $title = (string) $row->title;
        if ($title === '' || VersionFilter::frenchRank($title) !== 0) {
            return $row;
        }

        $base = trim((string) preg_replace('/\[[^\]]*\]/u', '', $title));
        if ($base === '') {
            return $row;
        }

        $results = Cache::remember(
            'library:frlookup:'.md5(mb_strtolower($base)),
            3600,
            function () use ($base) {
                try {
                    return ItemNormalizer::many($this->client->search($base, 0, 1, 20)['items'] ?? []);
                } catch (\Throwable $e) {
                    report($e);

                    return [];
                }
            }
        );

        if ($results === []) {
            return $row;
        }

        // Separate French version exists → switch the entry to it.
        foreach ($results as $item) {
            if (VersionFilter::frenchRank((string) ($item['title'] ?? '')) === 3) {
                $row->title = $item['title'] ?? $row->title;
                $row->cover = $item['cover'] ?? $row->cover;
                $row->subject_id = $item['subjectId'] ?? $row->subject_id;
                $row->subject_type = $item['subjectType'] ?? $row->subject_type;
                $row->detail_path = $item['detailPath'] ?? $row->detail_path;
                $row->save();

                return $row;
            }
        }

        // No separate French version, but the title carries French (or English)
        // audio → rewrite the "[Hindi]" tag as "[Français]" (or "[Anglais]").
        $lang = null;
        foreach ($results as $item) {
            if (! empty($item['french'])) {
                $lang = 'fr';
                break;
            }
            if (! empty($item['english'])) {
                $lang = 'en';
            }
        }
        if ($lang !== null) {
            $row->title = VersionFilter::languageize($title, $lang);
            $row->save();
        }

        return $row;
    }

    /**
     * Map a favorite/history row to the item shape ContentFilter expects.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $row
     * @return array<string,mixed>
     */
    protected static function toItem($row): array
    {
        $meta = (array) ($row->meta ?? []);

        return [
            'title' => (string) ($row->title ?? ''),
            'subjectId' => (string) ($row->subject_id ?? ''),
            'subjectType' => (int) ($row->subject_type ?? 0),
            'description' => (string) ($meta['description'] ?? ''),
            'genres' => (array) ($meta['genres'] ?? []),
            'imdbRating' => (float) ($meta['imdbRating'] ?? 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $extraRules
     * @return array<string,mixed>
     */
    protected function validateItem(Request $request, array $extraRules = []): array
    {
        return $request->validate(array_merge([
            'subject_id' => ['required', 'string', 'max:255'],
            'subject_type' => ['sometimes', 'integer'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cover' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'detail_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'meta' => ['sometimes', 'nullable', 'array'],
        ], $extraRules));
    }
}