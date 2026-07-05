import React, { useState } from 'react';
import { ScrollView, View, Text, TouchableOpacity, Linking, ActivityIndicator, StyleSheet } from 'react-native';
import { api } from '../api';
import { API_BASE } from '../config';
import { colors } from '../theme';

export default function AccountScreen() {
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState(null);

    const testConnection = async () => {
        setTesting(true);
        setResult(null);
        try {
            const data = await api.home();
            const count = (data.sections || []).length;
            setResult({ ok: true, text: `Connecté ✓  (${count} section${count > 1 ? 's' : ''} reçue${count > 1 ? 's' : ''})` });
        } catch (e) {
            setResult({ ok: false, text: `Échec : ${e.message}` });
        } finally {
            setTesting(false);
        }
    };

    return (
        <ScrollView style={{ backgroundColor: colors.bg }} contentContainerStyle={{ padding: 16 }}>
            <View style={styles.profile}>
                <View style={styles.avatar}><Text style={styles.avatarText}>👤</Text></View>
                <View>
                    <Text style={styles.name}>Invité</Text>
                    <Text style={styles.sub}>MovieBox</Text>
                </View>
            </View>

            <View style={styles.card}>
                <Text style={styles.cardTitle}>Serveur</Text>
                <Text style={styles.mono}>{API_BASE}</Text>
                <TouchableOpacity style={styles.btn} onPress={testConnection} disabled={testing}>
                    <Text style={styles.btnText}>{testing ? 'Test en cours…' : 'Tester la connexion'}</Text>
                </TouchableOpacity>
                {testing ? <ActivityIndicator color={colors.accent} style={{ marginTop: 10 }} /> : null}
                {result ? <Text style={[styles.result, { color: result.ok ? '#4ade80' : colors.accent2 }]}>{result.text}</Text> : null}
                <Text style={styles.hint}>
                    Si les films ne s'affichent pas, cette URL n'est probablement pas joignable depuis le téléphone.
                    Modifie-la dans « mobile/src/config.js ».
                </Text>
            </View>

            <View style={styles.card}>
                <Text style={styles.cardTitle}>Plus</Text>
                <TouchableOpacity style={styles.rowBtn} onPress={() => Linking.openURL(API_BASE)}>
                    <Text style={styles.rowBtnText}>Ouvrir le site web</Text>
                    <Text style={styles.chevron}>›</Text>
                </TouchableOpacity>
            </View>

            <Text style={styles.version}>MovieBox · v1.0.0</Text>
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    profile: { flexDirection: 'row', alignItems: 'center', gap: 14, marginBottom: 22 },
    avatar: { width: 64, height: 64, borderRadius: 32, backgroundColor: colors.card, alignItems: 'center', justifyContent: 'center' },
    avatarText: { fontSize: 30 },
    name: { color: colors.text, fontSize: 20, fontWeight: '800' },
    sub: { color: colors.dim, fontSize: 13, marginTop: 2 },
    card: { backgroundColor: colors.card, borderRadius: 12, borderWidth: 1, borderColor: colors.border, padding: 16, marginBottom: 16 },
    cardTitle: { color: colors.text, fontSize: 15, fontWeight: '700', marginBottom: 10 },
    mono: { color: colors.dim, fontSize: 13, fontFamily: 'monospace' },
    btn: { backgroundColor: colors.accent, borderRadius: 8, paddingVertical: 11, alignItems: 'center', marginTop: 14 },
    btnText: { color: '#fff', fontWeight: '700' },
    result: { marginTop: 12, fontSize: 14, fontWeight: '600' },
    hint: { color: colors.dim, fontSize: 12, lineHeight: 18, marginTop: 12 },
    rowBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 6 },
    rowBtnText: { color: colors.text, fontSize: 15 },
    chevron: { color: colors.dim, fontSize: 22 },
    version: { color: colors.dim, textAlign: 'center', marginTop: 8, fontSize: 12 },
});
