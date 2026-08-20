# Workflow Spécification → Build

Ce fichier définit un workflow imposé quand l'Opérateur emploie le mot-clé **« spec »** dans une commande.

--MANDATORY!!--

## Déclenchement

- Le workflow s'active **uniquement** lorsque la commande contient le mot-clé « spec » (ou une demande explicite de suivre ce workflow).
- Toute autre commande suit le comportement normal, directement.

## Phase 0 — Questions

- Recenser **tout** ce qui n'est pas précisé dans la commande et qui doit être décidé pour produire une spécification complète : comportement attendu, fichiers concernés, formats, dépendances, contraintes, limites, critères de réussite, hors-périmètre.
- Poser **toutes** les questions en **un seul message**, numérotées.
- **Interdiction de supposer** : tout point non précisé = une question.
- Après les réponses, relancer une seconde passe si de nouveaux points indéterminés apparaissent.
- Quand rien ne reste à préciser → passer à la Phase 1.

## Phase 1 — Spécification

- Rédiger la spécification complète en français, structurée ainsi :
  - **Objectif** — ce que la demande doit accomplir.
  - **Contexte** — état actuel, fichiers et mécanismes concernés.
  - **Comportement détaillé** — chaque comportement attendu, précisément.
  - **Contraintes & limites** — contraintes techniques, stack, règles du projet.
  - **Hors périmètre** — ce qui n'est pas couvert par cette spec.
  - **Critères d'acceptation** — vérifiables, mesurables.
- Après validation (Phase 2), sauvegarder la spec dans `docs/specs/<slug>.md` et en donner le résumé.

## Phase 2 — Validation

- Demander explicitement : « Spécification prête. Validez-vous ? (oui / corrections) ».
- **Attendre la réponse** de l'Opérateur.
- Corrections → réviser la spec → re-demander la validation.
- Aucun passage à la Phase 3 sans validation explicite (« oui »).

## Phase 3 — Tâches

- Écrire la liste ordonnée des tâches (outil de suivi de tâches), chacune contenant :
  - l'action à réaliser,
  - les fichiers concernés,
  - la vérification qui prouve sa réussite.
- Présenter la liste des tâches et attendre l'accord de l'Opérateur avant le mode build.

## Phase 4 — Passage en mode build

- Une fois la spec **validée** ET les tâches **écrites**, annoncer :
  « Prêt pour le mode build — spécification validée, N tâches prêtes. »
- **Aucune modification de fichier avant le basculement** en mode build.
- En mode build : exécuter les tâches une à une, avec la vérification définie pour chacune, et signaler la fin de chaque tâche.