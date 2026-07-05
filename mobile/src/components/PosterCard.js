import React from 'react';
import { TouchableOpacity, Image, View, Text, StyleSheet } from 'react-native';
import { colors } from '../theme';

export default function PosterCard({ item, onPress, width = 120 }) {
    const height = width * 1.5;
    return (
        <TouchableOpacity style={[styles.card, { width }]} onPress={onPress} activeOpacity={0.8}>
            <View>
                {item.cover ? (
                    <Image source={{ uri: item.cover }} style={[styles.poster, { width, height }]} />
                ) : (
                    <View style={[styles.poster, styles.ph, { width, height }]}>
                        <Text style={styles.phText} numberOfLines={3}>{item.title}</Text>
                    </View>
                )}
                {item.french ? (
                    <View style={styles.fr}><Text style={styles.frText}>VF</Text></View>
                ) : null}
                {item.imdbRating ? (
                    <View style={styles.rating}><Text style={styles.ratingText}>★ {item.imdbRating}</Text></View>
                ) : null}
            </View>
            <Text numberOfLines={2} style={styles.title}>{item.title}</Text>
        </TouchableOpacity>
    );
}

const styles = StyleSheet.create({
    card: { marginRight: 12, marginBottom: 12 },
    poster: { borderRadius: 10, backgroundColor: colors.card },
    ph: { alignItems: 'center', justifyContent: 'center', padding: 6 },
    phText: { color: colors.dim, fontSize: 12, textAlign: 'center' },
    title: { color: colors.text, fontSize: 12, marginTop: 6 },
    fr: {
        position: 'absolute', top: 6, right: 6,
        backgroundColor: 'rgba(0,0,0,0.72)', borderRadius: 5, paddingHorizontal: 6, paddingVertical: 2,
    },
    frText: { color: '#fff', fontSize: 10, fontWeight: '800', letterSpacing: 0.5 },
    rating: {
        position: 'absolute', bottom: 6, left: 6,
        backgroundColor: 'rgba(0,0,0,0.75)', borderRadius: 5, paddingHorizontal: 6, paddingVertical: 2,
    },
    ratingText: { color: '#f5c518', fontSize: 10, fontWeight: '800' },
});
