<?php
/**
 * Noyau : chargement des modules, activation/désactivation, réglages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TGO_Core {

	const OPT_ACTIFS     = 'tgo_modules_actifs';
	const OPT_EN_ATTENTE = 'tgo_modules_a_activer';

	/** @var array id => true pour les modules chargés dans cette requête. */
	private static $charges = array();

	/** @var array id => raison pour les modules activés mais non chargés. */
	private static $bloques = array();

	/* ---------------------------------------------------------------------
	 * État des modules
	 * ------------------------------------------------------------------- */

	/** Modules cochés (tous par défaut). */
	public static function actifs() {
		$opt = get_option( self::OPT_ACTIFS, null );
		$res = array();
		foreach ( array_keys( tgo_modules_registre() ) as $id ) {
			$res[ $id ] = ( ! is_array( $opt ) || ! array_key_exists( $id, $opt ) ) ? true : (bool) $opt[ $id ];
		}
		return $res;
	}

	public static function est_charge( $id ) {
		return ! empty( self::$charges[ $id ] );
	}

	public static function raison_blocage( $id ) {
		return isset( self::$bloques[ $id ] ) ? self::$bloques[ $id ] : '';
	}

	/** Extensions actives (site + réseau). */
	private static function extensions_actives() {
		$liste = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$liste = array_merge( $liste, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return $liste;
	}

	public static function woocommerce_actif() {
		return class_exists( 'WooCommerce' ) || in_array( 'woocommerce/woocommerce.php', self::extensions_actives(), true );
	}

	/** Chemin de l'ancienne extension séparée encore active pour ce module, sinon ''. */
	public static function ancienne_extension( $id ) {
		$reg = tgo_modules_registre();
		if ( empty( $reg[ $id ]['ancien'] ) ) {
			return '';
		}
		foreach ( self::extensions_actives() as $p ) {
			if ( basename( $p ) === $reg[ $id ]['ancien'] && $p !== TGO_BASENAME ) {
				return $p;
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Chargement
	 * ------------------------------------------------------------------- */

	public static function charger_modules() {
		$reg = tgo_modules_registre();
		foreach ( self::actifs() as $id => $actif ) {
			if ( ! $actif ) {
				continue;
			}
			$m = $reg[ $id ];
			if ( self::ancienne_extension( $id ) ) {
				self::$bloques[ $id ] = 'L\'ancienne extension séparée « ' . $m['nom'] . ' » est encore active : désactivez-la pour utiliser ce module.';
				continue;
			}
			if ( ! empty( $m['sentinelle'] ) && ( function_exists( $m['sentinelle'] ) || class_exists( $m['sentinelle'], false ) ) ) {
				self::$bloques[ $id ] = 'Une ancienne version de « ' . $m['nom'] . ' » est déjà chargée ailleurs (dossier mu-plugins ou thème) : supprimez-la pour utiliser ce module.';
				continue;
			}
			if ( ! empty( $m['woocommerce'] ) && ! self::woocommerce_actif() ) {
				self::$bloques[ $id ] = 'Ce module a besoin de WooCommerce, qui n\'est pas actif.';
				continue;
			}
			$fichier = TGO_DIR . 'modules/' . $id . '/module.php';
			if ( ! file_exists( $fichier ) ) {
				self::$bloques[ $id ] = 'Fichier du module introuvable.';
				continue;
			}
			require_once $fichier;
			self::$charges[ $id ] = true;
		}

		add_action( 'wp_loaded', array( __CLASS__, 'activations_en_attente' ) );
		add_action( 'update_option_' . self::OPT_ACTIFS, array( __CLASS__, 'modules_modifies' ), 10, 2 );
		add_action( 'add_option_' . self::OPT_ACTIFS, function ( $option, $valeur ) {
			TGO_Core::modules_modifies( array(), $valeur );
		}, 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Activation / désactivation
	 * ------------------------------------------------------------------- */

	/** Activation de l'extension : remplace les anciennes extensions séparées. */
	public static function activation() {
		$anciennes = array();
		foreach ( array_keys( tgo_modules_registre() ) as $id ) {
			$p = self::ancienne_extension( $id );
			if ( $p ) {
				$anciennes[] = $p;
			}
		}

		// Sauvegarde des réglages des prix dégressifs : l'ancienne extension les efface quand on la supprime.
		$sauvegarde = array();
		foreach ( array( 'show_table', 'table_title', 'table_position', 'loop_hint', 'aggregate', 'cart_strike', 'order_meta' ) as $k ) {
			$v = get_option( 'tgpd_' . $k, null );
			if ( null !== $v ) {
				$sauvegarde[ $k ] = $v;
			}
		}
		if ( $sauvegarde ) {
			update_option( 'tgo_sauvegarde_tgpd', $sauvegarde, false );
		}

		if ( $anciennes && function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( $anciennes );
			update_option( 'tgo_anciennes_desactivees', $anciennes, false );
		}

		// Les modules seront (ré)initialisés à la prochaine page, une fois réellement chargés.
		$attente = array();
		foreach ( self::actifs() as $id => $actif ) {
			if ( $actif ) {
				$attente[] = $id;
			}
		}
		update_option( self::OPT_EN_ATTENTE, $attente, false );

		// Mises à jour automatiques activées par défaut.
		if ( get_option( 'tgo_maj_auto', null ) === null ) {
			update_option( 'tgo_maj_auto', 1 );
		}
		TGO_Updater::synchroniser_maj_auto();
	}

	public static function desactivation() {
		foreach ( array_keys( self::$charges ) as $id ) {
			do_action( 'tgo_desactiver_module_' . $id );
		}
		wp_clear_scheduled_hook( 'tgo_verifier_maj' );
	}

	/** Exécute les initialisations des modules nouvellement activés. */
	public static function activations_en_attente() {
		$attente = get_option( self::OPT_EN_ATTENTE, array() );
		if ( ! is_array( $attente ) || ! $attente ) {
			return;
		}
		$reste = array();
		foreach ( $attente as $id ) {
			if ( self::est_charge( $id ) ) {
				do_action( 'tgo_activer_module_' . $id );
			} elseif ( isset( self::$bloques[ $id ] ) && self::actifs()[ $id ] ) {
				$reste[] = $id; // réessayer quand l'obstacle sera levé.
			}
		}
		if ( $reste ) {
			update_option( self::OPT_EN_ATTENTE, $reste, false );
		} else {
			delete_option( self::OPT_EN_ATTENTE );
		}
	}

	/** Un module vient d'être coché ou décoché dans « Modules ». */
	public static function modules_modifies( $ancien, $nouveau ) {
		$ancien  = is_array( $ancien ) ? $ancien : array();
		$nouveau = is_array( $nouveau ) ? $nouveau : array();
		$attente = get_option( self::OPT_EN_ATTENTE, array() );
		$attente = is_array( $attente ) ? $attente : array();
		foreach ( array_keys( tgo_modules_registre() ) as $id ) {
			$avant = ! array_key_exists( $id, $ancien ) || ! empty( $ancien[ $id ] );
			$apres = ! empty( $nouveau[ $id ] );
			if ( $avant && ! $apres && self::est_charge( $id ) ) {
				do_action( 'tgo_desactiver_module_' . $id );
			}
			if ( $apres && ( ! $avant || ! self::est_charge( $id ) ) ) {
				$attente[] = $id;
			}
		}
		update_option( self::OPT_EN_ATTENTE, array_values( array_unique( $attente ) ), false );
	}

	/* ---------------------------------------------------------------------
	 * Réglages des modules
	 * ------------------------------------------------------------------- */

	public static function champs( $module ) {
		$reg = tgo_modules_registre();
		return isset( $reg[ $module ]['reglages'] ) ? $reg[ $module ]['reglages'] : array();
	}

	public static function reglage( $module, $cle ) {
		static $cache = array();
		if ( ! isset( $cache[ $module ] ) ) {
			$v                = get_option( 'tgo_reglages_' . $module, array() );
			$cache[ $module ] = is_array( $v ) ? $v : array();
		}
		if ( array_key_exists( $cle, $cache[ $module ] ) ) {
			return $cache[ $module ][ $cle ];
		}
		foreach ( self::champs( $module ) as $c ) {
			if ( $c['cle'] === $cle ) {
				return $c['defaut'];
			}
		}
		return null;
	}

	/** Nettoie les valeurs envoyées par le formulaire de réglages d'un module. */
	public static function nettoyer_reglages( $module, $entree ) {
		$entree = is_array( $entree ) ? $entree : array();
		$res    = array();
		foreach ( self::champs( $module ) as $c ) {
			$v = isset( $entree[ $c['cle'] ] ) ? wp_unslash( $entree[ $c['cle'] ] ) : '';
			switch ( $c['type'] ) {
				case 'nombre':
					$v = (int) $v;
					if ( isset( $c['min'] ) ) {
						$v = max( (int) $c['min'], $v );
					}
					if ( isset( $c['max'] ) ) {
						$v = min( (int) $c['max'], $v );
					}
					break;
				case 'image':
					$v = absint( $v );
					break;
				case 'case':
					$v = empty( $v ) ? 0 : 1;
					break;
				case 'url':
					$v = trim( (string) $v );
					$v = ( strpos( $v, '/' ) === 0 && strpos( $v, '//' ) !== 0 ) ? sanitize_text_field( $v ) : esc_url_raw( $v );
					if ( $v === '' ) {
						$v = $c['defaut'];
					}
					break;
				case 'email':
					$v = is_email( $v ) ? sanitize_email( $v ) : $c['defaut'];
					break;
				case 'zone':
					$v = sanitize_textarea_field( $v );
					break;
				default:
					$v = sanitize_text_field( $v );
					if ( $v === '' ) {
						$v = $c['defaut'];
					}
			}
			$res[ $c['cle'] ] = $v;
		}
		return $res;
	}

	/** URL absolue à partir d'un réglage « url » (accepte /wp-content/…). */
	public static function url_absolue( $v ) {
		$v = (string) $v;
		return ( strpos( $v, '/' ) === 0 && strpos( $v, '//' ) !== 0 ) ? home_url( $v ) : $v;
	}
}
