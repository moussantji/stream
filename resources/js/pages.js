// Page renderers for the SPA.
import { api, isAuthed } from './api.js';
import {
    el, clear, row, grid, card, carousel, skeletonRow, loadingState, errorState, emptyState,
    navigate, watchHref, detailHref, toast, openAuthModal,
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
        api.home(),
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

    // MovieBox-style category strip at the very top.
    const catTabs = [['/', 'Tendance'], ['/tv', 'Live'], ['/series', 'Séries TV'], ['/films', 'Film']];
    app.appendChild(el('div', { class: 'home-tabs' }, catTabs.map(([href, label]) =>
        el('a', {
            class: `home-tab ${window.location.pathname === href ? 'active' : ''}`,
            href, 'data-link': '', 'data-nav': href, text: label,
        }))));

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
}

function hero(item) {
    const meta = [];
    if (item.typeLabel) meta.push(el('span', { class: 'badge', text: item.typeLabel }));
    if (item.year) meta.push(el('span', { text: String(item.year) }));
    if (item.imdbRating) meta.push(el('span', { class: 'rating', text: `★ ${item.imdbRating}` }));
    if (item.genres?.length) meta.push(el('span', { text: item.genres.slice(0, 3).join(' · ') }));

    const bg = item.cover ? el('img', { class: 'hero-bg', src: item.cover, alt: '' }) : el('div', { class: 'hero-bg' });
    const section = el('section', { class: 'hero' }, [
        bg,
        el('div', { class: 'hero-content' }, [
            el('h1', { class: 'hero-title', text: item.title }),
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
// Returns the observer so callers can disconnect it.
function onReachBottom(sentinel, cb) {
    const observer = new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) cb();
    }, { rootMargin: '600px 0px' });
    observer.observe(sentinel);
    return observer;
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

    const gridWrap = el('div', {}, [loadingState()]);
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
    const gridWrap = el('div', {}, [loadingState('Chargement de la base locale…')]);
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
    app.appendChild(loadingState());

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

    // Audio versions (dubs). Each dub is a separate subjectId, so switching
    // language means playing a different subject. Default to French if present.
    const dubs = data.dubs || [];
    const frenchDub = dubs.find((d) => (d.code || '').startsWith('fr') || /fran/i.test(d.label || ''));
    let activeSubjectId = frenchDub ? frenchDub.subjectId : item.subjectId;
    const currentItem = () => ({ ...item, subjectId: activeSubjectId });

    const play = () => navigate(isSeries && firstSeason
        ? watchHref(currentItem(), firstSeason.season, 1)
        : watchHref(currentItem()));

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
        el('h1', { class: 'detail-title', text: item.title }),
        el('div', { class: 'detail-meta' }, [el('span', { class: 'badge', text: item.typeLabel }), ...meta]),
        item.description ? el('p', { class: 'detail-desc', text: item.description }) : null,
        versionRow,
        el('div', { class: 'detail-actions' }, [
            el('button', { class: 'btn btn-primary', html: `${PLAY_SVG} <span>${isSeries ? 'Play S' + firstSeason?.season + ' E1' : 'Play'}</span>`, style: 'display:flex;gap:8px;align-items:center', onclick: play }),
            favBtn,
        ]),
    ]);

    const poster = el('div', { class: 'detail-poster' }, [
        item.cover ? el('img', { src: item.cover, alt: item.title }) : el('div', { class: 'ph' }),
    ]);

    const heroBg = trailer
        ? trailerVideo(trailer, item.cover, 'detail-hero-bg')
        : (item.cover ? el('img', { class: 'detail-hero-bg', src: item.cover, alt: '' }) : null);

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
    const label = season > 0 ? `${item.title} — S${season} E${episode}` : item.title;

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
    if (isAuthed()) {
        try {
            const hist = await api.history();
            const match = hist.find((h) => h.subject_id === item.subjectId && h.season === season && h.episode === episode);
            if (match) startTime = match.position_seconds || 0;
        } catch { /* ignore */ }
    }

    let data;
    try {
        data = await api.play({ subjectId: item.subjectId, detailPath: item.detailPath, season, episode, title: item.title });
    } catch (e) {
        clear(shell);
        shell.appendChild(el('div', { class: 'player-message' }, [errorState(e.message, () => watchPage(app, params))]));
        return;
    }

    if (!data.sources.length && !data.hls.length && !(data.dash && data.dash.length)) {
        clear(shell);
        shell.appendChild(el('div', { class: 'player-message' }, [
            emptyState('No stream available', 'This title may be restricted by the provider or only available in the app.'),
        ]));
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
