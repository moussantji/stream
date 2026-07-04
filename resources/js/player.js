// Video player: native MP4 with quality switching, HLS via hls.js fallback,
// and WebVTT subtitle tracks proxied through the backend.
import { api } from './api.js';
import { el } from './ui.js';

let hlsModule = null;
async function loadHls() {
    if (!hlsModule) {
        try { hlsModule = (await import('hls.js')).default; }
        catch { hlsModule = false; }
    }
    return hlsModule;
}

let dashModule = null;
async function loadDash() {
    if (!dashModule) {
        try {
            const mod = await import('dashjs');
            dashModule = mod.default || mod;
        } catch { dashModule = false; }
    }
    return dashModule;
}

export class Player {
    constructor(container) {
        this.container = container;
        this.hls = null;
        // NOTE: no `crossorigin` attribute — the media CDN doesn't send CORS
        // headers, and setting it would block cross-origin MP4 playback.
        // Subtitle tracks are proxied same-origin via /api/subtitle, so they
        // work without it.
        this.video = el('video', {
            controls: 'controls',
            playsinline: 'playsinline',
            preload: 'metadata',
        });
        this.container.appendChild(this.video);
        this._progressTimer = null;
    }

    /**
     * @param {{sources:Array, hls:Array, subtitles:Array, startTime?:number, onProgress?:Function}} data
     */
    async load(data) {
        this.destroyHls();
        this.destroyDash();
        const { sources = [], hls = [], dash = [], subtitles = [], startTime = 0, onProgress } = data;

        if (sources.length) {
            this.currentSources = sources;
            this.setMp4(sources[0].url);
        } else if (hls.length) {
            await this.setHls(hls[0]);
        } else if (dash.length) {
            await this.setDash(dash[0]);
        } else {
            throw new Error('No playable source found for this title.');
        }

        this.setSubtitles(subtitles);

        if (startTime > 0) {
            this.video.addEventListener('loadedmetadata', () => {
                if (startTime < (this.video.duration || Infinity) - 10) this.video.currentTime = startTime;
            }, { once: true });
        }

        if (onProgress) {
            clearInterval(this._progressTimer);
            this._progressTimer = setInterval(() => {
                if (!this.video.paused && this.video.currentTime > 0) {
                    onProgress(Math.floor(this.video.currentTime), Math.floor(this.video.duration || 0));
                }
            }, 8000);
        }
    }

    setMp4(url) {
        this.destroyHls();
        const t = this.video.currentTime;
        this.video.src = url;
        this.video.currentTime = t || 0;
    }

    async setHls(url) {
        const Hls = await loadHls();
        if (Hls && Hls.isSupported()) {
            this.hls = new Hls({ maxBufferLength: 30 });
            this.hls.loadSource(url);
            this.hls.attachMedia(this.video);
        } else {
            // Safari / native HLS
            this.video.src = url;
        }
    }

    async setDash(url) {
        const dashjs = await loadDash();
        if (dashjs && dashjs.MediaPlayer) {
            this.dash = dashjs.MediaPlayer().create();
            this.dash.updateSettings({ streaming: { buffer: { bufferTimeAtTopQuality: 30 } } });
            this.dash.initialize(this.video, url, false);
        } else {
            // Native MPD support is rare, but try as a last resort.
            this.video.src = url;
        }
    }

    setSubtitles(subtitles) {
        // Remove existing tracks
        this.video.querySelectorAll('track').forEach((t) => t.remove());
        subtitles.forEach((sub, i) => {
            if (!sub.url) return;
            const track = el('track', {
                kind: 'subtitles',
                label: sub.label || sub.lang || `Track ${i + 1}`,
                srclang: (sub.lang || 'en').slice(0, 2),
                src: api.subtitleUrl(sub.url),
            });
            if (i === 0) track.default = true;
            this.video.appendChild(track);
        });
    }

    play() { this.video.play().catch(() => {}); }

    destroyHls() {
        if (this.hls) { this.hls.destroy(); this.hls = null; }
    }

    destroyDash() {
        if (this.dash) { try { this.dash.reset(); } catch { /* ignore */ } this.dash = null; }
    }

    destroy() {
        clearInterval(this._progressTimer);
        this.destroyHls();
        this.destroyDash();
        this.video.pause();
        this.video.removeAttribute('src');
        this.video.load();
    }
}
