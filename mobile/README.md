# MovieBox — application mobile (Expo / React Native)

Application mobile React Native (Expo) qui consomme la **même API** que le site
Laravel (`/api/*`). Elle fournit l'accueil, la recherche, la fiche détail
(saisons/épisodes) et un lecteur vidéo.

## 1. Prérequis

- Node.js 18+
- L'API backend Laravel accessible depuis le téléphone/émulateur.

## 2. Configurer l'URL de l'API

Édite `app.json` → `expo.extra.apiBase` (ou `src/config.js`) :

- **Émulateur Android** : `http://10.0.2.2:8000` (déjà configuré) — atteint le
  `localhost` de ta machine.
- **Téléphone réel (même Wi‑Fi)** : `http://TON_IP_LAN:8000` (ex. `http://192.168.1.20:8000`).
- **Production** : `https://ton-domaine.com`.

> En prod, sers l'API en **HTTPS** (Android bloque le HTTP en clair par défaut).

## 3. Lancer en développement

```bash
cd mobile
npm install
npx expo install     # aligne les versions natives sur le SDK Expo
npx expo start       # puis 'a' pour Android, ou scanne le QR avec Expo Go
```

## 4. Construire l'APK

### Option A — EAS Build (recommandé, cloud, pas d'Android Studio)

```bash
npm install -g eas-cli
eas login
eas build:configure
# APK installable :
eas build -p android --profile preview
```

Ajoute ce profil dans `eas.json` si besoin :

```json
{
  "build": {
    "preview": { "android": { "buildType": "apk" } }
  }
}
```

À la fin, EAS fournit un lien de téléchargement de l'`.apk`.

### Option B — build local (nécessite Android SDK)

```bash
npx expo prebuild -p android
cd android && ./gradlew assembleRelease
# APK: android/app/build/outputs/apk/release/app-release.apk
```

## 5. Brancher le bouton « App » du site

Place l'APK obtenu dans le site sous :

```
public/downloads/moviebox.apk
```

Le bouton « App » de l'en-tête (et la config du site) pointe déjà vers
`/downloads/moviebox.apk`.

## Limites connues

- Le lecteur utilise `expo-av` : il lit le **MP4** et le **HLS**. Les flux
  **DASH** (certains épisodes) ne sont pas encore pris en charge — l'écran
  l'indique. On pourra ajouter un lecteur DASH (ex. `react-native-video` +
  build dev) plus tard.
- Pas encore d'authentification / favoris / historique dans l'app (l'API le
  permet ; à ajouter selon les besoins).
