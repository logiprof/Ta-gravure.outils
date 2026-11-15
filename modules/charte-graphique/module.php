<?php
/**
 * Module « Charte graphique » (anciennement l'extension ta-gravure.art – Charte graphique 1.0.0).
 * Applique à toutes les pages la charte de la page d'accueil : vert sapin, or, crème,
 * boutons arrondis et cartes produits. Désactiver le module rend l'apparence d'origine de Divi.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'wp_enqueue_scripts',
	function () {
		$file = plugin_dir_path( __FILE__ ) . 'charte.css';
		wp_enqueue_style(
			'tg-charte-graphique',
			plugins_url( 'charte.css', __FILE__ ),
			array(),
			file_exists( $file ) ? (string) filemtime( $file ) : '1.0.0'
		);
	},
	(int) tgo_reglage( 'charte-graphique', 'priorite' ) // 999 par défaut : après Divi et WooCommerce, pour que la charte l'emporte.
);
