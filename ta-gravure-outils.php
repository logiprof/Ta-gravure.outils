<?php
/**
 * Plugin Name:       Ta-gravure outils
 * Plugin URI:        https://github.com/logiprof/Ta-gravure.outils
 * Description:       Tous les outils de ta-gravure.art réunis en une seule extension modulaire : charte graphique, formulaire de contact, e-mails et factures, personnalisation à graver, prix dégressifs, partage social & SEO. Chaque module s'active séparément et a ses propres réglages (menu « Ta-gravure »). Mises à jour automatiques depuis GitHub.
 * Version:           1.0.0
 * Author:            ta-gravure.art
 * Author URI:        https://ta-gravure.art
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 8.0
 * WC tested up to:   11.3
 * Update URI:        https://github.com/logiprof/Ta-gravure.outils
 * Text Domain:       ta-gravure-outils
 * License:           GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TGO_VERSION', '1.0.0' );
define( 'TGO_FILE', __FILE__ );
define( 'TGO_DIR', plugin_dir_path( __FILE__ ) );
define( 'TGO_URL', plugin_dir_url( __FILE__ ) );
define( 'TGO_BASENAME', plugin_basename( __FILE__ ) );
define( 'TGO_GITHUB_REPO', 'logiprof/Ta-gravure.outils' );

require_once TGO_DIR . 'includes/modules.php';
require_once TGO_DIR . 'includes/class-tgo-core.php';
require_once TGO_DIR . 'includes/class-tgo-admin.php';
require_once TGO_DIR . 'includes/class-tgo-updater.php';

/** Valeur d'un réglage de module (avec sa valeur par défaut du registre). */
function tgo_reglage( $module, $cle ) {
	return TGO_Core::reglage( $module, $cle );
}

// Compatibilité WooCommerce : commandes HPOS et panier/paiement en blocs (pour tous les modules).
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TGO_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TGO_FILE, true );
	}
} );

register_activation_hook( __FILE__, array( 'TGO_Core', 'activation' ) );
register_deactivation_hook( __FILE__, array( 'TGO_Core', 'desactivation' ) );

TGO_Core::charger_modules();
TGO_Admin::init();
TGO_Updater::init();
