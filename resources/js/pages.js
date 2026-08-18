// Page renderers for the SPA.
import { api, isAuthed } from './api.js';
import {
    el, clear, row, grid, card, carousel, skeletonRow, loadingState, errorState, emptyState,
    navigate, watchHref, detailHref, toast, openAuthModal, displayTitle, blurPlaceholder,
} from './ui.js';
import { Player, attachHls } from './player.js';

// ---------- helpers ----------
function fmtDuration(seconds) {
    if (!seconds) return null;
    const h = Math.floor(seconds / 3600);
    const m = Math.round((seconds % 3600) / 60);
    return h ? `${h}h ${m}m` : `${m}m`;
}

function historyToItem(h) {
    return {
        subjectId: h.subject_id,
        subjectType: h.subject_type,
        title: h.title,
        cover: h.cover,
        detailPath: h.detail_path,
        typeLabel: h.subject_type === 2 ? 'TV Series' : 'Movie',
    };
}

// Muted, autoplaying, looping trailer video used as a hero background.
// Falls back to the poster image if the video errors.
function trailerVideo(url, poster, cls) {
    const v = el('video', { class: cls, loop: '', playsinline: '', preload: 'metadata' });
    v.muted = true;
    v.autoplay = true;
    v.setAttribute('muted', '');
    v.setAttribute('autoplay', '');
    if (poster) v.poster = poster;

    const fallbackToImage = () => { if (poster) v.replaceWith(el('img', { class: cls, src: poster, alt: '' })); };

    if (/\.m3u8(\?|$)/i.test(url)) {
        attachHls(v, url).catch(fallbackToImage);
    } else {
        v.src = url;
    }
    v.addEventListener('error', fallbackToImage, { once: true });
    v.play?.().catch(() => {});
    return v;
}

const PLAY_SVG = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
const PLUS_SVG = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>';
const CHECK_SVG = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

// ---------- HOME ----------
export async function homePage(app) {
    clear(app);
    app.appendChild(skeletonRow());
    app.appendChild(skeletonRow());

    const [homeRes, trendingRes, historyRes] = await Promise.allSettled([
        api.home(1),
        api.trending(1),
        isAuthed() ? api.history() : Promise.resolve([]),
    ]);

    clear(app);

    const sections = homeRes.status === 'fulfilled' ? (homeRes.value.sections || []) : [];
    const trending = trendingRes.status === 'fulfilled' ? (trendingRes.value.items || []) : [];

    if (!sections.length && !trending.length) {
        app.appendChild(errorState(
            homeRes.reason?.message || 'Could not load content from the provider.',
            () => homePage(app),
        ));
        return;
    }

    const heroItem = (sections[0]?.items || trending)[0];
    if (heroItem) {
        const heroNode = hero(heroItem);
        app.appendChild(heroNode);
        loadHeroTrailer(heroNode);
    }

    if (historyRes.status === 'fulfilled' && historyRes.value.length) {
        app.appendChild(continueRow(historyRes.value));
    }

    if (trending.length) app.appendChild(row('Les plus regardés', trending));
    sections.forEach((s) => app.appendChild(row(s.title, s.items)));

    // Infinite scroll: keep fetching the next home page and appending rows.
    const sentinel = el('div', { class: 'infinite-sentinel' });
    app.appendChild(sentinel);

    let page = 2;
    let loading = false;
    let done = !homeRes.value?.pager?.hasMore;
    let observer = null;
    const seen = new Set();

    const loadMore = async () => {
        if (loading || done) return;
        loading = true;
        sentinel.appendChild(el('div', { class: 'infinite-loader' }, [el('div', { class: 'spinner' })]));
        try {
            const data = await api.home(page);
            const next = data.sections || [];
            if (!next.length || !data.pager?.hasMore) done = true;
            next.forEach((s) => {
                const items = (s.items || []).filter((i) => {
                    const id = i.subjectId ?? i.title;
                    if (seen.has(id)) return false;
                    seen.add(id);
                    return true;
                });
                if (items.length) app.insertBefore(row(s.title, items), sentinel);
            });
            page += 1;
        } catch {
            done = true;
        } finally {
            clear(sentinel);
            loading = false;
        }
    };

    observer = onReachBottom(sentinel, loadMore);
}

function hero(item) {
    const meta = [];
    if (item.typeLabel) meta.push(el('span', { class: 'badge', text: item.typeLabel }));
    if (item.year) meta.push(el('span', { text: String(item.year) }));
    if (item.imdbRating) meta.push(el('span', { class: 'rating', text: `★ ${item.imdbRating}` }));
    if (item.genres?.length) meta.push(el('span', { text: item.genres.slice(0, 3).join(' · ') }));

    const bg = el('div', { class: 'hero-bg-wrap' }, [
        item.coverHash ? blurPlaceholder(item.coverHash, displayTitle(item), 160, 240) : null,
        item.cover ? el('img', { class: 'hero-bg', src: item.cover, alt: '', fetchpriority: 'high', decoding: 'async' }) : null,
    ]);
    const section = el('section', { class: 'hero' }, [
        bg,
        el('div', { class: 'hero-content' }, [
            el('h1', { class: 'hero-title', text: displayTitle(item) }),
            el('div', { class: 'hero-meta' }, meta),
            item.description ? el('p', { class: 'hero-desc', text: item.description }) : null,
            el('div', { class: 'hero-actions' }, [
                el('button', { class: 'btn btn-primary', html: `${PLAY_SVG} <span>Lecture</span>`, style: 'display:flex;gap:8px;align-items:center', onclick: () => navigate(watchHref(item)) }),
                el('button', { class: 'btn btn-ghost', text: "Plus d'infos", onclick: () => navigate(detailHref(item)) }),
            ]),
        ]),
    ]);
    section.__bg = bg;
    section.__item = item;
    return section;
}

// Fetch the hero item's trailer and swap the background image for a video.
async function loadHeroTrailer(section) {
    const item = section.__item;
    if (!item) return;
    try {
        const d = await api.detail({ subjectId: item.subjectId, subjectType: item.subjectType, title: item.title, cover: item.cover });
        if (d && d.trailer && section.isConnected) {
            const video = trailerVideo(d.trailer, item.cover, 'hero-bg');
            section.__bg.replaceWith(video);
            section.__bg = video;
        }
    } catch { /* keep the poster image */ }
}

function continueRow(history) {
    const cards = history.map((h) => {
        const item = historyToItem(h);
        const progress = h.duration_seconds > 0 ? (h.position_seconds / h.duration_seconds) * 100 : 0;
        const node = card(item, { progress });
        node.onclick = () => navigate(watchHref(item, h.season, h.episode));
        return node;
    });
    return el('section', { class: 'row container' }, [
        el('h2', { class: 'section-title', text: 'Reprendre la lecture' }),
        carousel(cards),
    ]);
}

// Observe a sentinel element and invoke cb() when it nears the viewport.
// IntersectionObserver is used when available; a scroll/resize fallback keeps
// infinite scroll working on limited WebKit (SmartTV) browsers.
function onReachBottom(sentinel, cb) {
    let observer = null;
    if (typeof IntersectionObserver === 'function') {
        observer = new IntersectionObserver((entries) => {
            if (entries.some((e) => e.isIntersecting)) cb();
        }, { rootMargin: '600px 0px' });
        observer.observe(sentinel);
    }

    const check = () => {
        const r = sentinel.getBoundingClientRect();
        if (r.top < window.innerHeight + 600) cb();
    };
    window.addEventListener('scroll', check, { passive: true });
    window.addEventListener('resize', check, { passive: true });
    // FIRES once now so short pages still trigger the first load on browsers
    // whose IntersectionObserver never fires for an already-visible element.
    requestAnimationFrame(check);

    return {
        disconnect() {
            if (observer) { observer.disconnect(); observer = null; }
            window.removeEventListener('scroll', check);
            window.removeEventListener('resize', check);
        },
    };
}

