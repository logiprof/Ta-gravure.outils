<?php
/**
 * Affichage côté boutique : tableau des paliers, mention dans les listes, code court.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TGPD_Frontend {

	/** @var bool Empêche d'afficher deux fois le tableau sur la même page produit. */
	private static $rendered = array();

	public static function init() {
		add_action( 'wp', array( __CLASS__, 'register_hooks' ) );
		add_shortcode( 'prix_degressifs', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function register_hooks() {
		if ( 'yes' === TGPD_Tiers::option( 'show_table', 'yes' ) ) {
			switch ( TGPD_Tiers::option( 'table_position', 'before_cart' ) ) {
				case 'after_cart':
					add_action( 'woocommerce_after_add_to_cart_form', array( __CLASS__, 'output_table' ) );
					break;
				case 'after_price':
					add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'output_table' ), 15 );
					break;
				case 'shortcode':
					break;
				default:
					add_action( 'woocommerce_before_add_to_cart_form', array( __CLASS__, 'output_table' ) );
			}
		}
		if ( 'yes' === TGPD_Tiers::option( 'loop_hint', 'no' ) ) {
			add_action( 'woocommerce_after_shop_loop_item_title', array( __CLASS__, 'output_loop_hint' ), 15 );
		}
	}

	public static function assets() {
		wp_register_style( 'tgpd-front', TGPD_URL . 'assets/frontend.css', array(), TGPD_VERSION );
		wp_register_script( 'tgpd-front', TGPD_URL . 'assets/frontend.js', array( 'jquery' ), TGPD_VERSION, true );
		wp_localize_script(
			'tgpd-front',
			'tgpdParams',
			array(
				'symbol'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'format'    => html_entity_decode( get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8' ),
				'decimals'  => wc_get_price_decimals(),
				'decSep'    => wc_get_price_decimal_separator(),
				'thousSep'  => wc_get_price_thousand_separator(),
				'trimZeros' => (bool) apply_filters( 'woocommerce_price_trim_zeros', false ),
			)
		);
		if ( is_product() ) {
			wp_enqueue_style( 'tgpd-front' );
			wp_enqueue_script( 'tgpd-front' );
		}
		if ( 'yes' === TGPD_Tiers::option( 'loop_hint', 'no' ) && ( is_shop() || is_product_taxonomy() ) ) {
			wp_enqueue_style( 'tgpd-front' );
		}
	}

	public static function output_table() {
		global $product;
		echo self::render_table( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'prix_degressifs' );
		$product = $atts['id'] ? wc_get_product( absint( $atts['id'] ) ) : null;
		if ( ! $product ) {
			global $product;
		}
		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		wp_enqueue_style( 'tgpd-front' );
		wp_enqueue_script( 'tgpd-front' );
		return self::render_table( $product, true );
	}

	/**
	 * Prix affiché (TTC/HT selon les réglages de la boutique) pour un prix brut donné.
	 */
	private static function display_price( $product, $price ) {
		return (float) wc_get_price_to_display( $product, array( 'price' => $price, 'qty' => 1 ) );
	}

	public static function render_table( $product, $force = false ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		if ( ! $product->is_type( array( 'simple', 'variable' ) ) || ! $product->is_purchasable() ) {
			return '';
		}
		$id = $product->get_id();
		if ( ! $force && ! empty( self::$rendered[ $id ] ) ) {
			return '';
		}

		$tiers = TGPD_Tiers::get_product_tiers( $product );
		if ( empty( $tiers ) ) {
			return '';
		}
		self::$rendered[ $id ] = true;

		$is_variable = $product->is_type( 'variable' );
		$raw_price   = $is_variable ? null : (float) $product->get_price();
		$base_disp   = $is_variable ? null : self::display_price( $product, $raw_price );
		$title       = TGPD_Tiers::option( 'table_title', 'Prix dégressifs' );

		// Lignes : éventuelle ligne « 1 à N-1 » au prix normal, puis un palier par ligne.
		$rows = array();
		if ( $tiers[0]['min'] > 1 ) {
			$rows[] = array(
				'min'  => 1,
				'max'  => $tiers[0]['min'] - 1,
				'tier' => null,
			);
		}
		$count = count( $tiers );
		foreach ( $tiers as $i => $tier ) {
			$rows[] = array(
				'min'  => $tier['min'],
				'max'  => ( $i + 1 < $count ) ? $tiers[ $i + 1 ]['min'] - 1 : null,
				'tier' => $tier,
			);
		}

		ob_start();
		?>
		<div class="tgpd-table-wrap" data-product="<?php echo esc_attr( $id ); ?>" data-variable="<?php echo $is_variable ? '1' : '0'; ?>">
			<?php if ( $title ) : ?>
				<p class="tgpd-title"><?php echo esc_html( $title ); ?></p>
			<?php endif; ?>
			<table class="tgpd-table">
				<thead>
					<tr>
						<th>Quantité</th>
						<th>Prix / pièce</th>
						<th>Remise</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					$tier  = $row['tier'];
					$range = ( null === $row['max'] )
						? sprintf( 'dès %d', $row['min'] )
						: ( $row['min'] === $row['max'] ? (string) $row['min'] : sprintf( '%d – %d', $row['min'], $row['max'] ) );

					$attrs = array(
						'data-min'   => $row['min'],
						'data-max'   => null === $row['max'] ? '' : $row['max'],
						'data-type'  => $tier ? $tier['type'] : 'base',
						'data-value' => $tier ? $tier['value'] : 0,
					);

					$price_html = '';
					$saving     = '—';

					if ( ! $is_variable ) {
						if ( $tier ) {
							$new_disp   = self::display_price( $product, TGPD_Tiers::calculate( $raw_price, $tier ) );
							$price_html = wc_price( $new_disp );
							$pct        = $base_disp > 0 ? round( ( 1 - $new_disp / $base_disp ) * 100 ) : 0;
							$saving     = $pct > 0 ? '-' . $pct . ' %' : '—';
						} else {
							$price_html = wc_price( $base_disp );
						}
					} else {
						// Produit variable : le prix dépend de la variante choisie, il est calculé en JavaScript.
						if ( $tier && 'fixed' === $tier['type'] ) {
							$fixed_disp                  = self::display_price( $product, $tier['value'] );
							$attrs['data-fixed-display'] = $fixed_disp;
							$price_html                  = wc_price( $fixed_disp );
						} elseif ( $tier ) {
							$price_html = '<span class="tgpd-pick">selon la variante</span>';
							$saving     = '-' . wc_format_localized_decimal( $tier['value'] ) . ' %';
						} else {
							$price_html = '<span class="tgpd-pick">prix normal</span>';
						}
					}

					$attr_html = '';
					foreach ( $attrs as $k => $v ) {
						$attr_html .= ' ' . $k . '="' . esc_attr( $v ) . '"';
					}
					?>
					<tr class="tgpd-row"<?php echo $attr_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<td class="tgpd-qty"><?php echo esc_html( $range ); ?></td>
						<td class="tgpd-price"><?php echo wp_kses_post( $price_html ); ?></td>
						<td class="tgpd-saving"><?php echo esc_html( $saving ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Mention courte sous le prix dans les listes de produits.
	 */
	public static function output_loop_hint() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$tiers = TGPD_Tiers::get_product_tiers( $product );
		if ( empty( $tiers ) ) {
			return;
		}
		$best = end( $tiers );
		$first = $tiers[0];
		if ( 'percent' === $best['type'] ) {
			$text = sprintf( 'Dès %d pièces : jusqu\'à -%s %%', $first['min'], wc_format_localized_decimal( $best['value'] ) );
			if ( count( $tiers ) === 1 ) {
				$text = sprintf( 'Dès %d pièces : -%s %%', $first['min'], wc_format_localized_decimal( $best['value'] ) );
			}
		} else {
			$text = sprintf( 'Dès %d pièces : prix dégressifs', $first['min'] );
		}
		echo '<span class="tgpd-loop-hint">' . esc_html( $text ) . '</span>';
	}
}
