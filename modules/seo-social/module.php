<?php
/**
 * Module « Partage social & SEO » (anciennement l'extension ta-gravure.art – Partage social & SEO 1.0.0).
 * Balises Open Graph (Facebook, Instagram, WhatsApp, Pinterest, LinkedIn) et Twitter/X, prix produit,
 * et ligne Sitemap dans robots.txt. Lit les titres/descriptions SEO de Jetpack.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Image utilisée quand une page n'a pas d'image à la une (réglage du module ; par défaut la boule de Noël avec prénom).
define( 'TG_SEO_FALLBACK_IMAGE_ID', (int) tgo_reglage( 'seo-social', 'image_secours' ) );

// Évite les doublons si les balises Open Graph de Jetpack venaient à être activées.
add_filter( 'jetpack_enable_open_graph', '__return_false', 99 );

/** Texte propre et coupé proprement à ~$max caractères. */
function tg_seo_clean( $text, $max = 200 ) {
	$text = wp_strip_all_tags( strip_shortcodes( (string) $text ), true );
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
	if ( mb_strlen( $text ) > $max ) {
		$cut  = mb_substr( $text, 0, $max );
		$pos  = mb_strrpos( $cut, ' ' );
		$text = ( $pos ? mb_substr( $cut, 0, $pos ) : $cut ) . '…';
	}
	return $text;
}

function tg_seo_strip_site_suffix( $title ) {
	$title = html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' );
	return trim( preg_replace( '/\s*[|–-]\s*ta-gravure\.art\s*$/u', '', $title ) );
}

function tg_seo_image_data( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	if ( ! $attachment_id ) {
		return null;
	}
	$src = wp_get_attachment_image_src( $attachment_id, 'large' );
	if ( ! $src ) {
		return null;
	}
	$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
	return array(
		'url'    => $src[0],
		'width'  => (int) $src[1],
		'height' => (int) $src[2],
		'alt'    => $alt ? $alt : '',
		'type'   => get_post_mime_type( $attachment_id ),
	);
}

function tg_seo_collect() {
	$data = array(
		'type'        => 'website',
		'title'       => tg_seo_strip_site_suffix( wp_get_document_title() ),
		'description' => '',
		'url'         => '',
		'image'       => null,
		'product'     => null,
	);

	if ( is_front_page() ) {
		$front_id            = (int) get_option( 'page_on_front' );
		$data['url']         = home_url( '/' );
		$data['description'] = $front_id ? get_post_meta( $front_id, 'advanced_seo_description', true ) : '';
		if ( ! $data['description'] ) {
			$data['description'] = get_option( 'advanced_seo_front_page_description' );
		}
		if ( $front_id && has_post_thumbnail( $front_id ) ) {
			$data['image'] = tg_seo_image_data( get_post_thumbnail_id( $front_id ) );
		}
	} elseif ( is_singular() ) {
		$post                = get_queried_object();
		$data['url']         = get_permalink( $post );
		$data['description'] = get_post_meta( $post->ID, 'advanced_seo_description', true );
		if ( ! $data['description'] ) {
			$data['description'] = has_excerpt( $post ) ? $post->post_excerpt : $post->post_content;
		}
		if ( has_post_thumbnail( $post ) ) {
			$data['image'] = tg_seo_image_data( get_post_thumbnail_id( $post ) );
		}
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$data['type']    = 'product';
				$data['product'] = array(
					'price'        => wc_get_price_to_display( $product ),
					'currency'     => get_woocommerce_currency(),
					'availability' => $product->is_in_stock() ? 'in stock' : 'out of stock',
				);
			}
		} elseif ( 'post' === $post->post_type ) {
			$data['type'] = 'article';
		}
	} elseif ( is_tax() || is_category() || is_tag() ) {
		$term                = get_queried_object();
		$data['url']         = get_term_link( $term );
		$data['description'] = term_description( $term );
		$thumb               = get_term_meta( $term->term_id, 'thumbnail_id', true );
		if ( $thumb ) {
			$data['image'] = tg_seo_image_data( $thumb );
		}
	} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
		$shop_id             = wc_get_page_id( 'shop' );
		$data['url']         = get_permalink( $shop_id );
		$data['description'] = get_post_meta( $shop_id, 'advanced_seo_description', true );
	}

	if ( is_wp_error( $data['url'] ) || ! $data['url'] ) {
		$req         = isset( $GLOBALS['wp']->request ) ? $GLOBALS['wp']->request : '';
		$data['url'] = home_url( $req ? '/' . $req . '/' : '/' );
	}
	if ( ! $data['description'] ) {
		$data['description'] = get_bloginfo( 'description' );
	}
	$data['description'] = tg_seo_clean( $data['description'] );

	if ( ! $data['image'] ) {
		$data['image'] = tg_seo_image_data( TG_SEO_FALLBACK_IMAGE_ID );
	}
	return $data;
}

