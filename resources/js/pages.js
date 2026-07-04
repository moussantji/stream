// Page renderers for the SPA.
import { api, isAuthed } from './api.js';
import {
    el, clear, row, grid, card, skeletonRow, loadingState, errorState, emptyState,
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
        api.trending(0, 24),
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

    if (trending.length) app.appendChild(row('Trending Now', trending));
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
                el('button', { class: 'btn btn-primary', html: `${PLAY_SVG} <span>Play</span>`, style: 'display:flex;gap:8px;align-items:center', onclick: () => navigate(watchHref(item)) }),
                el('button', { class: 'btn btn-ghost', text: 'More info', onclick: () => navigate(detailHref(item)) }),
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
        el('h2', { class: 'section-title', text: 'Continue Watching' }),
        el('div', { class: 'row-scroller' }, cards),
    ]);
}

// ---------- TRENDING ----------
export async function trendingPage(app) {
    clear(app);
    app.appendChild(el('div', { class: 'container' }, [el('h2', { class: 'section-title', text: 'Trending' }), loadingState()]));
    try {
        const data = await api.trending(0, 48);
        clear(app);
        app.appendChild(el('div', { class: 'container' }, [
            el('h2', { class: 'section-title', text: 'Trending Now' }),
            data.items.length ? grid(data.items) : emptyState('Nothing trending right now.'),
        ]));
    } catch (e) {
        clear(app);
        app.appendChild(errorState(e.message, () => trendingPage(app)));
    }
}

// ---------- SEARCH ----------
export async function searchPage(app, params) {
    const q = params.get('q') || '';
    const type = params.get('type') || 'all';
    clear(app);

    const tabs = el('div', { class: 'season-tabs' }, ['all', 'movies', 'tv-series'].map((t) =>
        el('button', {
            class: `season-tab ${t === type ? 'active' : ''}`,
            text: t === 'tv-series' ? 'Series' : t.charAt(0).toUpperCase() + t.slice(1),
            onclick: () => navigate(`/search?q=${encodeURIComponent(q)}&type=${t}`),
        })));

    const results = el('div', {}, [loadingState('Searching…')]);
    app.appendChild(el('div', { class: 'container' }, [
        el('h2', { class: 'section-title', text: `Results for “${q}”` }),
        tabs,
        results,
    ]));

    try {
        const data = await api.search(q, type);
        clear(results);
        results.appendChild(data.items.length ? grid(data.items) : emptyState('No matches found.', 'Try a different keyword or filter.'));
    } catch (e) {
        clear(results);
        results.appendChild(errorState(e.message, () => searchPage(app, params)));
    }
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

    const shell = el('div', { class: 'player-shell' }, [loadingState('Preparing stream…')]);
    const toolbar = el('div', { class: 'player-toolbar' });
    const label = season > 0 ? `${item.title} — S${season} E${episode}` : item.title;

    app.appendChild(el('div', { class: 'watch-wrap' }, [
        el('button', { class: 'btn btn-ghost', text: '← Back', onclick: () => navigate(detailHref(item)) }),
        el('h1', { class: 'watch-title', text: label || 'Now Playing' }),
        shell,
        toolbar,
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
        data = await api.play({ subjectId: item.subjectId, detailPath: item.detailPath, season, episode });
    } catch (e) {
        clear(shell);
        shell.appendChild(el('div', { class: 'player-message' }, [errorState(e.message, () => watchPage(app, params))]));
        return;
    }

    if (!data.sources.length && !data.hls.length) {
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
