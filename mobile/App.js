import React from 'react';
import { StatusBar } from 'expo-status-bar';
import { NavigationContainer, DarkTheme } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';

import HomeScreen from './src/screens/HomeScreen';
import SearchScreen from './src/screens/SearchScreen';
import DetailScreen from './src/screens/DetailScreen';
import WatchScreen from './src/screens/WatchScreen';
import { colors } from './src/theme';

const Stack = createNativeStackNavigator();

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

export default function App() {
    return (
        <NavigationContainer theme={navTheme}>
            <StatusBar style="light" />
            <Stack.Navigator
                screenOptions={{
                    headerStyle: { backgroundColor: colors.header },
                    headerTintColor: colors.text,
                    contentStyle: { backgroundColor: colors.bg },
                }}
            >
                <Stack.Screen name="Home" component={HomeScreen} options={{ title: 'MovieBox' }} />
                <Stack.Screen name="Search" component={SearchScreen} options={{ title: 'Recherche' }} />
                <Stack.Screen name="Detail" component={DetailScreen} options={{ title: '' }} />
                <Stack.Screen name="Watch" component={WatchScreen} options={{ title: 'Lecture' }} />
            </Stack.Navigator>
        </NavigationContainer>
    );
}
