import React, { useState } from 'react';
import { ScrollView, View, Text, TextInput, TouchableOpacity, Linking, ActivityIndicator, StyleSheet } from 'react-native';
import { api } from '../api';
import { getApiBase, setApiBase, DEFAULT_API_BASE } from '../config';
import { colors } from '../theme';

export default function AccountScreen() {
    const [url, setUrl] = useState(getApiBase());
    const [saved, setSaved] = useState(false);
    const [testing, setTesting] = useState(false);
    const [result, setResult] = useState(null);

    const save = async () => {
        const applied = await setApiBase(url);
        setUrl(applied);
        setSaved(true);
        setResult(null);
        setTimeout(() => setSaved(false), 2000);
    };

    const testConnection = async () => {
        await setApiBase(url); // test the URL currently typed
        setUrl(getApiBase());
        setTesting(true);
        setResult(null);
        try {
            const data = await api.home();
            const count = (data.sections || []).length;
            setResult({ ok: true, text: `Connecté ✓  (${count} section${count > 1 ? 's' : ''})` });
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
                <Text style={styles.cardTitle}>URL du serveur (site)</Text>
                <Text style={styles.hint}>
                    Mets l'adresse publique de ton site (ex. https://ton-site.com). L'app utilisera
                    le même backend que le site. Enregistrée sur ce téléphone.
                </Text>
                <TextInput
                    style={styles.input}
                    value={url}
                    onChangeText={setUrl}
                    placeholder={DEFAULT_API_BASE}
                    placeholderTextColor={colors.dim}
                    autoCapitalize="none"
                    autoCorrect={false}
                    keyboardType="url"
                />
                <View style={styles.btnRow}>
                    <TouchableOpacity style={[styles.btn, styles.btnPrimary]} onPress={save}>
                        <Text style={styles.btnText}>{saved ? 'Enregistré ✓' : 'Enregistrer'}</Text>
                    </TouchableOpacity>
                    <TouchableOpacity style={[styles.btn, styles.btnGhost]} onPress={testConnection} disabled={testing}>
                        <Text style={styles.btnText}>{testing ? 'Test…' : 'Tester'}</Text>
                    </TouchableOpacity>
                </View>
                {testing ? <ActivityIndicator color={colors.accent} style={{ marginTop: 10 }} /> : null}
                {result ? <Text style={[styles.result, { color: result.ok ? '#4ade80' : colors.accent2 }]}>{result.text}</Text> : null}
            </View>

            <View style={styles.card}>
                <TouchableOpacity style={styles.rowBtn} onPress={() => Linking.openURL(getApiBase())}>
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
    cardTitle: { color: colors.text, fontSize: 15, fontWeight: '700', marginBottom: 8 },
    hint: { color: colors.dim, fontSize: 12, lineHeight: 18, marginBottom: 12 },
    input: { backgroundColor: colors.bg, borderWidth: 1, borderColor: colors.border, borderRadius: 8, color: colors.text, paddingHorizontal: 12, paddingVertical: 10, fontSize: 14 },
    btnRow: { flexDirection: 'row', gap: 10, marginTop: 12 },
    btn: { flex: 1, borderRadius: 8, paddingVertical: 11, alignItems: 'center' },
    btnPrimary: { backgroundColor: colors.accent },
    btnGhost: { backgroundColor: 'transparent', borderWidth: 1, borderColor: colors.border },
    btnText: { color: '#fff', fontWeight: '700' },
    result: { marginTop: 12, fontSize: 14, fontWeight: '600' },
    rowBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 6 },
    rowBtnText: { color: colors.text, fontSize: 15 },
    chevron: { color: colors.dim, fontSize: 22 },
    version: { color: colors.dim, textAlign: 'center', marginTop: 8, fontSize: 12 },
});
