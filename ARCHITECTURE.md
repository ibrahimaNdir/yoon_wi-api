# Architecture Technique et Règles de Développement (Laravel 13)

## 1. Stack Technique
- **Framework Backend :** Laravel 13 (PHP 8.3+).
- **Base de données :** PostgreSQL avec l'extension PostGIS (pour la géolocalisation).
- **Moteur de Routage (Routing Engine) :** OpenTripPlanner (OTP) tournant dans un conteneur Docker sur le port 8080/8081. Il est alimenté par la carte OpenStreetMap (OSM) et les données de transport.
- **Géocodage :** API Mapbox (pour transformer les adresses textuelles en coordonnées GPS).

## 2. Règles Spécifiques à Laravel 13 (À respecter impérativement)
- **Structure simplifiée :** Ne pas créer de `app/Http/Kernel.php` ni de `app/Console/Kernel.php`. Utiliser la configuration centralisée dans `bootstrap/app.php` si besoin.
- **Migrations :** Toujours utiliser les classes anonymes pour les migrations (ex: `return new class extends Migration { ... };`).
- **Modèles :** Utiliser les `$fillable` et `$casts` modernes. Utiliser les types stricts (Type Hinting) dans les méthodes.
- **Structure des dossiers :** Garder le code métier lourd dans `app/Services/`. Les contrôleurs dans `app/Http/Controllers/Api/`.

## 3. Le Schéma de la Base de Données (Strict)
Les tables sont nommées en anglais pour respecter le standard mondial GTFS.
- `data_sources` : Traçabilité des données (sondages, officiel, signalements).
- `agencies` : Les entreprises de transport (TER, BRT, DDD, AFTU).
- `stops` : Les arrêts physiques et gares (contient la colonne PostGIS `geom`).
- `places` : Les lieux-dits et repères pour la recherche (ex: "UCAD", "Liberté 6").
- `routes` : Les lignes de transport.
- `route_stops` : L'ordre exact des arrêts sur une ligne (avec les temps de trajet théoriques).
- `fares` : Les tarifs (prix fixe, par zone, ou indicatif).
- `walk_links` : Les temps de marche à pied entre deux arrêts proches (correspondances).
- `reports` : Les signalements d'erreurs faits par les utilisateurs.

## 4. Règles d'Architecture du Code
- **Séparation des responsabilités :** Les Controllers valident et renvoient du JSON. La logique lourde (calculs de distance, formatage d'itinéraire) est dans des **Services** (`app/Services/RoutingService.php`, `GeoService.php`).
- **Modèles Eloquent :** Utiliser les relations natives (hasMany, belongsTo, belongsToMany).
  - *Exemple :* Une `Route` a plusieurs `Stop` via la table pivot `route_stops`.
- **PostGIS :** Pour toute requête de proximité (ex: "Trouver les arrêts à moins de 500m"), utiliser les fonctions spatiales de PostgreSQL (ST_DWithin) via des requêtes brutes (`DB::select`), jamais des calculs de distance en PHP.
- **API REST :** Les réponses doivent être formatées avec les **API Resources** de Laravel pour garantir un JSON propre et constant.
- **Gestion des erreurs :** Toujours renvoyer des codes HTTP appropriés (200, 422, 404).

## 5. Workflow du Calcul d'Itinéraire
1. L'application mobile envoie une requête à Laravel (Départ, Arrivée, Préférence : Eco/Express).
2. Laravel interroge le moteur OpenTripPlanner (OTP) via une requête HTTP.
3. OTP calcule le chemin en utilisant la carte OSM et les données GTFS.
4. Laravel récupère la réponse d'OTP, croise les données avec sa table `fares` pour calculer le prix exact en FCFA.
5. Laravel renvoie le JSON final formaté à l'application mobile.