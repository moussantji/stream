<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b0b0f">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'MovieBox Stream') }}</title>
    <meta name="description" content="Search, browse and stream movies and TV series.">
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
                <a href="/animation" data-link data-nav="/animation">Animation</a>
                <a href="/tv" data-link data-nav="/tv">TV en direct</a>
                <a href="/populaires" data-link data-nav="/populaires">Populaires</a>
                <a href="/library" data-link data-nav="/library" data-auth-only>Ma liste</a>
            </nav>

            <form class="search-box" id="search-form" role="search" autocomplete="off">
                <svg viewBox="0 0 24 24" class="icon"><path d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <input type="search" id="search-input" name="q" placeholder="Rechercher films &amp; séries…" aria-label="Rechercher">
                <div class="suggestions" id="suggestions" hidden></div>
            </form>

            <div class="auth-area" id="auth-area">
                <!-- populated by JS -->
            </div>
        </div>
    </header>

    <main id="app" class="app-main" aria-live="polite"></main>

    <footer class="site-footer">
        <p>All media and images are sourced from third-party providers on the internet, and their copyrights belong to their original creators. This project stores no content and is provided for educational purposes only.</p>
    </footer>

    <div class="modal-root" id="modal-root" hidden></div>
    <div class="toast-root" id="toast-root" aria-live="assertive"></div>
</body>
</html>
