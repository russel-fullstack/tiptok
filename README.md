# 📱 TipTok — Plateforme de Micro-Contenus Textuels en Temps Réel

> **TipTok** est une application web découplée *Mobile-First* directement inspirée du format d'interaction TikTok, réimaginé pour du contenu textuel dynamique, des dégradés visuels captivants et des échanges instantanés.

---

## 🎯 Concept & Problématique

Les plateformes de contenu actuelles se divisent souvent en deux catégories :
1. **Les réseaux textuels classiques**, axés sur la lecture continue mais pauvres en dynamique visuelle et en immersion.
2. **Les plateformes vidéo (TikTok, Reels)**, ultra-engagantes grâce au défilement plein écran et au temps réel, mais centrées exclusivement sur la vidéo.

**TipTok** fusionne le meilleur des deux mondes :
* **Un défilement vertical plein écran (`Scroll Snap`)** fluide et immersif.
* **Des cartes de micro-contenus textuels (max 280 caractères)** sur fond de dégradés CSS personnalisés, d'images d'arrière-plan élégantes ou d'ambiances sonores légères.
* **Une interactivité temps réel absolue** : chaque like, commentaire ou abonnement est diffusé instantanément sur les écrans de tous les utilisateurs connectés grâce aux WebSockets.

---

## 🚀 Fonctionnalités Clés

### 👤 1. Authentification & Gestion de Profil
* Inscription et connexion sécurisées via des jetons d'accès API (**Bearer Tokens**).
* Gestion complète du profil utilisateur (Nom d'utilisateur, avatar, biographie).
* Système d'abonnement (**Follow / Unfollow**) avec mise à jour instantanée des statistiques.

### 📰 2. Flux de Contenu Immersif (Feeds)
* **Feed "Pour toi"** : Découverte globale des posts populaires et récents.
* **Feed "Abonnements"** : Flux dédié exclusivement aux créateurs suivis.
* **Scroll Snap Vertical** : Navigation fluide d'un post à l'autre d'un simple geste sur mobile ou molette sur ordinateur.
* **Génération & Publication** : Création rapide de cartes textuelles avec sélection de styles graphiques (dégradés, arrière-plans) et de hashtags.

### ⚡ 3. Interactions en Temps Réel (WebSockets)
* **Likes Instantanés** : Le compteur de mentions "J'aime" s'actualise en direct sur l'écran du créateur et des spectateurs avec une animation de cœur.
* **Tiroir de Commentaires (Bottom Sheet)** : Ouverture d'un panneau latéral/inférieur permettant de discuter sur un post sans quitter le fil. Les nouveaux commentaires apparaissent au fil de la frappe des utilisateurs.
* **Notifications Push** : Alerte en temps réel lorsqu'un nouvel utilisateur vous suit.

---

## 🛠️ Architecture Technique & Stack

L'application repose sur une **architecture complètement découplée** entre le serveur d'API et le client interface.