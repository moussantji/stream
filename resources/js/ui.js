// DOM helpers + reusable UI pieces (cards, rows, skeletons, toasts, modals).
import { api, ApiError, setAuth, clearAuth, isAuthed, currentUser } from './api.js';

// ---- tiny DOM helper ----
export function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v === null || v === undefined || v === false) continue;
        if (k === 'class') node.className = v;
        else if (k === 'html') node.innerHTML = v;
        else if (k === 'text') node.textContent = v;
        else if (k.startsWith('on') && typeof v === 'function') node.addEventListener(k.slice(2), v);
        else if (k === 'dataset') Object.assign(node.dataset, v);
        else node.setAttribute(k, v);
    }
    (Array.isArray(children) ? children : [children]).forEach((c) => {
        if (c === null || c === undefined || c === false) return;
        node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return node;
}

export function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); return node; }

// ---- routing helper (History API) ----
export function navigate(path) {
    window.history.pushState({}, '', path);
    window.dispatchEvent(new PopStateEvent('popstate'));
}

export function detailHref(item) {
    const p = new URLSearchParams({
        subjectId: item.subjectId,
        detailPath: item.detailPath || '',
        subjectType: item.subjectType ?? 0,
    });
    if (item.title) p.set('title', item.title);
    if (item.cover) p.set('cover', item.cover);
    return `/title?${p.toString()}`;
}

export function watchHref(item, season = 0, episode = 0) {
    const p = new URLSearchParams({
        subjectId: item.subjectId,
        detailPath: item.detailPath || '',
        subjectType: item.subjectType ?? 0,
        season,
        episode,
    });
    if (item.title) p.set('title', item.title);
    if (item.cover) p.set('cover', item.cover);
    return `/watch?${p.toString()}`;
}

const PLAY_ICON = '<svg viewBox="0 0 24 24" width="22" height="22" fill="#fff"><path d="M8 5v14l11-7z"/></svg>';

// ---- content card ----
export function card(item, opts = {}) {
    const poster = item.cover
        ? el('img', { src: item.cover, alt: item.title, loading: 'lazy', onerror: (e) => { e.target.replaceWith(el('div', { class: 'ph', text: item.title || 'No image' })); } })
        : el('div', { class: 'ph', text: item.title || 'No image' });

    const posterWrap = el('div', { class: 'card-poster' }, [
        poster,
        el('div', { class: 'card-type', text: item.typeLabel || '' }),
        item.french ? el('div', { class: 'card-fr', title: 'Audio français disponible' }, [el('span', { text: 'VF' })]) : null,
        el('div', { class: 'card-play' }, [el('span', { html: PLAY_ICON })]),
    ]);

    if (opts.progress) {
        posterWrap.appendChild(el('div', { class: 'progress-bar' }, [
            el('span', { style: `width:${Math.min(100, Math.max(0, opts.progress))}%` }),
        ]));
    }

    const sub = [];
    if (item.year) sub.push(el('span', { text: String(item.year) }));
    if (item.imdbRating) sub.push(el('span', { class: 'rating', text: `★ ${item.imdbRating}` }));

    return el('div', {
        class: 'card',
        role: 'button',
        tabindex: '0',
        onclick: () => navigate(detailHref(item)),
        onkeydown: (e) => { if (e.key === 'Enter') navigate(detailHref(item)); },
    }, [
        posterWrap,
        el('div', { class: 'card-body' }, [
            el('div', { class: 'card-title', text: item.title || 'Untitled' }),
            el('div', { class: 'card-sub' }, sub),
        ]),
    ]);
}

const CHEVRON_L = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>';
const CHEVRON_R = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>';

