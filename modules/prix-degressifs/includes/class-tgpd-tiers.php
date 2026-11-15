<?php
/**
 * Lecture, nettoyage et calcul des paliers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TGPD_Tiers {

	const META_TIERS = '_tgpd_tiers';
	const META_MODE  = '_tgpd_mode';   // category (défaut) | custom | none
	const TERM_TIERS = 'tgpd_tiers';

	/** @var array Cache des paliers par ID produit parent. */
	private static $cache = array();

	/**
	 * Lit un réglage de l'extension.
	 */
	public static function option( $key, $default = '' ) {
		return get_option( 'tgpd_' . $key, $default );
	}

	/**
	 * Nettoie une liste de paliers [ ['min'=>, 'type'=>, 'value'=>], ... ].
	 * Retourne les paliers triés par quantité croissante, sans doublon.
	 */
	public static function sanitize( $rows ) {
		$tiers = array();
		if ( ! is_array( $rows ) ) {
			return $tiers;
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$min   = isset( $row['min'] ) ? absint( $row['min'] ) : 0;
			$type  = ( isset( $row['type'] ) && 'fixed' === $row['type'] ) ? 'fixed' : 'percent';
			$value = isset( $row['value'] ) ? str_replace( array( ',', ' ', "'" ), array( '.', '', '' ), trim( (string) $row['value'] ) ) : '';

			if ( $min < 2 || '' === $value || ! is_numeric( $value ) ) {
				continue;
			}
			$value = (float) $value;
			if ( $value < 0 ) {
				continue;
			}
			if ( 'percent' === $type ) {
				$value = min( 100, $value );
			}
			$tiers[ $min ] = array(
				'min'   => $min,
				'type'  => $type,
				'value' => $value,
			);
		}
		ksort( $tiers );
		return array_values( $tiers );
	}

	/**
	 * Construit les paliers à partir d'un champ de formulaire (tableaux parallèles min[] / type[] / value[]).
	 */
	public static function from_request( $field ) {
		// Nonce vérifié en amont par WooCommerce (produit) ou WordPress (catégorie).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST[ $field ] ) || ! is_array( $_POST[ $field ] ) ) {
			return array();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data  = wp_unslash( $_POST[ $field ] );
		$mins  = isset( $data['min'] ) ? (array) $data['min'] : array();
		$types = isset( $data['type'] ) ? (array) $data['type'] : array();
		$vals  = isset( $data['value'] ) ? (array) $data['value'] : array();
		$rows  = array();
		foreach ( $mins as $i => $min ) {
			$rows[] = array(
				'min'   => sanitize_text_field( $min ),
				'type'  => isset( $types[ $i ] ) ? sanitize_key( $types[ $i ] ) : 'percent',
				'value' => isset( $vals[ $i ] ) ? sanitize_text_field( $vals[ $i ] ) : '',
			);
		}
		return self::sanitize( $rows );
	}

	/**
	 * Paliers définis sur une catégorie (ou, à défaut, sur ses catégories parentes).
	 */
	public static function get_term_tiers( $term_id, $with_ancestors = true ) {
		$tiers = self::sanitize( get_term_meta( $term_id, self::TERM_TIERS, true ) );
		if ( $tiers || ! $with_ancestors ) {
			return $tiers;
		}
		foreach ( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
			$tiers = self::sanitize( get_term_meta( $ancestor_id, self::TERM_TIERS, true ) );
			if ( $tiers ) {
				return $tiers;
			}
		}
		return array();
	}

	/**
	 * Paliers hérités des catégories d'un produit + la catégorie d'origine.
	 *
	 * @return array{tiers: array, term: WP_Term|null}
	 */
	public static function get_category_tiers( $product_id ) {
		$term_ids = wc_get_product_term_ids( $product_id, 'product_cat' );

		// 1) Catégories directes du produit.
		foreach ( $term_ids as $term_id ) {
			$tiers = self::get_term_tiers( $term_id, false );
			if ( $tiers ) {
				return array( 'tiers' => $tiers, 'term' => get_term( $term_id, 'product_cat' ) );
			}
		}
		// 2) Catégories parentes.
		foreach ( $term_ids as $term_id ) {
			foreach ( get_ancestors( $term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
				$tiers = self::get_term_tiers( $ancestor_id, false );
				if ( $tiers ) {
					return array( 'tiers' => $tiers, 'term' => get_term( $ancestor_id, 'product_cat' ) );
				}
			}
		}
		return array( 'tiers' => array(), 'term' => null );
	}

	/**
	 * ID du produit « porteur » des paliers (le parent pour une variante).
	 */
	public static function owner_id( $product ) {
		return $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	}

	/**
	 * Paliers applicables à un produit (simple, variable ou variante).
	 */
	public static function get_product_tiers( $product ) {
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( $product );
		}
		if ( ! $product ) {
			return array();
		}

		$id = self::owner_id( $product );
		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}

		$mode = get_post_meta( $id, self::META_MODE, true );
		if ( 'none' === $mode ) {
			$tiers = array();
		} elseif ( 'custom' === $mode ) {
			$tiers = self::sanitize( get_post_meta( $id, self::META_TIERS, true ) );
		} else {
			$found = self::get_category_tiers( $id );
			$tiers = $found['tiers'];
		}

		/**
		 * Permet de modifier les paliers d'un produit.
		 */
		$tiers = apply_filters( 'tgpd_product_tiers', $tiers, $product );

		self::$cache[ $id ] = $tiers;
		return $tiers;
	}

	/**
	 * Palier atteint pour une quantité donnée (ou null).
	 */
	public static function get_tier_for_qty( $tiers, $qty ) {
		$match = null;
		foreach ( $tiers as $tier ) {
			if ( $qty >= $tier['min'] ) {
				$match = $tier;
			}
		}
		return $match;
	}

	/**
	 * Calcule le nouveau prix unitaire.
	 *
	 * @param float $base          Prix actuel de la ligne (peut inclure des options payantes ajoutées par d'autres extensions).
	 * @param array $tier          Palier atteint.
	 * @param float $product_price Prix propre du produit (sans options). Sert pour les paliers « prix fixe ».
	 */
	public static function calculate( $base, $tier, $product_price = null ) {
		$base = (float) $base;
		if ( null === $product_price ) {
			$product_price = $base;
		}
		if ( 'fixed' === $tier['type'] ) {
			// On retire l'écart entre le prix du produit et le prix fixe : les options payantes (gravure, texte…) sont conservées.
			$discount = max( 0, (float) $product_price - (float) $tier['value'] );
			$new      = $base - $discount;
		} else {
			$new = $base * ( 1 - ( (float) $tier['value'] / 100 ) );
		}
		$new = max( 0, min( $base, $new ) );
		return round( $new, wc_get_price_decimals() );
	}

	/**
	 * Libellé lisible d'un palier (pour le panier et les commandes).
	 */
	public static function label( $tier ) {
		if ( 'fixed' === $tier['type'] ) {
			return sprintf( 'dès %d pièces : %s / pièce', $tier['min'], html_entity_decode( wp_strip_all_tags( wc_price( $tier['value'] ) ), ENT_QUOTES, 'UTF-8' ) );
		}
		return sprintf( 'dès %d pièces : -%s %%', $tier['min'], wc_format_localized_decimal( $tier['value'] ) );
	}
}