// Paginated grid with ADVANCED client-side filters (genre / year / min rating /
// sort) + INFINITE SCROLL. fetchPage(page) -> { items, pager: { hasMore } }.
function filterableGrid(container, fetchPage, { emptyMsg = 'Rien à afficher.' } = {}) {
    const genreSel = el('select', { class: 'select' }, [el('option', { value: '', text: 'Tous les genres' })]);
    const yearSel = el('select', { class: 'select' }, [el('option', { value: '', text: 'Toutes les années' })]);
    const ratingSel = el('select', { class: 'select' }, [
        el('option', { value: '', text: 'Note : toutes' }),
        el('option', { value: '5', text: '5+' }),
        el('option', { value: '6', text: '6+' }),
        el('option', { value: '7', text: '7+' }),
        el('option', { value: '8', text: '8+' }),
    ]);
    const sortSel = el('select', { class: 'select' }, [
        el('option', { value: 'relevance', text: 'Par défaut' }),
        el('option', { value: 'rating', text: 'Mieux notés' }),
        el('option', { value: 'rating_asc', text: 'Moins bien notés' }),
        el('option', { value: 'year_desc', text: 'Plus récents' }),
        el('option', { value: 'year_asc', text: 'Plus anciens' }),
        el('option', { value: 'title', text: 'Titre (A→Z)' }),
        el('option', { value: 'title_desc', text: 'Titre (Z→A)' }),
    ]);
    container.appendChild(el('div', { class: 'filter-bar' }, [
        el('label', { class: 'filter' }, [el('span', { text: 'Genre' }), genreSel]),
        el('label', { class: 'filter' }, [el('span', { text: 'Année' }), yearSel]),
        el('label', { class: 'filter' }, [el('span', { text: 'Note min.' }), ratingSel]),
        el('label', { class: 'filter' }, [el('span', { text: 'Trier' }), sortSel]),
    ]));

    const gridWrap = el('div', {}, [skeletonRow()]);
    const sentinel = el('div', { class: 'infinite-sentinel' });
    container.appendChild(gridWrap);
    container.appendChild(sentinel);

    let page = 1;
    let loading = false;
    let done = false;
    let observer = null;
    let all = [];

    const stop = () => { done = true; if (observer) { observer.disconnect(); observer = null; } clear(sentinel); };

    const populateFilters = () => {
        const genres = new Set();
        const years = new Set();
        all.forEach((it) => { (it.genres || []).forEach((g) => genres.add(g)); if (it.year) years.add(it.year); });

        const kg = genreSel.value;
        clear(genreSel);
        genreSel.appendChild(el('option', { value: '', text: 'Tous les genres' }));
        [...genres].sort((a, b) => a.localeCompare(b)).forEach((g) => genreSel.appendChild(el('option', { value: g, text: g })));
        genreSel.value = kg;

        const ky = yearSel.value;
        clear(yearSel);
        yearSel.appendChild(el('option', { value: '', text: 'Toutes les années' }));
        [...years].sort((a, b) => b - a).forEach((y) => yearSel.appendChild(el('option', { value: String(y), text: String(y) })));
        yearSel.value = ky;
    };

    const render = () => {
        let items = all.slice();
        if (genreSel.value) items = items.filter((it) => (it.genres || []).includes(genreSel.value));
        if (yearSel.value) items = items.filter((it) => String(it.year) === yearSel.value);
        if (ratingSel.value) items = items.filter((it) => (it.imdbRating || 0) >= parseFloat(ratingSel.value));

        switch (sortSel.value) {
            case 'rating': items.sort((a, b) => (b.imdbRating || 0) - (a.imdbRating || 0)); break;
            case 'rating_asc': items.sort((a, b) => (a.imdbRating || 0) - (b.imdbRating || 0)); break;
            case 'year_desc': items.sort((a, b) => (b.year || 0) - (a.year || 0)); break;
            case 'year_asc': items.sort((a, b) => (a.year || 0) - (b.year || 0)); break;
            case 'title': items.sort((a, b) => (a.title || '').localeCompare(b.title || '')); break;
            case 'title_desc': items.sort((a, b) => (b.title || '').localeCompare(a.title || '')); break;
            default: break;
        }

        clear(gridWrap);
        if (!all.length) gridWrap.appendChild(emptyState(emptyMsg));
        else gridWrap.appendChild(items.length ? grid(items) : emptyState('Aucun résultat avec ces filtres.'));
    };

    [genreSel, yearSel, ratingSel, sortSel].forEach((s) => { s.onchange = render; });

    const load = async () => {
        if (loading || done) return;
        loading = true;
        clear(sentinel);
        sentinel.appendChild(el('div', { class: 'infinite-loader' }, [el('div', { class: 'spinner' })]));
        try {
            const data = await fetchPage(page);
            all = all.concat(data.items || []);
            populateFilters();
            render();
            page += 1;
            clear(sentinel);
            if (!data.pager || !data.pager.hasMore) stop();
        } catch (e) {
            clear(sentinel);
            sentinel.appendChild(errorState(e.message, () => { loading = false; load(); }));
        } finally {
            loading = false;
        }
    };

    observer = onReachBottom(sentinel, load);
    load();
}

// ---------- TRENDING / POPULAIRES ----------
export async function trendingPage(app, title = 'Les plus regardés') {
    clear(app);
    const container = el('div', { class: 'container' }, [el('h2', { class: 'section-title', text: title })]);
    app.appendChild(container);
    filterableGrid(container, (page) => api.trending(page), { emptyMsg: 'Rien à afficher pour le moment.' });
}

// ---------- CATEGORY (Films / Séries) ----------
export async function categoryPage(app, tab, title) {
    clear(app);
    const container = el('div', { class: 'container' }, [el('h2', { class: 'section-title', text: title })]);
    app.appendChild(container);
    filterableGrid(container, (page) => api.category(tab, page), { emptyMsg: 'Aucun contenu pour le moment.' });
}

// ---------- LOCAL CATALOG (MySQL) ----------
export async function localPage(app, params) {
    const type = params.get('type') || 'all';
    clear(app);

    const container = el('div', { class: 'container' });
    app.appendChild(container);
    container.appendChild(el('h2', { class: 'section-title', text: 'Catalogue local' }));

    const filters = [['all', 'Tous'], ['movies', 'Films'], ['tv-series', 'Séries'], ['animation', 'Animation']];
    container.appendChild(el('div', { class: 'season-tabs' }, filters.map(([t, label]) =>
        el('button', {
            class: `season-tab ${t === type ? 'active' : ''}`,
            text: label,
            onclick: () => navigate(`/local?type=${t}`),
        }))));

    const info = el('p', { style: 'color:var(--text-dim);margin:0 0 12px' });
    const gridWrap = el('div', {}, [skeletonRow()]);
    const sentinel = el('div', { class: 'infinite-sentinel' });
    container.appendChild(info);
    container.appendChild(gridWrap);
    container.appendChild(sentinel);

    let page = 1;
    let gridEl = null;
    let loading = false;
    let done = false;
    let observer = null;

    const stop = () => { done = true; if (observer) { observer.disconnect(); observer = null; } clear(sentinel); };

    const load = async () => {
        if (loading || done) return;
        loading = true;
        clear(sentinel);
        sentinel.appendChild(el('div', { class: 'infinite-loader' }, [el('div', { class: 'spinner' })]));
        try {
            const data = await api.local({ type, page });
            info.textContent = `${data.total} titre(s) enregistré(s) dans ta base`;

            if (!data.items.length && page === 1) {
                clear(gridWrap);
                gridWrap.appendChild(emptyState('Base locale vide', 'Navigue sur le site : chaque titre affiché est enregistré ici automatiquement.'));
                stop();
                return;
            }

            if (!gridEl) { gridEl = grid(data.items); clear(gridWrap); gridWrap.appendChild(gridEl); }
            else { data.items.forEach((it) => gridEl.appendChild(card(it))); }

            page += 1;
            clear(sentinel);
            if (!data.pager || !data.pager.hasMore) stop();
        } catch (e) {
            clear(sentinel);
            sentinel.appendChild(errorState(e.message, () => { loading = false; load(); }));
        } finally {
            loading = false;
        }
    };

    observer = onReachBottom(sentinel, load);
    await load();
}

// ---------- LIVE TV CHANNELS ----------
export async function channelsPage(app) {
    clear(app);
    app.appendChild(el('div', { class: 'container' }, [el('h2', { class: 'section-title', text: 'TV en direct' }), loadingState()]));

    let data;
    try {
        data = await api.channels();
    } catch (e) {
        clear(app);
        app.appendChild(errorState(e.message, () => channelsPage(app)));
        return;
    }

    clear(app);
    const container = el('div', { class: 'container' });
    app.appendChild(container);
    container.appendChild(el('h2', { class: 'section-title', text: 'TV en direct' }));

    if (!data.channels || !data.channels.length) {
        container.appendChild(emptyState(
            'Aucune chaîne disponible',
            "Le fournisseur n'expose pas de chaînes en direct pour cette région pour le moment.",
        ));
        return;
    }

    const nowPlaying = el('div', { class: 'watch-title', style: 'display:none' });
    const shell = el('div', { class: 'player-shell', style: 'display:none;margin-bottom:20px' });
    container.appendChild(nowPlaying);
    container.appendChild(shell);

    let player = null;
    const gridEl = el('div', { class: 'channel-grid' });

    data.channels.forEach((ch) => {
        const node = el('div', {
            class: `channel-card ${ch.url ? '' : 'disabled'}`,
            role: 'button', tabindex: '0',
        }, [
            ch.cover
                ? el('img', { src: ch.cover, alt: ch.title, loading: 'lazy' })
                : el('div', { class: 'ph', text: ch.title }),
            el('div', { class: 'channel-name', text: ch.title }),
            ch.url ? null : el('div', { class: 'channel-badge', text: 'Indisponible' }),
        ]);

        if (ch.url) {
            node.onclick = async () => {
                nowPlaying.style.display = '';
                nowPlaying.textContent = `En direct — ${ch.title}`;
                shell.style.display = '';
                clear(shell);
                if (player) player.destroy();
                player = new Player(shell);
                const isHls = /\.m3u8(\?|$)/i.test(ch.url);
                try {
                    await player.load(isHls
                        ? { hls: [ch.url] }
                        : { sources: [{ url: ch.url, quality: 'auto', resolution: 0 }] });
                    player.play();
                    shell.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } catch (err) {
                    clear(shell);
                    shell.appendChild(el('div', { class: 'player-message' }, [emptyState('Lecture impossible', err.message)]));
                }
            };
        }

        gridEl.appendChild(node);
    });

    container.appendChild(gridEl);
}

