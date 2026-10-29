# 🚌 Yoon Wi (YoonWi) – Le chemin, simplement 🇸🇳

> **Yoon Wi** (« Le chemin » en wolof) est une application mobile de calcul d'itinéraire et de guidage multimodal dédiée à la région de Dakar (Sénégal). Elle unifie et combine en une seule feuille de route les 4 grands réseaux de transport formels : le **TER**, le **BRT**, les **Bus Tata (AFTU)** et les bus **Dakar Dem Dikk (DDD)**.

---

## 📌 Sommaire

- [À propos du projet](#-à-propos-du-projet)
- [Fonctionnalités clés](#-fonctionnalités-clés)
- [Les 4 Réseaux Intégrés](#-les-4-réseaux-intégrés)
- [Architecture Technique](#-architecture-technique)
- [Structure du Projet](#-structure-du-projet)
- [Modèle de Données (Base de données)](#-modèle-de-données-base-de-données)
- [Installation & Configuration](#-installation--configuration)
- [Contribution](#-contribution)
- [Licence & Contact](#-licence--contact)

---

## 🎯 À propos du projet

À Dakar, l'offre de transport en commun est vaste mais fragmentée entre plusieurs opérateurs publics et privés. Pour un usager, planifier un trajet combinant plusieurs modes de transport relève souvent du défi. 

**Yoon Wi** résout ce problème en offrant une solution simple :
1. L'utilisateur indique son départ et son arrivée (via la géolocalisation, une carte interactive ou des repères populaires comme *UCAD, Petersen, Colobane, Liberté 6*).
2. L'application calcule et compare les meilleures options en affichant le **prix total exact en FCFA**, la **durée estimée** et les **correspondances**.
3. L'usager suit une feuille de route détaillée, étape par étape, même en **mode hors-ligne**.

---

## 🌟 Fonctionnalités clés

- 🔀 **Moteur de Routage Multimodal :** Combinaison fluide de la marche à pied, des bus de quartier (AFTU), des lignes urbaines (DDD), du bus rapide en site propre (BRT) et du train express (TER).
- 💰 **Estimation Transparente des Coûts & Temps :** Calcul précis du tarif global en FCFA selon les grilles zonales ou forfaitaires de chaque réseau.
- ⚡ **Double Optimisation (Éco vs Express) :**
  - **Option Éco :** Privilégie les trajets les moins chers (AFTU / DDD).
  - **Option Express :** Privilégie la rapidité et évite les embouteillages (BRT / TER).
- 📍 **Gestion des Hubs de Correspondance :** Explication claire des points de rupture majeurs (*Colobane, Petersen, Grand Médine, UCAD, Diamniadio*) et temps de marche entre les quais.
- 📶 **Mode Hors-Ligne (Offline-First) :** Base de données embarquée sur le téléphone permettant de calculer des itinéraires et consulter les lignes sans connexion Internet.
- 🚨 **Gestion des Perturbations :** Alertes en temps réel en cas de retards ou d'incidents sur une ligne avec recalcul automatique d'itinéraire alternatif.
- 🗣️ **Support Multilingue :** Disponible en Français et Anglais (intégration future de la recherche vocale en Wolof).
- 📣 **Signalements Communautaires :** Module permettant aux utilisateurs de signaler une erreur de tarif, un arrêt déplacé ou une ligne manquante.

---

## 🚌 Les 4 Réseaux Intégrés

| Réseau | Type | Description / Zone | Tarification |
| :--- | :--- | :--- | :--- |
| **TER** | Train Express Régional | Liaison rapide Dakar ↔ Diamniadio ↔ AIBD | Zonale (Z1, Z2, Z3) — dès 1 500 FCFA |
| **SunuBRT** | Bus Rapid Transit | Voies dédiées à haut niveau de service (Lignes B1, B2, B3) | Zonale — 400 / 500 FCFA |
| **Bus Tata** | AFTU (Minibus) | Réseau de proximité maillant tous les quartiers | Forfaitaire / Sectionnelle — dès 150 FCFA |
| **Dakar Dem Dikk** | Bus Urbains (DDD) | Lignes structurantes sur les grands axes et boulevards | Sectionnelle — dès 150 FCFA |

---

## 🏗️ Architecture Technique

Le projet repose sur une séparation claire entre le serveur central (API & Données maître) et l'application client mobile.git