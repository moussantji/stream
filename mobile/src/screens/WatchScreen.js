import React, { useEffect, useState } from 'react';
import { View, Text, ActivityIndicator, StyleSheet, useWindowDimensions, TouchableOpacity, Modal, Alert, ScrollView, Platform } from 'react-native';
import { Video, ResizeMode } from 'expo-av';
import { api } from '../api';
import { colors } from '../theme';
import { ensureDir, safeName, createDownload } from '../download';

const isHevc = (s) => /hevc|265/i.test(String(s && s.codec || ''));

// Prefer H.264 (avc) over HEVC/H.265 for device compatibility (HEVC often plays
// audio without video on Android and can fail on iOS).
function pickSource(sources) {
    const withUrl = (sources || []).filter((s) => s.url);
    const avc = withUrl.filter((s) => !isHevc(s));
    return (avc.length ? avc : withUrl)[0] || null;
}

// On iOS, HEVC only renders when tagged hvc1; route those sources through the
// server-side remux endpoint (field `remux`) so the picture shows up.
function playUri(source) {
    if (!source) return null;
    if (Platform.OS === 'ios' && isHevc(source) && source.remux) return source.remux;
    return source.url;
}

export default function WatchScreen({ route, navigation }) {
    const { item, season = 0, episode = 0, localUri, title: localTitle } = route.params;
    const { width } = useWindowDimensions();

    const [uri, setUri] = useState(localUri || null);
    const [selUrl, setSelUrl] = useState(null);
    const [sources, setSources] = useState([]);
    const [loading, setLoading] = useState(!localUri);
    const [message, setMessage] = useState(null);

    const [pickerOpen, setPickerOpen] = useState(false);
    const [downloading, setDownloading] = useState(false);
    const [progress, setProgress] = useState(0);

    useEffect(() => {
        navigation.setOptions({
            title: localTitle || (season > 0 ? `${item?.title} — S${season}E${episode}` : (item?.title || 'Lecture')),
        });
    }, [navigation]);

    useEffect(() => {
        if (localUri) return; // playing an offline file
        (async () => {
            try {
                const data = await api.play(item, season, episode);
                const srcs = data.sources || [];
                setSources(srcs);
                const best = pickSource(srcs);
                const hls = (data.hls || [])[0];
                if (best) { setSelUrl(best.url); setUri(playUri(best)); }
                else if (hls) setUri(hls);
                else if ((data.dash || []).length) setMessage("Épisode disponible uniquement en DASH (lecture non prise en charge dans l'app).");
                else setMessage('Aucune source de lecture disponible.');
            } catch (e) {
                setMessage(e.message);
            } finally {
                setLoading(false);
            }
        })();
    }, []);

    const doDownload = async (source) => {
        setPickerOpen(false);
        try {
            await ensureDir();
            const suffix = season > 0 ? `_S${season}E${episode}` : '';
            const quality = source.quality || (source.resolution ? `${source.resolution}p` : 'auto');
            const filename = `${safeName(item.title)}${suffix}_${quality}.mp4`;
            setDownloading(true);
            setProgress(0);
            const task = createDownload(source.url, filename, (p) => setProgress(p));
            await task.downloadAsync();
            setDownloading(false);
            Alert.alert('Téléchargé', 'Disponible dans l\'onglet « Téléchargés ».');
        } catch (e) {
            setDownloading(false);
            Alert.alert('Échec du téléchargement', e.message);
        }
    };

    const videoHeight = (width * 9) / 16;

    return (
        <View style={styles.wrap}>
            <View style={{ width, height: videoHeight, backgroundColor: '#000', alignItems: 'center', justifyContent: 'center' }}>
                {loading ? (
                    <ActivityIndicator color={colors.accent} size="large" />
                ) : uri ? (
                    <Video
                        style={{ width, height: videoHeight }}
                        source={{ uri }}
                        useNativeControls
                        resizeMode={ResizeMode.CONTAIN}
                        shouldPlay
                        usePoster={!!(item && item.cover)}
                        posterSource={item && item.cover ? { uri: item.cover } : undefined}
                        posterStyle={{ resizeMode: 'cover' }}
                        onError={() => setMessage("Lecture impossible (format non pris en charge sur cet appareil). Essaie une autre qualité.")}
                    />
                ) : (
                    <Text style={styles.msg}>{message}</Text>
                )}
            </View>

            {message && uri ? <Text style={styles.warn}>{message}</Text> : null}

            {!localUri && sources.length > 1 ? (
                <View style={styles.qualityRow}>
                    {sources.map((s, i) => {
                        const label = s.quality || (s.resolution ? `${s.resolution}p` : 'auto');
                        const active = selUrl === s.url;
                        return (
                            <TouchableOpacity key={i} style={[styles.qBtn, active && styles.qBtnActive]} onPress={() => { setMessage(null); setSelUrl(s.url); setUri(playUri(s)); }}>
                                <Text style={[styles.qText, active && styles.qTextActive]}>{label}</Text>
                            </TouchableOpacity>
                        );
                    })}
                </View>
            ) : null}

            {!localUri && sources.length > 0 ? (
                <View style={styles.tools}>
                    {downloading ? (
                        <View style={styles.progressWrap}>
                            <Text style={styles.progressText}>Téléchargement… {Math.round(progress * 100)}%</Text>
                            <View style={styles.bar}><View style={[styles.barFill, { width: `${Math.round(progress * 100)}%` }]} /></View>
                        </View>
                    ) : (
                        <TouchableOpacity style={styles.dlBtn} onPress={() => setPickerOpen(true)}>
                            <Text style={styles.dlText}>⬇  Télécharger</Text>
                        </TouchableOpacity>
                    )}
                </View>
            ) : null}

            <Modal visible={pickerOpen} transparent animationType="fade" onRequestClose={() => setPickerOpen(false)}>
                <TouchableOpacity style={styles.backdrop} activeOpacity={1} onPress={() => setPickerOpen(false)}>
                    <View style={styles.sheet}>
                        <Text style={styles.sheetTitle}>Qualité du téléchargement</Text>
                        <ScrollView>
                            {sources.map((s, i) => (
                                <TouchableOpacity key={i} style={styles.sheetItem} onPress={() => doDownload(s)}>
                                    <Text style={styles.sheetItemText}>{s.quality || (s.resolution ? `${s.resolution}p` : 'auto')}</Text>
                                    {s.size ? <Text style={styles.sheetItemSize}>{(s.size / 1048576).toFixed(0)} Mo</Text> : null}
                                </TouchableOpacity>
                            ))}
                        </ScrollView>
                    </View>
                </TouchableOpacity>
            </Modal>
        </View>
    );
}

