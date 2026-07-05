import React, { useEffect, useState } from 'react';
import { View, Text, ActivityIndicator, StyleSheet, useWindowDimensions } from 'react-native';
import { Video, ResizeMode } from 'expo-av';
import { api } from '../api';
import { colors } from '../theme';

export default function WatchScreen({ route, navigation }) {
    const { item, season = 0, episode = 0 } = route.params;
    const { width } = useWindowDimensions();
    const [uri, setUri] = useState(null);
    const [loading, setLoading] = useState(true);
    const [message, setMessage] = useState(null);

    useEffect(() => {
        navigation.setOptions({
            title: season > 0 ? `${item.title} — S${season}E${episode}` : (item.title || 'Lecture'),
        });
    }, [navigation, item, season, episode]);

    useEffect(() => {
        (async () => {
            try {
                const data = await api.play(item, season, episode);
                // Prefer a direct MP4 (best supported), then HLS. DASH isn't
                // supported by expo-av yet.
                const mp4 = (data.sources || []).find((s) => s.url) || null;
                const hls = (data.hls || [])[0] || null;
                if (mp4) setUri(mp4.url);
                else if (hls) setUri(hls);
                else if ((data.dash || []).length) setMessage('Cet épisode est uniquement disponible en DASH (non pris en charge dans l\'app pour l\'instant).');
                else setMessage('Aucune source de lecture disponible.');
            } catch (e) {
                setMessage(e.message);
            } finally {
                setLoading(false);
            }
        })();
    }, [item, season, episode]);

    const videoHeight = (width * 9) / 16;

    return (
        <View style={styles.wrap}>
            {loading ? (
                <ActivityIndicator color={colors.accent} size="large" />
            ) : uri ? (
                <Video
                    style={{ width, height: videoHeight, backgroundColor: '#000' }}
                    source={{ uri }}
                    useNativeControls
                    resizeMode={ResizeMode.CONTAIN}
                    shouldPlay
                />
            ) : (
                <Text style={styles.msg}>{message}</Text>
            )}
        </View>
    );
}

const styles = StyleSheet.create({
    wrap: { flex: 1, backgroundColor: '#000', alignItems: 'center', justifyContent: 'center' },
    msg: { color: colors.dim, textAlign: 'center', paddingHorizontal: 24 },
});
