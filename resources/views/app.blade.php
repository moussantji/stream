<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b0b0f">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $page['title'] ?? config('app.name', 'MovieBox Stream') }}</title>
    <meta name="description" content="{{ $page['description'] ?? 'Search, browse and stream movies and TV series.' }}">
    @if (! empty($page['data']['item']))
        <meta property="og:type" content="video.movie">
        <meta property="og:title" content="{{ $page['data']['item']['displayTitle'] ?? $page['data']['item']['title'] ?? $page['title'] ?? '' }}">
        <meta property="og:description" content="{{ mb_substr((string) ($page['data']['item']['description'] ?? ''), 0, 160) }}">
        @if (! empty($page['data']['item']['cover']))
            <meta property="og:image" content="{{ $page['data']['item']['cover'] }}">
        @endif
    @else
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $page['title'] ?? config('app.name', 'MovieBox Stream') }}">
    @endif
    <meta property="og:site_name" content="{{ config('app.name', 'MovieBox Stream') }}">
    <link rel="canonical" href="{{ url(request()->path()) }}">
    @if (! empty($page['jsonLd']))
        <script type="application/ld+json">@json($page['jsonLd'])</script>
    @endif
    {{-- Warm the image/CDN connections so banners and posters paint sooner. --}}
    <link rel="preconnect" href="https://pbcdn.aoneroom.com" crossorigin>
    <link rel="preconnect" href="https://pacdn.aoneroom.com" crossorigin>
    <link rel="preconnect" href="https://h5-static.aoneroom.com" crossorigin>
    <link rel="preconnect" href="https://bcdnxw.hakunaymatata.com" crossorigin>
    <link rel="preconnect" href="https://h5-api.aoneroom.com">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="site-header" id="site-header">
        <div class="header-inner">
            <button class="nav-toggle" id="nav-toggle" aria-label="Menu" aria-expanded="false">
                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>

            <a class="brand" href="/" data-link>
                <span class="brand-mark">M</span>
                <span class="brand-text">MovieBox<span>Stream</span></span>
            </a>

            <nav class="main-nav" id="main-nav">
                <a href="/" data-link data-nav="/">Accueil</a>
                <a href="/films" data-link data-nav="/films">Films</a>
                <a href="/series" data-link data-nav="/series">Séries &amp; Émissions</a>
                <a href="/library" data-link data-nav="/library" data-auth-only>Ma liste</a>
                <a href="/admin" data-link data-nav="/admin" data-admin-only style="display:none">Admin</a>
            </nav>

            <form class="search-box" id="search-form" role="search" autocomplete="off">
                <svg viewBox="0 0 24 24" class="icon"><path d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <input type="search" id="search-input" name="q" placeholder="Rechercher films &amp; séries…" aria-label="Rechercher">
                <div class="suggestions" id="suggestions" hidden></div>
            </form>

            <a class="btn btn-ghost app-dl" href="/downloads/moviebox.apk" download title="Télécharger l'application Android">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/></svg>
                <span>App</span>
            </a>

            <div class="auth-area" id="auth-area">
                <!-- populated by JS -->
            </div>
        </div>
    </header>

    <main id="app" class="app-main" aria-live="polite">{!! $ssrHtml ?? '' !!}</main>

    {{-- SSR page payload: the SPA hydrates from it without an extra API call. --}}
    @if (! empty($page['data']))
        <script id="page-data" type="application/json">{!! json_encode(['type' => $pageType ?? '', 'data' => $page['data']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endif

    <nav class="bottom-nav" id="bottom-nav" aria-label="Navigation">
        <a href="/" data-link data-nav="/">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/></svg>
            <span>Accueil</span>
        </a>
        <a href="/films" data-link data-nav="/films">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 4v16M17 4v16M3 9h4M3 15h4M17 9h4M17 15h4"/></svg>
            <span>Films</span>
        </a>
        <a href="/series" data-link data-nav="/series">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="13" rx="2"/><path d="M8 3l4 4 4-4"/></svg>
            <span>Séries</span>
        </a>
        <button type="button" id="bn-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg>
            <span>Rechercher</span>
        </button>
        <a href="/library" data-link data-nav="/library" data-auth-only>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
            <span>Ma liste</span>
        </a>
    </nav>

    <footer class="site-footer">
    </footer>

    <div class="modal-root" id="modal-root" hidden></div>
    <div class="toast-root" id="toast-root" aria-live="assertive"></div>
</body>
</html>