// Horizontal carousel with prev/next scroll arrows + mouse-wheel scrolling.
export function carousel(cardNodes) {
    const scroller = el('div', { class: 'row-scroller' }, cardNodes);
    const step = () => Math.max(220, scroller.clientWidth * 0.85);
    const go = (dir) => scroller.scrollBy({ left: dir * step(), behavior: 'smooth' });

    const prev = el('button', { class: 'row-nav prev', type: 'button', 'aria-label': 'Précédent', html: CHEVRON_L });
    const next = el('button', { class: 'row-nav next', type: 'button', 'aria-label': 'Suivant', html: CHEVRON_R });
    prev.addEventListener('click', () => go(-1));
    next.addEventListener('click', () => go(1));

    const viewport = el('div', { class: 'row-viewport' }, [prev, scroller, next]);

    // Show arrows only when the row actually overflows; hide the one at the end.
    const sync = () => {
        const max = scroller.scrollWidth - scroller.clientWidth - 2;
        const scrollable = max > 4;
        viewport.classList.toggle('has-nav', scrollable);
        prev.classList.toggle('hidden-nav', !scrollable || scroller.scrollLeft <= 2);
        next.classList.toggle('hidden-nav', !scrollable || scroller.scrollLeft >= max);
    };
    scroller.addEventListener('scroll', sync, { passive: true });
    window.addEventListener('resize', sync);
    requestAnimationFrame(sync);
    setTimeout(sync, 400); // re-check after posters/layout settle

    // Turn vertical wheel gestures into horizontal scrolling over the row.
    scroller.addEventListener('wheel', (e) => {
        if (Math.abs(e.deltaY) > Math.abs(e.deltaX)) {
            scroller.scrollLeft += e.deltaY;
            e.preventDefault();
        }
    }, { passive: false });

    // --- Auto-scroll: advances gently on its own, loops back at the end, and
    // pauses while the user hovers/interacts. Self-cleans when detached. ---
    let autoTimer = null;
    let paused = false;
    const stopAuto = () => { if (autoTimer) { clearInterval(autoTimer); autoTimer = null; } };
    const tick = () => {
        if (!scroller.isConnected) { stopAuto(); return; }
        if (paused || document.hidden) return;
        const max = scroller.scrollWidth - scroller.clientWidth - 2;
        if (max <= 4) return; // nothing to scroll
        if (scroller.scrollLeft >= max - 2) {
            scroller.scrollTo({ left: 0, behavior: 'smooth' }); // loop back to start
        } else {
            scroller.scrollBy({ left: Math.min(step() * 0.8, max - scroller.scrollLeft), behavior: 'smooth' });
        }
    };
    autoTimer = setInterval(tick, 4500);

    const pause = () => { paused = true; };
    const resume = () => { paused = false; };
    viewport.addEventListener('pointerenter', pause);
    viewport.addEventListener('pointerleave', resume);
    viewport.addEventListener('focusin', pause);
    viewport.addEventListener('focusout', resume);
    // Briefly pause after a manual wheel scroll so it doesn't fight the user.
    scroller.addEventListener('wheel', () => { pause(); clearTimeout(scroller._rz); scroller._rz = setTimeout(resume, 2500); }, { passive: true });

    return viewport;
}

export function row(title, items) {
    if (!items || !items.length) return document.createComment('empty row');
    return el('section', { class: 'row container' }, [
        el('h2', { class: 'section-title', text: title }),
        carousel(items.map((i) => card(i))),
    ]);
}

export function grid(items, opts = {}) {
    return el('div', { class: 'grid' }, items.map((i) => card(i, opts)));
}

// ---- states ----
export function loadingState(label = 'Loading…') {
    return el('div', { class: 'state' }, [el('div', { class: 'spinner' }), el('p', { text: label })]);
}

export function skeletonRow() {
    const cards = Array.from({ length: 8 }, () => el('div', { class: 'skeleton sk-card' }));
    return el('section', { class: 'row container' }, [
        el('div', { class: 'skeleton', style: 'height:22px;width:180px;margin:28px 0 14px;border-radius:6px' }),
        el('div', { class: 'row-scroller' }, cards),
    ]);
}

export function errorState(message, onRetry) {
    return el('div', { class: 'state' }, [
        el('h2', { text: 'Something went wrong' }),
        el('p', { text: message }),
        onRetry ? el('button', { class: 'btn btn-primary', text: 'Try again', onclick: onRetry }) : null,
    ]);
}

export function emptyState(title, sub) {
    return el('div', { class: 'state' }, [el('h2', { text: title }), sub ? el('p', { text: sub }) : null]);
}

// ---- toast ----
export function toast(message, type = 'info') {
    const rootEl = document.getElementById('toast-root');
    const node = el('div', { class: `toast ${type === 'error' ? 'error' : ''}`, text: message });
    rootEl.appendChild(node);
    setTimeout(() => { node.style.opacity = '0'; setTimeout(() => node.remove(), 250); }, 2800);
}

// ---- modal ----
export function closeModal() {
    const root = document.getElementById('modal-root');
    root.hidden = true;
    clear(root);
}

export function openModal(contentNode) {
    const root = document.getElementById('modal-root');
    clear(root);
    root.hidden = false;
    root.onclick = (e) => { if (e.target === root) closeModal(); };
    root.appendChild(contentNode);
}

