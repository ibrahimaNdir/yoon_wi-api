# Contexte du Projet : Yoon Wi (Backend)

## 1. Présentation Générale
**Yoon Wi** (qui signifie "Le chemin, simplement" en wolof) est une application mobile de guidage et de calcul d'itinéraire multimodal conçue pour la région de Dakar (Sénégal).

**Le Problème :** À Dakar, les transports sont fragmentés entre plusieurs acteurs (TER, BRT, Dakar Dem Dikk, AFTU/Tata). Combiner ces réseaux est un parcours du combattant pour l'usager.
**La Solution :** Yoon Wi unifie ces réseaux dans une seule application. L'utilisateur saisit un point A et un point B, et l'application propose le meilleur itinéraire (le moins cher ou le plus rapide) avec le prix en FCFA, la durée estimée et le guidage pas-à-pas.

## 2. Périmètre de la V1 (Version Actuelle)
La V1 se concentre sur 4 réseaux formels :
- TER (Train Express Régional)
- BRT (Bus Rapid Transit)
- Dakar Dem Dikk (DDD)
- AFTU (Bus Tata)

**Fonctionnalités clés de la V1 :**
- **Moteur de routage :** Calcul d'itinéraires avec les modes "Éco" (le moins cher) et "Express" (le plus rapide).
- **Guidage pas-à-pas :** Indication des correspondances à pied (ex: marcher 3 min entre l'arrêt de bus et la gare).
- **Hubs de correspondance :** Mise en évidence des points de jonction majeurs (ex: Colobane, Petersen, UCAD).
- **Tarification :** Affichage clair du coût total du trajet en FCFA.
- **Signalements (Crowdsourcing) :** Les utilisateurs peuvent signaler un changement de prix ou un arrêt déplacé pour maintenir la base de données à jour.
- **Mode Hors-ligne (Préparation) :** L'API doit pouvoir fournir un "package" de données pour que l'application calcule des trajets sans internet.

*(Note : La V2 intégrera les transports informels comme les Cars Rapides et les Ndiaga Ndiaye).*

## 3. Sources de Données
- **Actuellement :** Nous utilisons des fichiers JSON locaux (Ter.json, SunuBrt.json, ddd_lignes.json, aftu_lignes.json).
- **Futur :** Nous attendons les fichiers GTFS officiels du CETUD (Conseil Exécutif des Transports Urbains Durables).
- **Géocodage :** Les fichiers JSON actuels n'ayant pas de coordonnées GPS, nous utilisons l'API Mapbox pour transformer les noms d'arrêts en latitude/longitude.