<?php
/**
 * Module « Prix dégressifs » (anciennement l'extension TaGravure – Prix dégressifs 1.0.0).
 * Prix dégressifs selon la quantité pour WooCommerce : paliers par produit ou par catégorie,
 * tableau des paliers sur la fiche produit et calcul automatique dans le panier.
 * Réglages : WooCommerce › Réglages › Produits › Prix dégressifs (ou menu Ta-gravure › Prix dégressifs).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TGPD_VERSION', '1.0.0' );
define( 'TGPD_FILE', TGO_FILE );
define( 'TGPD_DIR', plugin_dir_path( __FILE__ ) );
define( 'TGPD_URL', plugin_dir_url( __FILE__ ) );

// Compatibilité HPOS et blocs déclarée par le noyau de Ta-gravure outils.

/*
 * Supprimer l'ancienne extension séparée efface ses réglages (son fichier uninstall.php) :
 * on les remet à partir de la sauvegarde faite à l'activation de Ta-gravure outils.
 */
add_action( 'admin_init', function () {
	$sauvegarde = get_option( 'tgo_sauvegarde_tgpd' );
	if ( ! is_array( $sauvegarde ) || ! $sauvegarde ) {
		return;
	}
	foreach ( $sauvegarde as $k => $v ) {
		if ( get_option( 'tgpd_' . $k, null ) === null ) {
			update_option( 'tgpd_' . $k, $v );
		}
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>TaGravure – Prix dégressifs</strong> nécessite WooCommerce pour fonctionner.</p></div>';
		} );
		return;
	}

	require_once TGPD_DIR . 'includes/class-tgpd-tiers.php';
	require_once TGPD_DIR . 'includes/class-tgpd-admin.php';
	require_once TGPD_DIR . 'includes/class-tgpd-frontend.php';
	require_once TGPD_DIR . 'includes/class-tgpd-cart.php';

	TGPD_Admin::init();
	TGPD_Frontend::init();
	TGPD_Cart::init();
} );

// Les réglages sont accessibles depuis le menu Ta-gravure › Prix dégressifs.
