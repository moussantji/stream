import React, { useEffect, useState } from 'react';
import { View, Text, ActivityIndicator, StyleSheet, useWindowDimensions, TouchableOpacity, Modal, Alert, ScrollView } from 'react-native';
import { Video, ResizeMode } from 'expo-av';
import { api } from '../api';
import { colors } from '../theme';
import { ensureDir, safeName, createDownload } from '../download';

export default function WatchScreen({ route, navigation }) {
    const { item, season = 0, episode = 0, localUri, title: localTitle } = route.params;
    const { width } = useWindowDimensions();

    const [uri, setUri] = useState(localUri || null);
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
                setSources(data.sources || []);
                const mp4 = (data.sources || []).find((s) => s.url);
                const hls = (data.hls || [])[0];
                if (mp4) setUri(mp4.url);
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
                    <Video style={{ width, height: videoHeight }} source={{ uri }} useNativeControls resizeMode={ResizeMode.CONTAIN} shouldPlay />
                ) : (
                    <Text style={styles.msg}>{message}</Text>
                )}
            </View>

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
