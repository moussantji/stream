import React, { useState } from 'react';
import { ScrollView, View, Text, TouchableOpacity, Linking, ActivityIndicator, StyleSheet } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { api } from '../api';
import { getApiBase } from '../config';
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
            setResult({ ok: true, text: `Connecté ✓  (${count} section${count > 1 ? 's' : ''})` });
        } catch (e) {
            setResult({ ok: false, text: `Hors ligne : ${e.message}` });
        } finally {
            setTesting(false);
        }
    };

    return (
        <ScrollView style={{ backgroundColor: colors.bg }} contentContainerStyle={{ padding: 16 }}>
            <View style={styles.profile}>
                <View style={styles.avatar}><Ionicons name="person" size={30} color={colors.dim} /></View>
                <View>
                    <Text style={styles.name}>Invité</Text>
                    <Text style={styles.sub}>MovieBox</Text>
                </View>
            </View>

            <View style={styles.card}>
                <TouchableOpacity style={styles.rowBtn} onPress={testConnection} disabled={testing}>
                    <View style={styles.rowLeft}>
                        <Ionicons name="pulse" size={20} color={colors.text} />
                        <Text style={styles.rowBtnText}>{testing ? 'Test en cours…' : 'Tester la connexion'}</Text>
                    </View>
                    {testing ? <ActivityIndicator color={colors.accent} /> : <Ionicons name="chevron-forward" size={20} color={colors.dim} />}
                </TouchableOpacity>
                {result ? <Text style={[styles.result, { color: result.ok ? '#4ade80' : colors.accent2 }]}>{result.text}</Text> : null}

                <View style={styles.sep} />

                <TouchableOpacity style={styles.rowBtn} onPress={() => Linking.openURL(getApiBase())}>
                    <View style={styles.rowLeft}>
                        <Ionicons name="globe-outline" size={20} color={colors.text} />
                        <Text style={styles.rowBtnText}>Ouvrir le site web</Text>
                    </View>
                    <Ionicons name="chevron-forward" size={20} color={colors.dim} />
                </TouchableOpacity>
            </View>

            <Text style={styles.version}>MovieBox · v1.0.0</Text>
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    profile: { flexDirection: 'row', alignItems: 'center', gap: 14, marginBottom: 22 },
    avatar: { width: 64, height: 64, borderRadius: 32, backgroundColor: colors.card, alignItems: 'center', justifyContent: 'center' },
    name: { color: colors.text, fontSize: 20, fontWeight: '800' },
    sub: { color: colors.dim, fontSize: 13, marginTop: 2 },
    card: { backgroundColor: colors.card, borderRadius: 12, borderWidth: 1, borderColor: colors.border, paddingHorizontal: 16 },
    rowBtn: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', paddingVertical: 16 },
    rowLeft: { flexDirection: 'row', alignItems: 'center', gap: 12 },
    rowBtnText: { color: colors.text, fontSize: 15 },
    result: { paddingBottom: 14, fontSize: 14, fontWeight: '600' },
    sep: { height: 1, backgroundColor: colors.border },
    version: { color: colors.dim, textAlign: 'center', marginTop: 18, fontSize: 12 },
});
