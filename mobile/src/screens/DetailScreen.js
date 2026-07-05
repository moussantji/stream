import React, { useEffect, useState } from 'react';
import { ScrollView, View, Text, Image, TouchableOpacity, ActivityIndicator, StyleSheet } from 'react-native';
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

    useEffect(() => {
        navigation.setOptions({ title: item.title || '' });
    }, [navigation, item]);

    if (loading) {
        return <View style={styles.center}><ActivityIndicator color={colors.accent} size="large" /></View>;
    }

    const meta = data.item || item;
    const isSeries = data.isSeries && data.seasons && data.seasons.length;

    return (
        <ScrollView style={{ backgroundColor: colors.bg }} contentContainerStyle={{ padding: 16 }}>
            <View style={{ flexDirection: 'row' }}>
                {meta.cover ? <Image source={{ uri: meta.cover }} style={styles.poster} /> : <View style={[styles.poster, styles.ph]} />}
                <View style={{ flex: 1, marginLeft: 14 }}>
                    <Text style={styles.title}>{meta.title}</Text>
                    <Text style={styles.sub}>
                        {[meta.year, meta.imdbRating ? `★ ${meta.imdbRating}` : null, meta.country]
                            .filter(Boolean).join('  ·  ')}
                    </Text>
                    <Text style={styles.sub}>{(meta.genres || []).slice(0, 3).join(', ')}</Text>
                    {meta.french ? <Text style={styles.vf}>VF disponible</Text> : null}

                    {!isSeries ? (
                        <TouchableOpacity
                            style={styles.play}
                            onPress={() => navigation.navigate('Watch', { item: meta, season: 0, episode: 0 })}
                        >
                            <Text style={styles.playText}>▶  Lecture</Text>
                        </TouchableOpacity>
                    ) : null}
                </View>
            </View>

            {meta.description ? <Text style={styles.desc}>{meta.description}</Text> : null}

            {isSeries ? (
                <View style={{ marginTop: 18 }}>
                    <Text style={styles.section}>Épisodes</Text>

                    {data.seasons.length > 1 ? (
                        <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ marginBottom: 12 }}>
                            {data.seasons.map((s) => (
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
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg },
    poster: { width: 120, height: 180, borderRadius: 10, backgroundColor: colors.card },
    ph: {},
    title: { color: colors.text, fontSize: 20, fontWeight: '800' },
    sub: { color: colors.dim, fontSize: 13, marginTop: 6 },
    vf: { color: colors.accent2, fontSize: 12, fontWeight: '700', marginTop: 6 },
    desc: { color: '#d6d6de', fontSize: 14, lineHeight: 21, marginTop: 16 },
    section: { color: colors.text, fontSize: 16, fontWeight: '700', marginBottom: 10 },
    play: { backgroundColor: colors.accent, borderRadius: 8, paddingVertical: 10, alignItems: 'center', marginTop: 14 },
    playText: { color: '#fff', fontWeight: '700' },
    tab: { backgroundColor: colors.card, borderRadius: 8, borderWidth: 1, borderColor: colors.border, paddingHorizontal: 14, paddingVertical: 8, marginRight: 8 },
    tabActive: { backgroundColor: colors.accent, borderColor: 'transparent' },
    tabText: { color: colors.text, fontWeight: '600' },
    epGrid: { flexDirection: 'row', flexWrap: 'wrap' },
    ep: { width: 56, height: 44, backgroundColor: colors.card, borderRadius: 8, borderWidth: 1, borderColor: colors.border, alignItems: 'center', justifyContent: 'center', marginRight: 8, marginBottom: 8 },
    epText: { color: colors.text, fontWeight: '700' },
});
