import * as FileSystem from 'expo-file-system';

// Downloads are stored inside the app's private document directory, so the
// feature lives ONLY in the app (nothing is exposed on the website).
const DIR = FileSystem.documentDirectory + 'downloads/';

export async function ensureDir() {
    const info = await FileSystem.getInfoAsync(DIR);
    if (!info.exists) {
        await FileSystem.makeDirectoryAsync(DIR, { intermediates: true });
    }
}

export function safeName(str) {
    return (str || 'video')
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // strip accents
        .replace(/[^\w\-]+/g, '_')
        .replace(/_+/g, '_')
        .slice(0, 90);
}

export function formatSize(bytes) {
    if (!bytes) return '';
    const mb = bytes / (1024 * 1024);
    return mb >= 1024 ? `${(mb / 1024).toFixed(1)} Go` : `${mb.toFixed(0)} Mo`;
}

export async function listDownloads() {
    await ensureDir();
    const names = await FileSystem.readDirectoryAsync(DIR);
    const files = [];
    for (const name of names) {
        if (!name.endsWith('.mp4')) continue;
        const uri = DIR + name;
        const info = await FileSystem.getInfoAsync(uri, { size: true });
        files.push({ name, uri, size: info.size || 0 });
    }
    return files.sort((a, b) => a.name.localeCompare(b.name));
}

export async function deleteDownload(uri) {
    await FileSystem.deleteAsync(uri, { idempotent: true });
}

// Returns a DownloadResumable; caller awaits .downloadAsync().
export function createDownload(url, filename, onProgress) {
    const target = DIR + filename;
    return FileSystem.createDownloadResumable(url, target, {}, (p) => {
        if (p.totalBytesExpectedToWrite > 0) {
            onProgress(p.totalBytesWritten / p.totalBytesExpectedToWrite);
        }
    });
}

export { DIR as DOWNLOAD_DIR };
