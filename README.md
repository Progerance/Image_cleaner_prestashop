# Script de Suppression d'Images Inutilisées pour PrestaShop

Ce script PHP est conçu pour aider les administrateurs de sites PrestaShop à nettoyer leurs dossiers d'images en supprimant les fichiers d'images inutilisés. Il parcourt récursivement le dossier d'images de PrestaShop, vérifie si chaque image est référencée dans la base de données, et supprime celles qui ne sont plus utilisées, libérant ainsi de l'espace disque précieux.

## Fonctionnalités

- **Identification des Images Inutilisées** : Le script identifie de manière efficace les images non référencées dans la base de données de PrestaShop.
- **Suppression Sécurisée** : Offre la possibilité de visualiser les images inutilisées (mode d'affichage) avant de procéder à leur suppression (mode de suppression).
- **Affichage de la Taille des Images** : Calcule et affiche la taille des images inutilisées en mégaoctets (MB), fournissant une estimation claire de l'espace disque potentiellement récupérable.
- **Facilité d'Utilisation** : Conçu pour être facile à utiliser, nécessitant peu ou pas de configuration pour la plupart des installations de PrestaShop.

## Prérequis

- PrestaShop 1.7.x (Testé jusqu'à la version 1.7.7, mais devrait être compatible avec les versions ultérieures)
- Accès au serveur où PrestaShop est hébergé
- Permissions nécessaires pour exécuter des scripts PHP et supprimer des fichiers sur le serveur

## Installation

1. Téléchargez le script PHP sur votre serveur, dans le dossier racine de votre installation PrestaShop.
2. Assurez-vous que le script a les permissions nécessaires pour lire les dossiers d'images et écrire dans les logs si nécessaire.

## Utilisation

### Mode d'Affichage

Pour visualiser les images inutilisées sans les supprimer, ouvrez le script dans un navigateur ou exécutez-le via la ligne de commande avec le mode réglé sur `0`.

```php
$mode = 0; // Mode d'affichage
```

### Mode de Suppression

Pour supprimer les images inutilisées, changez le mode à `1` et exécutez à nouveau le script.

```php
$mode = 1; // Mode de suppression
```

**Attention :** Utilisez le mode de suppression avec prudence pour éviter la perte de données importantes.

## Contribution

Les contributions, qu'il s'agisse de rapports de bugs, de suggestions d'améliorations ou de demandes de pull, sont les bienvenues. Avant de contribuer, veuillez ouvrir une issue pour discuter des changements que vous souhaitez apporter.

## Licence

Ce script est fourni sous licence MIT. Voir le fichier `LICENSE` pour plus de détails.

---

Ce formatage utilise des titres, des listes à puces, et des blocs de code pour une meilleure lisibilité et organisation dans le fichier `README.md` de GitHub.