// ---------- SEARCH ----------
export async function searchPage(app, params) {
    const q = params.get('q') || '';
    const type = params.get('type') || 'all';
    clear(app);

    const container = el('div', { class: 'container' });
    app.appendChild(container);
    container.appendChild(el('h2', { class: 'section-title', text: `Résultats pour « ${q} »` }));

    // Type filter (server-side): switching reloads the query.
    container.appendChild(el('div', { class: 'season-tabs' }, [['all', 'Tous'], ['movies', 'Films'], ['tv-series', 'Séries']].map(([t, label]) =>
        el('button', {
            class: `season-tab ${t === type ? 'active' : ''}`,
            text: label,
            onclick: () => navigate(`/search?q=${encodeURIComponent(q)}&type=${t}`),
        }))));

    if (!q) {
        container.appendChild(emptyState('Saisis un mot-clé pour lancer une recherche.'));
        return;
    }

    // Search is intentionally NOT content-filtered server-side, so hidden
    // categories (sex/animation) remain reachable here.
    filterableGrid(container, (page) => api.search(q, type, page), { emptyMsg: 'Aucun résultat pour cette recherche.' });
}

// ---------- DETAIL ----------
export async function detailPage(app, params) {
    clear(app);

    // Progressive paint: the title/cover are already in the URL, so show them
    // immediately while the full detail (metadata, seasons, cast) loads.
    const hint = {
        subjectId: params.get('subjectId'),
        subjectType: params.get('subjectType') || 0,
        title: params.get('title') || undefined,
        cover: params.get('cover') || undefined,
    };
    if (hint.cover || hint.title) {
        app.appendChild(el('div', { class: 'detail-progressive' }, [
            hint.cover ? el('img', { class: 'detail-prog-poster', src: hint.cover, alt: '', decoding: 'async' }) : el('div', { class: 'ph' }),
            el('div', { class: 'detail-prog-info' }, [
                hint.title ? el('h1', { class: 'detail-title', text: displayTitle(hint) }) : null,
                loadingState('Chargement des détails…'),
            ]),
        ]));
    } else {
        app.appendChild(loadingState());
    }

    const query = {
        subjectId: params.get('subjectId'),
        detailPath: params.get('detailPath'),
        subjectType: params.get('subjectType') || 0,
        title: params.get('title') || undefined,
        cover: params.get('cover') || undefined,
    };

    let data;
    try {
        data = await api.detail(query);
    } catch (e) {
        clear(app);
        app.appendChild(errorState(e.message, () => detailPage(app, params)));
        return;
    }

    // First paint may be the fast local payload (no seasons/cast yet) while the
    // server warms the full build in the background — silently refetch once so
    // the enriched data (seasons, cast, trailer) replaces it when ready.
    if (!data.detailAvailable) {
        setTimeout(async () => {
            if (!app.isConnected) return;
            try {
                const richer = await api.detail(query);
                if (richer && richer.detailAvailable) renderDetail(app, richer, params);
            } catch { /* keep the fast paint */ }
        }, 12000);
    }

    renderDetail(app, data, params);
}

// Full detail view: metadata, seasons, cast, recommendations, player warm.
async function renderDetail(app, data, params) {
    const { item, isSeries, seasons, cast, recommendations } = data;
    const trailer = data.trailer;
    clear(app);

    // Favorite state
    let favorites = [];
    if (isAuthed()) {
        try { favorites = await api.favorites(); } catch { /* ignore */ }
    }
    const isFav = favorites.some((f) => f.subject_id === item.subjectId);

    const meta = [];
    if (item.year) meta.push(el('span', { class: 'chip', text: String(item.year) }));
    if (item.imdbRating) meta.push(el('span', { class: 'chip rating', text: `★ ${item.imdbRating}` }));
    if (item.durationSeconds) meta.push(el('span', { class: 'chip', text: fmtDuration(item.durationSeconds) }));
    if (item.country) meta.push(el('span', { class: 'chip', text: item.country }));
    (item.genres || []).slice(0, 4).forEach((g) => meta.push(el('span', { class: 'chip', text: g })));

    const favBtn = el('button', {
        class: `btn btn-ghost fav-btn ${isFav ? 'active' : ''}`,
        html: `${isFav ? CHECK_SVG : PLUS_SVG} <span>${isFav ? 'In My List' : 'My List'}</span>`,
        style: 'display:flex;gap:8px;align-items:center',
        onclick: () => toggleFavorite(favBtn, item),
    });

    const firstSeason = seasons[0];
    const playLabel = isSeries ? `Play S${firstSeason?.season ?? 1} E1` : 'Play';

    // Audio versions (dubs). Each dub is a separate subjectId, so switching
    // language means playing a different subject. Default to French if present.
    const dubs = data.dubs || [];
    const frenchDub = dubs.find((d) => (d.code || '').startsWith('fr') || /fran/i.test(d.label || ''));
    let activeSubjectId = frenchDub ? frenchDub.subjectId : item.subjectId;
    const currentItem = () => ({ ...item, subjectId: activeSubjectId });

    const play = () => navigate(isSeries && firstSeason
        ? watchHref(currentItem(), firstSeason.season, 1)
        : watchHref(currentItem()));

    // Warm the streaming cache in the background so the player starts instantly
    // when "Lecture" is clicked — /api/play is cached server-side, so this turns
    // a multi-second cold resolution into a cache hit on the watch page.
    setTimeout(() => {
        const target = isSeries && firstSeason
            ? { season: firstSeason.season, episode: 1 }
            : { season: 0, episode: 0 };
        api.play({ ...currentItem(), ...target }).catch(() => {});
    }, 100);

    let versionRow = null;
    if (dubs.length > 1) {
        const buttons = dubs.map((d) => el('button', {
            class: `season-tab ${d.subjectId === activeSubjectId ? 'active' : ''}`,
            text: d.label + (d.original ? ' (VO)' : ''),
            onclick: (e) => {
                activeSubjectId = d.subjectId;
                versionRow.querySelectorAll('.season-tab').forEach((b) => b.classList.remove('active'));
                e.target.classList.add('active');
            },
        }));
        versionRow = el('div', { style: 'margin-bottom:16px' }, [
            el('div', { class: 'section-title', style: 'font-size:15px;margin:0 0 8px', text: 'Version / Langue' }),
            el('div', { class: 'season-tabs' }, buttons),
        ]);
    }

    const info = el('div', { class: 'detail-info' }, [
        el('h1', { class: 'detail-title', text: displayTitle(item) }),
        el('div', { class: 'detail-meta' }, [el('span', { class: 'badge', text: item.typeLabel }), ...meta]),
        item.description ? el('p', { class: 'detail-desc', text: item.description }) : null,
        versionRow,
        el('div', { class: 'detail-actions' }, [
            el('button', { class: 'btn btn-primary', html: `${PLAY_SVG} <span>${playLabel}</span>`, style: 'display:flex;gap:8px;align-items:center', onclick: play }),
            favBtn,
        ]),
    ]);

    const poster = el('div', { class: 'detail-poster' }, [
        item.coverHash ? blurPlaceholder(item.coverHash, displayTitle(item), 96, 144) : null,
        item.cover ? el('img', { src: item.coverSmall || item.cover, alt: displayTitle(item), loading: 'eager', decoding: 'async', width: '96', height: '144', class: 'cover-fade', onload: (e) => e.target.classList.add('loaded') }) : el('div', { class: 'ph' }),
    ]);

    const heroBg = el('div', { class: 'detail-hero-bg-wrap' }, [
        item.coverHash ? blurPlaceholder(item.coverHash, displayTitle(item), 160, 240) : null,
        trailer
            ? trailerVideo(trailer, item.cover, 'detail-hero-bg')
            : (item.cover ? el('img', { class: 'detail-hero-bg', src: item.cover, alt: '' }) : null),
    ]);

    app.appendChild(el('section', { class: 'detail-hero' }, [
        heroBg,
        el('div', { class: 'detail-hero-overlay' }),
        el('div', { class: 'container' }, [poster, info]),
    ]));

    const body = el('div', { class: 'container' });
    app.appendChild(body);

    // Seasons / episodes
    if (isSeries && seasons.length) {
        body.appendChild(el('h2', { class: 'section-title', text: 'Episodes' }));
        body.appendChild(episodesBlock(currentItem, seasons));
    }

    // Cast
    if (cast && cast.length) {
        body.appendChild(el('h2', { class: 'section-title', text: 'Cast' }));
        body.appendChild(el('div', { class: 'cast-row' }, cast.slice(0, 20).map((c) =>
            el('div', { class: 'cast-card' }, [
                c.avatar ? el('img', { src: c.avatar, alt: c.name, loading: 'lazy' }) : el('div', { class: 'ph' }),
                el('div', { class: 'cast-name', text: c.name || '' }),
                el('div', { class: 'cast-char', text: c.character || '' }),
            ]))));
    }

    // Recommendations
    if (recommendations && recommendations.length) {
        app.appendChild(row('More Like This', recommendations));
    }

    // Infinite suggestions grid: keeps loading similar titles (genre-based)
    // as the user scrolls the detail page.
    const genres = (item.genres || []).join('|');
    const sugWrap = el('section', { class: 'container' }, [
        el('h2', { class: 'section-title', text: 'Suggestions' }),
    ]);
    const sugGrid = el('div', { class: 'grid' });
    const sugSentinel = el('div', { class: 'infinite-sentinel' });
    sugWrap.appendChild(sugGrid);
    sugWrap.appendChild(sugSentinel);
    app.appendChild(sugWrap);

    let sugPage = 1;
    let sugLoading = false;
    let sugDone = false;
    let sugObserver = null;
    const sugSeen = new Set((recommendations || []).map((r) => r.subjectId ?? r.title));

    const loadSuggestions = async () => {
        if (sugLoading || sugDone) return;
        sugLoading = true;
        sugSentinel.appendChild(el('div', { class: 'infinite-loader' }, [el('div', { class: 'spinner' })]));
        try {
            const data = await api.suggestions({ subjectId: item.subjectId, subjectType: item.subjectType, genres, page: sugPage });
            const items = (data.items || []).filter((i) => {
                const id = i.subjectId ?? i.title;
                if (sugSeen.has(id)) return false;
                sugSeen.add(id);
                return true;
            });
            if (items.length) items.forEach((i) => sugGrid.appendChild(card(i)));
            if (!items.length || !data.pager?.hasMore) sugDone = true;
            sugPage += 1;
        } catch {
            sugDone = true;
        } finally {
            clear(sugSentinel);
            sugLoading = false;
        }
    };

    sugObserver = onReachBottom(sugSentinel, loadSuggestions);
    loadSuggestions();
}

