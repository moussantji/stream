import React from 'react';
import { Text } from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { NavigationContainer, DarkTheme } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';

import HomeScreen from './src/screens/HomeScreen';
import SearchScreen from './src/screens/SearchScreen';
import DownloadsScreen from './src/screens/DownloadsScreen';
import DetailScreen from './src/screens/DetailScreen';
import WatchScreen from './src/screens/WatchScreen';
import { colors } from './src/theme';

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
    <Text style={{ fontSize: 20, opacity: focused ? 1 : 0.55 }}>{glyph}</Text>
);

function Tabs() {
    return (
        <Tab.Navigator
            screenOptions={{
                headerStyle: { backgroundColor: colors.header },
                headerTintColor: colors.text,
                tabBarStyle: { backgroundColor: colors.header, borderTopColor: colors.border },
                tabBarActiveTintColor: colors.accent2,
                tabBarInactiveTintColor: colors.dim,
            }}
        >
            <Tab.Screen name="HomeTab" component={HomeScreen} options={{ title: 'MovieBox', tabBarLabel: 'Accueil', tabBarIcon: tabIcon('🏠') }} />
            <Tab.Screen name="SearchTab" component={SearchScreen} options={{ title: 'Recherche', tabBarLabel: 'Recherche', tabBarIcon: tabIcon('🔍') }} />
            <Tab.Screen name="DownloadsTab" component={DownloadsScreen} options={{ title: 'Téléchargements', tabBarLabel: 'Téléchargés', tabBarIcon: tabIcon('⬇️') }} />
        </Tab.Navigator>
    );
}

export default function App() {
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
                <RootStack.Screen name="Detail" component={DetailScreen} options={{ title: '', headerTransparent: true }} />
                <RootStack.Screen name="Watch" component={WatchScreen} options={{ title: 'Lecture' }} />
            </RootStack.Navigator>
        </NavigationContainer>
    );
}
