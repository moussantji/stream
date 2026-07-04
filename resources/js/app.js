import '../css/app.css';
import { api } from './api.js';
import { navigate, renderAuthArea, el, clear } from './ui.js';
import { homePage, trendingPage, searchPage, detailPage, watchPage, libraryPage, categoryPage, channelsPage, localPage } from './pages.js';

const appRoot = document.getElementById('app');

// ---------------- Router ----------------
function route() {
    const path = window.location.pathname;
    const params = new URLSearchParams(window.location.search);

    window.scrollTo(0, 0);
    highlightNav(path);
    closeMobileNav();

    switch (true) {
        case path === '/' :
            return homePage(appRoot);
        case path === '/films':
            return categoryPage(appRoot, 'films', 'Films');
        case path === '/series':
            return categoryPage(appRoot, 'series', 'Séries & Émissions');
        case path === '/animation':
            return categoryPage(appRoot, 'animation', 'Animation');
        case path === '/tv':
            return channelsPage(appRoot);
        case path === '/local':
            return localPage(appRoot, params);
        case path === '/populaires':
        case path === '/trending':
            return trendingPage(appRoot, 'Les plus regardés');
        case path === '/search':
            return searchPage(appRoot, params);
        case path === '/title':
            return detailPage(appRoot, params);
        case path === '/watch':
            return watchPage(appRoot, params);
        case path === '/library':
            return libraryPage(appRoot);
        default:
            clear(appRoot);
            appRoot.appendChild(el('div', { class: 'state' }, [
                el('h2', { text: 'Page not found' }),
                el('button', { class: 'btn btn-primary', text: 'Go home', onclick: () => navigate('/') }),
            ]));
    }
}

function highlightNav(path) {
    document.querySelectorAll('[data-nav]').forEach((a) => {
        a.classList.toggle('active', a.getAttribute('data-nav') === path);
    });
}

function closeMobileNav() {
    const nav = document.getElementById('main-nav');
    const toggle = document.getElementById('nav-toggle');
    if (nav) nav.classList.remove('open');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
}

function initNavToggle() {
    const nav = document.getElementById('main-nav');
    const toggle = document.getElementById('nav-toggle');
    if (!nav || !toggle) return;
    toggle.addEventListener('click', () => {
        const open = nav.classList.toggle('open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
}

// Intercept internal link clicks for SPA navigation.
document.addEventListener('click', (e) => {
    const link = e.target.closest('a[data-link]');
    if (!link) return;
    const href = link.getAttribute('href');
    if (href && href.startsWith('/')) {
        e.preventDefault();
        navigate(href);
    }
});

window.addEventListener('popstate', route);
window.addEventListener('auth:changed', () => { renderAuthArea(); });

// ---------------- Search box ----------------
function initSearch() {
    const form = document.getElementById('search-form');
    const input = document.getElementById('search-input');
    const suggestBox = document.getElementById('suggestions');
    let timer = null;

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const q = input.value.trim();
        if (q) { suggestBox.hidden = true; navigate(`/search?q=${encodeURIComponent(q)}`); }
    });

    input.addEventListener('input', () => {
        const q = input.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { suggestBox.hidden = true; return; }
        timer = setTimeout(async () => {
            try {
                const data = await api.suggest(q);
                clear(suggestBox);
                if (!data.suggestions.length) { suggestBox.hidden = true; return; }
                data.suggestions.slice(0, 8).forEach((s) => {
                    suggestBox.appendChild(el('button', {
                        type: 'button', text: s.word,
                        onclick: () => { input.value = s.word; suggestBox.hidden = true; navigate(`/search?q=${encodeURIComponent(s.word)}`); },
                    }));
                });
                suggestBox.hidden = false;
            } catch { suggestBox.hidden = true; }
        }, 250);
    });

    document.addEventListener('click', (e) => {
        if (!form.contains(e.target)) suggestBox.hidden = true;
    });
}

// ---------------- Init ----------------
renderAuthArea();
initSearch();
initNavToggle();
route();
