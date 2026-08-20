<?php

namespace App\Support;

/**
 * Renders the SSR markup of the listing pages (home, films, series, detail)
 * from the same payloads the JSON API serves. The markup mirrors the SPA's
 * DOM classes so the hydrated render replaces it seamlessly, and stays fully
 * navigable without JavaScript (cards and hero actions are real links).
 */
class SsrRenderer
{
    /** @param  array<string,mixed>  $data */
    public static function render(string $type, array $data): string
    {
        return match ($type) {
            'home' => self::home($data),
            'category:films', 'category:series' => self::category($data),
            'detail' => self::detail($data),
            default => '',
        };
    }

    /** @param  array<string,mixed>  $data */
    protected static function home(array $data): string
    {
        $html = '';
        $first = true;

        foreach (($data['sections'] ?? []) as $section) {
            $items = array_values(array_filter((array) ($section['items'] ?? [])));
            if ($items === []) {
                continue;
            }
            if ($first) {
                $html .= self::hero($items[0]);
                $first = false;
            }
            $html .= self::row((string) ($section['title'] ?? ''), $items);
        }

        return $html;
    }

    /** @param  array<string,mixed>  $data */
    protected static function category(array $data): string
    {
        $items = array_values(array_filter((array) ($data['items'] ?? [])));
        if ($items === []) {
            return '';
        }

        $html = '<div class="container"><h2 class="section-title">'.e((string) ($data['title'] ?? '')).'</h2>';
        $html .= '<div class="grid">';
        foreach ($items as $item) {
            $html .= self::card($item);
        }
        $html .= '</div></div>';

        return $html;
    }

