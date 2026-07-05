import React from 'react';
import { StatusBar } from 'expo-status-bar';
import { NavigationContainer, DarkTheme, useNavigation } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { Ionicons } from '@expo/vector-icons';
import { TouchableOpacity } from 'react-native';

import HomeScreen from './src/screens/HomeScreen';
import TrendingScreen from './src/screens/TrendingScreen';
import SearchScreen from './src/screens/SearchScreen';
import DownloadsScreen from './src/screens/DownloadsScreen';
import AccountScreen from './src/screens/AccountScreen';
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

const tabIcon = (name) => ({ color, size }) => <Ionicons name={name} size={size ?? 23} color={color} />;

function SearchButton() {
    const navigation = useNavigation();
    return (
        <TouchableOpacity onPress={() => navigation.navigate('Search')} hitSlop={12} style={{ marginRight: 14 }}>
            <Ionicons name="search" size={22} color={colors.text} />
        </TouchableOpacity>
    );
}

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
            <Tab.Screen name="HomeTab" component={HomeScreen} options={{ headerShown: false, tabBarLabel: 'Accueil', tabBarIcon: tabIcon('home') }} />
            <Tab.Screen name="TrendingTab" component={TrendingScreen} options={{ title: 'Tendance', tabBarLabel: 'Tendance', tabBarIcon: tabIcon('flame'), headerRight: () => <SearchButton /> }} />
            <Tab.Screen name="DownloadsTab" component={DownloadsScreen} options={{ title: 'Téléchargements', tabBarLabel: 'Téléchargements', tabBarIcon: tabIcon('download') }} />
            <Tab.Screen name="AccountTab" component={AccountScreen} options={{ title: 'Mon compte', tabBarLabel: 'Mon compte', tabBarIcon: tabIcon('person') }} />
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
                <RootStack.Screen name="Search" component={SearchScreen} options={{ headerShown: false }} />
                <RootStack.Screen name="Detail" component={DetailScreen} options={{ title: '', headerTransparent: true }} />
                <RootStack.Screen name="Watch" component={WatchScreen} options={{ title: 'Lecture' }} />
            </RootStack.Navigator>
        </NavigationContainer>
    );
}
