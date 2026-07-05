import React, { useEffect, useState, useCallback } from 'react';
import { View, Text, FlatList, ActivityIndicator, TouchableOpacity, useWindowDimensions, StyleSheet } from 'react-native';
import { api } from '../api';
import { getApiBase } from '../config';
import PosterCard from '../components/PosterCard';
import { colors } from '../theme';

export default function TrendingScreen({ navigation }) {
    const { width } = useWindowDimensions();
    const [items, setItems] = useState([]);
    const [page, setPage] = useState(1);
    const [hasMore, setHasMore] = useState(true);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const load = useCallback(async (p) => {
        try {
            const data = await api.trending(p);
            const list = data.items || [];
            setItems((prev) => (p === 1 ? list : [...prev, ...list]));
            setHasMore(!!(data.pager && data.pager.hasMore));
            setError(null);
        } catch (e) {
            if (p === 1) setError(`Impossible de joindre le serveur.\n\n${getApiBase()}`);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(1); }, [load]);

    if (loading) return <View style={styles.center}><ActivityIndicator color={colors.accent} size="large" /></View>;
    if (error) {
        return (
            <View style={styles.center}>
                <Text style={styles.err}>{error}</Text>
                <TouchableOpacity onPress={() => { setLoading(true); load(1); }} style={styles.retry}><Text style={styles.retryText}>Réessayer</Text></TouchableOpacity>
            </View>
        );
    }

    const colW = Math.floor((width - 12 * 4) / 3);

    return (
        <FlatList
            style={{ backgroundColor: colors.bg }}
            data={items}
            key="grid3"
            numColumns={3}
            keyExtractor={(it, idx) => `${it.subjectId}-${idx}`}
            columnWrapperStyle={{ paddingHorizontal: 12, justifyContent: 'space-between' }}
            contentContainerStyle={{ paddingTop: 12 }}
            renderItem={({ item }) => <PosterCard item={item} width={colW} onPress={() => navigation.navigate('Detail', { item })} />}
            onEndReachedThreshold={0.5}
            onEndReached={() => { if (hasMore) { const n = page + 1; setPage(n); load(n); } }}
            ListFooterComponent={hasMore ? <ActivityIndicator color={colors.accent} style={{ margin: 20 }} /> : <View style={{ height: 20 }} />}
        />
    );
}

const styles = StyleSheet.create({
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg, padding: 28 },
    err: { color: colors.dim, textAlign: 'center', lineHeight: 20, marginBottom: 16 },
    retry: { backgroundColor: colors.accent, paddingHorizontal: 18, paddingVertical: 10, borderRadius: 8 },
    retryText: { color: '#fff', fontWeight: '700' },
});
