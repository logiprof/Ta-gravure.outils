<?php
/**
 * Application des prix dégressifs dans le panier et sur les commandes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TGPD_Cart {

	/** @var array Prix de départ de chaque article du panier, indexé par objet produit. */
	private static $base_prices = array();

	/** @var array Paliers appliqués, indexés par clé de ligne du panier. */
	private static $applied = array();

	public static function init() {
		// Priorité élevée : on passe après les extensions d'options payantes (texte gravé, etc.).
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_prices' ), 99 );
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'cart_item_price' ), 99, 3 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 99, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item_meta' ), 10, 3 );
	}

	/**
	 * Recalcule le prix de chaque ligne selon la quantité.
	 *
	 * @param WC_Cart $cart Panier.
	 */
	public static function apply_prices( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		$aggregate = ( 'yes' === TGPD_Tiers::option( 'aggregate', 'yes' ) );
		$items     = $cart->get_cart();

		// 1) Quantités par produit (toutes lignes et variantes confondues si le cumul est activé).
		$totals = array();
		foreach ( $items as $key => $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			$group            = $aggregate ? TGPD_Tiers::owner_id( $item['data'] ) : $key;
			$totals[ $group ] = ( isset( $totals[ $group ] ) ? $totals[ $group ] : 0 ) + (int) $item['quantity'];
		}

		// 2) Prix de chaque ligne.
		self::$applied = array();
		foreach ( $items as $key => $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			/** @var WC_Product $product */
			$product = $item['data'];

			// Ne pas toucher aux lignes gérées par d'autres mécanismes (cadeaux, lots…).
			if ( apply_filters( 'tgpd_skip_cart_item', false, $item, $key ) ) {
				continue;
			}

			$obj_id = spl_object_id( $product );
			if ( ! isset( self::$base_prices[ $obj_id ] ) ) {
				// Premier passage : on mémorise le prix de départ (options payantes comprises).
				self::$base_prices[ $obj_id ] = (float) $product->get_price( 'edit' );
			}
			$base = self::$base_prices[ $obj_id ];

			$tiers = TGPD_Tiers::get_product_tiers( $product );
			$group = $aggregate ? TGPD_Tiers::owner_id( $product ) : $key;
			$qty   = isset( $totals[ $group ] ) ? $totals[ $group ] : (int) $item['quantity'];
			$tier  = $tiers ? TGPD_Tiers::get_tier_for_qty( $tiers, $qty ) : null;

			if ( ! $tier || $base <= 0 ) {
				$product->set_price( $base );
				continue;
			}

			// Prix propre du produit, sans options, pour les paliers « prix fixe ».
			$own_price = $base;
			if ( 'fixed' === $tier['type'] ) {
				$fresh = wc_get_product( $product->get_id() );
				if ( $fresh ) {
					$own_price = (float) $fresh->get_price( 'edit' );
				}
			}

			$new = TGPD_Tiers::calculate( $base, $tier, $own_price );
			$product->set_price( $new );

			if ( $new < $base ) {
				self::$applied[ $key ] = array(
					'base' => $base,
					'new'  => $new,
					'tier' => $tier,
				);
			}
		}
	}

	/**
	 * Prix unitaire dans le panier : ancien prix barré + nouveau prix.
	 */
	public static function cart_item_price( $price_html, $cart_item, $cart_item_key ) {
		if ( 'yes' !== TGPD_Tiers::option( 'cart_strike', 'yes' ) || empty( self::$applied[ $cart_item_key ] ) ) {
			return $price_html;
		}
		$data    = self::$applied[ $cart_item_key ];
		$product = $cart_item['data'];
		$incl    = WC()->cart && WC()->cart->display_prices_including_tax();
		$fn      = $incl ? 'wc_get_price_including_tax' : 'wc_get_price_excluding_tax';
		$old     = $fn( $product, array( 'price' => $data['base'], 'qty' => 1 ) );
		$new     = $fn( $product, array( 'price' => $data['new'], 'qty' => 1 ) );
		return wc_format_sale_price( $old, $new );
	}

	/**
	 * Petite ligne « Prix dégressif : dès 6 pièces… » sous l'article dans le panier.
	 */
	public static function cart_item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['key'] ) || empty( self::$applied[ $cart_item['key'] ] ) ) {
			return $item_data;
		}
		$item_data[] = array(
			'key'   => 'Prix dégressif',
			'value' => TGPD_Tiers::label( self::$applied[ $cart_item['key'] ]['tier'] ),
		);
		return $item_data;
	}

	/**
	 * Note le palier appliqué sur la ligne de commande.
	 */
	public static function order_item_meta( $item, $cart_item_key, $values ) {
		if ( 'yes' !== TGPD_Tiers::option( 'order_meta', 'yes' ) || empty( self::$applied[ $cart_item_key ] ) ) {
			return;
		}
		$item->add_meta_data( 'Prix dégressif', TGPD_Tiers::label( self::$applied[ $cart_item_key ]['tier'] ), true );
	}
}
