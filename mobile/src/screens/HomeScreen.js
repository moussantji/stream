import React, { useEffect, useState, useCallback } from 'react';
import { ScrollView, View, Text, FlatList, ActivityIndicator, TouchableOpacity, ImageBackground, StyleSheet } from 'react-native';
import { api } from '../api';
import PosterCard from '../components/PosterCard';
import { colors } from '../theme';

function Hero({ item, onPress }) {
    if (!item) return null;
    return (
        <TouchableOpacity activeOpacity={0.9} onPress={onPress}>
            <ImageBackground source={item.cover ? { uri: item.cover } : undefined} style={styles.hero} resizeMode="cover">
                <View style={styles.heroShade} />
                <View style={styles.heroContent}>
                    {item.typeLabel ? <Text style={styles.heroBadge}>{item.typeLabel}{item.french ? '  ·  VF' : ''}</Text> : null}
                    <Text style={styles.heroTitle} numberOfLines={2}>{item.title}</Text>
                    <Text style={styles.heroMeta} numberOfLines={1}>
                        {[item.year, item.imdbRating ? `★ ${item.imdbRating}` : null, (item.genres || []).slice(0, 2).join(', ')]
                            .filter(Boolean).join('   ·   ')}
                    </Text>
                    <View style={styles.heroBtn}><Text style={styles.heroBtnText}>▶  Regarder</Text></View>
                </View>
            </ImageBackground>
        </TouchableOpacity>
    );
}

export default function HomeScreen({ navigation }) {
    const [sections, setSections] = useState([]);
    const [hero, setHero] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const [home, trending] = await Promise.all([
                api.home().catch(() => ({ sections: [] })),
                api.trending(1).catch(() => ({ items: [] })),
            ]);
            const secs = [];
            if (trending.items && trending.items.length) secs.push({ title: 'Les plus regardés', items: trending.items });
            (home.sections || []).forEach((s) => secs.push(s));
            setSections(secs);
            const first = (secs[0] && secs[0].items) || [];
            setHero(first[0] || null);
        } catch (e) {
            setError(e.message);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    if (loading) return <View style={styles.center}><ActivityIndicator color={colors.accent} size="large" /></View>;
    if (error) {
        return (
            <View style={styles.center}>
                <Text style={{ color: colors.dim, marginBottom: 12 }}>{error}</Text>
                <TouchableOpacity onPress={load} style={styles.retry}><Text style={styles.retryText}>Réessayer</Text></TouchableOpacity>
            </View>
        );
    }

    return (
        <ScrollView style={{ backgroundColor: colors.bg }}>
            <Hero item={hero} onPress={() => navigation.navigate('Detail', { item: hero })} />
            {sections.map((s, i) => (
                <View key={`${s.title}-${i}`} style={{ marginTop: 18 }}>
                    <Text style={styles.rowTitle}>{s.title}</Text>
                    <FlatList
                        horizontal
                        data={s.items}
                        keyExtractor={(it, idx) => `${it.subjectId}-${idx}`}
                        showsHorizontalScrollIndicator={false}
                        contentContainerStyle={{ paddingHorizontal: 12 }}
                        renderItem={({ item }) => (
                            <PosterCard item={item} onPress={() => navigation.navigate('Detail', { item })} />
                        )}
                    />
                </View>
            ))}
            <View style={{ height: 24 }} />
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg },
    hero: { height: 300, justifyContent: 'flex-end', backgroundColor: colors.card },
    heroShade: { ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(11,11,15,0.45)' },
    heroContent: { padding: 16 },
    heroBadge: { color: colors.accent2, fontWeight: '800', fontSize: 12, marginBottom: 6, letterSpacing: 0.5 },
    heroTitle: { color: '#fff', fontSize: 26, fontWeight: '900' },
    heroMeta: { color: '#d6d6de', fontSize: 13, marginTop: 6 },
    heroBtn: { backgroundColor: colors.accent, alignSelf: 'flex-start', paddingHorizontal: 20, paddingVertical: 10, borderRadius: 8, marginTop: 14 },
    heroBtnText: { color: '#fff', fontWeight: '800' },
    rowTitle: { color: colors.text, fontSize: 17, fontWeight: '700', marginLeft: 12, marginBottom: 10 },
    retry: { backgroundColor: colors.accent, paddingHorizontal: 18, paddingVertical: 10, borderRadius: 8 },
    retryText: { color: '#fff', fontWeight: '700' },
});
