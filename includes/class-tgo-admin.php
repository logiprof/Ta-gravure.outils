<?php
/**
 * Administration : menu « Ta-gravure », activation des modules, réglages séparés par module.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TGO_Admin {

	const MENU = 'tg-outils';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'enregistrer_reglages' ) );
		add_action( 'admin_notices', array( __CLASS__, 'avis' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'scripts' ) );
		add_filter( 'plugin_action_links_' . TGO_BASENAME, array( __CLASS__, 'liens_extension' ) );
	}

	/* ---------------------------------------------------------------------
	 * Menu
	 * ------------------------------------------------------------------- */

	public static function menu() {
		add_menu_page( 'Ta-gravure outils', 'Ta-gravure', 'manage_options', self::MENU, array( __CLASS__, 'page_modules' ), 'dashicons-admin-tools', 58 );
		add_submenu_page( self::MENU, 'Modules – Ta-gravure outils', 'Modules', 'manage_options', self::MENU, array( __CLASS__, 'page_modules' ) );

		foreach ( tgo_modules_registre() as $id => $m ) {
			if ( ! TGO_Core::est_charge( $id ) ) {
				continue;
			}
			if ( ! empty( $m['reglages'] ) ) {
				add_submenu_page( self::MENU, $m['nom'] . ' – Réglages', $m['nom'], 'manage_options', self::MENU . '-' . $id, function () use ( $id ) {
					TGO_Admin::page_module( $id );
				} );
			} elseif ( ! empty( $m['page_reglages'] ) ) {
				// Lien direct vers la page de réglages d'origine du module.
				add_submenu_page( self::MENU, $m['nom'] . ' – Réglages', $m['nom'], 'manage_options', $m['page_reglages'] );
			}
		}

		add_submenu_page( self::MENU, 'Mises à jour – Ta-gravure outils', 'Mises à jour', 'manage_options', self::MENU . '-maj', array( 'TGO_Updater', 'page' ) );
	}

	public static function url_reglages_module( $id ) {
		$m = tgo_modules_registre()[ $id ];
		if ( ! empty( $m['reglages'] ) ) {
			return admin_url( 'admin.php?page=' . self::MENU . '-' . $id );
		}
		return empty( $m['page_reglages'] ) ? '' : admin_url( $m['page_reglages'] );
	}

	public static function liens_extension( $liens ) {
		array_unshift(
			$liens,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU ) ) . '">Modules et réglages</a>'
		);
		return $liens;
	}

	/* ---------------------------------------------------------------------
	 * Réglages
	 * ------------------------------------------------------------------- */

	public static function enregistrer_reglages() {
		register_setting( 'tgo_modules', TGO_Core::OPT_ACTIFS, array(
			'type'              => 'array',
			'sanitize_callback' => function ( $in ) {
				$in  = is_array( $in ) ? $in : array();
				$res = array();
				foreach ( array_keys( tgo_modules_registre() ) as $id ) {
					$res[ $id ] = empty( $in[ $id ] ) ? 0 : 1;
				}
				return $res;
			},
		) );

		foreach ( array_keys( tgo_modules_registre() ) as $id ) {
			if ( ! TGO_Core::champs( $id ) ) {
				continue;
			}
			register_setting( 'tgo_reglages_' . $id, 'tgo_reglages_' . $id, array(
				'type'              => 'array',
				'sanitize_callback' => function ( $in ) use ( $id ) {
					return TGO_Core::nettoyer_reglages( $id, $in );
				},
			) );
		}
	}

	public static function scripts( $hook ) {
		if ( strpos( (string) $hook, self::MENU ) === false ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'tgo-admin', TGO_URL . 'assets/admin.css', array(), TGO_VERSION );
		wp_enqueue_script( 'tgo-admin', TGO_URL . 'assets/admin.js', array( 'jquery' ), TGO_VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * Page « Modules »
	 * ------------------------------------------------------------------- */

	public static function page_modules() {
		$actifs = TGO_Core::actifs();
		?>
		<div class="wrap tgo-wrap">
			<h1>Ta-gravure outils <span class="tgo-version">version <?php echo esc_html( TGO_VERSION ); ?></span></h1>
			<p class="tgo-intro">Chaque outil est un module : cochez ceux que vous utilisez. Un module décoché n'est plus chargé du tout, ses réglages et ses données sont conservés.</p>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'tgo_modules' ); ?>
				<div class="tgo-grille">
				<?php
				foreach ( tgo_modules_registre() as $id => $m ) {
					$charge  = TGO_Core::est_charge( $id );
					$raison  = TGO_Core::raison_blocage( $id );
					$coche   = ! empty( $actifs[ $id ] );
					$url_reg = $charge ? self::url_reglages_module( $id ) : '';
					if ( $charge ) {
						$etat = array( 'actif', 'Actif' );
					} elseif ( $coche && $raison ) {
						$etat = array( 'bloque', 'En pause' );
					} elseif ( $coche ) {
						$etat = array( 'attente', 'Actif à la prochaine page' );
					} else {
						$etat = array( 'inactif', 'Désactivé' );
					}
					?>
					<div class="tgo-carte tgo-<?php echo esc_attr( $etat[0] ); ?>">
						<div class="tgo-carte-tete">
							<label class="tgo-interrupteur">
								<input type="hidden" name="<?php echo esc_attr( TGO_Core::OPT_ACTIFS ); ?>[<?php echo esc_attr( $id ); ?>]" value="0">
								<input type="checkbox" name="<?php echo esc_attr( TGO_Core::OPT_ACTIFS ); ?>[<?php echo esc_attr( $id ); ?>]" value="1" <?php checked( $coche ); ?>>
								<span class="tgo-curseur" aria-hidden="true"></span>
								<strong><?php echo esc_html( $m['nom'] ); ?></strong>
							</label>
							<span class="tgo-etat"><?php echo esc_html( $etat[1] ); ?></span>
						</div>
						<p><?php echo esc_html( $m['description'] ); ?></p>
						<?php if ( $raison && $coche ) : ?>
							<p class="tgo-raison"><?php echo esc_html( $raison ); ?></p>
						<?php endif; ?>
						<p class="tgo-pied">
							<span>Module v<?php echo esc_html( $m['version'] ); ?><?php echo ! empty( $m['woocommerce'] ) ? ' · WooCommerce' : ''; ?></span>
							<?php if ( $url_reg ) : ?>
								<a class="button" href="<?php echo esc_url( $url_reg ); ?>">Réglages</a>
							<?php endif; ?>
						</p>
					</div>
					<?php
				}
				?>
				</div>
				<?php submit_button( 'Enregistrer les modules' ); ?>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Page de réglages d'un module
	 * ------------------------------------------------------------------- */

	public static function page_module( $id ) {
		$m      = tgo_modules_registre()[ $id ];
		$option = 'tgo_reglages_' . $id;
		?>
		<div class="wrap tgo-wrap">
			<h1><?php echo esc_html( $m['nom'] ); ?> <span class="tgo-version">module v<?php echo esc_html( $m['version'] ); ?></span></h1>
			<p class="tgo-intro"><?php echo esc_html( $m['description'] ); ?></p>
			<?php if ( ! empty( $m['note'] ) ) : ?>
				<p class="description"><?php echo esc_html( $m['note'] ); ?></p>
			<?php endif; ?>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( $option ); ?>
				<table class="form-table" role="presentation">
				<?php
				foreach ( TGO_Core::champs( $id ) as $c ) {
					$v    = TGO_Core::reglage( $id, $c['cle'] );
					$nom  = $option . '[' . $c['cle'] . ']';
					$html = 'tgo_' . $id . '_' . $c['cle'];
					echo '<tr><th scope="row"><label for="' . esc_attr( $html ) . '">' . esc_html( $c['label'] ) . '</label></th><td>';
					switch ( $c['type'] ) {
						case 'nombre':
							printf(
								'<input type="number" class="small-text" id="%s" name="%s" value="%d"%s%s>',
								esc_attr( $html ),
								esc_attr( $nom ),
								(int) $v,
								isset( $c['min'] ) ? ' min="' . (int) $c['min'] . '"' : '',
								isset( $c['max'] ) ? ' max="' . (int) $c['max'] . '"' : ''
							);
							break;
						case 'case':
							printf(
								'<input type="hidden" name="%2$s" value="0"><label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> Activé</label>',
								esc_attr( $html ),
								esc_attr( $nom ),
								checked( ! empty( $v ), true, false )
							);
							break;
						case 'image':
							$src = $v ? wp_get_attachment_image_url( (int) $v, 'medium' ) : '';
							printf(
								'<div class="tgo-image"><input type="hidden" id="%1$s" name="%2$s" value="%3$d"><img src="%4$s" alt="" %5$s><p><button type="button" class="button tgo-choisir-image">Choisir une image</button> <button type="button" class="button-link tgo-retirer-image">Retirer</button> <span class="description">N° %3$d</span></p></div>',
								esc_attr( $html ),
								esc_attr( $nom ),
								(int) $v,
								esc_url( (string) $src ),
								$src ? '' : 'style="display:none"'
							);
							break;
						case 'zone':
							printf( '<textarea class="large-text" rows="5" id="%s" name="%s">%s</textarea>', esc_attr( $html ), esc_attr( $nom ), esc_textarea( (string) $v ) );
							break;
						default:
							printf(
								'<input type="%s" class="regular-text" id="%s" name="%s" value="%s">',
								$c['type'] === 'email' ? 'email' : 'text',
								esc_attr( $html ),
								esc_attr( $nom ),
								esc_attr( (string) $v )
							);
							if ( $c['type'] === 'url' && $v ) {
								echo ' <a href="' . esc_url( TGO_Core::url_absolue( $v ) ) . '" target="_blank" rel="noopener">Voir</a>';
							}
					}
					if ( ! empty( $c['aide'] ) ) {
						echo '<p class="description">' . esc_html( $c['aide'] ) . '</p>';
					}
					echo '</td></tr>';
				}
				?>
				</table>
				<?php submit_button( 'Enregistrer' ); ?>
			</form>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU ) ); ?>">← Tous les modules</a></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Avis
	 * ------------------------------------------------------------------- */

	public static function avis() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$anciennes = get_option( 'tgo_anciennes_desactivees' );
		if ( $anciennes ) {
			delete_option( 'tgo_anciennes_desactivees' );
			$noms = array_map( 'dirname', (array) $anciennes );
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>Ta-gravure outils a remplacé vos extensions séparées.</strong> Elles ont été désactivées : <code>%s</code>.<br>Tous les réglages, produits, demandes clients et paliers sont repris tels quels. Vous pouvez supprimer ces anciennes extensions dans <a href="%s">Extensions</a>.</p></div>',
				implode( '</code>, <code>', array_map( 'esc_html', $noms ) ), // phpcs:ignore WordPress.Security.EscapeOutput
				esc_url( admin_url( 'plugins.php?plugin_status=inactive' ) )
			);
		}

		$bloques = array();
		foreach ( TGO_Core::actifs() as $id => $actif ) {
			$r = $actif ? TGO_Core::raison_blocage( $id ) : '';
			if ( $r ) {
				$bloques[] = '<li><strong>' . esc_html( tgo_modules_registre()[ $id ]['nom'] ) . '</strong> : ' . esc_html( $r ) . '</li>';
			}
		}
		if ( $bloques ) {
			printf(
				'<div class="notice notice-warning"><p><strong>Ta-gravure outils</strong> : certains modules sont en pause.</p><ul style="list-style:disc;margin-left:20px">%s</ul><p><a href="%s">Gérer les modules</a></p></div>',
				implode( '', $bloques ), // phpcs:ignore WordPress.Security.EscapeOutput
				esc_url( admin_url( 'admin.php?page=' . self::MENU ) )
			);
		}
	}
}
