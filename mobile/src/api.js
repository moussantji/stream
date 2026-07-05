import { getApiBase } from './config';

function qs(params = {}) {
    const parts = Object.entries(params)
        .filter(([, v]) => v !== undefined && v !== null && v !== '')
        .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`);
    return parts.length ? `?${parts.join('&')}` : '';
}

async function get(path, params) {
    const url = `${getApiBase()}${path}${qs(params)}`;
    let res;
    try {
        res = await fetch(url, { headers: { Accept: 'application/json' } });
    } catch (e) {
        throw new Error(`Connexion impossible :\n${url}`);
    }
    if (!res.ok) {
        throw new Error(`HTTP ${res.status} :\n${url}`);
    }
    const json = await res.json();
    return json && Object.prototype.hasOwnProperty.call(json, 'data') ? json.data : json;
}

export const api = {
    home: () => get('/api/home'),
    trending: (page = 1) => get('/api/trending', { page }),
    category: (tab, page = 1) => get('/api/category', { tab, page }),
    search: (q, type = 'all', page = 1) => get('/api/search', { q, type, page }),
    detail: (item) => get('/api/detail', {
        subjectId: item.subjectId,
        subjectType: item.subjectType,
        title: item.title,
        cover: item.cover,
    }),
    play: (item, season = 0, episode = 0) => get('/api/play', {
        subjectId: item.subjectId,
        season,
        episode,
        title: item.title,
    }),
};
