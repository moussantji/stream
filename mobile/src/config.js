// URL du backend du SITE (celui qui sert /api/*).
// 👉 Mets ici l'adresse publique de ton site, SANS "/api" à la fin.
//    ex: https://ton-domaine.com
// (Émulateur Android + backend local: http://10.0.2.2:8000)
export const API_BASE = 'https://ton-site.com';

export function getApiBase() {
    return API_BASE;
}
