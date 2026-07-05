// Thin API client for the MovieBox Stream backend.
// Sanctum bearer token + user are persisted in localStorage.

const TOKEN_KEY = 'mbx_token';
const USER_KEY = 'mbx_user';

export function getToken() {
    return localStorage.getItem(TOKEN_KEY);
}

export function currentUser() {
    try {
        return JSON.parse(localStorage.getItem(USER_KEY) || 'null');
    } catch {
        return null;
    }
}

export function isAuthed() {
    return !!getToken();
}

export function setAuth(token, user) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(user || null));
    window.dispatchEvent(new CustomEvent('auth:changed'));
}

export function clearAuth() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    window.dispatchEvent(new CustomEvent('auth:changed'));
}

export class ApiError extends Error {
    constructor(message, status, errors) {
        super(message);
        this.status = status;
        this.errors = errors || {};
    }
}

async function request(path, { method = 'GET', params, body } = {}) {
    const url = new URL(`/api/${path.replace(/^\//, '')}`, window.location.origin);
    if (params) {
        Object.entries(params).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
        });
    }

    const headers = { Accept: 'application/json' };
    const token = getToken();
    if (token) headers.Authorization = `Bearer ${token}`;

    const opts = { method, headers };
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }

    let res;
    try {
        res = await fetch(url, opts);
    } catch (e) {
        throw new ApiError('Network error — check your connection.', 0);
    }

    if (res.status === 204) return null;

    let json = null;
    const text = await res.text();
    if (text) {
        try { json = JSON.parse(text); } catch { /* non-json */ }
    }

    if (!res.ok) {
        if (res.status === 401) clearAuth();
        const message = (json && json.message) || `Request failed (${res.status}).`;
        throw new ApiError(message, res.status, json && json.errors);
    }

    return json;
}

const unwrap = (p) => p.then((r) => (r && 'data' in r ? r.data : r));

// Authenticated file download (sends the bearer token, then triggers a
// browser download of the returned blob).
async function downloadFile(path, params, filename) {
    const url = new URL(`/api/${path.replace(/^\//, '')}`, window.location.origin);
    if (params) {
        Object.entries(params).forEach(([k, v]) => {
            if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
        });
    }
    const headers = {};
    const token = getToken();
    if (token) headers.Authorization = `Bearer ${token}`;

    const res = await fetch(url, { headers });
    if (!res.ok) {
        if (res.status === 401) clearAuth();
        throw new ApiError(`Export échoué (${res.status}).`, res.status);
    }
    const blob = await res.blob();
    const objUrl = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = objUrl;
    a.download = filename || 'export.txt';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(objUrl), 2000);
}

export const api = {
    // ---- Auth ----
    register: (payload) => request('auth/register', { method: 'POST', body: payload }),
    login: (payload) => request('auth/login', { method: 'POST', body: payload }),
    logout: () => request('auth/logout', { method: 'POST' }),
    me: () => unwrap(request('auth/me')),

    // ---- Catalog ----
    home: () => unwrap(request('home')),
    trending: (page = 1, type = 'all') => unwrap(request('trending', { params: { page, type } })),
    search: (q, type = 'all', page = 1) => unwrap(request('search', { params: { q, type, page } })),
    suggest: (q) => unwrap(request('suggest', { params: { q } })),
    discover: () => unwrap(request('discover')),
    category: (tab, page = 1) => unwrap(request('category', { params: { tab, page } })),
    channels: () => unwrap(request('channels')),
    local: (params) => unwrap(request('local', { params })),
    detail: (params) => unwrap(request('detail', { params })),

    // ---- Streaming ----
    play: (params) => unwrap(request('play', { params })),
    downloads: (params) => unwrap(request('downloads', { params })),
    subtitleUrl: (url) => `/api/subtitle?url=${encodeURIComponent(url)}`,

    // ---- Admin ----
    adminStats: () => unwrap(request('admin/stats')),
    adminImport: (pages = 15) => unwrap(request('admin/import', { method: 'POST', body: { pages } })),
    exportLinks: (params) => downloadFile('admin/export-links', params, 'moviebox-links.txt'),
    adminBlockedTitles: () => unwrap(request('admin/blocked-titles')),
    adminAddBlockedTitle: (term) => unwrap(request('admin/blocked-titles', { method: 'POST', body: { term } })),
    adminDeleteBlockedTitle: (id) => request(`admin/blocked-titles/${id}`, { method: 'DELETE' }),

    // ---- Library ----
    favorites: () => unwrap(request('favorites')),
    addFavorite: (payload) => unwrap(request('favorites', { method: 'POST', body: payload })),
    removeFavorite: (subjectId) => request(`favorites/${encodeURIComponent(subjectId)}`, { method: 'DELETE' }),
    history: () => unwrap(request('history')),
    saveHistory: (payload) => unwrap(request('history', { method: 'POST', body: payload })),
    removeHistory: (subjectId) => request(`history/${encodeURIComponent(subjectId)}`, { method: 'DELETE' }),
};
