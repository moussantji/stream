import React, { useState, useCallback } from 'react';
import { View, Text, FlatList, TouchableOpacity, Alert, StyleSheet } from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { listDownloads, deleteDownload, formatSize } from '../download';
import { colors } from '../theme';

function prettyName(name) {
    return name.replace(/\.mp4$/, '').replace(/_/g, ' ');
}

export default function DownloadsScreen({ navigation }) {
    const [files, setFiles] = useState([]);
    const [loading, setLoading] = useState(true);

    const refresh = useCallback(async () => {
        setLoading(true);
        try {
            setFiles(await listDownloads());
        } catch {
            setFiles([]);
        } finally {
            setLoading(false);
        }
    }, []);

    useFocusEffect(useCallback(() => { refresh(); }, [refresh]));

    const confirmDelete = (file) => {
        Alert.alert('Supprimer', `Supprimer « ${prettyName(file.name)} » ?`, [
            { text: 'Annuler', style: 'cancel' },
            { text: 'Supprimer', style: 'destructive', onPress: async () => { await deleteDownload(file.uri); refresh(); } },
        ]);
    };

    if (!loading && !files.length) {
        return (
            <View style={styles.center}>
                <Text style={styles.emptyTitle}>Aucun téléchargement</Text>
                <Text style={styles.emptySub}>Ouvre un titre puis appuie sur « Télécharger » pour le regarder hors connexion.</Text>
            </View>
        );
    }

    return (
        <FlatList
            style={{ backgroundColor: colors.bg }}
            data={files}
            keyExtractor={(f) => f.uri}
            contentContainerStyle={{ padding: 12 }}
            renderItem={({ item }) => (
                <View style={styles.row}>
                    <TouchableOpacity
                        style={{ flex: 1 }}
                        onPress={() => navigation.navigate('Watch', { localUri: item.uri, title: prettyName(item.name) })}
                    >
                        <Text style={styles.name} numberOfLines={2}>{prettyName(item.name)}</Text>
                        <Text style={styles.size}>{formatSize(item.size)} · disponible hors connexion</Text>
                    </TouchableOpacity>
                    <TouchableOpacity onPress={() => confirmDelete(item)} hitSlop={10} style={styles.del}>
                        <Text style={{ color: colors.accent2, fontSize: 18 }}>🗑</Text>
                    </TouchableOpacity>
                </View>
            )}
        />
    );
}

const styles = StyleSheet.create({
    center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg, padding: 32 },
    emptyTitle: { color: colors.text, fontSize: 18, fontWeight: '700', marginBottom: 8 },
    emptySub: { color: colors.dim, textAlign: 'center', lineHeight: 20 },
    row: { flexDirection: 'row', alignItems: 'center', backgroundColor: colors.card, borderRadius: 10, padding: 14, marginBottom: 10 },
    name: { color: colors.text, fontSize: 15, fontWeight: '600' },
    size: { color: colors.dim, fontSize: 12, marginTop: 4 },
    del: { paddingLeft: 12 },
});
