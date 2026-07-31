// Custom video player: MP4 (with quality switching), HLS via hls.js, DASH via
// dash.js, WebVTT subtitles proxied same-origin, and a full custom control bar
// (seek + buffer, volume, quality, speed, subtitles, fullscreen, shortcuts).
import { api } from './api.js';
import { el } from './ui.js';

const isHevcSource = (s) => !!s && /hevc|265/i.test(String(s.codec || ''));

// Apple's AVFoundation (Safari on iOS/macOS) only renders HEVC tagged `hvc1`;
// MovieBox ships `hev1` (audio plays, no picture). Firefox/Chrome/Android
// tolerate `hev1`, so only Safari needs the server-side hvc1 remux.
function appleNeedsRemux() {
    const ua = navigator.userAgent;
    const isiOS = /iP(hone|od|ad)/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    const isSafari = /Safari/.test(ua) && !/(Chrome|Chromium|Android|Edg|OPR|Firefox|CriOS|FxiOS)/.test(ua);
    return isiOS || isSafari;
}

let hlsModule = null;
async function loadHls() {
    if (!hlsModule) {
        try { hlsModule = (await import('hls.js')).default; }
        catch { hlsModule = false; }
    }
    return hlsModule;
}

// Attach an HLS (m3u8) source to an arbitrary <video> (used for hero trailers).
export async function attachHls(video, url) {
    const Hls = await loadHls();
    if (Hls && Hls.isSupported()) {
        const hls = new Hls({ maxBufferLength: 20 });
        hls.loadSource(url);
        hls.attachMedia(video);
        return hls;
    }
    video.src = url; // Safari / native HLS
    return null;
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

const I = {
    play: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>',
    pause: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>',
    volume: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 10v4h4l5 5V5L7 10H3zm13.5 2a4.5 4.5 0 0 0-2.5-4v8a4.5 4.5 0 0 0 2.5-4zM14 3.2v2.06a7 7 0 0 1 0 13.48v2.06a9 9 0 0 0 0-17.6z"/></svg>',
    muted: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 10v4h4l5 5V5L7 10H3zm18.3-1.3-1.4-1.4L17 10.2 14.1 7.3l-1.4 1.4L15.6 11.6l-2.9 2.9 1.4 1.4L17 13l2.9 2.9 1.4-1.4L18.4 11.6z"/></svg>',
    cc: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm2.5 6.8c.28 0 .53.12.7.32l1.03-.72A2.4 2.4 0 0 0 7.5 9.2 2.55 2.55 0 0 0 5 11.8v.4a2.55 2.55 0 0 0 2.5 2.6c.78 0 1.47-.36 1.93-.9l-1.03-.72a.9.9 0 0 1-.9.42.95.95 0 0 1-.9-1v-.4a.95.95 0 0 1 .9-1zm7 0c.28 0 .53.12.7.32l1.03-.72a2.4 2.4 0 0 0-1.73-.9 2.55 2.55 0 0 0-2.5 2.6v.4a2.55 2.55 0 0 0 2.5 2.6c.78 0 1.47-.36 1.93-.9l-1.03-.72a.9.9 0 0 1-.9.42.95.95 0 0 1-.9-1v-.4a.95.95 0 0 1 .9-1z"/></svg>',
    gear: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19.4 13a7.8 7.8 0 0 0 0-2l2-1.6-2-3.4-2.4 1a7.6 7.6 0 0 0-1.7-1l-.4-2.5H10.9l-.4 2.5a7.6 7.6 0 0 0-1.7 1l-2.4-1-2 3.4L4.6 11a7.8 7.8 0 0 0 0 2l-2 1.6 2 3.4 2.4-1c.5.4 1.1.7 1.7 1l.4 2.5h4.1l.4-2.5c.6-.3 1.2-.6 1.7-1l2.4 1 2-3.4-2-1.6zM12 15.5A3.5 3.5 0 1 1 12 8.5a3.5 3.5 0 0 1 0 7z"/></svg>',
    enterFs: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 9V4h5v2H6v3H4zm11-5h5v5h-2V6h-3V4zM6 15v3h3v2H4v-5h2zm12 0h2v5h-5v-2h3v-3z"/></svg>',
    exitFs: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 7V4H5v5h5V7H7zm10 0h-3v2h5V4h-2v3zM7 17h3v-2H5v5h2v-3zm10 0v3h2v-5h-5v2h3z"/></svg>',
    bigPlay: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>',
};

const fmt = (s) => {
    if (!isFinite(s) || s < 0) s = 0;
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = Math.floor(s % 60).toString().padStart(2, '0');
    return h ? `${h}:${m.toString().padStart(2, '0')}:${sec}` : `${m}:${sec}`;
};

export class Player {
    constructor(container) {
        this.container = container;
        this.hls = null;
        this.dash = null;
        this.currentSources = [];
        this.subtitles = [];
        this.activeTrack = -1; // -1 = off
        this._progressTimer = null;
        this._hideTimer = null;
        this._seeking = false;
        this.build();
    }

    build() {
        // No `crossorigin`: the media CDN doesn't send CORS headers; subtitles
        // are proxied same-origin so they work regardless.
        this.video = el('video', { playsinline: 'playsinline', preload: 'metadata' });

        this.spinner = el('div', { class: 'vp-spinner', hidden: 'hidden' });
        this.bigBtn = el('button', { class: 'vp-big', 'aria-label': 'Lecture', html: I.bigPlay });

        // Double-tap seek indicators (YouTube-style).
        this.skipL = el('div', { class: 'vp-skip vp-skip-l', text: '\u00AB 10s', hidden: 'hidden' });
        this.skipR = el('div', { class: 'vp-skip vp-skip-r', text: '10s \u00BB', hidden: 'hidden' });

        // Progress / seek bar
        this.buffered = el('div', { class: 'vp-buffered' });
        this.played = el('div', { class: 'vp-played' });
        this.handle = el('div', { class: 'vp-handle' });
        this.progress = el('div', { class: 'vp-progress', role: 'slider', tabindex: '0' }, [
            el('div', { class: 'vp-track' }, [this.buffered, this.played, this.handle]),
        ]);

        this.playBtn = el('button', { class: 'vp-btn', 'aria-label': 'Lecture/Pause', html: I.play });
        this.muteBtn = el('button', { class: 'vp-btn', 'aria-label': 'Muet', html: I.volume });
        this.volume = el('input', { class: 'vp-volume', type: 'range', min: '0', max: '1', step: '0.05', value: '1' });
        this.time = el('span', { class: 'vp-time', text: '0:00 / 0:00' });

        this.ccBtn = el('button', { class: 'vp-btn', 'aria-label': 'Sous-titres', html: I.cc, hidden: 'hidden' });

        // Dedicated quality control (separate from the settings gear).
        this.qualityBtn = el('button', { class: 'vp-btn vp-quality', 'aria-label': 'Qualité', text: 'Auto', hidden: 'hidden' });
        this.qualityMenu = el('div', { class: 'vp-menu', hidden: 'hidden' });

        this.gearBtn = el('button', { class: 'vp-btn', 'aria-label': 'Réglages', html: I.gear });
        this.fsBtn = el('button', { class: 'vp-btn', 'aria-label': 'Plein écran', html: I.enterFs });

        this.menu = el('div', { class: 'vp-menu', hidden: 'hidden' });

        this.controls = el('div', { class: 'vp-controls' }, [
            this.progress,
            el('div', { class: 'vp-row' }, [
                this.playBtn,
                el('div', { class: 'vp-vol' }, [this.muteBtn, this.volume]),
                this.time,
                el('div', { class: 'vp-spacer' }),
                this.ccBtn,
                el('div', { class: 'vp-settings' }, [this.qualityBtn, this.qualityMenu]),
                el('div', { class: 'vp-settings' }, [this.gearBtn, this.menu]),
                this.fsBtn,
            ]),
        ]);

        this.wrap = el('div', { class: 'vp', tabindex: '0' }, [
            this.video, this.spinner, this.skipL, this.skipR, this.bigBtn, this.controls,
        ]);
        this.container.appendChild(this.wrap);

        this.bind();
    }

    bind() {
        const v = this.video;

        const toggle = () => (v.paused ? this.play() : v.pause());
        this.playBtn.onclick = toggle;
        this.bigBtn.onclick = toggle;

        // Single click toggles play/pause; a double-click is disambiguated with
        // a short delay so it can trigger seek / pause zones instead.
        this._clickTimer = null;
        v.addEventListener('click', () => {
            if (this._clickTimer) return; // a double-click is in progress
            this._clickTimer = setTimeout(() => { this._clickTimer = null; toggle(); }, 220);
        });
        v.addEventListener('dblclick', (e) => {
            if (this._clickTimer) { clearTimeout(this._clickTimer); this._clickTimer = null; }
            const zone = this.zoneOf(e.clientX);
            if (zone === 'left') this.seekBy(-10);
            else if (zone === 'right') this.seekBy(10);
            else toggle(); // centre → double-clic = pause / lecture
        });

        v.addEventListener('play', () => { this.playBtn.innerHTML = I.pause; this.wrap.classList.add('vp-playing'); this.scheduleHide(); });
        v.addEventListener('pause', () => { this.playBtn.innerHTML = I.play; this.wrap.classList.remove('vp-playing'); this.showControls(); });
        v.addEventListener('waiting', () => { this.spinner.hidden = false; });
        v.addEventListener('playing', () => { this.spinner.hidden = true; });
        v.addEventListener('canplay', () => { this.spinner.hidden = true; });
        v.addEventListener('error', () => this.onVideoError());
        // Playing HEVC on Safari can succeed for audio while the picture never
        // decodes (videoWidth stays 0) -> retry through the hvc1 remux.
        v.addEventListener('loadeddata', () => {
            if (v.videoWidth === 0 && v.videoHeight === 0) this.onVideoError();
        });
        v.addEventListener('timeupdate', () => this.updateProgress());
        v.addEventListener('progress', () => this.updateProgress());
        v.addEventListener('loadedmetadata', () => this.updateProgress());
        v.addEventListener('ended', () => { this.playBtn.innerHTML = I.play; this.showControls(); });

        // Seeking
        const seekTo = (clientX) => {
            const r = this.progress.getBoundingClientRect();
            const ratio = Math.min(1, Math.max(0, (clientX - r.left) / r.width));
            if (isFinite(v.duration)) v.currentTime = ratio * v.duration;
        };
        this.progress.addEventListener('pointerdown', (e) => {
            this._seeking = true;
            this.progress.setPointerCapture(e.pointerId);
            seekTo(e.clientX);
        });
        this.progress.addEventListener('pointermove', (e) => { if (this._seeking) seekTo(e.clientX); });
        this.progress.addEventListener('pointerup', (e) => { this._seeking = false; try { this.progress.releasePointerCapture(e.pointerId); } catch { /* */ } });

        // Volume
        this.muteBtn.onclick = () => { v.muted = !v.muted; this.updateVolumeUi(); };
        this.volume.oninput = () => { v.volume = parseFloat(this.volume.value); v.muted = v.volume === 0; this.updateVolumeUi(); };
        v.addEventListener('volumechange', () => this.updateVolumeUi());

        // Subtitles
        this.ccBtn.onclick = () => this.cycleSubtitle();

        // Quality menu (dedicated button)
        this.qualityBtn.onclick = (e) => { e.stopPropagation(); this.qualityMenu.hidden ? this.openQualityMenu() : (this.qualityMenu.hidden = true); };

        // Settings menu
        this.gearBtn.onclick = (e) => { e.stopPropagation(); this.menu.hidden ? this.openMenu() : (this.menu.hidden = true); };
        document.addEventListener('click', this._docClick = (e) => {
            if (!this.wrap.contains(e.target)) { this.menu.hidden = true; this.qualityMenu.hidden = true; }
        });

        // Fullscreen
        this.fsBtn.onclick = () => this.toggleFullscreen();
        document.addEventListener('fullscreenchange', this._fsChange = () => {
            const fs = document.fullscreenElement === this.wrap;
            this.wrap.classList.toggle('vp-fs', fs);
            this.fsBtn.innerHTML = fs ? I.exitFs : I.enterFs;
        });

        // Auto-hide controls
        this.wrap.addEventListener('pointermove', () => { this.showControls(); this.scheduleHide(); });
        this.wrap.addEventListener('pointerleave', () => { if (!v.paused) this.hideControls(); });

        // Keyboard shortcuts
        this.wrap.addEventListener('keydown', (e) => {
            switch (e.key) {
                case ' ': case 'k': e.preventDefault(); toggle(); break;
                case 'ArrowRight': this.seekBy(10); break;
                case 'ArrowLeft': this.seekBy(-10); break;
                case 'ArrowUp': e.preventDefault(); v.volume = Math.min(1, v.volume + 0.1); break;
                case 'ArrowDown': e.preventDefault(); v.volume = Math.max(0, v.volume - 0.1); break;
                case 'f': this.toggleFullscreen(); break;
                case 'm': v.muted = !v.muted; this.updateVolumeUi(); break;
                default: return;
            }
            this.showControls();
            this.scheduleHide();
        });

        this.updateVolumeUi();
    }

    updateProgress() {
        const v = this.video;
        const d = v.duration || 0;
        this.played.style.width = d ? `${(v.currentTime / d) * 100}%` : '0%';
        this.handle.style.left = d ? `${(v.currentTime / d) * 100}%` : '0%';
        try {
            if (v.buffered.length) {
                this.buffered.style.width = d ? `${(v.buffered.end(v.buffered.length - 1) / d) * 100}%` : '0%';
            }
        } catch { /* */ }
        this.time.textContent = `${fmt(v.currentTime)} / ${fmt(d)}`;
    }

    updateVolumeUi() {
        const v = this.video;
        this.muteBtn.innerHTML = (v.muted || v.volume === 0) ? I.muted : I.volume;
        this.volume.value = v.muted ? 0 : v.volume;
    }

    showControls() { this.wrap.classList.add('vp-active'); }
    hideControls() { if (!this.menu.hidden) return; this.wrap.classList.remove('vp-active'); }
    scheduleHide() {
        clearTimeout(this._hideTimer);
        this._hideTimer = setTimeout(() => { if (!this.video.paused) this.hideControls(); }, 3000);
    }

    // Which horizontal third of the player was interacted with.
    zoneOf(clientX) {
        const r = this.wrap.getBoundingClientRect();
        const x = (clientX - r.left) / (r.width || 1);
        if (x < 0.35) return 'left';
        if (x > 0.65) return 'right';
        return 'center';
    }

    // Jump forward/backward by N seconds, with a brief on-screen indicator.
    seekBy(delta) {
        const v = this.video;
        const d = isFinite(v.duration) ? v.duration : 0;
        const target = v.currentTime + delta;
        v.currentTime = Math.max(0, d ? Math.min(d, target) : target);

        const ind = delta < 0 ? this.skipL : this.skipR;
        ind.hidden = false;
        ind.classList.add('vp-skip-on');
        clearTimeout(ind._t);
        ind._t = setTimeout(() => { ind.classList.remove('vp-skip-on'); ind.hidden = true; }, 550);

        this.showControls();
        this.scheduleHide();
    }

    toggleFullscreen() {
        const doc = document;
        const inFs = doc.fullscreenElement === this.wrap || doc.webkitFullscreenElement === this.wrap;
        if (inFs) {
            (doc.exitFullscreen || doc.webkitExitFullscreen || (() => {})).call(doc);
            return;
        }
        if (this.wrap.requestFullscreen) {
            this.wrap.requestFullscreen();
        } else if (this.wrap.webkitRequestFullscreen) {
            this.wrap.webkitRequestFullscreen();
        } else if (this.video.webkitEnterFullscreen) {
            // iPhone Safari: only the <video> element supports fullscreen.
            this.video.webkitEnterFullscreen();
        }
    }

    // Dedicated quality menu (MP4 sources only; HLS/DASH are adaptive).
    openQualityMenu() {
        const menu = this.qualityMenu;
        menu.innerHTML = '';
        menu.appendChild(el('div', { class: 'vp-menu-title', text: 'Qualité de l\u2019image' }));
        this.currentSources.forEach((s) => {
            const active = this._activeSource && this._activeSource.url === s.url;
            menu.appendChild(el('button', {
                class: `vp-menu-item ${active ? 'active' : ''}`,
                text: s.quality || (s.resolution ? s.resolution + 'p' : 'auto'),
                onclick: () => { this.setMp4(s); this.qualityMenu.hidden = true; },
            }));
        });
        this.menu.hidden = true;
        menu.hidden = false;
    }

    // Reflect the active source on the quality button; hide it when there is
    // nothing to switch (single source, or adaptive HLS/DASH).
    updateQualityUi() {
        const s = this._activeSource;
        const label = s ? (s.quality || (s.resolution ? s.resolution + 'p' : 'Auto')) : 'Auto';
        this.qualityBtn.textContent = label;
        this.qualityBtn.hidden = !(this.currentSources && this.currentSources.length > 1);
    }

    openMenu() {
        const v = this.video;
        const menu = this.menu;
        menu.innerHTML = '';
        this.qualityMenu.hidden = true;

        // Playback speed
        menu.appendChild(el('div', { class: 'vp-menu-title', text: 'Vitesse' }));
        [0.5, 0.75, 1, 1.25, 1.5, 2].forEach((rate) => {
            menu.appendChild(el('button', {
                class: `vp-menu-item ${v.playbackRate === rate ? 'active' : ''}`,
                text: rate === 1 ? 'Normale' : rate + '×',
                onclick: () => { v.playbackRate = rate; this.menu.hidden = true; },
            }));
        });

        menu.hidden = false;
    }

    async load(data) {
        this.destroyHls();
        this.destroyDash();
        const { sources = [], hls = [], dash = [], subtitles = [], startTime = 0, onProgress } = data;
        this.currentSources = sources;
        this._activeSource = null;

        if (sources.length) {
            this.setMp4(sources[0]);
        } else if (hls.length) {
            await this.setHls(hls[0]);
        } else if (dash.length) {
            await this.setDash(dash[0]);
        } else {
            throw new Error('No playable source found for this title.');
        }

        this.setSubtitles(subtitles);
        this.updateQualityUi();

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

    // Resolve the best URL for an MP4 source: on Safari, HEVC is routed through
    // the hvc1 remux so the picture renders (audio-only otherwise).
    mp4UrlFor(source) {
        if (source && source.remux && isHevcSource(source) && appleNeedsRemux()) {
            return source.remux;
        }
        return source ? source.url : null;
    }

    setMp4(source) {
        // Accept either a source object or a bare URL string (back-compat).
        if (typeof source === 'string') source = { url: source };
        this.destroyHls();
        this._activeSource = source;
        const url = this.mp4UrlFor(source);
        this._triedRemux = !!(source && source.remux && url === source.remux);
        this._setSrc(url);
        this.updateQualityUi();
    }

    _setSrc(url) {
        const t = this.video.currentTime;
        const wasPlaying = !this.video.paused;
        this.video.src = url;
        this.video.addEventListener('loadedmetadata', () => {
            if (t) this.video.currentTime = t;
            if (wasPlaying) this.play();
        }, { once: true });
    }

    // If a direct HEVC source fails to render (error, or metadata loaded but no
    // video track), fall back once to the server-side hvc1 remux.
    onVideoError() {
        const s = this._activeSource;
        if (s && s.remux && !this._triedRemux) {
            this._triedRemux = true;
            this._setSrc(s.remux);
        }
    }

    async setHls(url) {
        const Hls = await loadHls();
        if (Hls && Hls.isSupported()) {
            this.hls = new Hls({ maxBufferLength: 30 });
            this.hls.loadSource(url);
            this.hls.attachMedia(this.video);
        } else {
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
            this.video.src = url;
        }
    }

    setSubtitles(subtitles) {
        this.video.querySelectorAll('track').forEach((t) => t.remove());
        this.subtitles = subtitles || [];
        this.activeTrack = -1;
        this.subtitles.forEach((sub, i) => {
            if (!sub.url) return;
            const track = el('track', {
                kind: 'subtitles',
                label: sub.label || sub.lang || `Piste ${i + 1}`,
                srclang: (sub.lang || 'en').slice(0, 2),
                src: api.subtitleUrl(sub.url),
            });
            this.video.appendChild(track);
        });
        // Hide all tracks initially (custom CC control drives visibility).
        Array.from(this.video.textTracks).forEach((t) => { t.mode = 'hidden'; });
        this.ccBtn.hidden = this.subtitles.length === 0;
        this.ccBtn.classList.remove('active');
    }

    cycleSubtitle() {
        const tracks = this.video.textTracks;
        if (!tracks.length) return;
        Array.from(tracks).forEach((t) => { t.mode = 'hidden'; });
        this.activeTrack += 1;
        if (this.activeTrack >= tracks.length) this.activeTrack = -1;
        if (this.activeTrack >= 0) {
            tracks[this.activeTrack].mode = 'showing';
            this.ccBtn.classList.add('active');
        } else {
            this.ccBtn.classList.remove('active');
        }
    }

    play() { this.video.play().catch(() => {}); }

    destroyHls() { if (this.hls) { this.hls.destroy(); this.hls = null; } }
    destroyDash() { if (this.dash) { try { this.dash.reset(); } catch { /* */ } this.dash = null; } }

    destroy() {
        clearInterval(this._progressTimer);
        clearTimeout(this._hideTimer);
        document.removeEventListener('click', this._docClick);
        document.removeEventListener('fullscreenchange', this._fsChange);
        this.destroyHls();
        this.destroyDash();
        this.video.pause();
        this.video.removeAttribute('src');
        this.video.load();
    }
}
