import React, { useEffect, useState, useCallback } from 'react';
import { ScrollView, View, Text, FlatList, ActivityIndicator, TouchableOpacity, StyleSheet } from 'react-native';
import { api } from '../api';
import PosterCard from '../components/PosterCard';
import { colors } from '../theme';

export default function HomeScreen({ navigation }) {
    const [sections, setSections] = useState([]);
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
            if (trending.items && trending.items.length) {
                secs.push({ title: 'Les plus regardés', items: trending.items });
            }
            (home.sections || []).forEach((s) => secs.push(s));
            setSections(secs);
        } catch (e) {
            setError(e.message);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);

    useEffect(() => {
        navigation.setOptions({
            headerRight: () => (
                <TouchableOpacity onPress={() => navigation.navigate('Search')} hitSlop={10}>
                    <Text style={{ color: colors.text, fontSize: 18 }}>🔍</Text>
                </TouchableOpacity>
            ),
        });
    }, [navigation]);

    if (loading) {
        return <View style={styles.center}><ActivityIndicator color={colors.accent} size="large" /></View>;
    }
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
            {sections.map((s, i) => (
                <View key={`${s.title}-${i}`} style={{ marginTop: 16 }}>
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
    rowTitle: { color: colors.text, fontSize: 17, fontWeight: '700', marginLeft: 12, marginBottom: 10 },
    retry: { backgroundColor: colors.accent, paddingHorizontal: 18, paddingVertical: 10, borderRadius: 8 },
    retryText: { color: '#fff', fontWeight: '700' },
});
