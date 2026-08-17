<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Favorite;
use App\Models\WatchHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LibraryController extends Controller
{
    // -----------------------------------------------------------------
    // Favorites
    // -----------------------------------------------------------------

    public function favorites(Request $request): JsonResponse
    {
        $favorites = $request->user()->favorites()->latest()->get();

        return response()->json(['data' => $favorites]);
    }

    public function storeFavorite(Request $request): JsonResponse
    {
        $data = $this->validateItem($request);

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

        return response()->json(['data' => $history]);
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

        return response()->json(['data' => $entry]);
    }

    public function destroyHistory(Request $request, string $subjectId): JsonResponse
    {
        $request->user()->watchHistories()->where('subject_id', $subjectId)->delete();

        return response()->json(['message' => 'Removed from history.']);
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
