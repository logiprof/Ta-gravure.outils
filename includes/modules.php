<?php
/**
 * Registre des modules de « Ta-gravure outils ».
 *
 * Chaque module :
 *  - vit dans son dossier modules/<id>/ et se charge par modules/<id>/module.php ;
 *  - s'active ou se désactive indépendamment (Ta-gravure › Modules) ;
 *  - a ses propres réglages : soit des champs gérés par le noyau (clé « reglages »,
 *    enregistrés dans l'option « tgo_reglages_<id> »), soit sa page d'origine (clé « page_reglages »).
 *
 * Clés :
 *  nom, description, version
 *  woocommerce : true si le module a besoin de WooCommerce
 *  ancien      : nom du fichier principal de l'ancienne extension séparée (pour éviter les doublons)
 *  sentinelle  : fonction ou classe déjà définie si l'ancienne version est chargée ailleurs (mu-plugins…)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function tgo_modules_registre() {
	return array(
		'charte-graphique' => array(
			'nom'         => 'Charte graphique',
			'description' => 'Applique à toutes les pages (fiches produits, boutique, panier, paiement, compte, contact, CGV) la charte de la page d\'accueil : vert sapin, or, crème, boutons arrondis et cartes produits.',
			'version'     => '1.0.0',
			'woocommerce' => false,
			'ancien'      => 'tg-charte-graphique.php',
			'sentinelle'  => '',
			'reglages'    => array(
				array( 'cle' => 'priorite', 'type' => 'nombre', 'label' => 'Priorité de chargement', 'defaut' => 999, 'min' => 10, 'max' => 9999, 'aide' => 'Plus le nombre est grand, plus la charte est chargée tard (et l\'emporte sur Divi et WooCommerce). 999 par défaut.' ),
			),
		),
		'contact'          => array(
			'nom'           => 'Formulaire de contact',
			'description'   => 'Formulaire [tg_contact] avec types de demande et pièces jointes sécurisées. Chaque demande est enregistrée dans « Demandes clients » avec statut et notes internes.',
			'version'       => '1.5.0',
			'woocommerce'   => false,
			'ancien'        => 'tg-contact.php',
			'sentinelle'    => 'tg_contact_reglages',
			'page_reglages' => 'edit.php?post_type=tg_demande&page=tg-contact-reglages',
		),
		'mail-commande'    => array(
			'nom'         => 'E-mails et factures',
			'description' => 'Met aux couleurs du site tous les e-mails WooCommerce, l\'e-mail « Nouvelle commande » de la boutique et les factures PDF (PDF Invoices & Packing Slips).',
			'version'     => '2.0.1',
			'woocommerce' => true,
			'ancien'      => 'tg-mail-commande.php',
			'sentinelle'  => 'tg_mail_commande_logo',
			'reglages'    => array(
				array( 'cle' => 'logo', 'type' => 'url', 'label' => 'Logo des e-mails et factures', 'defaut' => '/wp-content/uploads/2026/11/logo-ta-gravure-site-600.png', 'aide' => 'Adresse de l\'image (complète, ou commençant par /wp-content/…). Idéalement un PNG de 600 px de large.' ),
				array( 'cle' => 'prefixe_sujet', 'type' => 'texte', 'label' => 'Préfixe de l\'objet « Nouvelle commande »', 'defaut' => '[ta-gravure]', 'aide' => 'Placé au début de l\'objet de l\'e-mail reçu par la boutique.' ),
			),
		),
		'prenom-a-graver'  => array(
			'nom'         => 'Personnalisation à graver',
			'description' => 'Champs de personnalisation (prénoms, dates, textes, choix) sur les fiches produits choisies. Les données suivent dans le panier, la commande, l\'administration, les e-mails et les factures.',
			'version'     => '2.4.1',
			'woocommerce' => true,
			'ancien'      => 'tg-prenom-a-graver.php',
			'sentinelle'  => 'tg_perso_actif',
			'reglages'    => array(
				array( 'cle' => 'max_pieces', 'type' => 'nombre', 'label' => 'Nombre maximum de pièces personnalisées', 'defaut' => 50, 'min' => 1, 'max' => 500, 'aide' => 'Au-delà, le client est invité à passer par le formulaire de contact.' ),
				array( 'cle' => 'texte_sans', 'type' => 'texte', 'label' => 'Libellé « sans gravure »', 'defaut' => 'Sans gravure', 'aide' => 'Texte de la case que le client peut cocher, repris dans le panier et la commande.' ),
			),
			'note'        => 'Les champs se règlent produit par produit : Produits › modifier un produit › onglet « Personnalisation ».',
		),
		'prix-degressifs'  => array(
			'nom'           => 'Prix dégressifs',
			'description'   => 'Prix dégressifs selon la quantité : paliers par produit ou par catégorie, tableau des paliers sur la fiche produit et calcul automatique dans le panier.',
			'version'       => '1.0.0',
			'woocommerce'   => true,
			'ancien'        => 'tg-prix-degressifs.php',
			'sentinelle'    => 'TGPD_Admin',
			'page_reglages' => 'admin.php?page=wc-settings&tab=products&section=tgpd',
			'note'          => 'Les paliers se règlent dans l\'onglet « Prix dégressifs » d\'un produit, ou dans une catégorie de produits.',
		),
		'seo-social'       => array(
			'nom'         => 'Partage social & SEO',
			'description' => 'Balises Open Graph (Facebook, Instagram, WhatsApp, Pinterest, LinkedIn) et Twitter/X, prix produit, et ligne Sitemap dans robots.txt. Lit les titres et descriptions SEO de Jetpack.',
			'version'     => '1.0.0',
			'woocommerce' => false,
			'ancien'      => 'tg-seo-social.php',
			'sentinelle'  => 'tg_seo_clean',
			'reglages'    => array(
				array( 'cle' => 'image_secours', 'type' => 'image', 'label' => 'Image de partage par défaut', 'defaut' => 29061, 'aide' => 'Utilisée quand une page n\'a pas d\'image à la une.' ),
				array( 'cle' => 'marque', 'type' => 'texte', 'label' => 'Marque des produits', 'defaut' => 'ta-gravure.art', 'aide' => 'Balise product:brand des fiches produits.' ),
				array( 'cle' => 'sitemap_robots', 'type' => 'case', 'label' => 'Plan du site dans robots.txt', 'defaut' => 1, 'aide' => 'Ajoute la ligne « Sitemap: » au robots.txt généré par WordPress.' ),
			),
		),
	);
}
