import Constants from 'expo-constants';

// Base URL of the Laravel backend that serves /api/*.
// - Android emulator reaches your machine's localhost via 10.0.2.2.
// - On a real device, use your computer's LAN IP (e.g. http://192.168.1.20:8000)
//   or your deployed domain (https://moviebox.example.com).
// Override without editing code via app.json -> expo.extra.apiBase.
export const API_BASE =
    (Constants.expoConfig && Constants.expoConfig.extra && Constants.expoConfig.extra.apiBase) ||
    'http://10.0.2.2:8000';
