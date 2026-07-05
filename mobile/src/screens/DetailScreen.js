import React, { useEffect, useState } from 'react';
import { ScrollView, View, Text, Image, ImageBackground, TouchableOpacity, ActivityIndicator, Alert, StyleSheet } from 'react-native';
import { Video, ResizeMode } from 'expo-av';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../api';
import { colors } from '../theme';
import { ensureDir, safeName, createDownload, formatSize } from '../download';

export default function DetailScreen({ route, navigation }) {
    const { item } = route.params;
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [season, setSeason] = useState(null);
    const [files, setFiles] = useState([]);
    const [dlKey, setDlKey] = useState(null);
    const [progress, setProgress] = useState(0);

    useEffect(() => {
        (async () => {
            try {
                const d = await api.detail(item);
                setData(d);
                if (d.seasons && d.seasons.length) {
                    setSeason(d.seasons[0]);
                } else {
                    // Movie: load the downloadable files (qualities).
                    try {
                        const dl = await api.downloads(item, 0, 0);
                        setFiles(dl.downloads || []);
                    } catch { /* ignore */ }
                }
            } catch {
                setData({ item });
            } finally {
                setLoading(false);
            }
        })();
    }, []);

    if (loading) {
        return <View style={styles.center}><ActivityIndicator color={colors.accent} size="large" /></View>;
    }

    const meta = data.item || item;
    const seasons = (data.isSeries && data.seasons) || [];
    const isSeries = seasons.length > 0;
    const trailer = data.trailer;

    const play = () => {
        if (isSeries) {
            const s = season || seasons[0];
            const firstEp = (s.episodes && s.episodes[0]) || 1;
            navigation.navigate('Watch', { item: meta, season: s.season, episode: firstEp });
        } else {
            navigation.navigate('Watch', { item: meta, season: 0, episode: 0 });
        }
    };

    // Fire-and-forget download (runs in the background: you can keep browsing /
    // start watching the stream meanwhile). Files land in the Downloads tab.
    const doDownload = async (file) => {
        const quality = file.quality || (file.resolution ? `${file.resolution}p` : 'auto');
        const filename = `${safeName(meta.title)}_${quality}.mp4`;
        try {
            await ensureDir();
            setDlKey(filename);
            setProgress(0);
            const task = createDownload(file.url, filename, (p) => setProgress(p));
            await task.downloadAsync();
            setDlKey(null);
            Alert.alert('Téléchargé', 'Disponible dans l\'onglet « Téléchargements ».');
        } catch (e) {
            setDlKey(null);
            Alert.alert('Échec du téléchargement', e.message);
        }
    };

    return (
        <ScrollView style={{ backgroundColor: colors.bg }}>
            <View style={styles.backdrop}>
                {trailer ? (
                    <Video
                        style={StyleSheet.absoluteFill}
                        source={{ uri: trailer }}
                        resizeMode={ResizeMode.COVER}
                        shouldPlay
                        isLooping
                        isMuted
                    />
                ) : meta.cover ? (
                    <ImageBackground source={{ uri: meta.cover }} style={StyleSheet.absoluteFill} resizeMode="cover" />
                ) : null}
                <View style={styles.backdropShade} />
                <View style={styles.backdropRow}>
                    {meta.cover ? <Image source={{ uri: meta.cover }} style={styles.poster} /> : <View style={styles.poster} />}
                    <View style={{ flex: 1, marginLeft: 14, justifyContent: 'flex-end' }}>
                        <Text style={styles.title} numberOfLines={3}>{meta.title}</Text>
                        <Text style={styles.sub} numberOfLines={1}>
                            {[meta.year, meta.imdbRating ? `★ ${meta.imdbRating}` : null, meta.typeLabel].filter(Boolean).join('  ·  ')}
                        </Text>
                        {meta.french ? <Text style={styles.vf}>VF disponible</Text> : null}
                    </View>
                </View>
            </View>

            <View style={{ padding: 16 }}>
                <TouchableOpacity style={styles.play} onPress={play}>
                    <Ionicons name="play" size={18} color="#fff" />
                    <Text style={styles.playText}>{isSeries ? `Lecture S${(season || seasons[0]).season}` : 'Lecture'}</Text>
                </TouchableOpacity>

                <Text style={styles.genres}>{(meta.genres || []).join('  ·  ')}</Text>
                {meta.description ? <Text style={styles.desc}>{meta.description}</Text> : null}

                {/* Movie: downloadable files list */}
                {!isSeries && files.length ? (
                    <View style={{ marginTop: 20 }}>
                        <Text style={styles.section}>Fichiers · téléchargement</Text>
                        {files.map((f, i) => {
                            const quality = f.quality || (f.resolution ? `${f.resolution}p` : 'auto');
                            const key = `${safeName(meta.title)}_${quality}.mp4`;
                            const busy = dlKey === key;
                            return (
                                <View style={styles.fileRow} key={i}>
                                    <View>
                                        <Text style={styles.fileQ}>{quality}</Text>
                                        {f.size ? <Text style={styles.fileSize}>{formatSize(f.size)}</Text> : null}
                                    </View>
                                    <TouchableOpacity style={styles.dlBtn} onPress={() => doDownload(f)} disabled={!!dlKey}>
                                        {busy ? (
                                            <Text style={styles.dlPct}>{Math.round(progress * 100)}%</Text>
                                        ) : (
                                            <Ionicons name="download-outline" size={22} color={colors.text} />
                                        )}
                                    </TouchableOpacity>
                                </View>
                            );
                        })}
                        <Text style={styles.hint}>Tu peux lancer la lecture pendant le téléchargement. Les fichiers restent dans l'app (onglet Téléchargements).</Text>
                    </View>
                ) : null}

                {/* Series: episodes (tap = lecture; téléchargement depuis le lecteur) */}
                {isSeries ? (
                    <View style={{ marginTop: 20 }}>
                        <Text style={styles.section}>Épisodes</Text>
                        {seasons.length > 1 ? (
                            <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginBottom: 12 }}>
                                {seasons.map((s) => (
                                    <TouchableOpacity key={s.season} onPress={() => setSeason(s)} style={[styles.tab, season && season.season === s.season && styles.tabActive]}>
                                        <Text style={styles.tabText}>Saison {s.season}</Text>
                                    </TouchableOpacity>
                                ))}
                            </ScrollView>
                        ) : null}
                        <View style={styles.epGrid}>
                            {(season ? season.episodes : []).map((ep) => (
                                <TouchableOpacity key={ep} style={styles.ep} onPress={() => navigation.navigate('Watch', { item: meta, season: season.season, episode: ep })}>
                                    <Text style={styles.epText}>E{ep}</Text>
                                </TouchableOpacity>
                            ))}
                        </View>
                    </View>
                ) : null}
            </View>
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg },
    backdrop: { height: 320, justifyContent: 'flex-end', backgroundColor: colors.card, overflow: 'hidden' },
    backdropShade: { ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(11,11,15,0.5)' },
    backdropRow: { flexDirection: 'row', padding: 16 },
    poster: { width: 100, height: 150, borderRadius: 10, backgroundColor: colors.card },
    title: { color: '#fff', fontSize: 22, fontWeight: '900' },
    sub: { color: '#d6d6de', fontSize: 13, marginTop: 6 },
    vf: { color: colors.accent2, fontSize: 12, fontWeight: '700', marginTop: 6 },
    play: { flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 8, backgroundColor: colors.accent, borderRadius: 8, paddingVertical: 12 },
    playText: { color: '#fff', fontWeight: '800', fontSize: 15 },
    genres: { color: colors.dim, fontSize: 13, marginTop: 14 },
    desc: { color: '#d6d6de', fontSize: 14, lineHeight: 21, marginTop: 10 },
    section: { color: colors.text, fontSize: 16, fontWeight: '700', marginBottom: 10 },
    fileRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', backgroundColor: colors.card, borderRadius: 10, paddingHorizontal: 14, paddingVertical: 12, marginBottom: 8 },
    fileQ: { color: colors.text, fontSize: 15, fontWeight: '700' },
    fileSize: { color: colors.dim, fontSize: 12, marginTop: 2 },
    dlBtn: { minWidth: 44, alignItems: 'flex-end' },
    dlPct: { color: colors.accent2, fontWeight: '800' },
    hint: { color: colors.dim, fontSize: 12, lineHeight: 18, marginTop: 6 },
    tab: { backgroundColor: colors.card, borderRadius: 8, borderWidth: 1, borderColor: colors.border, paddingHorizontal: 14, paddingVertical: 8, marginRight: 8 },
    tabActive: { backgroundColor: colors.accent, borderColor: 'transparent' },
    tabText: { color: colors.text, fontWeight: '600' },
    epGrid: { flexDirection: 'row', flexWrap: 'wrap' },
    ep: { width: 56, height: 44, backgroundColor: colors.card, borderRadius: 8, borderWidth: 1, borderColor: colors.border, alignItems: 'center', justifyContent: 'center', marginRight: 8, marginBottom: 8 },
    epText: { color: colors.text, fontWeight: '700' },
});
