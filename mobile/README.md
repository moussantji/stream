# MovieBox — application mobile (Expo / React Native)

Application mobile React Native (Expo) qui consomme le **même backend API** que
le site Laravel (`/api/*`). Interface façon MovieBox : navigation par onglets en
bas (**Accueil / Tendance / Téléchargements / Mon compte**), recherche via
l'icône 🔍 en haut, bannière en vedette sur l'accueil, fiche détail avec grande
image, lecteur vidéo, et **téléchargement hors ligne (uniquement dans l'app)**.

> ⚠️ **Les films ne s'affichent pas ?** L'URL de l'API dans `src/config.js`
> (`API_BASE`) doit être joignable depuis le téléphone (voir §2). L'onglet
> « Mon compte » propose un bouton **« Tester la connexion »** pour vérifier.

## 1. Prérequis

- Node.js 18+
- L'API backend Laravel accessible depuis le téléphone/émulateur.

## 2. Configurer l'URL de l'API

Édite `src/config.js` → `API_BASE` :

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

## Téléchargement hors ligne (app uniquement)

- Depuis l'écran de lecture, bouton **« Télécharger »** → choix de la qualité →
  le fichier MP4 est enregistré dans le stockage **privé de l'app**
  (`expo-file-system`), puis lisible hors connexion depuis l'onglet
  **« Téléchargés »**.
- Cette fonctionnalité n'existe **que dans l'app** : le site web n'expose aucun
  bouton de téléchargement.

## Limites connues

- Le lecteur utilise `expo-av` : il lit le **MP4** et le **HLS**. Les flux
  **DASH** (certains épisodes) ne sont pas encore pris en charge — l'écran
  l'indique (et le téléchargement n'est proposé que s'il existe un MP4).
- Pas encore d'authentification / favoris / historique dans l'app (l'API le
  permet ; à ajouter selon les besoins).
