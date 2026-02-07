# 🧹 Orphan Image Cleaner pour PrestaShop

Script PHP professionnel avec interface web pour nettoyer les images orphelines dans PrestaShop.

![PrestaShop](https://img.shields.io/badge/PrestaShop-1.7%20%7C%208.x%20%7C%209.x-green)
![License](https://img.shields.io/badge/license-AFL%203.0-orange)

## Description

Identifiez et supprimez les images orphelines (non référencées en base de données) du dossier `/img/p/` pour libérer de l'espace disque.

## Fonctionnalités principales

### Interface moderne
- Design responsive adapté desktop/mobile
- Multilingue (Français / English)
- Statistiques en temps réel

### Gestion des images
- Groupement automatique par ID avec tous les formats
- Aperçu visuel avec vignettes
- Tri par taille (format le plus léger en premier)

### Outils puissants
- Recherche avec wildcards : `174986*.avif`
- Pagination : 25/50/100/200 par page
- Sélection multiple ou suppression globale
- Expand/Collapse des groupes

### Sécurité
- Protection par token
- Validation stricte des chemins
- Confirmation avant suppression
- Logs détaillés

## Prérequis

- PrestaShop 1.7.x / 8.x / 9.x
- PHP 7.1+ (8.0+ recommandé)
- Permissions lecture/écriture sur `/img/p/`

## Installation

1. Téléchargez `orphan-image-cleaner.php`
2. Uploadez à la racine de PrestaShop
3. Configurez le token (ligne 22) :

```php
$securityToken = 'VOTRE_TOKEN_SECRET';
```

Générez un token sécurisé :

```bash
openssl rand -hex 32
```

## Utilisation

Accédez au script via votre navigateur :

```
https://votre-site.com/orphan-image-cleaner.php?token=VOTRE_TOKEN
```

### Workflow recommandé

1. Analysez les statistiques
2. Filtrez avec la recherche si besoin
3. Vérifiez les aperçus
4. Sélectionnez les images à supprimer
5. Confirmez la suppression

### Exemples de recherche

- `174986` - Tous les formats de l'image 174986
- `*-large_default.jpg` - Tous les large_default JPG
- `174986*avif` - Tous les AVIF de l'image 174986

## Formats supportés

JPG, JPEG, PNG, GIF, WebP, AVIF

## Sécurité

### Avant utilisation

- ✅ Sauvegardez votre base de données
- ✅ Sauvegardez le dossier `/img/p/`
- ✅ Testez sur un environnement de staging

### Protection intégrée

- Token de sécurité obligatoire
- Validation stricte des chemins
- Confirmation avant suppression
- Logs des résultats

## Dépannage

**Erreur "Access denied"**  
Vérifiez que le token dans l'URL correspond au token du script

**Pas d'images affichées**  
Vérifiez les permissions de lecture sur `/img/p/`

**Erreur de suppression**  
Vérifiez les permissions d'écriture sur `/img/p/`

**Timeout**  
Augmentez `max_execution_time` dans php.ini

## Contribution

Les contributions sont bienvenues !

- 🐛 Bug reports : Ouvrez une issue
- 💡 Suggestions : Proposez des améliorations
- 🔧 Pull requests : Soumettez vos modifications

## Changelog

### v3.2 (Actuelle)

- Interface web complète
- Support multilingue FR/EN
- Recherche avec wildcards
- Pagination avancée
- Groupement par ID
- Aperçus visuels
- Sécurité renforcée

## Licence

Academic Free License (AFL 3.0)

## Auteur

**PROGERANCE - Dubois Arnaud**

- Website: [progerance.com](https://progerance.com)
- Email: support@progerance.com

## Remerciements

Un grand merci à **Yann Bonnaillie** pour sa contribution à l'amélioration de ce script.

---

**⚠️ Avertissement** : Testez toujours en staging avant la production. La suppression est irréversible !

