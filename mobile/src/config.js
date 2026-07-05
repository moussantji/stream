// ⚠️ URL du backend Laravel (celui qui sert /api/*). À ADAPTER :
//
//  - Émulateur Android          : http://10.0.2.2:8000
//  - Téléphone réel (même Wi-Fi): http://TON_IP_LAN:8000   (ex: http://192.168.1.20:8000)
//        (lance le backend avec :  php artisan serve --host=0.0.0.0 )
//  - Backend en ligne           : https://ton-domaine.com
//
// Si les films ne s'affichent pas, c'est presque toujours parce que cette URL
// n'est pas joignable depuis le téléphone.
export const API_BASE = 'http://10.0.2.2:8000';