function episodesBlock(getItem, seasons) {
    const wrap = el('div', { class: 'episodes' });
    const gridEl = el('div', { class: 'episode-grid' });

    const renderSeason = (season) => {
        clear(gridEl);
        season.episodes.forEach((ep) => {
            gridEl.appendChild(el('button', {
                class: 'episode-btn', text: `E${ep}`,
                onclick: () => navigate(watchHref(getItem(), season.season, ep)),
            }));
        });
    };

    if (seasons.length > 1) {
        const tabs = el('div', { class: 'season-tabs' }, seasons.map((s, i) =>
            el('button', {
                class: `season-tab ${i === 0 ? 'active' : ''}`,
                text: `Season ${s.season}`,
                onclick: (e) => {
                    wrap.querySelectorAll('.season-tab').forEach((t) => t.classList.remove('active'));
                    e.target.classList.add('active');
                    renderSeason(s);
                },
            })));
        wrap.appendChild(tabs);
    }

    wrap.appendChild(gridEl);
    if (seasons[0]) renderSeason(seasons[0]);
    return wrap;
}

async function toggleFavorite(btn, item) {
    if (!isAuthed()) { openAuthModal('login'); return; }
    const active = btn.classList.contains('active');
    try {
        if (active) {
            await api.removeFavorite(item.subjectId);
            btn.classList.remove('active');
            btn.innerHTML = `${PLUS_SVG} <span>My List</span>`;
            toast('Removed from your list.');
        } else {
            await api.addFavorite({
                subject_id: item.subjectId,
                subject_type: item.subjectType,
                title: item.title,
                cover: item.cover,
                detail_path: item.detailPath,
            });
            btn.classList.add('active');
            btn.innerHTML = `${CHECK_SVG} <span>In My List</span>`;
            toast('Added to your list.');
        }
    } catch (e) {
        toast(e.message, 'error');
    }
}

// ---------- WATCH ----------
export async function watchPage(app, params) {
    const item = {
        subjectId: params.get('subjectId'),
        detailPath: params.get('detailPath'),
        subjectType: Number(params.get('subjectType') || 0),
        title: params.get('title'),
        cover: params.get('cover'),
    };
    const season = Number(params.get('season') || 0);
    const episode = Number(params.get('episode') || 0);

    clear(app);

    const shell = el('div', { class: 'player-shell' }, [loadingState('Préparation du flux…')]);
    const toolbar = el('div', { class: 'player-toolbar' });
    const shortTitle = displayTitle(item);
    const label = season > 0 ? `${shortTitle} — S${season} E${episode}` : shortTitle;

    // Left column: video player + toolbar.
    const main = el('div', { class: 'watch-main' }, [
        el('h1', { class: 'watch-title', text: label || 'Lecture en cours' }),
        shell,
        toolbar,
    ]);

    // Right column: seasons / episodes selector (series only).
    const layout = el('div', { class: 'watch-layout' }, [main]);
    if (season > 0) {
        const side = el('aside', { class: 'watch-side' });
        layout.appendChild(side);
        buildEpisodeSidebar(side, item, season, episode);
    } else {
        layout.classList.add('solo');
    }

    app.appendChild(el('div', { class: 'watch-wrap' }, [
        el('button', { class: 'btn btn-ghost', text: '← Retour', onclick: () => navigate(detailHref(item)) }),
        layout,
    ]));

    let startTime = 0;
    const playP = api.play({ subjectId: item.subjectId, detailPath: item.detailPath, season, episode, title: item.title });

    // Resume position is looked up in parallel with the stream resolution so it
    // never delays the player start (play is the slow part, not the DB).
    const histP = isAuthed()
        ? api.history().then((hist) => {
            const match = hist.find((h) => h.subject_id === item.subjectId && h.season === season && h.episode === episode);
            if (match) startTime = match.position_seconds || 0;
        }).catch(() => {})
        : Promise.resolve();

    let data;
    try {
        [data] = await Promise.all([playP, histP]);
    } catch (e) {
        clear(shell);
        shell.appendChild(el('div', { class: 'player-message' }, [errorState(e.message, () => watchPage(app, params))]));
        return;
    }

    if (!data.sources.length && !data.hls.length && !(data.dash && data.dash.length)) {
        clear(shell);
        if (data.streamError) {
            // The upstream provider errored (timeout / rate-limit / 5xx) — offer a retry.
            const retryBtn = el('button', {
                class: 'btn btn-primary',
                text: 'Réessayer',
                style: 'display:flex;gap:8px;align-items:center;margin-top:12px',
                onclick: () => watchPage(app, params),
            });
            shell.appendChild(el('div', { class: 'player-message' }, [
                emptyState(
                    data.streamError.retryable ? 'Le fournisseur n\'a pas répondu' : 'Flux indisponible',
                    data.streamError.retryable
                        ? 'Le service de streaming est temporairement indisponible. Réessayez dans un instant.'
                        : 'Ce titre est indisponible chez le fournisseur pour le moment.',
                ),
                retryBtn,
            ]));
        } else {
            shell.appendChild(el('div', { class: 'player-message' }, [
                emptyState('No stream available', 'This title may be restricted by the provider or only available in the app.'),
            ]));
        }
        return;
    }

    clear(shell);
    const player = new Player(shell);

    const onProgress = (position, duration) => {
        if (!isAuthed()) return;
        api.saveHistory({
            subject_id: item.subjectId,
            subject_type: item.subjectType,
            title: item.title,
            cover: item.cover,
            detail_path: item.detailPath,
            season, episode,
            position_seconds: position,
            duration_seconds: duration,
        }).catch(() => {});
    };

    try {
        await player.load({ ...data, startTime, onProgress });
        player.play();
    } catch (e) {
        clear(shell);
        shell.appendChild(el('div', { class: 'player-message' }, [emptyState('Playback error', e.message)]));
        return;
    }

    // Quality, subtitles, speed and fullscreen are handled inside the player's
    // own control bar now.

    // Clean up when navigating away
    const cleanup = () => { player.destroy(); window.removeEventListener('popstate', cleanup); };
    window.addEventListener('popstate', cleanup);
}

