import React, { useState, useCallback } from 'react';
import { View, TextInput, FlatList, ActivityIndicator, Text, StyleSheet } from 'react-native';
import { api } from '../api';
import PosterCard from '../components/PosterCard';
import { colors } from '../theme';

export default function SearchScreen({ navigation }) {
    const [q, setQ] = useState('');
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(false);
    const [searched, setSearched] = useState(false);

    const run = useCallback(async () => {
        const query = q.trim();
        if (!query) return;
        setLoading(true);
        setSearched(true);
        try {
            const data = await api.search(query, 'all', 1);
            setItems(data.items || []);
        } catch {
            setItems([]);
        } finally {
            setLoading(false);
        }
    }, [q]);

    return (
        <View style={{ flex: 1, backgroundColor: colors.bg }}>
            <TextInput
                style={styles.input}
                placeholder="Rechercher films & séries…"
                placeholderTextColor={colors.dim}
                value={q}
                onChangeText={setQ}
                onSubmitEditing={run}
                returnKeyType="search"
                autoFocus
            />
            {loading ? (
                <View style={styles.center}><ActivityIndicator color={colors.accent} size="large" /></View>
            ) : (
                <FlatList
                    data={items}
                    key="grid3"
                    numColumns={3}
                    keyExtractor={(it, idx) => `${it.subjectId}-${idx}`}
                    contentContainerStyle={{ padding: 12 }}
                    columnWrapperStyle={{ justifyContent: 'space-between' }}
                    renderItem={({ item }) => (
                        <PosterCard item={item} width={104} onPress={() => navigation.navigate('Detail', { item })} />
                    )}
                    ListEmptyComponent={searched ? (
                        <Text style={styles.empty}>Aucun résultat.</Text>
                    ) : (
                        <Text style={styles.empty}>Saisis un mot-clé puis valide.</Text>
                    )}
                />
            )}
        </View>
    );
}

const styles = StyleSheet.create({
    input: {
        margin: 12, backgroundColor: colors.card, borderRadius: 10, borderWidth: 1, borderColor: colors.border,
        color: colors.text, paddingHorizontal: 14, paddingVertical: 10, fontSize: 15,
    },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
    empty: { color: colors.dim, textAlign: 'center', marginTop: 40 },
});
