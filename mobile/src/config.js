import AsyncStorage from '@react-native-async-storage/async-storage';

// URL par défaut du backend du SITE (celui qui sert /api/*).
// Remplace-la par l'URL publique de ton site OU change-la directement dans
// l'app : onglet « Mon compte » → « URL du serveur ». Elle est alors mémorisée
// sur le téléphone (pas besoin de recompiler).
export const DEFAULT_API_BASE = 'https://ton-site.com';

const STORAGE_KEY = 'moviebox.apiBase';
let current = DEFAULT_API_BASE;

/** Current API base URL (sync, used by the API client). */
export function getApiBase() {
    return current;
}

/** Load the saved URL at startup. */
export async function loadApiBase() {
    try {
        const saved = await AsyncStorage.getItem(STORAGE_KEY);
        if (saved) current = saved;
    } catch {
        /* ignore */
    }
    return current;
}

/** Persist a new API base URL (trims trailing slashes). */
export async function setApiBase(url) {
    const clean = String(url || '').trim().replace(/\/+$/, '');
    current = clean || DEFAULT_API_BASE;
    try {
        await AsyncStorage.setItem(STORAGE_KEY, current);
    } catch {
        /* ignore */
    }
    return current;
}
