# Ta-gravure outils

Extension WordPress modulaire qui réunit tous les outils de [ta-gravure.art](https://ta-gravure.art) :

| Module | Ancienne extension | Réglages |
|---|---|---|
| Charte graphique | ta-gravure.art – Charte graphique 1.0.0 | Ta-gravure › Charte graphique |
| Formulaire de contact | ta-gravure – Formulaire de contact 1.5.0 | Demandes clients › Réglages |
| E-mails et factures | ta-gravure – E-mails et factures 2.0.1 | Ta-gravure › E-mails et factures |
| Personnalisation à graver | ta-gravure – Personnalisation à graver 2.4.1 | Ta-gravure › Personnalisation à graver (+ onglet du produit) |
| Prix dégressifs | TaGravure – Prix dégressifs 1.0.0 | WooCommerce › Réglages › Produits › Prix dégressifs |
| Partage social & SEO | ta-gravure.art – Partage social & SEO 1.0.0 | Ta-gravure › Partage social & SEO |

Chaque module s'active ou se désactive séparément (**Ta-gravure › Modules**) et garde ses propres réglages.

## Installation

1. Télécharger `ta-gravure-outils.zip` depuis la [dernière version](https://github.com/logiprof/Ta-gravure.outils/releases/latest).
2. WordPress › Extensions › Ajouter › Téléverser › Installer › Activer.
3. Les six anciennes extensions sont désactivées automatiquement ; réglages, produits, demandes clients et paliers sont repris. Elles peuvent ensuite être supprimées.

## Mises à jour automatiques

Le site interroge GitHub toutes les 6 heures (et lors des vérifications normales de WordPress). Quand une nouvelle version est publiée, elle s'installe seule si **Ta-gravure › Mises à jour › Installer automatiquement** est activé (activé par défaut).

## Publier une nouvelle version

1. Modifier le code.
2. Augmenter le numéro de version à deux endroits dans `ta-gravure-outils.php` : l'en-tête `Version:` et `define( 'TGO_VERSION', … )` (ex. `1.0.1`).
3. Ajouter une section `## 1.0.1` dans `CHANGELOG.md`.
4. Pousser sur `main`.

L'action GitHub « Publier une version » vérifie la syntaxe PHP, construit `ta-gravure-outils.zip`, crée l'étiquette `v1.0.1` et la release. Les sites l'installent ensuite automatiquement.

## Structure

```
ta-gravure-outils.php        Fichier principal (en-tête, version)
includes/modules.php         Registre des modules et de leurs réglages
includes/class-tgo-core.php  Chargement, activation/désactivation des modules
includes/class-tgo-admin.php Menu « Ta-gravure », pages de réglages
includes/class-tgo-updater.php Mises à jour depuis GitHub
modules/<module>/module.php  Code de chaque module
```

Ajouter un module : créer `modules/<id>/module.php` et l'inscrire dans `includes/modules.php`. Ses réglages se lisent avec `tgo_reglage( '<id>', '<clé>' )`. Les initialisations à l'activation se branchent sur `tgo_activer_module_<id>` / `tgo_desactiver_module_<id>`.
