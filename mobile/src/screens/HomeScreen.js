import React, { useEffect, useState, useCallback } from 'react';
import { ScrollView, View, Text, FlatList, ActivityIndicator, TouchableOpacity, ImageBackground, useWindowDimensions, StyleSheet } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../api';
import { getApiBase } from '../config';
import PosterCard from '../components/PosterCard';
import { colors } from '../theme';

const TABS = [
    { key: 'tendance', label: 'Pour toi' },
    { key: 'series', label: 'Séries TV' },
    { key: 'films', label: 'Film' },
];

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

function CategoryTabs({ current, onChange }) {
    return (
        <View style={styles.tabs}>
            {TABS.map((t) => (
                <TouchableOpacity key={t.key} onPress={() => onChange(t.key)} style={styles.tab}>
                    <Text style={[styles.tabLabel, current === t.key && styles.tabActive]}>{t.label}</Text>
                    {current === t.key ? <View style={styles.tabBar} /> : null}
                </TouchableOpacity>
            ))}
        </View>
    );
}

export default function HomeScreen({ navigation }) {
    const { width } = useWindowDimensions();
    const insets = useSafeAreaInsets();
    const [tab, setTab] = useState('tendance');
    const [sections, setSections] = useState([]);
    const [items, setItems] = useState([]);
    const [hero, setHero] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            if (tab === 'tendance') {
                const [h, t] = await Promise.allSettled([api.home(), api.trending(1)]);
                const home = h.status === 'fulfilled' ? h.value : null;
                const trending = t.status === 'fulfilled' ? t.value : null;
                if (!home && !trending) {
                    const reason = (h.reason && h.reason.message) || (t.reason && t.reason.message) || '';
                    throw new Error(`Impossible de charger les données.\n\n${reason}\n\nDéfinis l'URL de ton site dans src/config.js (API_BASE).`);
                }
                const secs = [];
                if (trending && trending.items && trending.items.length) secs.push({ title: 'Les plus regardés', items: trending.items });
                (home && home.sections ? home.sections : []).forEach((s) => secs.push(s));
                setSections(secs);
                setItems([]);
                setHero((secs[0] && secs[0].items ? secs[0].items[0] : null) || null);
            } else {
                const data = await api.category(tab, 1);
                const list = data.items || [];
                setItems(list);
                setSections([]);
                setHero(list[0] || null);
            }
        } catch (e) {
            setError(e.message);
        } finally {
            setLoading(false);
        }
    }, [tab]);

    useEffect(() => { load(); }, [load]);

    const openDetail = (item) => navigation.navigate('Detail', { item });

    const searchBar = (
        <View style={[styles.searchWrap, { paddingTop: insets.top + 8 }]}>
            <TouchableOpacity style={styles.searchBar} activeOpacity={0.85} onPress={() => navigation.navigate('Search')}>
                <Ionicons name="search" size={18} color={colors.dim} />
                <Text style={styles.searchPlaceholder}>Rechercher un film, une série…</Text>
            </TouchableOpacity>
        </View>
    );

    let body;
    if (error) {
        body = (
            <View style={styles.center}>
                <Text style={styles.errText}>{error}</Text>
                <TouchableOpacity onPress={load} style={styles.retry}><Text style={styles.retryText}>Réessayer</Text></TouchableOpacity>
            </View>
        );
    } else if (loading) {
        body = <View style={styles.center}><ActivityIndicator color={colors.accent} size="large" /></View>;
    } else if (tab !== 'tendance') {
        const colW = Math.floor((width - 12 * 4) / 3);
        body = (
            <FlatList
                data={items}
                key="grid3"
                numColumns={3}
                keyExtractor={(it, idx) => `${it.subjectId}-${idx}`}
                ListHeaderComponent={<Hero item={hero} onPress={() => hero && openDetail(hero)} />}
                columnWrapperStyle={{ paddingHorizontal: 12, justifyContent: 'space-between' }}
                renderItem={({ item }) => <PosterCard item={item} width={colW} onPress={() => openDetail(item)} />}
                ListEmptyComponent={<Text style={styles.empty}>Aucun titre.</Text>}
            />
        );
    } else {
        body = (
            <ScrollView>
                <Hero item={hero} onPress={() => hero && openDetail(hero)} />
                {sections.map((s, i) => (
                    <View key={`${s.title}-${i}`} style={{ marginTop: 18 }}>
                        <Text style={styles.rowTitle}>{s.title}</Text>
                        <FlatList
                            horizontal
                            data={s.items}
                            keyExtractor={(it, idx) => `${it.subjectId}-${idx}`}
                            showsHorizontalScrollIndicator={false}
                            contentContainerStyle={{ paddingHorizontal: 12 }}
                            renderItem={({ item }) => <PosterCard item={item} onPress={() => openDetail(item)} />}
                        />
                    </View>
                ))}
                <View style={{ height: 24 }} />
            </ScrollView>
        );
    }

    return (
        <View style={{ flex: 1, backgroundColor: colors.bg }}>
            {searchBar}
            <CategoryTabs current={tab} onChange={setTab} />
            <View style={{ flex: 1 }}>{body}</View>
        </View>
    );
}

const styles = StyleSheet.create({
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: 28 },
    searchWrap: { backgroundColor: colors.header, paddingHorizontal: 12, paddingBottom: 8 },
    searchBar: { flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: colors.card, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 11 },
    searchPlaceholder: { color: colors.dim, fontSize: 14 },
    tabs: { flexDirection: 'row', paddingHorizontal: 12, backgroundColor: colors.header },
    tab: { marginRight: 22, paddingVertical: 10, alignItems: 'center' },
    tabLabel: { color: colors.dim, fontSize: 16, fontWeight: '700' },
    tabActive: { color: colors.text },
    tabBar: { height: 3, backgroundColor: colors.accent, borderRadius: 3, alignSelf: 'stretch', marginTop: 5 },
    hero: { height: 300, justifyContent: 'flex-end', backgroundColor: colors.card },
    heroShade: { ...StyleSheet.absoluteFillObject, backgroundColor: 'rgba(11,11,15,0.45)' },
    heroContent: { padding: 16 },
    heroBadge: { color: colors.accent2, fontWeight: '800', fontSize: 12, marginBottom: 6, letterSpacing: 0.5 },
    heroTitle: { color: '#fff', fontSize: 26, fontWeight: '900' },
    heroMeta: { color: '#d6d6de', fontSize: 13, marginTop: 6 },
    heroBtn: { backgroundColor: colors.accent, alignSelf: 'flex-start', paddingHorizontal: 20, paddingVertical: 10, borderRadius: 8, marginTop: 14 },
    heroBtnText: { color: '#fff', fontWeight: '800' },
    rowTitle: { color: colors.text, fontSize: 17, fontWeight: '700', marginLeft: 12, marginBottom: 10 },
    empty: { color: colors.dim, textAlign: 'center', marginTop: 40 },
    errText: { color: colors.dim, textAlign: 'center', lineHeight: 20, marginBottom: 16 },
    retry: { backgroundColor: colors.accent, paddingHorizontal: 18, paddingVertical: 10, borderRadius: 8 },
    retryText: { color: '#fff', fontWeight: '700' },
});
