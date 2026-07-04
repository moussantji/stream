// Page renderers for the SPA.
import { api, isAuthed } from './api.js';
import {
    el, clear, row, grid, card, carousel, skeletonRow, loadingState, errorState, emptyState,
    navigate, watchHref, detailHref, toast, openAuthModal,
} from './ui.js';
import { Player } from './player.js';

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

    const heroItem = (sections[0]?.items || trending)[0];
    if (heroItem) app.appendChild(hero(heroItem));

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

    return el('section', { class: 'hero' }, [
        item.cover ? el('img', { class: 'hero-bg', src: item.cover, alt: '' }) : null,
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

// Generic paginated grid with a "Charger plus" button.
// fetchPage(page) must resolve to { items: [...], pager: { hasMore } }.
function paginatedGrid(container, fetchPage, { emptyMsg = 'Rien à afficher.' } = {}) {
    const gridWrap = el('div', {}, [loadingState()]);
    const moreWrap = el('div', { style: 'text-align:center;padding:8px 0 24px' });
    container.appendChild(gridWrap);
    container.appendChild(moreWrap);

    let page = 1;
    let gridEl = null;
    let loading = false;

    const load = async (append) => {
        if (loading) return;
        loading = true;
        try {
            const data = await fetchPage(page);
            const items = data.items || [];
            if (!append) { clear(gridWrap); gridEl = null; }

            if (!items.length && page === 1) {
                clear(gridWrap);
                gridWrap.appendChild(emptyState(emptyMsg));
                clear(moreWrap);
                return;
            }

            if (!gridEl) { gridEl = grid(items); clear(gridWrap); gridWrap.appendChild(gridEl); }
            else items.forEach((it) => gridEl.appendChild(card(it)));

            clear(moreWrap);
            if (data.pager && data.pager.hasMore) {
                const btn = el('button', {
                    class: 'btn btn-ghost', text: 'Charger plus',
                    onclick: () => { page += 1; load(true); },
                });
                moreWrap.appendChild(btn);
            }
        } catch (e) {
            clear(gridWrap);
            gridWrap.appendChild(errorState(e.message, () => load(false)));
        } finally {
            loading = false;
        }
    };

    load(false);
}

// ---------- TRENDING / POPULAIRES ----------
export async function trendingPage(app, title = 'Les plus regardés') {
    clear(app);
    const container = el('div', { class: 'container' }, [el('h2', { class: 'section-title', text: title })]);
    app.appendChild(container);
    paginatedGrid(container, (page) => api.trending(page), { emptyMsg: 'Rien à afficher pour le moment.' });
}

// ---------- CATEGORY (Films / Séries / Animation) ----------
export async function categoryPage(app, tab, title) {
    clear(app);
    const container = el('div', { class: 'container' }, [el('h2', { class: 'section-title', text: title })]);
    app.appendChild(container);
    paginatedGrid(container, (page) => api.category(tab, page), { emptyMsg: 'Aucun contenu pour le moment.' });
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
    const moreWrap = el('div', { style: 'text-align:center;padding:8px 0 24px' });
    container.appendChild(info);
    container.appendChild(gridWrap);
    container.appendChild(moreWrap);

    let page = 1;
    let gridEl = null;

    const load = async (append) => {
        try {
            const data = await api.local({ type, page });
            info.textContent = `${data.total} titre(s) enregistré(s) dans ta base`;

            if (!append) { clear(gridWrap); clear(moreWrap); gridEl = null; }

            if (!data.items.length && page === 1) {
                gridWrap.appendChild(emptyState('Base locale vide', 'Navigue sur le site : chaque titre affiché est enregistré ici automatiquement.'));
                return;
            }

            if (!gridEl) { gridEl = grid(data.items); gridWrap.appendChild(gridEl); }
            else { data.items.forEach((it) => gridEl.appendChild(card(it))); }

            clear(moreWrap);
            if (data.pager && data.pager.hasMore) {
                moreWrap.appendChild(el('button', {
                    class: 'btn btn-ghost', text: 'Charger plus',
                    onclick: () => { page += 1; load(true); },
                }));
            }
        } catch (e) {
            clear(gridWrap);
            gridWrap.appendChild(errorState(e.message, () => load(false)));
        }
    };

    await load(false);
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
    const typeTabs = el('div', { class: 'season-tabs' }, [['all', 'Tous'], ['movies', 'Films'], ['tv-series', 'Séries']].map(([t, label]) =>
        el('button', {
            class: `season-tab ${t === type ? 'active' : ''}`,
            text: label,
            onclick: () => navigate(`/search?q=${encodeURIComponent(q)}&type=${t}`),
        })));
    container.appendChild(typeTabs);

    // Client-side refinement filters (genre / year / sort).
    const genreSel = el('select', { class: 'select' }, [el('option', { value: '', text: 'Tous les genres' })]);
    const yearSel = el('select', { class: 'select' }, [el('option', { value: '', text: 'Toutes les années' })]);
    const sortSel = el('select', { class: 'select' }, [
        el('option', { value: 'relevance', text: 'Pertinence' }),
        el('option', { value: 'rating', text: 'Mieux notés' }),
        el('option', { value: 'year_desc', text: 'Plus récents' }),
        el('option', { value: 'year_asc', text: 'Plus anciens' }),
        el('option', { value: 'title', text: 'Titre (A→Z)' }),
    ]);
    const filterBar = el('div', { class: 'filter-bar' }, [
        el('label', { class: 'filter' }, [el('span', { text: 'Genre' }), genreSel]),
        el('label', { class: 'filter' }, [el('span', { text: 'Année' }), yearSel]),
        el('label', { class: 'filter' }, [el('span', { text: 'Trier' }), sortSel]),
    ]);
    container.appendChild(filterBar);

    const gridWrap = el('div', {}, [loadingState('Recherche…')]);
    const moreWrap = el('div', { style: 'text-align:center;padding:8px 0 24px' });
    container.appendChild(gridWrap);
    container.appendChild(moreWrap);

    if (!q) {
        clear(gridWrap);
        gridWrap.appendChild(emptyState('Saisis un mot-clé pour lancer une recherche.'));
        return;
    }

    let page = 1;
    let loading = false;
    let all = [];

    const populateFilters = () => {
        const genres = new Set();
        const years = new Set();
        all.forEach((it) => { (it.genres || []).forEach((g) => genres.add(g)); if (it.year) years.add(it.year); });

        const keepG = genreSel.value;
        clear(genreSel);
        genreSel.appendChild(el('option', { value: '', text: 'Tous les genres' }));
        [...genres].sort((a, b) => a.localeCompare(b)).forEach((g) => genreSel.appendChild(el('option', { value: g, text: g })));
        genreSel.value = keepG;

        const keepY = yearSel.value;
        clear(yearSel);
        yearSel.appendChild(el('option', { value: '', text: 'Toutes les années' }));
        [...years].sort((a, b) => b - a).forEach((y) => yearSel.appendChild(el('option', { value: String(y), text: String(y) })));
        yearSel.value = keepY;
    };

    const render = () => {
        let items = all.slice();
        if (genreSel.value) items = items.filter((it) => (it.genres || []).includes(genreSel.value));
        if (yearSel.value) items = items.filter((it) => String(it.year) === yearSel.value);

        switch (sortSel.value) {
            case 'rating': items.sort((a, b) => (b.imdbRating || 0) - (a.imdbRating || 0)); break;
            case 'year_desc': items.sort((a, b) => (b.year || 0) - (a.year || 0)); break;
            case 'year_asc': items.sort((a, b) => (a.year || 0) - (b.year || 0)); break;
            case 'title': items.sort((a, b) => (a.title || '').localeCompare(b.title || '')); break;
            default: break;
        }

        clear(gridWrap);
        if (!all.length) {
            gridWrap.appendChild(emptyState('Aucun résultat pour cette recherche.', 'Essaie un autre mot-clé ou un autre type.'));
        } else {
            gridWrap.appendChild(items.length ? grid(items) : emptyState('Aucun résultat avec ces filtres.'));
        }
    };

    [genreSel, yearSel, sortSel].forEach((sel) => { sel.onchange = render; });

    const load = async (append) => {
        if (loading) return;
        loading = true;
        try {
            const data = await api.search(q, type, page);
            all = append ? all.concat(data.items || []) : (data.items || []);
            populateFilters();
            render();
            clear(moreWrap);
            if (data.pager && data.pager.hasMore) {
                moreWrap.appendChild(el('button', {
                    class: 'btn btn-ghost', text: 'Charger plus',
                    onclick: () => { page += 1; load(true); },
                }));
            }
        } catch (e) {
            clear(gridWrap);
            gridWrap.appendChild(errorState(e.message, () => load(false)));
        } finally {
            loading = false;
        }
    };

    await load(false);
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

    app.appendChild(el('section', { class: 'detail-hero' }, [
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

    // Quality selector for MP4 sources
    if (data.sources.length > 1) {
        const select = el('select', { class: 'select' }, data.sources.map((s) =>
            el('option', { value: s.url, text: s.quality })));
        select.onchange = () => player.setMp4(select.value);
        toolbar.appendChild(el('span', { text: 'Quality:', style: 'color:var(--text-dim)' }));
        toolbar.appendChild(select);
    }

    if (data.subtitles.length) {
        toolbar.appendChild(el('span', { text: `${data.subtitles.length} subtitle track(s) — use the player’s CC menu.`, style: 'color:var(--text-dim);font-size:13px' }));
    }

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
