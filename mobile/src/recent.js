import AsyncStorage from '@react-native-async-storage/async-storage';

const KEY = 'moviebox.recentSearches';
const MAX = 8;

export async function getRecentSearches() {
    try {
        const raw = await AsyncStorage.getItem(KEY);
        const list = raw ? JSON.parse(raw) : [];
        return Array.isArray(list) ? list : [];
    } catch {
        return [];
    }
}

export async function addRecentSearch(query) {
    const q = String(query || '').trim();
    if (!q) return;
    try {
        const list = await getRecentSearches();
        const next = [q, ...list.filter((x) => x.toLowerCase() !== q.toLowerCase())].slice(0, MAX);
        await AsyncStorage.setItem(KEY, JSON.stringify(next));
    } catch {
        /* ignore */
    }
}

export async function removeRecentSearch(query) {
    try {
        const list = await getRecentSearches();
        await AsyncStorage.setItem(KEY, JSON.stringify(list.filter((x) => x !== query)));
    } catch {
        /* ignore */
    }
}

export async function clearRecentSearches() {
    try {
        await AsyncStorage.removeItem(KEY);
    } catch {
        /* ignore */
    }
}