    /** @param  array<string,mixed>  $data */
    protected static function detail(array $data): string
    {
        $item = is_array($data['item'] ?? null) ? $data['item'] : null;
        if ($item === null) {
            return '';
        }

        $title = e(self::display($item));
        $cover = e((string) ($item['cover'] ?? ''));
        $coverSmall = e((string) ($item['coverSmall'] ?? $item['cover'] ?? ''));

        $meta = '';
        if (! empty($item['year'])) {
            $meta .= '<span class="chip">'.e((string) $item['year']).'</span>';
        }
        if (! empty($item['imdbRating'])) {
            $meta .= '<span class="chip rating">★ '.e((string) $item['imdbRating']).'</span>';
        }
        if (! empty($item['durationSeconds'])) {
            $meta .= '<span class="chip">'.e(self::duration((int) $item['durationSeconds'])).'</span>';
        }
        if (! empty($item['country'])) {
            $meta .= '<span class="chip">'.e((string) $item['country']).'</span>';
        }
        foreach (array_slice((array) ($item['genres'] ?? []), 0, 4) as $genre) {
            $meta .= '<span class="chip">'.e((string) $genre).'</span>';
        }

        $dubs = array_values(array_filter((array) ($data['dubs'] ?? [])));
        $versionRow = '';
        if (count($dubs) > 1) {
            $buttons = '';
            foreach ($dubs as $i => $dub) {
                $label = e((string) ($dub['label'] ?? ''));
                if (! empty($dub['original'])) {
                    $label .= ' (VO)';
                }
                $buttons .= '<button type="button" class="season-tab'.($i === 0 ? ' active' : '').'">'.$label.'</button>';
            }
            $versionRow = '<div style="margin-bottom:16px">'
                .'<div class="section-title" style="font-size:15px;margin:0 0 8px">Version / Langue</div>'
                .'<div class="season-tabs">'.$buttons.'</div></div>';
        }

        $playHref = e(self::watchHref($item));
        $actions = '<a class="btn btn-primary" data-link href="'.$playHref.'" style="display:flex;gap:8px;align-items:center">'
            .self::playSvg().' <span>Lecture</span></a>';

        $html = '<section class="detail-hero">'
            .'<div class="detail-hero-bg-wrap"><div class="cover-hash cover-hash-gray" role="img" aria-label="Aperçu de '.$title.'"></div>'
            .($cover !== '' ? '<img class="detail-hero-bg" src="'.$cover.'" alt="">' : '')
            .'</div><div class="detail-hero-overlay"></div><div class="container">'
            .'<div class="detail-poster">'
            .'<div class="cover-hash cover-hash-gray" role="img" aria-label="Aperçu de '.$title.'"></div>'
            .($coverSmall !== '' ? '<img class="cover-fade" src="'.$coverSmall.'" alt="'.$title.'" width="96" height="144" decoding="async">' : '')
            .'</div>'
            .'<div class="detail-info">'
            .'<h1 class="detail-title">'.$title.'</h1>'
            .'<div class="detail-meta"><span class="badge">'.e((string) ($item['typeLabel'] ?? '')).'</span>'.$meta.'</div>'
            .(! empty($item['description']) ? '<p class="detail-desc">'.e((string) $item['description']).'</p>' : '')
            .$versionRow
            .'<div class="detail-actions">'.$actions.'</div>'
            .'</div></div></section>';

        $isSeries = (int) ($item['subjectType'] ?? 0) === 2;
        $seasons = array_values(array_filter((array) ($data['seasons'] ?? [])));
        if ($isSeries && $seasons !== []) {
            $html .= '<div class="container"><h2 class="section-title">Episodes</h2>';
            $tabs = '';
            foreach ($seasons as $i => $season) {
                $tabs .= '<button type="button" class="season-tab'.($i === 0 ? ' active' : '').'">Season '.e((string) ($season['season'] ?? $i + 1)).'</button>';
            }
            if (count($seasons) > 1) {
                $html .= '<div class="season-tabs">'.$tabs.'</div>';
            }
            $html .= '<div class="episode-grid">';
            foreach ((array) ($seasons[0]['episodes'] ?? []) as $ep) {
                $href = e(self::watchHref($item, (int) ($seasons[0]['season'] ?? 1), (int) $ep));
                $html .= '<a class="episode-btn" data-link href="'.$href.'">E'.e((string) $ep).'</a>';
            }
            $html .= '</div></div>';
        }

        $cast = array_values(array_filter((array) ($data['cast'] ?? [])));
        if ($cast !== []) {
            $html .= '<div class="container"><h2 class="section-title">Cast</h2><div class="cast-row">';
            foreach (array_slice($cast, 0, 20) as $c) {
                $avatar = e((string) ($c['avatar'] ?? ''));
                $html .= '<div class="cast-card">'
                    .($avatar !== '' ? '<img src="'.$avatar.'" alt="'.e((string) ($c['name'] ?? '')).'" loading="lazy">' : '<div class="ph"></div>')
                    .'<div class="cast-name">'.e((string) ($c['name'] ?? '')).'</div>'
                    .'<div class="cast-char">'.e((string) ($c['character'] ?? '')).'</div>'
                    .'</div>';
            }
            $html .= '</div></div>';
        }

        $recos = array_values(array_filter((array) ($data['recommendations'] ?? [])));
        if ($recos !== []) {
            $html .= self::row('More Like This', $recos);
        }

        return $html;
    }

