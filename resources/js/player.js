// Custom video player: MP4 (with quality switching), HLS via hls.js, DASH via
// dash.js, WebVTT subtitles proxied same-origin, and a full custom control bar
// (seek + buffer, volume, quality, speed, subtitles, fullscreen, shortcuts).
import { api } from './api.js';
import { el } from './ui.js';

const isHevcSource = (s) => !!s && (/hevc|265/i.test(String(s.codec || ''))
    || /\/h265\/|_h265_|\.hevc\./i.test(String(s.url || '')));

// HEVC decode support varies wildly: Chrome needs a hardware decoder or the
// proprietary codec, Firefox desktop usually has none, Safari/Apple have it.
// dash.js cannot play HEVC DASH on a device that can't decode it — the video
// just loops on `waiting` forever. Detect support up front so we can skip the
// adaptive HEVC stream and play the (H.264) MP4 instead.
function hevcSupported() {
    if (typeof MediaSource === 'undefined' || !MediaSource.isTypeSupported) return false;
    return ['hvc1.1.6.L93.B0', 'hvc1.1.6.L120.90', 'hvc1.1.6.L150.90',
        'hev1.1.6.L93.B0', 'hev1.1.6.L120.90', 'hev1.1.6.L150.90']
        .some((codec) => MediaSource.isTypeSupported(`video/mp4; codecs="${codec}"`));
}

