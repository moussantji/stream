<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\CatalogController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

/**
 * SSR entry points: the first paint of the listing pages (home, films,
 * series, detail) is rendered server-side from the same cached payloads the
 * JSON API serves, with the data embedded so the SPA hydrates without a
 * round-trip. Other routes keep the plain SPA shell.
 */
class WebController extends Controller
{
    public function __construct(protected CatalogController $catalog)
    {
    }

    public function home(): View
    {
        return $this->shell('home', [
            'title' => config('app.name', 'MovieBox Stream'),
            'description' => 'Streaming : films et séries récents les mieux notés.',
            'data' => $this->catalog->homeData(1),
        ]);
    }

    public function category(string $tab): View
    {
        $names = [
            'films' => ['Films', 'Films récents les mieux notés en streaming.'],
            'series' => ['Séries & Émissions', 'Séries récentes les mieux notées en streaming.'],
        ];
        [$title, $description] = $names[$tab] ?? ['Films', ''];

        return $this->shell("category:$tab", [
            'title' => $title.' — '.config('app.name', 'MovieBox Stream'),
            'description' => $description,
            'data' => $this->catalog->categoryData($tab, 1),
        ]);
    }

    public function title(Request $request, ?string $slug = null): View|RedirectResponse
    {
        $subjectId = trim((string) $request->query('subjectId', ''));

        if ($slug !== null && $slug !== '') {
            $resolved = $this->catalog->resolveSubjectId($slug);
            if ($resolved === null) {
                abort(404, 'Content unavailable.');
            }
            $subjectId = $resolved;
        } elseif ($subjectId !== '') {
            // Legacy /title?subjectId=… links (history, bookmarks, shares):
            // canonical form is the short /t/{code} URL.
            return Redirect::to(
                '/t/'.\App\Support\ShortId::encode($subjectId, (int) $request->query('subjectType', 0)),
                301
            );
        }

        if ($subjectId === '') {
            abort(404, 'Content unavailable.');
        }

        return $this->renderTitle(
            $request,
            $subjectId,
            (int) $request->query('subjectType', 0),
            $request->query('title'),
            $request->query('cover'),
            $request->query('detailPath')
        );
    }

    /** Short detail URL: /t/{code} — the canonical form for every title. */
    public function titleByCode(Request $request, string $code): View|RedirectResponse
    {
        [$subjectId, $subjectType] = \App\Support\ShortId::decode($code);

        if ($subjectId === '') {
            abort(404, 'Content unavailable.');
        }

        return $this->renderTitle($request, $subjectId, $subjectType);
    }

    /**
     * Shared detail-page rendering: validated URL hints plus the live build.
     *
     * @param  mixed  $title  URL-provided title (fast-paint hint)
     * @param  mixed  $cover  URL-provided cover (fast-paint hint)
     * @param  mixed  $detailPath  URL-provided detailPath (play links)
     */
    protected function renderTitle(
        Request $request,
        string $subjectId,
        int $subjectType,
        mixed $title = null,
        mixed $cover = null,
        mixed $detailPath = null
    ): View|RedirectResponse {
        // Fast-paint hint: pass the URL-provided title/cover only. Empty
        // strings would overwrite the fallback item built in buildDetail when
        // the upstream detail is absent.
        $validated = [
            'subjectId' => $subjectId,
            'subjectType' => $subjectType,
        ];
        foreach (['title' => $title, 'cover' => $cover, 'detailPath' => $detailPath] as $key => $value) {
            if (is_string($value) && $value !== '') {
                $validated[$key] = $value;
            }
        }

        $data = $this->catalog->detailData($validated);
        $item = $data['item'] ?? null;

        return $this->shell('detail', [
            'title' => ($item['displayTitle'] ?? $item['title'] ?? 'Fiche').' — '.config('app.name', 'MovieBox Stream'),
            'description' => mb_substr((string) ($item['description'] ?? ''), 0, 160),
            'data' => $data,
        ]);
    }

    /**
     * Render the app shell: SSR content into #app when a page payload is
     * given, plus the payload itself embedded for SPA hydration.
     *
     * @param  array{title?:string,description?:string,data:array<string,mixed>}  $page
     */
    protected function shell(string $type, array $page): View
    {
        $item = is_array($page['data']['item'] ?? null) ? $page['data']['item'] : null;
        if ($item !== null && ! empty($item['title'])) {
            $year = $item['year'] ?? null;
            $page['jsonLd'] = [
                '@context' => 'https://schema.org',
                '@type' => (int) ($item['subjectType'] ?? 0) === 2 ? 'TVSeries' : 'Movie',
                'name' => $item['displayTitle'] ?? $item['title'],
                'description' => mb_substr((string) ($item['description'] ?? ''), 0, 500),
                'image' => $item['cover'] ?? null,
                'datePublished' => $item['releaseDate'] ?? (is_numeric($year) ? $year.'-01-01' : null),
                'aggregateRating' => isset($item['imdbRating']) && is_numeric($item['imdbRating'])
                    ? ['@type' => 'AggregateRating', 'ratingValue' => (float) $item['imdbRating'], 'bestRating' => 10]
                    : null,
                'genre' => is_array($item['genres'] ?? null) ? $item['genres'] : [],
            ];
        }

        return view('app', [
            'page' => $page,
            'pageType' => $type,
            'ssrHtml' => \App\Support\SsrRenderer::render($type, $page['data']),
        ]);
    }
}