    /** @param  array<string,mixed>  $item */
    protected static function hero(array $item): string
    {
        $title = e(self::display($item));
        $cover = e((string) ($item['cover'] ?? ''));

        $meta = '';
        if (! empty($item['typeLabel'])) {
            $meta .= '<span class="badge">'.e((string) $item['typeLabel']).'</span>';
        }
        if (! empty($item['year'])) {
            $meta .= '<span>'.e((string) $item['year']).'</span>';
        }
        if (! empty($item['imdbRating'])) {
            $meta .= '<span class="rating">★ '.e((string) $item['imdbRating']).'</span>';
        }
        $genres = array_slice(array_values(array_filter((array) ($item['genres'] ?? []))), 0, 3);
        if ($genres !== []) {
            $meta .= '<span>'.e(implode(' · ', $genres)).'</span>';
        }

        return '<section class="hero">'
            .'<div class="hero-bg-wrap"><div class="cover-hash cover-hash-gray" role="img" aria-label="Aperçu de '.$title.'"></div>'
            .($cover !== '' ? '<img class="hero-bg" src="'.$cover.'" alt="" fetchpriority="high" decoding="async">' : '')
            .'</div>'
            .'<div class="hero-content">'
            .'<h1 class="hero-title">'.$title.'</h1>'
            .'<div class="hero-meta">'.$meta.'</div>'
            .(! empty($item['description']) ? '<p class="hero-desc">'.e((string) $item['description']).'</p>' : '')
            .'<div class="hero-actions">'
            .'<a class="btn btn-primary" data-link href="'.e(self::watchHref($item)).'" style="display:flex;gap:8px;align-items:center">'.self::playSvg().' <span>Lecture</span></a>'
            .'<a class="btn btn-ghost" data-link href="'.e(self::detailHref($item)).'">Plus d\'infos</a>'
            .'</div></div></section>';
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     */
    protected static function row(string $title, array $items): string
    {
        $html = '<section class="row container"><h2 class="section-title">'.e($title).'</h2>'
            .'<div class="row-scroller">';
        foreach ($items as $item) {
            $html .= self::card($item);
        }
        $html .= '</div></section>';

        return $html;
    }

    /** @param  array<string,mixed>  $item */
    protected static function card(array $item): string
    {
        $title = e(self::display($item));
        $src = e((string) ($item['coverSmall'] ?? $item['cover'] ?? ''));

        $poster = '<div class="card-poster">'
            .'<div class="cover-hash cover-hash-gray" role="img" aria-label="Aperçu de '.$title.'"></div>';
        if ($src !== '') {
            $poster .= '<img class="cover-fade" src="'.$src.'" alt="'.$title.'" loading="lazy" decoding="async" width="96" height="144">';
        } else {
            $poster .= '<div class="ph">'.$title.'</div>';
        }
        $poster .= '<div class="card-type">'.e((string) ($item['typeLabel'] ?? '')).'</div>';
        if (! empty($item['french'])) {
            $poster .= '<div class="card-fr" title="Audio français disponible"><span>VF</span></div>';
        }
        if (! empty($item['imdbRating'])) {
            $poster .= '<div class="card-rating"><span>★ '.e((string) $item['imdbRating']).'</span></div>';
        }
        $poster .= '</div>';

        $sub = ! empty($item['year']) ? '<div class="card-sub"><span>'.e((string) $item['year']).'</span></div>' : '';

        $body = '<div class="card-body"><div class="card-title">'.$title.'</div>'.$sub.'</div>';

        $href = e(self::detailHref($item));

        return '<a class="card" data-link href="'.$href.'" tabindex="0">'.$poster.$body.'</a>';
    }

    /** @param  array<string,mixed>  $item */
    protected static function watchHref(array $item, int $season = 0, int $episode = 0): string
    {
        return '/w/'.\App\Support\ShortId::encode(
            (string) ($item['subjectId'] ?? ''),
            (int) ($item['subjectType'] ?? 0)
        ).'/'.$season.'/'.$episode;
    }

    /** @param  array<string,mixed>  $item */
    protected static function detailHref(array $item): string
    {
        // Canonical short URL — a base62 token of subjectId+subjectType.
        return '/t/'.\App\Support\ShortId::encode(
            (string) ($item['subjectId'] ?? ''),
            (int) ($item['subjectType'] ?? 0)
        );
    }

    /** @param  array<string,mixed>  $item */
    protected static function display(array $item): string
    {
        return (string) ($item['displayTitle'] ?? $item['title'] ?? 'Untitled');
    }

    protected static function duration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = (int) round(($seconds % 3600) / 60);

        return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
    }

    protected static function playSvg(): string
    {
        return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
    }
}