// MovieBox ships HEVC tagged `hev1`. A large share of decoders — including
// Apple's AVFoundation (Safari on iOS/macOS) and several Android/Chrome
// hardware decoders — only render `hvc1` and play `hev1` as audio-only. The
// server-side remux rewrites the sample-entry fourcc to `hvc1`, so every HEVC
// source is routed through it for reliable playback on all devices.

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
    back10: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 5V1L7 6l5 5V7a6 6 0 1 1-6 6H4a8 8 0 1 0 8-8z"/></svg>',
    fwd10: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 5V1l5 5-5 5V7a6 6 0 1 0 6 6h2a8 8 0 1 1-8-8z"/></svg>',
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
        this._waitT = null;
        this.build();
    }

    build() {
        // No `crossorigin`: the media CDN doesn't send CORS headers; subtitles
        // are proxied same-origin so they work regardless. `preload="auto"`
        // lets the browser pivot immediately, fetching the stream index + first
        // frames as soon as the source is set (before the user hits play).
        this.video = el('video', { playsinline: 'playsinline', preload: 'auto' });

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

        this.seekBackBtn = el('button', { class: 'vp-btn', 'aria-label': 'Reculer de 10 s', html: I.back10, onclick: () => this.seekBy(-10) });
        this.seekFwdBtn = el('button', { class: 'vp-btn', 'aria-label': 'Avancer de 10 s', html: I.fwd10, onclick: () => this.seekBy(10) });
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
                this.seekBackBtn, this.playBtn, this.seekFwdBtn,
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
        this.errorEl = el('div', { class: 'vp-error', hidden: 'hidden' });
        this.wrap.appendChild(this.errorEl);
        this.container.appendChild(this.wrap);

        this.bind();
    }

    bind() {
        const v = this.video;

        const toggle = () => (v.paused ? this.play() : v.pause());
        this.playBtn.onclick = toggle;
        this.bigBtn.onclick = toggle;

        // Single tap only opens/closes the control bar (never touches playback);
        // a double tap pauses/resumes, and on the left/right thirds it seeks ±10s
        // (YouTube-style). The two component clicks of a double tap just toggle
        // the control bar twice, leaving playback untouched.
        v.addEventListener('click', () => this.toggleControls());
        v.addEventListener('dblclick', (e) => {
            const zone = this.zoneOf(e.clientX);
            if (zone === 'left') this.seekBy(-10);
            else if (zone === 'right') this.seekBy(10);
            else toggle(); // centre → double-clic = pause / lecture
        });

        v.addEventListener('play', () => { this.playBtn.innerHTML = I.pause; this.wrap.classList.add('vp-playing'); this.scheduleHide(); });
        v.addEventListener('pause', () => { this.playBtn.innerHTML = I.play; this.wrap.classList.remove('vp-playing'); this.showControls(); });
        // The proxy streams in ~256KB chunks, so short network stalls may still
        // fire a `waiting` between chunk bursts. Debounce the spinner: only show
        // it when the buffer is genuinely starved for a sustained moment, and
        // clear it on any sign of playback progress — otherwise it flickers or
        // stays stuck over a playing video.
        v.addEventListener('waiting', () => {
            clearTimeout(this._waitT);
            this._waitT = setTimeout(() => { this.spinner.hidden = false; }, 500);
        });
        v.addEventListener('playing', () => {
            this._started = true;
            clearTimeout(this._watchdog);
            this.hideSpinner();
            // A slow-but-working pipe must not keep the error overlay visible:
            // hide it as soon as real playback starts.
            this.errorEl.hidden = true;
            this.bigBtn.style.display = '';
        });
        v.addEventListener('canplay', () => this.hideSpinner());
        v.addEventListener('timeupdate', () => this.hideSpinner());
        v.addEventListener('error', () => this.onVideoError());
        // Some devices report an audio-only decode as success while the video
        // track never materialises (videoWidth stays 0) -> retry through the
        // hvc1 remux when one exists.
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

        // Two stalls in a row on the same source = the pipe cannot sustain it:
        // drop to the next-lower MP4 quality (adaptive DASH/HLS rely on ABR).
        this._stallCount = 0;
        this._stallTimer = null;
        v.addEventListener('waiting', () => this.onStall());
        v.addEventListener('playing', () => {
            clearTimeout(this._stallTimer);
            this._stallTimer = setTimeout(() => { this._stallCount = 0; }, 30000);
        });

        this.updateVolumeUi();
    }

    onStall() {
        if (!this._started) return; // startup stalls are the watchdog's job
        clearTimeout(this._stallTimer);
        this._stallTimer = setTimeout(() => {
            if (this.video.readyState >= 3) return; // recovered before the check
            this._stallCount += 1;
            if (this._stallCount < 2) return;
            this._stallCount = 0;
            this.downgradeQuality();
        }, 1200);
    }

    // Move to the next-lower MP4 source (sources are ordered desc by resolution).
    downgradeQuality() {
        if (this.dash || this.hls) return; // adaptive streams self-adjust
        const ordered = [...this.currentSources].sort((a, b) =>
            Number(b.resolution || 0) - Number(a.resolution || 0)
        );
        const idx = ordered.findIndex((s) => this._activeSource && s.url === this._activeSource.url);
        if (idx < 0 || idx >= ordered.length - 1) return;
        const next = ordered[idx + 1];
        if (next && next.url) this.setMp4(next);
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
    toggleControls() {
        if (this.wrap.classList.contains('vp-active')) {
            this.hideControls();
        } else {
            this.showControls();
            this.scheduleHide();
        }
    }
    hideSpinner() { clearTimeout(this._waitT); this.spinner.hidden = true; }
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
        if (this.currentSources.length === 0) {
            // Adaptive-only stream (DASH/HLS): quality is picked automatically.
            menu.appendChild(el('div', { class: 'vp-menu-item', text: 'Auto (adaptatif)' }));
        } else {
            this.currentSources.forEach((s) => {
                const active = this._activeSource && this._activeSource.url === s.url;
                menu.appendChild(el('button', {
                    class: `vp-menu-item ${active ? 'active' : ''}`,
                    text: s.quality || (s.resolution ? s.resolution + 'p' : 'auto'),
                    onclick: () => { this.setMp4(s); this.qualityMenu.hidden = true; },
                }));
            });
        }
        this.menu.hidden = true;
        menu.hidden = false;
    }

    // Reflect the active source on the quality button. Always visible as a
    // dedicated control (separate from the settings gear); with a single
    // source or an adaptive stream it just shows the current pick.
    updateQualityUi() {
        const s = this._activeSource;
        const label = s ? (s.quality || (s.resolution ? s.resolution + 'p' : 'Auto')) : 'Auto';
        this.qualityBtn.textContent = label;
        this.qualityBtn.hidden = false;
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
        this._triedAdaptiveFallback = false;
        this._triedSources = new Set();
        this._started = false;
        clearTimeout(this._watchdog);
        const { sources = [], hls = [], dash = [], subtitles = [], startTime = 0, onProgress } = data;
        this.currentSources = sources;
        this._activeSource = null;

        // Source selection: keep the highest available resolution instead of
        // always choosing H.264. A catalogue may only offer H.264 at 480p and
        // HEVC at 1080p; supported browsers should use the higher-quality file.
        const hevc = hevcSupported();
        const h264 = [...sources.filter((s) => !isHevcSource(s))].sort((a, b) =>
            Number(b.resolution || 0) - Number(a.resolution || 0)
        );
        const ordered = [...sources].sort((a, b) =>
            Number(b.resolution || 0) - Number(a.resolution || 0)
        );
        // Prefer H.264 for smoothness: it decodes lighter and plays direct from
        // the CDN, while HEVC always goes through the PHP remux proxy. Only when
        // no H.264 exists do we pick the top (HEVC) rendition.
        // Prefer the adaptive stream (DASH/HLS) whenever possible: it starts
        // almost instantly (low-bitrate first segments, then ABR climbs to the
        // best quality) and never stalls on a single heavy MP4 piped through
        // the PHP proxy. DASH here is HEVC (needs a decoder); HLS is H.264 and
        // plays everywhere via hls.js. If an adaptive stream fails to start,
        // the watchdog falls back to the H.264 MP4.
        if (dash.length && hevc) {
            await this.setDash(dash[0]);
            this.startWatchdog();
        } else if (hls.length) {
            await this.setHls(hls[0]);
            this.startWatchdog();
        } else if (h264.length) {
            this.setMp4(h264[0]);
            this.startWatchdog();
        } else if (sources.length) {
            // Only HEVC left and the device can't decode it — trying the
            // adaptive HEVC stream anyway just loops on `waiting` forever.
            // Fall back to the MP4 so the error surfaces (or plays when a
            // hardware decoder or the hvc1 remux makes it work).
            this.setMp4(sources[0]);
            this.startWatchdog();
        } else if (dash.length) {
            // No MP4 fallback on this device — try the adaptive stream anyway;
            // the watchdog + error handlers bail if it cannot start.
            await this.setDash(dash[0]);
            this.startWatchdog();
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

        // Best-effort auto-start: the user just navigated here (often without a
        // fresh gesture, so the browser may reject it) — but when allowed, the
        // film begins as soon as the stream is buffered, with no extra click.
        this.play().then(() => { this._autoPlayed = true; }).catch(() => { /* gesture required */ });

        if (onProgress) {
            clearInterval(this._progressTimer);
            this._progressTimer = setInterval(() => {
                if (!this.video.paused && this.video.currentTime > 0) {
                    onProgress(Math.floor(this.video.currentTime), Math.floor(this.video.duration || 0));
                }
            }, 8000);
        }
    }

    // Resolve the best URL for an MP4 source: HEVC is always routed through the
    // server-side hvc1 remux — `hev1` plays audio-only on too many devices to
    // ever serve it raw (H.264 sources stay on the fast direct CDN URL).
    mp4UrlFor(source) {
        if (source && source.remux && isHevcSource(source)) {
            return source.remux;
        }
        return source ? source.url : null;
    }

    setMp4(source) {
        // Accept either a source object or a bare URL string (back-compat).
        if (typeof source === 'string') source = { url: source };
        this.destroyHls();
        clearTimeout(this._watchdog);
        this._stallCount = 0;
        this._activeSource = source;
        const url = this.mp4UrlFor(source);
        this._triedRemux = !!(source && source.remux && url === source.remux);
        this._setSrc(url);
        // Silent stalls (e.g. an undecodable HEVC file on a device without a
        // hardware decoder) never fire a `video error` — surface them instead
        // of spinning forever.
        this.startWatchdog();
        this.updateQualityUi();
    }

    _setSrc(url, position) {
        const t = (typeof position === 'number' && isFinite(position)) ? position : this.video.currentTime;
        const wasPlaying = !this.video.paused;
        this.video.src = url;
        this.video.addEventListener('loadedmetadata', () => {
            if (t) this.video.currentTime = t;
            if (wasPlaying) this.play();
        }, { once: true });
    }

    // If playback fails (error, or metadata loaded but no video track), retry
    // through the hvc1 remux when the source is HEVC, else try the next
    // lower-quality source; if an adaptive (DASH/HLS) stream was playing, fall
    // back to a downloadable MP4. When no fallback remains, surface a visible
    // error instead of spinning forever.
    onVideoError() {
        const s = this._activeSource;
        if (s && s.remux && !this._triedRemux) {
            this._triedRemux = true;
            this._setSrc(s.remux);
            return;
        }
        if (s && s.url) this._triedSources.add(s.url);
        // currentSources is already sorted by resolution desc (H.264 ahead of
        // HEVC at equal resolution), so the first untried one is the best
        // quality we haven't attempted yet.
        const next = this.currentSources.find((c) => c.url && !this._triedSources.has(c.url));
        if (next) {
            this.setMp4(next);
            return;
        }
        if ((this.dash || this.hls) && this.currentSources.length && !this._triedAdaptiveFallback) {
            this._triedAdaptiveFallback = true;
            this.destroyDash();
            this.destroyHls();
            // Prefer an H.264 MP4 when falling back from adaptive HEVC (a device
            // that couldn't decode the HEVC stream usually can't decode HEVC MP4).
            const mp4 = this.currentSources.find((s) => !isHevcSource(s)) || this.currentSources[0];
            this.setMp4(mp4);
            return;
        }
        this.showError('La lecture a échoué. Rechargez la page ou essayez un autre titre.');
    }

    // Display a visible error overlay in place of the endless spinner.
    showError(message) {
        this.hideSpinner();
        clearTimeout(this._watchdog);
        this.errorEl.textContent = message;
        this.errorEl.hidden = false;
        this.bigBtn.style.display = 'none';
    }

    // If an adaptive stream is selected but the video hasn't started within the
    // grace period, it's stuck (missing HEVC decoder, bad manifest, …) — fall
    // back to a downloadable MP4 instead of looping on the spinner forever. The
    // grace is generous: the proxy on a slow host can take 15s+ to push the
    // first bytes on a cold pipe.
    startWatchdog() {
        clearTimeout(this._watchdog);
        this._watchdog = setTimeout(() => {
            if (!this._started && this.video.currentTime === 0) {
                this.onVideoError();
            }
        }, 20000);
    }

    async setHls(url) {
        const Hls = await loadHls();
        if (Hls && Hls.isSupported()) {
            this.hls = new Hls({ maxBufferLength: 30, abrEwmaDefaultEstimate: 500000 });
            this.hls.on(Hls.Events.ERROR, (_, data) => {
                if (data && (data.fatal || (data.type === Hls.ErrorTypes.MEDIA_ERROR && data.details === Hls.ErrorDetails.BUFFER_APPEND_ERROR))) {
                    this.onVideoError();
                }
            });
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
            this.dash.updateSettings({
                streaming: {
                    // Start fast: begin at ~480p (small first segments) instead of
                    // letting ABR pick the 1080p rendition (a 5s 1080p chunk is
                    // ~3.8MB and delays the first frame by seconds). dash.js then
                    // adapts up as bandwidth allows.
                    buffer: {
                        bufferTimeAtTopQuality: 60,
                        fastSwitchEnabled: true,
                        // The upstream CDN is throttled (~50KB/s/connection): keep
                        // a deep buffer ahead of the playhead so brief stalls in
                        // the CDN don't pause the video.
                        minBufferTime: 12,
                    },
                    abr: {
                        autoSwitchBitrate: { video: true, audio: true },
                        initialBitrate: { video: 500000, audio: 48000 },
                    },
                },
            });
            this.dash.on(dashjs.MediaPlayer.events.ERROR, (event) => {
                // The main "can't play at all" cases: no codec support or an
                // unparsable manifest — fall back to an MP4 source.
                const code = event && event.error && event.error.code;
                if (code === 'capabilityError' || code === 'manifestError') {
                    this.onVideoError();
                }
            });
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

    play() { return this.video.play().catch(() => {}); }

    destroyHls() { if (this.hls) { this.hls.destroy(); this.hls = null; } }
    destroyDash() { if (this.dash) { try { this.dash.reset(); } catch { /* */ } this.dash = null; } }

    destroy() {
        clearInterval(this._progressTimer);
        clearTimeout(this._hideTimer);
        clearTimeout(this._watchdog);
        document.removeEventListener('click', this._docClick);
        document.removeEventListener('fullscreenchange', this._fsChange);
        this.destroyHls();
        this.destroyDash();
        this.video.pause();
        this.video.removeAttribute('src');
        this.video.load();
    }
}