// ---- auth modal ----
export function openAuthModal(mode = 'login') {
    const errorBox = el('div', { class: 'form-error' });

    const buildLogin = () => {
        const form = el('form', { class: 'auth-form' }, [
            el('h2', { text: 'Welcome back' }),
            el('p', { class: 'sub', text: 'Sign in to keep your list and continue watching.' }),
            errorBox,
            field('Email', 'email', 'email', 'you@example.com'),
            field('Password', 'password', 'password', '••••••••'),
            el('button', { class: 'btn btn-primary btn-block', type: 'submit', text: 'Sign in' }),
            el('div', { class: 'modal-switch' }, [
                'New here? ',
                el('button', { type: 'button', text: 'Create an account', onclick: () => openAuthModal('register') }),
            ]),
        ]);
        form.onsubmit = async (e) => {
            e.preventDefault();
            errorBox.textContent = '';
            const fd = new FormData(form);
            try {
                const res = await api.login({ email: fd.get('email'), password: fd.get('password') });
                setAuth(res.token, res.user);
                closeModal();
                toast(`Signed in as ${res.user?.name || 'you'}.`);
            } catch (err) { errorBox.textContent = extractError(err); }
        };
        return form;
    };

    const buildRegister = () => {
        const form = el('form', { class: 'auth-form' }, [
            el('h2', { text: 'Create your account' }),
            el('p', { class: 'sub', text: 'It only takes a moment.' }),
            errorBox,
            field('Name', 'name', 'text', 'Your name'),
            field('Email', 'email', 'email', 'you@example.com'),
            field('Password', 'password', 'password', 'At least 8 characters'),
            field('Confirm password', 'password_confirmation', 'password', 'Repeat password'),
            el('button', { class: 'btn btn-primary btn-block', type: 'submit', text: 'Sign up' }),
            el('div', { class: 'modal-switch' }, [
                'Already have an account? ',
                el('button', { type: 'button', text: 'Sign in', onclick: () => openAuthModal('login') }),
            ]),
        ]);
        form.onsubmit = async (e) => {
            e.preventDefault();
            errorBox.textContent = '';
            const fd = new FormData(form);
            try {
                const res = await api.register({
                    name: fd.get('name'),
                    email: fd.get('email'),
                    password: fd.get('password'),
                    password_confirmation: fd.get('password_confirmation'),
                });
                setAuth(res.token, res.user);
                closeModal();
                toast('Account created — welcome!');
            } catch (err) { errorBox.textContent = extractError(err); }
        };
        return form;
    };

    openModal(el('div', { class: 'modal' }, [mode === 'register' ? buildRegister() : buildLogin()]));
}

function field(label, name, type, placeholder) {
    return el('div', { class: 'field' }, [
        el('label', { text: label, for: name }),
        el('input', { id: name, name, type, placeholder, required: 'required' }),
    ]);
}

function extractError(err) {
    if (err instanceof ApiError) {
        const first = err.errors && Object.values(err.errors)[0];
        return Array.isArray(first) ? first[0] : err.message;
    }
    return 'Unexpected error. Please try again.';
}

// ---- header auth area ----
export function renderAuthArea() {
    const area = document.getElementById('auth-area');
    clear(area);

    if (isAuthed()) {
        const user = currentUser();
        const initial = (user?.name || 'U').charAt(0).toUpperCase();
        area.appendChild(el('div', { class: 'avatar-chip' }, [
            el('span', { text: user?.name || 'Account' }),
            el('span', { class: 'dot', text: initial }),
        ]));
        area.appendChild(el('button', {
            class: 'btn btn-ghost', text: 'Sign out',
            onclick: async () => {
                try { await api.logout(); } catch { /* ignore */ }
                clearAuth();
                toast('Signed out.');
                navigate('/');
            },
        }));
    } else {
        area.appendChild(el('button', { class: 'btn btn-ghost', text: 'Sign in', onclick: () => openAuthModal('login') }));
        area.appendChild(el('button', { class: 'btn btn-primary', text: 'Sign up', onclick: () => openAuthModal('register') }));
    }

    document.querySelectorAll('[data-auth-only]').forEach((n) => { n.style.display = isAuthed() ? '' : 'none'; });

    const isAdmin = isAuthed() && !!(currentUser()?.is_admin);
    document.querySelectorAll('[data-admin-only]').forEach((n) => { n.style.display = isAdmin ? '' : 'none'; });
}