const styles = StyleSheet.create({
    wrap: { flex: 1, backgroundColor: colors.bg },
    msg: { color: colors.dim, textAlign: 'center', paddingHorizontal: 24 },
    warn: { color: colors.accent2, fontSize: 12, paddingHorizontal: 16, paddingTop: 10 },
    qualityRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, paddingHorizontal: 16, paddingTop: 12 },
    qBtn: { backgroundColor: colors.card, borderWidth: 1, borderColor: colors.border, borderRadius: 8, paddingHorizontal: 14, paddingVertical: 8 },
    qBtnActive: { backgroundColor: colors.accent, borderColor: 'transparent' },
    qText: { color: colors.text, fontWeight: '600', fontSize: 13 },
    qTextActive: { color: '#fff' },
    tools: { padding: 16 },
    dlBtn: { backgroundColor: colors.card, borderWidth: 1, borderColor: colors.border, borderRadius: 8, paddingVertical: 12, alignItems: 'center' },
    dlText: { color: colors.text, fontWeight: '700' },
    progressWrap: { paddingVertical: 6 },
    progressText: { color: colors.text, marginBottom: 8 },
    bar: { height: 6, backgroundColor: colors.card, borderRadius: 3, overflow: 'hidden' },
    barFill: { height: '100%', backgroundColor: colors.accent },
    backdrop: { flex: 1, backgroundColor: 'rgba(0,0,0,0.6)', justifyContent: 'flex-end' },
    sheet: { backgroundColor: colors.header, borderTopLeftRadius: 16, borderTopRightRadius: 16, padding: 16, maxHeight: '60%' },
    sheetTitle: { color: colors.text, fontSize: 16, fontWeight: '800', marginBottom: 12 },
    sheetItem: { flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 14, borderBottomWidth: 1, borderBottomColor: colors.border },
    sheetItemText: { color: colors.text, fontSize: 15, fontWeight: '600' },
    sheetItemSize: { color: colors.dim, fontSize: 13 },
});
