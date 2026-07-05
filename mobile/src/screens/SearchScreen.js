import React, { useState, useEffect, useRef, useCallback } from 'react';
import { View, TextInput, FlatList, ActivityIndicator, Text, TouchableOpacity, useWindowDimensions, StyleSheet } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../api';
import PosterCard from '../components/PosterCard';
import { getRecentSearches, addRecentSearch, clearRecentSearches } from '../recent';
import { colors } from '../theme';

export default function SearchScreen({ navigation }) {
    const { width } = useWindowDimensions();
    const insets = useSafeAreaInsets();
    const [q, setQ] = useState('');
    const [suggestions, setSuggestions] = useState([]);
    const [recent, setRecent] = useState([]);
    const [results, setResults] = useState(null);
    const [loading, setLoading] = useState(false);
    const timer = useRef(null);

    const refreshRecent = useCallback(async () => { setRecent(await getRecentSearches()); }, []);
    useEffect(() => { refreshRecent(); }, [refreshRecent]);

    const onChange = (text) => {
        setQ(text);
        setResults(null);
        clearTimeout(timer.current);
        const term = text.trim();
        if (term.length < 2) { setSuggestions([]); return; }
        timer.current = setTimeout(async () => {
            try {
                const data = await api.suggest(term);
                setSuggestions((data.suggestions || []).map((s) => s.word).slice(0, 8));
            } catch {
                setSuggestions([]);
            }
        }, 250);
    };

    const runSearch = async (term) => {
        const query = String(term || '').trim();
        if (!query) return;
        setQ(query);
        setSuggestions([]);
        setLoading(true);
        setResults([]);
        await addRecentSearch(query);
        refreshRecent();
        try {
            const data = await api.search(query, 'all', 1);
            setResults(data.items || []);
        } catch {
            setResults([]);
        } finally {
            setLoading(false);
        }
    };

    const colW = Math.floor((width - 12 * 4) / 3);

    const renderBody = () => {
        if (results !== null) {
            if (loading) return <View style={styles.center}><ActivityIndicator color={colors.accent} size="large" /></View>;
            return (
                <FlatList
                    data={results}
                    key="grid3"
                    numColumns={3}
                    keyExtractor={(it, idx) => `${it.subjectId}-${idx}`}
                    columnWrapperStyle={{ paddingHorizontal: 12, justifyContent: 'space-between' }}
                    contentContainerStyle={{ paddingTop: 8 }}
                    renderItem={({ item }) => <PosterCard item={item} width={colW} onPress={() => navigation.navigate('Detail', { item })} />}
                    ListEmptyComponent={<Text style={styles.empty}>Aucun résultat.</Text>}
                    keyboardShouldPersistTaps="handled"
                />
            );
        }

        if (q.trim().length >= 2 && suggestions.length) {
            return (
                <FlatList
                    data={suggestions}
                    keyExtractor={(s, i) => `${s}-${i}`}
                    keyboardShouldPersistTaps="handled"
                    renderItem={({ item }) => (
                        <TouchableOpacity style={styles.row} onPress={() => runSearch(item)}>
                            <Ionicons name="search" size={17} color={colors.dim} />
                            <Text style={styles.rowText} numberOfLines={1}>{item}</Text>
                        </TouchableOpacity>
                    )}
                />
            );
        }

        // Idle: recent searches.
        return (
            <View>
                {recent.length ? (
                    <View style={styles.recentHead}>
                        <Text style={styles.recentTitle}>Recherches récentes</Text>
                        <TouchableOpacity onPress={async () => { await clearRecentSearches(); refreshRecent(); }}>
                            <Text style={styles.clear}>Effacer</Text>
                        </TouchableOpacity>
                    </View>
                ) : (
                    <Text style={styles.empty}>Cherche un film ou une série.</Text>
                )}
                {recent.map((r, i) => (
                    <TouchableOpacity key={`${r}-${i}`} style={styles.row} onPress={() => runSearch(r)}>
                        <Ionicons name="time-outline" size={18} color={colors.dim} />
                        <Text style={styles.rowText} numberOfLines={1}>{r}</Text>
                    </TouchableOpacity>
                ))}
            </View>
        );
    };

    return (
        <View style={{ flex: 1, backgroundColor: colors.bg }}>
            <View style={[styles.bar, { paddingTop: insets.top + 8 }]}>
                <TouchableOpacity onPress={() => navigation.goBack()} hitSlop={10} style={{ padding: 4 }}>
                    <Ionicons name="arrow-back" size={24} color={colors.text} />
                </TouchableOpacity>
                <View style={styles.inputWrap}>
                    <Ionicons name="search" size={18} color={colors.dim} />
                    <TextInput
                        style={styles.input}
                        value={q}
                        onChangeText={onChange}
                        onSubmitEditing={() => runSearch(q)}
                        placeholder="Rechercher…"
                        placeholderTextColor={colors.dim}
                        autoFocus
                        returnKeyType="search"
                        autoCapitalize="none"
                        autoCorrect={false}
                    />
                    {q ? (
                        <TouchableOpacity onPress={() => { setQ(''); setResults(null); setSuggestions([]); }} hitSlop={10}>
                            <Ionicons name="close-circle" size={18} color={colors.dim} />
                        </TouchableOpacity>
                    ) : null}
                </View>
            </View>
            <View style={{ flex: 1 }}>{renderBody()}</View>
        </View>
    );
}

const styles = StyleSheet.create({
    bar: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 12, paddingBottom: 10, backgroundColor: colors.header },
    inputWrap: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: colors.card, borderRadius: 999, paddingHorizontal: 14, paddingVertical: 9 },
    input: { flex: 1, color: colors.text, fontSize: 15, padding: 0 },
    center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
    empty: { color: colors.dim, textAlign: 'center', marginTop: 40 },
    row: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: 16, paddingVertical: 14, borderBottomWidth: 1, borderBottomColor: colors.border },
    rowText: { color: colors.text, fontSize: 15, flex: 1 },
    recentHead: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingHorizontal: 16, paddingVertical: 14 },
    recentTitle: { color: colors.text, fontSize: 15, fontWeight: '700' },
    clear: { color: colors.accent2, fontSize: 14, fontWeight: '600' },
});
