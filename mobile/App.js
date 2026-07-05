import React, { useEffect, useState } from 'react';
import { Text, TouchableOpacity, View, ActivityIndicator } from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { NavigationContainer, DarkTheme, useNavigation } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';

import HomeScreen from './src/screens/HomeScreen';
import TrendingScreen from './src/screens/TrendingScreen';
import SearchScreen from './src/screens/SearchScreen';
import DownloadsScreen from './src/screens/DownloadsScreen';
import AccountScreen from './src/screens/AccountScreen';
import DetailScreen from './src/screens/DetailScreen';
import WatchScreen from './src/screens/WatchScreen';
import { colors } from './src/theme';
import { loadApiBase } from './src/config';

const Tab = createBottomTabNavigator();
const RootStack = createNativeStackNavigator();

const navTheme = {
    ...DarkTheme,
    colors: {
        ...DarkTheme.colors,
        background: colors.bg,
        card: colors.header,
        text: colors.text,
        primary: colors.accent,
        border: colors.border,
    },
};

const tabIcon = (glyph) => ({ focused }) => (
    <Text style={{ fontSize: 20, opacity: focused ? 1 : 0.5 }}>{glyph}</Text>
);

function SearchButton() {
    const navigation = useNavigation();
    return (
        <TouchableOpacity onPress={() => navigation.navigate('Search')} hitSlop={12} style={{ marginRight: 14 }}>
            <Text style={{ fontSize: 20 }}>🔍</Text>
        </TouchableOpacity>
    );
}

const searchHeader = { headerRight: () => <SearchButton /> };

function Tabs() {
    return (
        <Tab.Navigator
            screenOptions={{
                headerStyle: { backgroundColor: colors.header },
                headerTintColor: colors.text,
                tabBarStyle: { backgroundColor: colors.header, borderTopColor: colors.border, height: 62, paddingBottom: 8, paddingTop: 6 },
                tabBarLabelStyle: { fontSize: 11, fontWeight: '600' },
                tabBarActiveTintColor: colors.accent2,
                tabBarInactiveTintColor: colors.dim,
            }}
        >
            <Tab.Screen name="HomeTab" component={HomeScreen} options={{ title: 'MovieBox', tabBarLabel: 'Accueil', tabBarIcon: tabIcon('🏠'), ...searchHeader }} />
            <Tab.Screen name="TrendingTab" component={TrendingScreen} options={{ title: 'Tendance', tabBarLabel: 'Tendance', tabBarIcon: tabIcon('🔥'), ...searchHeader }} />
            <Tab.Screen name="DownloadsTab" component={DownloadsScreen} options={{ title: 'Téléchargements', tabBarLabel: 'Téléchargements', tabBarIcon: tabIcon('⬇️') }} />
            <Tab.Screen name="AccountTab" component={AccountScreen} options={{ title: 'Mon compte', tabBarLabel: 'Mon compte', tabBarIcon: tabIcon('👤') }} />
        </Tab.Navigator>
    );
}

export default function App() {
    const [ready, setReady] = useState(false);

    useEffect(() => {
        loadApiBase().finally(() => setReady(true));
    }, []);

    if (!ready) {
        return (
            <View style={{ flex: 1, backgroundColor: colors.bg, alignItems: 'center', justifyContent: 'center' }}>
                <ActivityIndicator color={colors.accent} size="large" />
            </View>
        );
    }

    return (
        <NavigationContainer theme={navTheme}>
            <StatusBar style="light" />
            <RootStack.Navigator
                screenOptions={{
                    headerStyle: { backgroundColor: colors.header },
                    headerTintColor: colors.text,
                    contentStyle: { backgroundColor: colors.bg },
                }}
            >
                <RootStack.Screen name="Tabs" component={Tabs} options={{ headerShown: false }} />
                <RootStack.Screen name="Search" component={SearchScreen} options={{ title: 'Recherche' }} />
                <RootStack.Screen name="Detail" component={DetailScreen} options={{ title: '', headerTransparent: true }} />
                <RootStack.Screen name="Watch" component={WatchScreen} options={{ title: 'Lecture' }} />
            </RootStack.Navigator>
        </NavigationContainer>
    );
}