add_action(
	'wp_head',
	function () {
		if ( is_admin() || is_feed() || is_robots() || is_404() ) {
			return;
		}
		$d    = tg_seo_collect();
		$tags = array(
			array( 'property', 'og:locale', 'fr_FR' ),
			array( 'property', 'og:site_name', get_bloginfo( 'name' ) ),
			array( 'property', 'og:type', $d['type'] ),
			array( 'property', 'og:title', $d['title'] ),
			array( 'property', 'og:description', $d['description'] ),
			array( 'property', 'og:url', $d['url'] ),
		);
		if ( $d['image'] ) {
			$tags[] = array( 'property', 'og:image', $d['image']['url'] );
			$tags[] = array( 'property', 'og:image:secure_url', $d['image']['url'] );
			if ( $d['image']['width'] ) {
				$tags[] = array( 'property', 'og:image:width', $d['image']['width'] );
				$tags[] = array( 'property', 'og:image:height', $d['image']['height'] );
			}
			if ( $d['image']['type'] ) {
				$tags[] = array( 'property', 'og:image:type', $d['image']['type'] );
			}
			if ( $d['image']['alt'] ) {
				$tags[] = array( 'property', 'og:image:alt', $d['image']['alt'] );
			}
		}
		if ( $d['product'] ) {
			$tags[] = array( 'property', 'product:price:amount', number_format( (float) $d['product']['price'], 2, '.', '' ) );
			$tags[] = array( 'property', 'product:price:currency', $d['product']['currency'] );
			$tags[] = array( 'property', 'product:availability', $d['product']['availability'] );
			$tags[] = array( 'property', 'product:condition', 'new' );
			$tags[] = array( 'property', 'product:brand', (string) tgo_reglage( 'seo-social', 'marque' ) );
		}
		$tags[] = array( 'name', 'twitter:card', $d['image'] ? 'summary_large_image' : 'summary' );
		$tags[] = array( 'name', 'twitter:title', $d['title'] );
		$tags[] = array( 'name', 'twitter:description', $d['description'] );
		if ( $d['image'] ) {
			$tags[] = array( 'name', 'twitter:image', $d['image']['url'] );
			if ( $d['image']['alt'] ) {
				$tags[] = array( 'name', 'twitter:image:alt', $d['image']['alt'] );
			}
		}

		echo "\n<!-- ta-gravure.art : partage social -->\n";
		foreach ( $tags as $t ) {
			if ( '' === (string) $t[2] ) {
				continue;
			}
			printf( '<meta %s="%s" content="%s" />' . "\n", $t[0], esc_attr( $t[1] ), esc_attr( $t[2] ) );
		}
		echo "<!-- / partage social -->\n";
	},
	5
);

// Indique le plan du site aux moteurs (effectif si robots.txt est généré par WordPress).
add_filter(
	'robots_txt',
	function ( $output, $public ) {
		if ( $public && tgo_reglage( 'seo-social', 'sitemap_robots' ) && false === stripos( $output, 'Sitemap:' ) ) {
			$output = rtrim( $output ) . "\n\nSitemap: " . home_url( '/wp-sitemap.xml' ) . "\n";
		}
		return $output;
	},
	99,
	2
);

// Exclut du plan du site les pages sans valeur pour les moteurs.
add_filter(
	'wp_sitemaps_posts_query_args',
	function ( $args, $post_type ) {
		if ( 'page' === $post_type && function_exists( 'wc_get_page_id' ) ) {
			$exclude = array_filter(
				array( wc_get_page_id( 'cart' ), wc_get_page_id( 'checkout' ), wc_get_page_id( 'myaccount' ) ),
				function ( $id ) { return $id > 0; }
			);
			$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? $args['post__not_in'] : array(), $exclude );
		}
		return $args;
	},
	10,
	2
);

// Le plan des utilisateurs (auteurs) n'apporte rien à une boutique.
add_filter(
	'wp_sitemaps_add_provider',
	function ( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	},
	10,
	2
);
