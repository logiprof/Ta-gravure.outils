<?php
/**
 * Administration : onglet produit, champs de catégorie, réglages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TGPD_Admin {

	public static function init() {
		// Fiche produit.
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );

		// Catégories de produits.
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'category_add_fields' ) );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'category_edit_fields' ) );
		add_action( 'created_product_cat', array( __CLASS__, 'save_category' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'save_category' ) );

		// Réglages : WooCommerce > Réglages > Produits > Prix dégressifs.
		add_filter( 'woocommerce_get_sections_products', array( __CLASS__, 'settings_section' ) );
		add_filter( 'woocommerce_get_settings_products', array( __CLASS__, 'settings_fields' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$is_product  = ( 'product' === $screen->post_type && in_array( $screen->base, array( 'post' ), true ) );
		$is_category = ( 'product_cat' === $screen->taxonomy );
		if ( ! $is_product && ! $is_category ) {
			return;
		}
		wp_enqueue_style( 'tgpd-admin', TGPD_URL . 'assets/admin.css', array(), TGPD_VERSION );
		wp_enqueue_script( 'tgpd-admin', TGPD_URL . 'assets/admin.js', array( 'jquery' ), TGPD_VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * Éditeur de paliers (réutilisé partout)
	 * ------------------------------------------------------------------- */

	public static function render_repeater( $name, $tiers ) {
		$currency = get_woocommerce_currency_symbol();
		?>
		<div class="tgpd-repeater" data-name="<?php echo esc_attr( $name ); ?>">
			<table class="widefat striped tgpd-tiers-table">
				<thead>
					<tr>
						<th>À partir de (quantité)</th>
						<th>Type de réduction</th>
						<th>Valeur</th>
						<th class="tgpd-col-actions"></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $tiers as $tier ) {
						self::render_row( $name, $tier, $currency );
					}
					?>
				</tbody>
			</table>
			<p class="tgpd-actions">
				<button type="button" class="button tgpd-add">+ Ajouter un palier</button>
			</p>
			<template class="tgpd-row-template">
				<?php self::render_row( $name, array( 'min' => '', 'type' => 'percent', 'value' => '' ), $currency ); ?>
			</template>
		</div>
		<?php
	}

	private static function render_row( $name, $tier, $currency ) {
		$value = ( '' === $tier['value'] ) ? '' : wc_format_localized_decimal( $tier['value'] );
		?>
		<tr>
			<td><input type="number" min="2" step="1" name="<?php echo esc_attr( $name ); ?>[min][]" value="<?php echo esc_attr( $tier['min'] ); ?>" placeholder="ex. 6" /></td>
			<td>
				<select name="<?php echo esc_attr( $name ); ?>[type][]">
					<option value="percent" <?php selected( $tier['type'], 'percent' ); ?>>Remise en %</option>
					<option value="fixed" <?php selected( $tier['type'], 'fixed' ); ?>>Prix unitaire fixe (<?php echo esc_html( $currency ); ?>)</option>
				</select>
			</td>
			<td><input type="text" inputmode="decimal" name="<?php echo esc_attr( $name ); ?>[value][]" value="<?php echo esc_attr( $value ); ?>" placeholder="ex. 10" /></td>
			<td class="tgpd-col-actions"><button type="button" class="button-link tgpd-remove" aria-label="Supprimer ce palier">✕</button></td>
		</tr>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Fiche produit
	 * ------------------------------------------------------------------- */

	public static function product_tab( $tabs ) {
		$tabs['tgpd'] = array(
			'label'    => 'Prix dégressifs',
			'target'   => 'tgpd_product_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);
		return $tabs;
	}

	public static function product_panel() {
		global $post;
		$product_id = $post ? $post->ID : 0;
		$mode       = get_post_meta( $product_id, TGPD_Tiers::META_MODE, true );
		$mode       = in_array( $mode, array( 'category', 'custom', 'none' ), true ) ? $mode : 'category';
		$tiers      = TGPD_Tiers::sanitize( get_post_meta( $product_id, TGPD_Tiers::META_TIERS, true ) );
		$inherited  = TGPD_Tiers::get_category_tiers( $product_id );
		?>
		<div id="tgpd_product_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group tgpd-panel">
				<p class="form-field">
					<label for="tgpd_mode">Prix dégressifs</label>
					<select id="tgpd_mode" name="tgpd_mode" class="tgpd-mode">
						<option value="category" <?php selected( $mode, 'category' ); ?>>Utiliser les paliers de la catégorie</option>
						<option value="custom" <?php selected( $mode, 'custom' ); ?>>Paliers propres à ce produit</option>
						<option value="none" <?php selected( $mode, 'none' ); ?>>Désactivés pour ce produit</option>
					</select>
				</p>

				<div class="tgpd-inherited" <?php echo 'category' === $mode ? '' : 'style="display:none"'; ?>>
					<?php if ( $inherited['tiers'] && $inherited['term'] ) : ?>
						<p><strong>Paliers hérités de la catégorie « <?php echo esc_html( $inherited['term']->name ); ?> » :</strong></p>
						<ul class="tgpd-inherited-list">
							<?php foreach ( $inherited['tiers'] as $tier ) : ?>
								<li><?php echo esc_html( ucfirst( TGPD_Tiers::label( $tier ) ) ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="description">Aucune catégorie de ce produit n'a de paliers pour l'instant. Tu peux en définir dans <em>Produits › Catégories</em>, ou choisir « Paliers propres à ce produit ».</p>
					<?php endif; ?>
				</div>

				<div class="tgpd-custom-wrap" <?php echo 'custom' === $mode ? '' : 'style="display:none"'; ?>>
					<p class="description">
						Le palier s'applique dès que la quantité indiquée est atteinte.
						<strong>Remise en %</strong> : le prix baisse du pourcentage choisi.
						<strong>Prix unitaire fixe</strong> : le prix de chaque pièce devient ce montant.
						Pour un produit variable, la remise en % est la plus adaptée (elle s'applique au prix de chaque variante).
					</p>
					<?php self::render_repeater( 'tgpd_tiers', $tiers ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	public static function save_product( $product ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mode = isset( $_POST['tgpd_mode'] ) ? sanitize_key( wp_unslash( $_POST['tgpd_mode'] ) ) : 'category';
		$mode = in_array( $mode, array( 'category', 'custom', 'none' ), true ) ? $mode : 'category';

		$product->update_meta_data( TGPD_Tiers::META_MODE, $mode );
		$product->update_meta_data( TGPD_Tiers::META_TIERS, TGPD_Tiers::from_request( 'tgpd_tiers' ) );
	}

	/* ---------------------------------------------------------------------
	 * Catégories
	 * ------------------------------------------------------------------- */

	public static function category_add_fields() {
		?>
		<div class="form-field tgpd-term-field">
			<label>Prix dégressifs</label>
			<input type="hidden" name="tgpd_term_present" value="1" />
			<?php self::render_repeater( 'tgpd_term_tiers', array() ); ?>
			<p class="description">S'applique à tous les produits de cette catégorie (et de ses sous-catégories), sauf ceux qui ont leurs propres paliers.</p>
		</div>
		<?php
	}

	public static function category_edit_fields( $term ) {
		$tiers = TGPD_Tiers::sanitize( get_term_meta( $term->term_id, TGPD_Tiers::TERM_TIERS, true ) );
		?>
		<tr class="form-field tgpd-term-field">
			<th scope="row"><label>Prix dégressifs</label></th>
			<td>
				<input type="hidden" name="tgpd_term_present" value="1" />
				<?php self::render_repeater( 'tgpd_term_tiers', $tiers ); ?>
				<p class="description">S'applique à tous les produits de cette catégorie (et de ses sous-catégories), sauf ceux qui ont leurs propres paliers ou qui sont désactivés.</p>
			</td>
		</tr>
		<?php
	}

	public static function save_category( $term_id ) {
		if ( ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// Le formulaire n'envoie pas toujours le champ (ex. édition rapide) : on ne touche à rien dans ce cas.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['tgpd_term_tiers'] ) && ! isset( $_POST['tgpd_term_present'] ) ) {
			return;
		}
		$tiers = TGPD_Tiers::from_request( 'tgpd_term_tiers' );
		if ( $tiers ) {
			update_term_meta( $term_id, TGPD_Tiers::TERM_TIERS, $tiers );
		} else {
			delete_term_meta( $term_id, TGPD_Tiers::TERM_TIERS );
		}
	}

	/* ---------------------------------------------------------------------
	 * Réglages
	 * ------------------------------------------------------------------- */

	public static function settings_section( $sections ) {
		$sections['tgpd'] = 'Prix dégressifs';
		return $sections;
	}

	public static function settings_fields( $settings, $section ) {
		if ( 'tgpd' !== $section ) {
			return $settings;
		}
		return array(
			array(
				'title' => 'Prix dégressifs',
				'type'  => 'title',
				'desc'  => 'Les paliers se définissent dans chaque produit (onglet « Prix dégressifs ») ou dans les catégories (Produits › Catégories).',
				'id'    => 'tgpd_settings',
			),
			array(
				'title'   => 'Tableau sur la fiche produit',
				'desc'    => 'Afficher le tableau des paliers sur la page du produit',
				'id'      => 'tgpd_show_table',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => 'Titre du tableau',
				'id'      => 'tgpd_table_title',
				'type'    => 'text',
				'default' => 'Prix dégressifs',
			),
			array(
				'title'   => 'Position du tableau',
				'id'      => 'tgpd_table_position',
				'type'    => 'select',
				'default' => 'before_cart',
				'options' => array(
					'before_cart' => 'Au-dessus du bouton « Ajouter au panier »',
					'after_cart'  => 'Sous le bouton « Ajouter au panier »',
					'after_price' => 'Juste sous le prix',
					'shortcode'   => 'Uniquement via le code court [prix_degressifs]',
				),
			),
			array(
				'title'   => 'Mention dans la boutique',
				'desc'    => 'Afficher « Dès X pièces : … » sous le prix dans les listes de produits',
				'id'      => 'tgpd_loop_hint',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'title'    => 'Cumul des quantités',
				'desc'     => 'Additionner les quantités d\'un même produit présentes sur plusieurs lignes du panier',
				'desc_tip' => 'Utile pour la gravure : 6 pièces avec 6 textes différents (ou plusieurs variantes) comptent comme 6 pièces du même produit.',
				'id'       => 'tgpd_aggregate',
				'type'     => 'checkbox',
				'default'  => 'yes',
			),
			array(
				'title'   => 'Panier',
				'desc'    => 'Montrer l\'ancien prix barré dans le panier',
				'id'      => 'tgpd_cart_strike',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => 'Commandes',
				'desc'    => 'Indiquer le palier appliqué sur chaque ligne de commande (visible par toi et par le client)',
				'id'      => 'tgpd_order_meta',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'tgpd_settings',
			),
		);
	}
}