// Builds the seasons/episodes selector shown to the right of the player.
async function buildEpisodeSidebar(side, item, activeSeason, activeEpisode) {
    clear(side);
    side.appendChild(el('h3', { text: 'Saisons & épisodes' }));

    const body = el('div', {}, [loadingState('Chargement des épisodes…')]);
    side.appendChild(body);

    let seasons = [];
    try {
        const data = await api.detail({
            subjectId: item.subjectId,
            detailPath: item.detailPath,
            subjectType: item.subjectType,
            title: item.title,
            cover: item.cover,
        });
        seasons = data.seasons || [];
    } catch {
        clear(body);
        body.appendChild(emptyState('Épisodes indisponibles'));
        return;
    }

    if (!seasons.length) {
        // The first visit is painted from the fast local payload while the
        // server warms the full build — retry once so the episode list fills
        // in as soon as the enriched snapshot is ready.
        setTimeout(async () => {
            if (!side.isConnected) return;
            try {
                const richer = await api.detail({
                    subjectId: item.subjectId,
                    detailPath: item.detailPath,
                    subjectType: item.subjectType,
                    title: item.title,
                    cover: item.cover,
                });
                const full = richer?.seasons?.length ? richer.seasons : [];
                if (!full.length) return;

                clear(body);
                const retryWrap = el('div', { class: 'episode-list' });
                const retryRender = (season) => {
                    clear(retryWrap);
                    season.episodes.forEach((ep) => {
                        retryWrap.appendChild(el('button', {
                            class: `episode-btn ${season.season === activeSeason && ep === activeEpisode ? 'active' : ''}`,
                            text: `E${ep}`,
                            onclick: () => navigate(watchHref(item, season.season, ep)),
                        }));
                    });
                };
                if (full.length > 1) {
                    const tabs = el('div', { class: 'season-tabs' }, full.map((s) =>
                        el('button', {
                            class: `season-tab ${s.season === activeSeason ? 'active' : ''}`,
                            text: `Saison ${s.season}`,
                            onclick: (e) => {
                                tabs.querySelectorAll('.season-tab').forEach((t) => t.classList.remove('active'));
                                e.target.classList.add('active');
                                retryRender(s);
                            },
                        })));
                    body.appendChild(tabs);
                }
                body.appendChild(retryWrap);
                const current = full.find((s) => s.season === activeSeason) || full[0];
                retryRender(current);
            } catch { /* keep the empty state */ }
        }, 12000);

        clear(body);
        body.appendChild(emptyState('Aucun épisode à afficher'));
        return;
    }

    clear(body);

    const listWrap = el('div', { class: 'episode-list' });

    const renderSeason = (season) => {
        clear(listWrap);
        season.episodes.forEach((ep) => {
            const isActive = season.season === activeSeason && ep === activeEpisode;
            listWrap.appendChild(el('button', {
                class: `episode-btn ${isActive ? 'active' : ''}`,
                text: `E${ep}`,
                onclick: () => navigate(watchHref(item, season.season, ep)),
            }));
        });
    };

    if (seasons.length > 1) {
        const tabs = el('div', { class: 'season-tabs' }, seasons.map((s) =>
            el('button', {
                class: `season-tab ${s.season === activeSeason ? 'active' : ''}`,
                text: `Saison ${s.season}`,
                onclick: (e) => {
                    tabs.querySelectorAll('.season-tab').forEach((t) => t.classList.remove('active'));
                    e.target.classList.add('active');
                    renderSeason(s);
                },
            })));
        body.appendChild(tabs);
    }

    body.appendChild(listWrap);

    const current = seasons.find((s) => s.season === activeSeason) || seasons[0];
    renderSeason(current);
}

// ---------- ADMIN ----------
function statCard(label, val) {
    return el('div', { class: 'stat-card' }, [
        el('div', { class: 'stat-val', text: String(val ?? 0) }),
        el('div', { class: 'stat-label', text: label }),
    ]);
}

