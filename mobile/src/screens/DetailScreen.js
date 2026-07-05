import React, { useEffect, useState } from 'react';
import { ScrollView, View, Text, Image, ImageBackground, TouchableOpacity, ActivityIndicator, StyleSheet } from 'react-native';
import { api } from '../api';
import { colors } from '../theme';

export default function DetailScreen({ route, navigation }) {
    const { item } = route.params;
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [season, setSeason] = useState(null);

    useEffect(() => {
        (async () => {
            try {
                const d = await api.detail(item);
                setData(d);
                if (d.seasons && d.seasons.length) setSeason(d.seasons[0]);
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

    const play = () => {
        if (isSeries) {
            const s = season || seasons[0];
            const firstEp = (s.episodes && s.episodes[0]) || 1;
            navigation.navigate('Watch', { item: meta, season: s.season, episode: firstEp });
        } else {
            navigation.navigate('Watch', { item: meta, season: 0, episode: 0 });
        }
    };

    return (
        <ScrollView style={{ backgroundColor: colors.bg }}>
            <ImageBackground source={meta.cover ? { uri: meta.cover } : undefined} style={styles.backdrop} resizeMode="cover">
                <View style={styles.backdropShade} />
                <View style={styles.backdropRow}>
                    {meta.cover ? <Image source={{ uri: meta.cover }} style={styles.poster} /> : <View style={[styles.poster, styles.ph]} />}
                    <View style={{ flex: 1, marginLeft: 14, justifyContent: 'flex-end' }}>
                        <Text style={styles.title} numberOfLines={3}>{meta.title}</Text>
                        <Text style={styles.sub} numberOfLines={1}>
                            {[meta.year, meta.imdbRating ? `★ ${meta.imdbRating}` : null, meta.typeLabel].filter(Boolean).join('  ·  ')}
                        </Text>
                        {meta.french ? <Text style={styles.vf}>VF disponible</Text> : null}
                    </View>
                </View>
            </ImageBackground>

            <View style={{ padding: 16 }}>
                <TouchableOpacity style={styles.play} onPress={play}>
                    <Text style={styles.playText}>▶  {isSeries ? `Lecture S${(season || seasons[0]).season}` : 'Lecture'}</Text>
                </TouchableOpacity>

                <Text style={styles.genres}>{(meta.genres || []).join('  ·  ')}</Text>
                {meta.description ? <Text style={styles.desc}>{meta.description}</Text> : null}

                {isSeries ? (
                    <View style={{ marginTop: 20 }}>
                        <Text style={styles.section}>Épisodes</Text>
                        {seasons.length > 1 ? (
                            <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginBottom: 12 }}>
                                {seasons.map((s) => (
                                    <TouchableOpacity
                                        key={s.season}
                                        onPress={() => setSeason(s)}
                                        style={[styles.tab, season && season.season === s.season && styles.tabActive]}
                                    >
                                        <Text style={styles.tabText}>Saison {s.season}</Text>
                                    </TouchableOpacity>
                                ))}
                            </ScrollView>
                        ) : null}
                        <View style={styles.epGrid}>
                            {(season ? season.episodes : []).map((ep) => (
                                <TouchableOpacity
                                    key={ep}
                                    style={styles.ep}
                                    onPress={() => navigation.navigate('Watch', { item: meta, season: season.season, episode: ep })}
                                >
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
    backdrop: { height: 300, justifyContent: 'flex-end', backgroundColor: colors.card },
    backdropShade: { ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(11,11,15,0.55)' },
    backdropRow: { flexDirection: 'row', padding: 16 },
    poster: { width: 100, height: 150, borderRadius: 10, backgroundColor: colors.card },
    ph: {},
    title: { color: '#fff', fontSize: 22, fontWeight: '900' },
    sub: { color: '#d6d6de', fontSize: 13, marginTop: 6 },
    vf: { color: colors.accent2, fontSize: 12, fontWeight: '700', marginTop: 6 },
    play: { backgroundColor: colors.accent, borderRadius: 8, paddingVertical: 12, alignItems: 'center' },
    playText: { color: '#fff', fontWeight: '800', fontSize: 15 },
    genres: { color: colors.dim, fontSize: 13, marginTop: 14 },
    desc: { color: '#d6d6de', fontSize: 14, lineHeight: 21, marginTop: 10 },
    section: { color: colors.text, fontSize: 16, fontWeight: '700', marginBottom: 10 },
    tab: { backgroundColor: colors.card, borderRadius: 8, borderWidth: 1, borderColor: colors.border, paddingHorizontal: 14, paddingVertical: 8, marginRight: 8 },
    tabActive: { backgroundColor: colors.accent, borderColor: 'transparent' },
    tabText: { color: colors.text, fontWeight: '600' },
    epGrid: { flexDirection: 'row', flexWrap: 'wrap' },
    ep: { width: 56, height: 44, backgroundColor: colors.card, borderRadius: 8, borderWidth: 1, borderColor: colors.border, alignItems: 'center', justifyContent: 'center', marginRight: 8, marginBottom: 8 },
    epText: { color: colors.text, fontWeight: '700' },
});
