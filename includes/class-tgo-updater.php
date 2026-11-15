<?php
/**
 * Mises à jour automatiques depuis GitHub (https://github.com/logiprof/Ta-gravure.outils).
 *
 * Chaque version publiée sur GitHub (« release » vX.Y.Z) contient :
 *  - ta-gravure-outils.zip : l'extension prête à installer ;
 *  - info.json             : numéro de version, notes de version, compatibilité.
 *
 * WordPress interroge GitHub (en-tête « Update URI » → filtre update_plugins_github.com),
 * affiche la mise à jour comme pour n'importe quelle extension et, si les mises à jour
 * automatiques sont activées, l'installe tout seul. En plus des deux vérifications
 * quotidiennes de WordPress, l'extension vérifie elle-même toutes les 6 heures.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TGO_Updater {

	const SLUG       = 'ta-gravure-outils';
	const CACHE      = 'tgo_github_version';
	const OPT_AUTO   = 'tgo_maj_auto';
	const CRON       = 'tgo_verifier_maj';
	const ZIP        = 'ta-gravure-outils.zip';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'verifier' ), 10, 4 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'renommer_dossier' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'maj_auto' ), 20, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'apres_maj' ), 10, 2 );

		// Synchronise l'interrupteur « mises à jour auto » de la liste des extensions avec le nôtre.
		add_action( 'update_option_' . self::OPT_AUTO, array( __CLASS__, 'synchroniser_maj_auto' ) );
		add_action( 'add_option_' . self::OPT_AUTO, array( __CLASS__, 'synchroniser_maj_auto' ) );
		add_action( 'update_site_option_auto_update_plugins', array( __CLASS__, 'depuis_liste_extensions' ), 10, 3 );

		// Vérification toutes les 6 heures + installation automatique.
		add_filter( 'cron_schedules', function ( $s ) {
			$s['tgo_6h'] = array( 'interval' => 6 * HOUR_IN_SECONDS, 'display' => 'Toutes les 6 heures (Ta-gravure outils)' );
			return $s;
		} );
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( TGO_Updater::CRON ) ) {
				wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'tgo_6h', TGO_Updater::CRON );
			}
		} );
		add_action( self::CRON, array( __CLASS__, 'tache_planifiee' ) );

		add_action( 'admin_post_tgo_verifier_maj', array( __CLASS__, 'action_verifier' ) );
		add_action( 'admin_init', function () {
			register_setting( 'tgo_maj', TGO_Updater::OPT_AUTO, array(
				'type'              => 'integer',
				'sanitize_callback' => function ( $v ) {
					return empty( $v ) ? 0 : 1;
				},
			) );
		} );
	}

	public static function auto_active() {
		return (bool) get_option( self::OPT_AUTO, 1 );
	}

	/* ---------------------------------------------------------------------
	 * Lecture de la dernière version sur GitHub
	 * ------------------------------------------------------------------- */

	/**
	 * @return array|null array( version, package, notes, date, requires, requires_php, tested, url ) ou null.
	 */
	public static function derniere_version( $forcer = false ) {
		if ( ! $forcer ) {
			$c = get_site_transient( self::CACHE );
			if ( is_array( $c ) ) {
				return empty( $c['version'] ) ? null : $c;
			}
		}

		$base   = 'https://github.com/' . TGO_GITHUB_REPO . '/releases/latest/download/';
		$args   = array(
			'timeout' => 15,
			'headers' => array( 'Accept' => 'application/json', 'User-Agent' => 'ta-gravure-outils/' . TGO_VERSION . '; ' . home_url() ),
		);
		$info   = null;
		$erreur = '';

		// 1) Fichier info.json joint à la dernière version (pas de limite de requêtes).
		$r = wp_remote_get( $base . 'info.json', $args );
		if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
			$j = json_decode( wp_remote_retrieve_body( $r ), true );
			if ( is_array( $j ) && ! empty( $j['version'] ) ) {
				$info = array(
					'version'      => ltrim( (string) $j['version'], 'v' ),
					'package'      => ! empty( $j['package'] ) ? (string) $j['package'] : $base . self::ZIP,
					'notes'        => isset( $j['notes'] ) ? (string) $j['notes'] : '',
					'date'         => isset( $j['date'] ) ? (string) $j['date'] : '',
					'requires'     => isset( $j['requires'] ) ? (string) $j['requires'] : '6.0',
					'requires_php' => isset( $j['requires_php'] ) ? (string) $j['requires_php'] : '7.4',
					'tested'       => isset( $j['tested'] ) ? (string) $j['tested'] : '',
				);
			}
		} else {
			$erreur = is_wp_error( $r ) ? $r->get_error_message() : 'info.json : HTTP ' . wp_remote_retrieve_response_code( $r );
		}

		// 2) Secours : API GitHub.
		if ( ! $info ) {
			$r = wp_remote_get( 'https://api.github.com/repos/' . TGO_GITHUB_REPO . '/releases/latest', array_merge( $args, array( 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => $args['headers']['User-Agent'] ) ) ) );
			if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
				$j = json_decode( wp_remote_retrieve_body( $r ), true );
				if ( is_array( $j ) && ! empty( $j['tag_name'] ) ) {
					$package = isset( $j['zipball_url'] ) ? $j['zipball_url'] : '';
					foreach ( isset( $j['assets'] ) ? (array) $j['assets'] : array() as $a ) {
						if ( isset( $a['name'] ) && self::ZIP === $a['name'] ) {
							$package = $a['browser_download_url'];
						}
					}
					$info   = array(
						'version'      => ltrim( (string) $j['tag_name'], 'v' ),
						'package'      => $package,
						'notes'        => isset( $j['body'] ) ? (string) $j['body'] : '',
						'date'         => isset( $j['published_at'] ) ? (string) $j['published_at'] : '',
						'requires'     => '6.0',
						'requires_php' => '7.4',
						'tested'       => '',
					);
					$erreur = '';
				}
			} elseif ( ! $erreur ) {
				$erreur = is_wp_error( $r ) ? $r->get_error_message() : 'API GitHub : HTTP ' . wp_remote_retrieve_response_code( $r );
			}
		}

		if ( $info ) {
			$info['url'] = 'https://github.com/' . TGO_GITHUB_REPO . '/releases/tag/v' . $info['version'];
			set_site_transient( self::CACHE, $info, 6 * HOUR_IN_SECONDS );
			delete_site_option( 'tgo_maj_erreur' );
			return $info;
		}

		// Échec : on ne réessaie pas avant 1 heure.
		set_site_transient( self::CACHE, array( 'version' => '' ), HOUR_IN_SECONDS );
		update_site_option( 'tgo_maj_erreur', array( 'date' => current_time( 'mysql' ), 'detail' => $erreur ) );
		return null;
	}

	/* ---------------------------------------------------------------------
	 * Intégration au système de mises à jour de WordPress
	 * ------------------------------------------------------------------- */

	/** Filtre update_plugins_github.com (WordPress 5.8+, grâce à l'en-tête « Update URI »). */
	public static function verifier( $update, $plugin_data, $plugin_file, $locales = array() ) {
		if ( TGO_BASENAME !== $plugin_file ) {
			return $update;
		}
		$info = self::derniere_version();
		if ( ! $info ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . TGO_GITHUB_REPO,
			'slug'         => self::SLUG,
			'plugin'       => TGO_BASENAME,
			'version'      => $info['version'],
			'url'          => 'https://github.com/' . TGO_GITHUB_REPO,
			'package'      => $info['package'],
			'requires'     => $info['requires'],
			'requires_php' => $info['requires_php'],
			'tested'       => $info['tested'],
			'icons'        => array(),
			'autoupdate'   => self::auto_active(),
		);
	}

	/** Fenêtre « Voir les détails » de la mise à jour. */
	public static function details( $res, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $res;
		}
		$info  = self::derniere_version();
		$notes = $info && $info['notes'] !== '' ? $info['notes'] : 'Voir l\'historique sur GitHub.';
		return (object) array(
			'name'          => 'Ta-gravure outils',
			'slug'          => self::SLUG,
			'version'       => $info ? $info['version'] : TGO_VERSION,
			'author'        => '<a href="https://ta-gravure.art">ta-gravure.art</a>',
			'homepage'      => 'https://github.com/' . TGO_GITHUB_REPO,
			'requires'      => $info ? $info['requires'] : '6.0',
			'requires_php'  => $info ? $info['requires_php'] : '7.4',
			'tested'        => $info ? $info['tested'] : '',
			'last_updated'  => $info ? $info['date'] : '',
			'download_link' => $info ? $info['package'] : '',
			'sections'      => array(
				'description' => '<p>Tous les outils de ta-gravure.art réunis en une seule extension modulaire.</p><ul>' . implode( '', array_map( function ( $m ) {
					return '<li><strong>' . esc_html( $m['nom'] ) . '</strong> – ' . esc_html( $m['description'] ) . '</li>';
				}, tgo_modules_registre() ) ) . '</ul>',
				'changelog'   => wpautop( esc_html( $notes ) ),
			),
		);
	}

	/** Garantit que le dossier installé s'appelle toujours « ta-gravure-outils ». */
	public static function renommer_dossier( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || TGO_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}
		$voulu = trailingslashit( $remote_source ) . dirname( TGO_BASENAME ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $voulu ) ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $voulu, true ) ) {
			return $voulu;
		}
		return new WP_Error( 'tgo_dossier', 'Impossible de renommer le dossier de la mise à jour.' );
	}

	/** Autorise WordPress à installer seul les nouvelles versions. */
	public static function maj_auto( $update, $item ) {
		if ( isset( $item->plugin ) && TGO_BASENAME === $item->plugin && self::auto_active() ) {
			return true;
		}
		return $update;
	}

	public static function apres_maj( $upgrader, $extra ) {
		if ( isset( $extra['type'], $extra['plugins'] ) && 'plugin' === $extra['type'] && in_array( TGO_BASENAME, (array) $extra['plugins'], true ) ) {
			delete_site_transient( self::CACHE );
		}
	}

	/** Reporte notre réglage dans la liste « mises à jour auto » de WordPress (Extensions). */
	public static function synchroniser_maj_auto() {
		$liste = (array) get_site_option( 'auto_update_plugins', array() );
		$dans  = in_array( TGO_BASENAME, $liste, true );
		if ( self::auto_active() && ! $dans ) {
			$liste[] = TGO_BASENAME;
		} elseif ( ! self::auto_active() && $dans ) {
			$liste = array_values( array_diff( $liste, array( TGO_BASENAME ) ) );
		} else {
			return;
		}
		update_site_option( 'auto_update_plugins', $liste );
	}

	/** L'interrupteur a été changé depuis la liste des extensions : on suit. */
	public static function depuis_liste_extensions( $option, $valeur, $ancienne = array() ) {
		$voulu = in_array( TGO_BASENAME, (array) $valeur, true ) ? 1 : 0;
		if ( (int) self::auto_active() !== $voulu ) {
			remove_action( 'update_option_' . self::OPT_AUTO, array( __CLASS__, 'synchroniser_maj_auto' ) );
			update_option( self::OPT_AUTO, $voulu );
			add_action( 'update_option_' . self::OPT_AUTO, array( __CLASS__, 'synchroniser_maj_auto' ) );
		}
	}

	/** Toutes les 6 heures : nouvelle version ? → WordPress l'installe (si les mises à jour auto sont activées). */
	public static function tache_planifiee() {
		$info = self::derniere_version( true );
		if ( ! $info || ! version_compare( $info['version'], TGO_VERSION, '>' ) ) {
			return;
		}
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		if ( self::auto_active() && function_exists( 'wp_maybe_auto_update' ) ) {
			wp_maybe_auto_update();
		}
	}

	public static function action_verifier() {
		if ( ! current_user_can( 'update_plugins' ) || ! check_admin_referer( 'tgo_verifier_maj' ) ) {
			wp_die( 'Accès refusé.' );
		}
		self::derniere_version( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( admin_url( 'admin.php?page=' . TGO_Admin::MENU . '-maj&verifie=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Page « Mises à jour »
	 * ------------------------------------------------------------------- */

	public static function page() {
		$info    = self::derniere_version();
		$erreur  = get_site_option( 'tgo_maj_erreur' );
		$nouveau = $info && version_compare( $info['version'], TGO_VERSION, '>' );
		$bloque  = ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
		$prochain = wp_next_scheduled( self::CRON );
		?>
		<div class="wrap tgo-wrap">
			<h1>Mises à jour</h1>
			<?php if ( isset( $_GET['verifie'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>Vérification effectuée auprès de GitHub.</p></div>
			<?php endif; ?>
			<?php settings_errors(); ?>

			<table class="form-table" role="presentation">
				<tr><th>Version installée</th><td><strong><?php echo esc_html( TGO_VERSION ); ?></strong></td></tr>
				<tr><th>Dernière version sur GitHub</th><td>
					<?php if ( $info ) : ?>
						<strong><?php echo esc_html( $info['version'] ); ?></strong>
						<?php echo $info['date'] ? ' · publiée le ' . esc_html( mysql2date( 'j.m.Y', $info['date'] ) ) : ''; ?>
						· <a href="<?php echo esc_url( $info['url'] ); ?>" target="_blank" rel="noopener">voir sur GitHub</a>
						<?php if ( $nouveau ) : ?>
							<p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( TGO_BASENAME ) ), 'upgrade-plugin_' . TGO_BASENAME ) ); ?>">Mettre à jour maintenant</a></p>
						<?php else : ?>
							<p class="description">Vous avez la dernière version.</p>
						<?php endif; ?>
					<?php else : ?>
						<em>Inconnue</em>
						<?php if ( $erreur ) : ?>
							<p class="description">Dernier échec (<?php echo esc_html( mysql2date( 'j.m.Y H:i', $erreur['date'] ) ); ?>) : <code><?php echo esc_html( $erreur['detail'] ); ?></code></p>
						<?php endif; ?>
					<?php endif; ?>
				</td></tr>
				<tr><th>Dépôt</th><td><a href="https://github.com/<?php echo esc_attr( TGO_GITHUB_REPO ); ?>" target="_blank" rel="noopener">github.com/<?php echo esc_html( TGO_GITHUB_REPO ); ?></a></td></tr>
				<tr><th>Prochaine vérification</th><td><?php echo $prochain ? esc_html( wp_date( 'j.m.Y à H:i', $prochain ) ) : '—'; ?> <span class="description">(toutes les 6 heures)</span></td></tr>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 30px">
				<input type="hidden" name="action" value="tgo_verifier_maj">
				<?php wp_nonce_field( 'tgo_verifier_maj' ); ?>
				<?php submit_button( 'Vérifier maintenant', 'secondary', 'submit', false ); ?>
			</form>

			<h2>Mises à jour automatiques</h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'tgo_maj' ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::OPT_AUTO ); ?>" value="0">
				<label class="tgo-interrupteur">
					<input type="checkbox" name="<?php echo esc_attr( self::OPT_AUTO ); ?>" value="1" <?php checked( self::auto_active() ); ?>>
					<span class="tgo-curseur" aria-hidden="true"></span>
					<strong>Installer automatiquement chaque nouvelle version publiée sur GitHub</strong>
				</label>
				<p class="description">Dès qu'une nouvelle version est publiée sur GitHub, votre site l'installe tout seul (au plus tard 6 heures après). Les réglages et les données de tous les modules sont conservés.</p>
				<?php if ( $bloque ) : ?>
					<p class="tgo-raison">Les mises à jour automatiques sont bloquées par la configuration du site (<code>AUTOMATIC_UPDATER_DISABLED</code> ou <code>DISALLOW_FILE_MODS</code> dans wp-config.php). Demandez à votre hébergeur de les autoriser.</p>
				<?php endif; ?>
				<?php submit_button( 'Enregistrer' ); ?>
			</form>
		</div>
		<?php
	}
}