export async function adminPage(app) {
    clear(app);

    if (!isAuthed()) {
        app.appendChild(el('div', { class: 'container' }, [
            emptyState('Connexion requise', 'Cet espace est réservé aux administrateurs.'),
        ]));
        return;
    }

    const container = el('div', { class: 'container' });
    app.appendChild(container);
    container.appendChild(el('h2', { class: 'section-title', text: 'Administration' }));

    const body = el('div', {}, [loadingState('Vérification des droits…')]);
    container.appendChild(body);

    let stats;
    try {
        stats = await api.adminStats();
    } catch (e) {
        clear(body);
        body.appendChild(emptyState(
            e.status === 403 ? 'Accès refusé' : 'Erreur',
            e.status === 403 ? "Ton compte n'a pas les droits administrateur." : e.message,
        ));
        return;
    }

    clear(body);

    body.appendChild(el('div', { class: 'admin-stats' }, [
        statCard('Titres', stats.total),
        statCard('Films', stats.movies),
        statCard('Séries', stats.series),
        statCard('Autres', stats.other),
    ]));

    // --- Catalogue / deep import ---
    const importStatus = el('p', { class: 'admin-status' });
    const importBtn = el('button', { class: 'btn btn-primary', text: 'Approfondir l’import' });
    importBtn.onclick = async () => {
        importBtn.disabled = true;
        const label = importBtn.textContent;
        importBtn.textContent = 'Lancement…';
        importStatus.textContent = '';
        try {
            const r = await api.adminImport(20);
            importStatus.textContent = r.message || 'Import lancé en arrière-plan.';
        } catch (e) {
            importStatus.textContent = 'Échec : ' + e.message;
        } finally {
            importBtn.disabled = false;
            importBtn.textContent = label;
        }
    };

    body.appendChild(el('div', { class: 'admin-panel' }, [
        el('h3', { text: 'Catalogue' }),
        el('p', { class: 'admin-hint', text: `${stats.total} titre(s) actuellement en base. L’API MovieBox n’expose pas de liste complète du catalogue : celui-ci est découvert page par page. Lance une exploration plus profonde pour enregistrer davantage de titres (l’opération tourne en arrière-plan, recharge la page ensuite).` }),
        el('div', { class: 'admin-controls' }, [importBtn]),
        importStatus,
    ]));

    // --- Export des liens ---
    const typeSel = el('select', { class: 'select' }, [['all', 'Tous'], ['movies', 'Films'], ['tv-series', 'Séries']].map(([v, l]) =>
        el('option', { value: v, text: l })));
    const limitInput = el('input', { class: 'select', type: 'number', min: '0', max: '100000', value: '0', style: 'width:120px' });
    const status = el('p', { class: 'admin-status' });

    const btn = el('button', { class: 'btn btn-primary', text: 'Exporter les liens (.txt)' });
    btn.onclick = async () => {
        btn.disabled = true;
        const label = btn.textContent;
        btn.textContent = 'Export en cours…';
        status.textContent = '';
        try {
            await api.exportLinks({ type: typeSel.value, limit: limitInput.value });
            status.textContent = 'Téléchargement lancé ✓';
        } catch (e) {
            status.textContent = 'Échec : ' + e.message;
        } finally {
            btn.disabled = false;
            btn.textContent = label;
        }
    };

    body.appendChild(el('div', { class: 'admin-panel' }, [
        el('h3', { text: 'Exporter les liens de téléchargement' }),
        el('p', { class: 'admin-hint', text: 'Génère un .txt (un bloc par titre, une ligne par épisode pour les séries). Mets « Nombre max » à 0 pour tout exporter. L’export web interroge l’API pour chaque titre : pour un très gros catalogue, préfère la commande CLI « php artisan catalog:export-links ».' }),
        el('div', { class: 'admin-controls' }, [
            el('label', { class: 'filter' }, [el('span', { text: 'Type' }), typeSel]),
            el('label', { class: 'filter' }, [el('span', { text: 'Nombre max (0 = tout)' }), limitInput]),
            btn,
        ]),
        status,
    ]));

    // --- Titres masqués (hors recherche) ---
    const blockedListWrap = el('div', { class: 'blocked-list' });
    const blockedInput = el('input', { class: 'select', type: 'text', placeholder: 'Nom du film/série (ou subjectId)', style: 'flex:1;min-width:220px' });
    const blockedStatus = el('p', { class: 'admin-status' });

    const renderBlocked = (list) => {
        clear(blockedListWrap);
        if (!list.length) {
            blockedListWrap.appendChild(el('p', { class: 'admin-hint', text: 'Aucun titre masqué pour l\'instant.' }));
            return;
        }
        list.forEach((b) => {
            blockedListWrap.appendChild(el('div', { class: 'blocked-item' }, [
                el('span', { text: b.term }),
                el('button', {
                    class: 'blocked-x', text: '✕', title: 'Retirer',
                    onclick: async () => {
                        try { await api.adminDeleteBlockedTitle(b.id); loadBlocked(); }
                        catch (e) { blockedStatus.textContent = e.message; }
                    },
                }),
            ]));
        });
    };
    const loadBlocked = async () => {
        try { renderBlocked(await api.adminBlockedTitles()); }
        catch (e) { blockedStatus.textContent = e.message; }
    };
    const addBlocked = async () => {
        const term = blockedInput.value.trim();
        if (term.length < 2) { blockedStatus.textContent = 'Saisis au moins 2 caractères.'; return; }
        blockedStatus.textContent = '';
        try { await api.adminAddBlockedTitle(term); blockedInput.value = ''; loadBlocked(); }
        catch (e) { blockedStatus.textContent = e.message; }
    };
    blockedInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') addBlocked(); });

    body.appendChild(el('div', { class: 'admin-panel' }, [
        el('h3', { text: 'Titres masqués (hors recherche)' }),
        el('p', { class: 'admin-hint', text: "Ces titres n'apparaissent plus sur l'accueil, les catégories, les tendances ni les suggestions — mais restent trouvables via la recherche. Correspondance par nom (contient) ou par subjectId exact." }),
        el('div', { class: 'admin-controls' }, [blockedInput, el('button', { class: 'btn btn-primary', text: 'Masquer', onclick: addBlocked })]),
        blockedStatus,
        blockedListWrap,
    ]));

    // --- Envoi vers Streamtape ---
    const stStatus = el('p', { class: 'admin-status' });
    const stSearchInput = el('input', { class: 'select', type: 'text', placeholder: 'Recherche en direct : nom du film / série…', style: 'flex:1;min-width:260px' });
    const stSearchHint = el('p', { class: 'admin-hint', style: 'margin-top:8px', text: '' });
    const stResults = el('div', { class: 'st-results' });
    const stSendWrap = el('div', { class: 'st-send-wrap' });
    const stHistory = el('div', { class: 'st-history' });

    const ST_BADGE_LABELS = {
        done: 'Terminé', failed: 'Échec', new: 'Nouveau', converting: 'Conversion',
        downloading: 'Téléchargement', running: 'Téléchargement', queued: 'En file',
        error: 'Erreur', unknown: 'Inconnu',
    };
    const stBadge = (status) => el('span', { class: `st-badge st-${status}`, text: ST_BADGE_LABELS[status] || status });
    const fmtBytes = (n) => {
        if (!n && n !== 0) return '';
        if (n >= 1073741824) return `${(n / 1073741824).toFixed(2)} Go`;
        if (n >= 1048576) return `${(n / 1048576).toFixed(0)} Mo`;
        return `${(n / 1024).toFixed(0)} Ko`;
    };

    const stFolderControls = () => {
        const select = el('select', { class: 'select', style: 'max-width:210px' }, [
            el('option', { value: 'auto', text: 'Dossier automatique (nom du film / Séries)' }),
            el('option', { value: '', text: 'Dossier par défaut' }),
            el('option', { value: '__custom', text: '+ Dossier personnalisé…' }),
        ]);
        const input = el('input', { class: 'select', type: 'text', placeholder: 'Nom du dossier (ex. : Action, VF…)', style: 'display:none;min-width:200px' });
        select.addEventListener('change', () => { input.style.display = select.value === '__custom' ? '' : 'none'; });
        return { select, input, value: () => (select.value === '__custom' ? input.value.trim() : select.value) };
    };

    const stRenderHistory = (link) => {
        let sub = link.resolution ? `${link.resolution}p` : 'auto';
        if (link.season || link.episode) sub += ` · S${String(link.season).padStart(2, '0')}E${String(link.episode).padStart(2, '0')}`;
        if (link.folder) sub += ` · 📁 ${link.folder}`;
        const pending = link.status !== 'done' && link.status !== 'failed';
        const pct = link.bytesTotal > 0 ? Math.round((link.bytesLoaded / link.bytesTotal) * 100) : 0;
        const langBadge = link.language ? el('span', { class: 'st-lang-badge', style: 'display:inline-block;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase;background:var(--accent);color:#fff;margin-left:6px', text: link.language }) : null;
        const item = el('div', { class: 'st-item' }, [
            el('div', { class: 'st-item-main' }, [
                el('span', { class: 'st-item-title', text: link.title }),
                langBadge,
                el('span', { class: 'st-item-sub', text: sub }),
            ]),
            stBadge(link.status),
            pending
                ? el('div', { class: 'st-progress' }, [
                    el('span', { class: 'st-progress-bar', style: `width:${link.status === 'converting' ? 100 : Math.min(100, pct)}%` }),
                    el('span', {
                        class: 'st-progress-label',
                        text: link.status === 'converting'
                            ? 'Conversion Streamtape en cours… (peut prendre plusieurs minutes)'
                            : (link.bytesLoaded > 0 ? `${fmtBytes(link.bytesLoaded)} / ${fmtBytes(link.bytesTotal)} (${pct}%)` : 'En attente…'),
                    }),
                ])
                : null,
            el('div', { class: 'st-item-actions' }, [
                el('button', {
                    class: 'btn btn-ghost', text: 'Actualiser',
                    onclick: async () => {
                        try { stReplaceItem(link.id, await api.adminStreamtapeStatus(link.id)); }
                        catch (e) { stStatus.textContent = e.message; }
                    },
                }),
                !link.streamtapeUrl
                    ? el('button', {
                        class: 'btn btn-ghost', text: 'Réessayer', title: 'Re-résout l\'URL source et relance le téléchargement distant',
                        onclick: async () => {
                            try {
                                const next = await api.adminStreamtapeRetry(link.id);
                                stReplaceItem(link.id, next);
                                toast('Téléchargement relancé ✓');
                                stPoll(link.id);
                            } catch (e) { stStatus.textContent = e.message; }
                        },
                    })
                    : null,
                link.streamtapeUrl
                    ? el('a', { class: 'btn btn-primary', href: link.streamtapeUrl, target: '_blank', rel: 'noopener', text: 'Ouvrir' })
                    : null,
                link.streamtapeUrl
                    ? el('button', {
                        class: 'btn btn-ghost', text: 'Copier',
                        onclick: async () => {
                            try { await navigator.clipboard.writeText(link.streamtapeUrl); toast('Lien Streamtape copié ✓'); }
                            catch { stStatus.textContent = link.streamtapeUrl; }
                        },
                    })
                    : null,
                el('button', {
                    class: 'btn btn-danger', text: 'Supprimer', title: 'Supprime le fichier de Streamtape et de l\'historique',
                    onclick: async () => {
                        if (!window.confirm(`Supprimer « ${link.title} » de Streamtape et de l'historique ?`)) return;
                        try {
                            const r = await api.adminStreamtapeDelete(link.id);
                            const cur = stItems.get(link.id);
                            if (cur) cur.remove();
                            stItems.delete(link.id);
                            toast(r.removedRemote ? 'Fichier supprimé de Streamtape ✓' : 'Entrée supprimée de l\'historique');
                        } catch (e) { stStatus.textContent = e.message; }
                    },
                }),
                link.streamtapeUrl
                    ? el('button', {
                        class: 'btn btn-ghost', text: 'Déplacer', title: 'Déplacer le fichier dans un autre dossier',
                        onclick: () => {
                            const fc = stFolderControls();
                            const okBtn = el('button', {
                                class: 'btn btn-primary', text: 'OK',
                                onclick: async () => {
                                    okBtn.disabled = true;
                                    try {
                                        stReplaceItem(link.id, await api.adminStreamtapeMove(link.id, fc.value() || 'auto'));
                                        toast('Fichier déplacé ✓');
                                        moveRow.remove();
                                    } catch (e) {
                                        stStatus.textContent = e.message;
                                        okBtn.disabled = false;
                                    }
                                },
                            });
                            const moveRow = el('div', { class: 'st-move' }, [fc.select, fc.input, okBtn]);
                            item.appendChild(moveRow);
                            fc.select.focus();
                        },
                    })
                    : null,
            ]),
            link.status === 'failed' && link.error ? el('p', { class: 'st-error', text: link.error }) : null,
            link.status === 'done' && link.streamtapeUrl ? el('p', { class: 'st-done', text: link.streamtapeUrl }) : null,
        ]);
        item.dataset.final = link.status === 'done' || link.status === 'failed' ? '1' : '0';

        return item;
    };

    const stItems = new Map(); // id -> element
    const stReplaceItem = (id, link) => {
        const next = stRenderHistory(link);
        const old = stItems.get(id);
        if (old) old.replaceWith(next);
        stItems.set(id, next);
    };

    const stPoll = (id) => {
        const timer = setInterval(async () => {
            const cur = stItems.get(id);
            if (!cur) { clearInterval(timer); return; }
            if (cur.dataset.final === '1') { clearInterval(timer); return; }
            const link = await api.adminStreamtapeStatus(id).catch(() => null);
            if (link) {
                stReplaceItem(id, link);
                if (link.status === 'done' || link.status === 'failed') clearInterval(timer);
            }
        }, 10000);
    };

    // ---- recherche en direct (debounce) ----
    let stSearchSeq = 0;
    let stSearchTimer = null;
    const stDoSearch = async () => {
        const q = stSearchInput.value.trim();
        const seq = ++stSearchSeq;
        clear(stSendWrap);
        if (q.length < 2) {
            clear(stResults);
            stSearchHint.textContent = q.length === 1 ? 'Tape au moins 2 lettres…' : '';
            stStatus.textContent = '';
            return;
        }
        stSearchHint.textContent = 'Recherche « ' + q + ' »…';
        stStatus.textContent = '';
        try {
            const items = await api.adminStreamtapeSearch(q);
            if (seq !== stSearchSeq) return;
            stRenderResults(items);
            stSearchHint.textContent = items.length
                ? `${items.length} résultat(s) — clique sur une affiche pour l'envoyer.`
                : 'Aucun résultat en base pour « ' + q + ' ».';
        } catch (e) {
            if (seq !== stSearchSeq) return;
            stSearchHint.textContent = 'Échec : ' + e.message;
        }
    };
    stSearchInput.addEventListener('input', () => {
        clearTimeout(stSearchTimer);
        stSearchTimer = setTimeout(stDoSearch, 300);
    });

    const stRenderResults = (items) => {
        clear(stResults);
        items.forEach((it) => {
            const doneLink = it.links.find((l) => l.status === 'done' && l.streamtapeUrl);
            const pendingLink = it.links.find((l) => l.status !== 'done' && l.status !== 'failed');
            const poster = it.cover
                ? el('img', { src: it.cover, alt: it.title, loading: 'lazy', onerror: (e) => { e.target.style.display = 'none'; } })
                : null;
            stResults.appendChild(el('button', {
                class: 'st-card',
                title: it.title,
                onclick: () => stOpenSend(it),
            }, [
                el('span', { class: 'st-card-poster' }, [
                    poster || el('span', { class: 'st-ph', text: '🎬' }),
                    el('span', {
                        class: `st-dot ${it.hasResource ? 'on' : 'off'}`,
                        title: it.hasResource ? 'Vidéo disponible' : 'Flux indisponible en amont',
                    }),
                ]),
                el('span', { class: 'st-card-title', text: it.title }),
                el('span', { class: 'st-card-meta' }, [
                    it.year ? el('span', { class: 'st-year', text: String(it.year) }) : null,
                    it.french ? el('span', { class: 'st-vf', text: 'VF' }) : null,
                ]),
                el('span', { class: 'st-card-foot' }, [
                    el('span', { class: `st-badge st-${it.subjectType === 2 ? 'serie' : 'film'}`, text: it.typeLabel || (it.subjectType === 2 ? 'Série' : 'Film') }),
                    doneLink
                        ? el('a', { class: 'st-sent', href: doneLink.streamtapeUrl, target: '_blank', rel: 'noopener', text: '✓ Envoyé', onclick: (e) => e.stopPropagation() })
                        : (pendingLink ? el('span', { class: 'st-sent pending', text: '… Envoi en cours' }) : null),
                ]),
            ]));
        });
    };

    const stOpenSend = (it) => {
        clear(stSendWrap);
        const status = el('p', { class: 'admin-status' });
        const isSeries = it.subjectType === 2;
        const seasonInput = el('input', { class: 'select', type: 'number', min: '1', value: '1', style: 'width:80px' });
        const episodeInput = el('input', { class: 'select', type: 'number', min: '1', value: '1', style: 'width:80px' });
        const resSelect = el('select', { class: 'select', style: 'max-width:220px' }, [
            el('option', { value: '0', text: 'Meilleure qualité (auto)' }),
        ]);
        resSelect.disabled = true;

        const onSeEpChange = async () => {
            resSelect.disabled = true;
            clear(resSelect);
            resSelect.appendChild(el('option', { value: '0', text: 'Meilleure qualité (auto)' }));
            try {
                const sources = await api.adminStreamtapeSources({
                    subjectId: it.subjectId,
                    season: isSeries ? parseInt(seasonInput.value, 10) || 1 : 0,
                    episode: isSeries ? parseInt(episodeInput.value, 10) || 1 : 0,
                });
                sources.forEach((s) => {
                    const label = s.resolution ? `${s.resolution}p` : 'auto'
                        + (s.size ? ` · ${fmtBytes(s.size)}` : '');
                    resSelect.appendChild(el('option', { value: String(s.resolution || 0), text: label }));
                });
                resSelect.disabled = sources.length === 0;
            } catch { /* keep auto-only */ }
        };
        seasonInput.addEventListener('change', onSeEpChange);
        episodeInput.addEventListener('change', onSeEpChange);

        const sendBtn = el('button', { class: 'btn btn-primary', text: 'Envoyer sur Streamtape' });
        const fc = stFolderControls();
        sendBtn.onclick = async () => {
            sendBtn.disabled = true;
            sendBtn.textContent = 'Envoi…';
            status.textContent = '';
            const folder = fc.value();
            if (fc.select.value === '__custom' && folder === '') {
                status.textContent = 'Saisis un nom de dossier.';
                sendBtn.disabled = false;
                sendBtn.textContent = 'Envoyer sur Streamtape';
                return;
            }
            try {
                const link = await api.adminStreamtapeUpload({
                    subjectId: it.subjectId,
                    title: it.title,
                    subjectType: it.subjectType,
                    season: isSeries ? parseInt(seasonInput.value, 10) || 1 : 0,
                    episode: isSeries ? parseInt(episodeInput.value, 10) || 1 : 0,
                    resolution: parseInt(resSelect.value, 10) || 0,
                    folder,
                });
                const sel = link.resolution ? `${link.resolution}p` : 'auto';
                status.textContent = `Upload remote lancé ✓ — ${sel}${link.bytesTotal ? ' · ' + fmtBytes(link.bytesTotal) : ''}${link.folder ? ' → dossier « ' + link.folder + ' »' : ''} (le suivi est en bas, sous « Envois récents »).`;
                stPoll(link.id);
                loadStHistory();
                sendBtn.textContent = 'Envoyer à nouveau';
            } catch (e) {
                status.textContent = e.message || 'Échec de l’envoi.';
                sendBtn.textContent = 'Envoyer sur Streamtape';
            } finally {
                sendBtn.disabled = false;
            }
        };

        const poster = it.cover
            ? el('img', { src: it.cover, alt: it.title, loading: 'lazy', style: 'width:64px;height:96px;object-fit:cover;border-radius:8px', onerror: (e) => { e.target.style.display = 'none'; } })
            : null;

        const seriesBtn = isSeries ? el('button', {
            class: 'btn btn-ghost', text: '📁 Envoyer toute la série',
            title: 'Crée un dossier au nom de la série, un sous-dossier par saison, et envoie tous les épisodes',
            onclick: async () => {
                if (!window.confirm(`Envoyer TOUTE la série « ${it.title} » sur Streamtape ?\nDossier « Séries/${it.title.split(' [')[0]} » → sous-dossiers S1, S2…\nChaque épisode part en meilleure qualité disponible (1080p).`)) return;
                seriesBtn.disabled = true;
                seriesBtn.textContent = 'Lancement…';
                status.textContent = '';
                try {
                    const r = await api.adminStreamtapeUploadSeries({
                        subjectId: it.subjectId,
                        title: it.title,
                        subjectType: it.subjectType,
                        quality: parseInt(resSelect.value, 10) || 0,
                    });
                    status.textContent = `Batch lancé ✓ (${r.total} épisodes) — création des dossiers puis envoi, ça peut prendre plusieurs minutes.`;
                    let seen = new Set(stItems.keys());
                    let timer = setInterval(async () => {
                        try {
                            const links = await api.adminStreamtapeBatch(r.batch);
                            let newItems = links.filter((l) => !seen.has(l.id));
                            newItems.forEach((l) => { stPoll(l.id); seen.add(l.id); });
                            loadStHistory();
                            const done = links.length;
                            const total = (links.find((l) => l.total) || {}).total || r.total;
                            status.textContent = `Série « ${it.title} » : ${done}/${total} épisodes envoyés${done >= total ? ' ✓' : '…'}`;
                            if (done >= total) {
                                clearInterval(timer);
                                seriesBtn.textContent = '📁 Envoyer toute la série';
                                seriesBtn.disabled = false;
                                toast('Série envoyée ✓');
                            }
                        } catch { /* retry next tick */ }
                    }, 4000);
                } catch (e) {
                    status.textContent = e.message || 'Échec du lancement.';
                    seriesBtn.textContent = '📁 Envoyer toute la série';
                    seriesBtn.disabled = false;
                }
            },
        }) : null;

        stSendWrap.appendChild(el('div', { class: 'st-send' }, [
            poster,
            el('div', { class: 'st-send-info' }, [
                el('strong', { text: it.title }),
                el('div', { class: 'st-send-controls' }, [
                    isSeries ? el('label', { class: 'filter' }, [el('span', { text: 'Saison' }), seasonInput]) : null,
                    isSeries ? el('label', { class: 'filter' }, [el('span', { text: 'Épisode' }), episodeInput]) : null,
                    el('label', { class: 'filter' }, [el('span', { text: 'Qualité' }), resSelect]),
                    el('label', { class: 'filter' }, [el('span', { text: 'Dossier' }), fc.select]),
                    fc.input,
                    sendBtn,
                    seriesBtn,
                ]),
            ]),
            status,
        ]));

        onSeEpChange();
    };

    const loadStHistory = async () => {
        try {
            const links = await api.adminStreamtapeLinks();
            clear(stHistory);
            if (!links.length) {
                stHistory.appendChild(el('p', { class: 'admin-hint', text: 'Aucun envoi pour l\'instant.' }));
                return;
            }

            // Group by folder → render each group with its header.
            const byFolder = {};
            links.forEach((link) => {
                const key = link.folder || '(sans dossier)';
                if (!byFolder[key]) byFolder[key] = [];
                byFolder[key].push(link);
            });

            Object.keys(byFolder).sort((a, b) => a.localeCompare(b)).forEach((folder) => {
                const group = byFolder[folder];
                const header = el('div', { class: 'st-folder-header', style: 'margin:16px 0 6px;font-weight:600;color:var(--text-dim);font-size:13px' }, [
                    el('span', { text: '📁 ' + folder }),
                    el('span', { class: 'st-item-sub', text: ` · ${group.length} fichier${group.length > 1 ? 's' : ''}` }),
                ]);
                stHistory.appendChild(header);
                group.forEach((link) => {
                    stItems.set(link.id, stRenderHistory(link));
                    stHistory.appendChild(stItems.get(link.id));
                });
            });
        } catch (e) {
            stHistory.appendChild(el('p', { class: 'admin-status', text: e.message }));
        }
    };

    const stFoldersWrap = el('div', { class: 'st-folders' });
    const stUsageWrap = el('div', { class: 'st-usage' });
    const loadStUsage = async (refresh = false) => {
        try {
            const u = await api.adminStreamtapeUsage(refresh);
            clear(stUsageWrap);
            const badge = el('span', { class: 'st-usage-value', text: fmtBytes(u.used_bytes) });
            const meta = el('span', { class: 'st-item-sub', text: ` · ${u.files} fichier${u.files > 1 ? 's' : ''} · ${u.folders} dossier${u.folders > 1 ? 's' : ''}` });
            const refreshBtn = el('button', {
                class: 'btn btn-ghost', text: 'Actualiser',
                onclick: async () => {
                    refreshBtn.disabled = true;
                    refreshBtn.textContent = '…';
                    try { await loadStUsage(true); toast('Espace Streamtape actualisé ✓'); }
                    catch (e) { stStatus.textContent = e.message; }
                    refreshBtn.disabled = false;
                    refreshBtn.textContent = 'Actualiser';
                },
            });
            stUsageWrap.appendChild(el('span', { class: 'st-usage-label', text: '💾 Espace utilisé : ' }));
            stUsageWrap.appendChild(badge);
            stUsageWrap.appendChild(meta);
            stUsageWrap.appendChild(refreshBtn);
        } catch (e) {
            stUsageWrap.appendChild(el('p', { class: 'admin-status', text: 'Espace Streamtape indisponible : ' + e.message }));
        }
    };
    const stRenderFolderNode = (f) => {
        return el('div', { class: 'st-folder-item' }, [
            el('span', { class: 'st-folder-name', text: '📁 ' + f.name }),
            el('span', { class: 'st-item-sub', text: f.folderId }),
            el('button', {
                class: 'btn btn-danger', text: 'Supprimer', title: 'Supprime le dossier ET tout son contenu sur Streamtape',
                onclick: async () => {
                    if (!window.confirm(`Supprimer le dossier « ${f.name} » et TOUT son contenu sur Streamtape ?`)) return;
                    try {
                        const r = await api.adminStreamtapeDeleteFolder(f.id);
                        if (r && r.deleted) toast('Dossier supprimé ✓');
                        loadStFolders();
                    } catch (e) { stStatus.textContent = e.message; }
                },
            }),
        ]);
    };

    const loadStFolders = async () => {
        try {
            const folders = await api.adminStreamtapeFolders();
            clear(stFoldersWrap);
            if (!folders.length) {
                stFoldersWrap.appendChild(el('p', { class: 'admin-hint', text: 'Aucun dossier créé via l\'app pour l\'instant (les dossiers « Films » et « Séries » apparaîtront dès le premier envoi automatique).' }));
                return;
            }

            // Build a tree: parent (empty string) → children → grandchildren.
            const childrenOf = {};
            const roots = [];
            folders.forEach((f) => {
                const pid = f.parent ?? '';
                if (!childrenOf[pid]) childrenOf[pid] = [];
                childrenOf[pid].push(f);
            });
            roots.push(...(childrenOf[''] || []));

            const renderTree = (nodes, depth) => {
                nodes.forEach((f) => {
                    const wrap = el('div', { class: 'st-folder-branch', style: `padding-left:${depth * 20}px` }, [
                        stRenderFolderNode(f),
                    ]);
                    stFoldersWrap.appendChild(wrap);
                    const kids = childrenOf[f.folderId] || [];
                    if (kids.length) renderTree(kids, depth + 1);
                });
            };
            renderTree(roots, 0);
        } catch (e) {
            stFoldersWrap.appendChild(el('p', { class: 'admin-status', text: e.message }));
        }
    };

    body.appendChild(el('div', { class: 'admin-panel' }, [
        el('h3', { text: 'Envoyer sur Streamtape' }),
        el('p', { class: 'admin-hint', text: "Les résultats s'affichent pendant que tu tapes. Clique sur une affiche pour l'envoyer sur ton lecteur Streamtape (upload remote, headers navigateur inclus). Point vert = vidéo disponible, point rouge = flux indisponible en amont." }),
        stUsageWrap,
        stSearchInput,
        stSearchHint,
        stResults,
        stSendWrap,
        el('h4', { class: 'st-history-title', text: 'Envois récents' }),
        stHistory,
        el('h4', { class: 'st-history-title', text: 'Dossiers sur Streamtape' }),
        stFoldersWrap,
    ]));

    loadStFolders();

    loadStUsage();

    loadStHistory();

    loadBlocked();
}

// ---------- LIBRARY ----------
export async function libraryPage(app) {
    clear(app);
    if (!isAuthed()) {
        app.appendChild(el('div', { class: 'container' }, [emptyState('Sign in to see your list', 'Your favorites and watch history live here.')]));
        const btn = el('button', { class: 'btn btn-primary', text: 'Sign in', onclick: () => openAuthModal('login') });
        app.querySelector('.state').appendChild(btn);
        return;
    }

    app.appendChild(el('div', { class: 'container' }, [loadingState()]));

    const [favRes, histRes] = await Promise.allSettled([api.favorites(), api.history()]);
    clear(app);
    const container = el('div', { class: 'container' });
    app.appendChild(container);

    const favorites = favRes.status === 'fulfilled' ? favRes.value : [];
    const history = histRes.status === 'fulfilled' ? histRes.value : [];

    container.appendChild(el('h2', { class: 'section-title', text: 'My List' }));
    container.appendChild(favorites.length
        ? grid(favorites.map(historyToItem))
        : emptyState('Your list is empty', 'Add titles from any detail page.'));

    container.appendChild(el('h2', { class: 'section-title', text: 'Watch History' }));
    if (history.length) {
        const cards = history.map((h) => {
            const item = historyToItem(h);
            const progress = h.duration_seconds > 0 ? (h.position_seconds / h.duration_seconds) * 100 : 0;
            const node = card(item, { progress });
            node.onclick = () => navigate(watchHref(item, h.season, h.episode));
            return node;
        });
        container.appendChild(el('div', { class: 'grid' }, cards));
    } else {
        container.appendChild(emptyState('Nothing watched yet.'));
    }
}
