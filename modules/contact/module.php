<?php
/**
 * Module « Formulaire de contact » (anciennement l'extension ta-gravure – Formulaire de contact 1.5.0).
 * Formulaire avec types de demande et pièces jointes sécurisées (images, PDF, Word, Excel, texte — sans macros).
 * Chaque demande est enregistrée dans « Demandes clients » avec un statut de traitement et des notes internes.
 * Code court : [tg_contact]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TG_CONTACT_FILE', TGO_FILE );
define( 'TG_CONTACT_DIR', plugin_dir_path( __FILE__ ) );

require_once TG_CONTACT_DIR . 'includes/fichiers.php';
require_once TG_CONTACT_DIR . 'includes/antispam.php';
require_once TG_CONTACT_DIR . 'includes/formulaire.php';
require_once TG_CONTACT_DIR . 'includes/admin.php';

/* -------------------------------------------------------------------------
 * Réglages et constantes
 * ---------------------------------------------------------------------- */

function tg_contact_reglages() {
	$defaut = array(
		'destinataire' => 'carlos@ta-gravure.art',
		'expediteur'   => 'carlos@ta-gravure.art',
		'delai'        => '48 heures (jours ouvrables)',
		'conservation' => 90,
		'filtre_spam'  => 1,
		'liste_noire'  => '',
	);
	$r = get_option( 'tg_contact_reglages', array() );
	$r = wp_parse_args( is_array( $r ) ? $r : array(), $defaut );
	if ( ! is_email( $r['expediteur'] ) ) {
		$r['expediteur'] = $r['destinataire'];
	}
	return $r;
}

/* -------------------------------------------------------------------------
 * Journal de diagnostic : envois reçus, robots écartés, e-mails envoyés ou en échec
 * ---------------------------------------------------------------------- */

function tg_contact_journal( $type, $detail = '' ) {
	$j = get_option( 'tg_contact_journal', array() );
	$j = is_array( $j ) ? $j : array();
	array_unshift( $j, array( 'date' => current_time( 'mysql' ), 'type' => $type, 'detail' => mb_substr( (string) $detail, 0, 400 ) ) );
	update_option( 'tg_contact_journal', array_slice( $j, 0, 40 ), false );
	if ( $type === 'mail_echec' ) {
		update_option( 'tg_contact_derniere_erreur', array( 'date' => current_time( 'mysql' ), 'detail' => mb_substr( (string) $detail, 0, 400 ) ), false );
	} elseif ( $type === 'mail_ok' ) {
		delete_option( 'tg_contact_derniere_erreur' );
	}
}

/** Coupe le HTML en lignes courtes : une ligne par balise de bloc, jamais plus de ~900 caractères. */
function tg_contact_lignes_courtes( $html ) {
	$html = preg_replace( '#(</(?:tr|td|table|p|div|h1|h2|li|ul|head|title)>|<br\s*/?>|<(?:tr|table|body|html)\b[^>]*>)#i', "$1\n", $html );
	$out  = array();
	foreach ( explode( "\n", $html ) as $l ) {
		while ( strlen( $l ) > 900 ) {
			$coupe = strrpos( substr( $l, 0, 900 ), ' ' );
			$coupe = $coupe ? $coupe : 900;
			$out[] = substr( $l, 0, $coupe );
			$l     = substr( $l, $coupe );
		}
		$out[] = $l;
	}
	return implode( "\n", $out );
}

/**
 * Envoie un e-mail et rend [ réussi, message d'erreur ].
 * Un e-mail mis en page (HTML) refusé par le serveur est renvoyé automatiquement en texte simple ($texte_secours).
 * La réponse exacte du serveur d'envoi est conservée pour le diagnostic.
 */
function tg_contact_envoyer( $a, $sujet, $corps, $entetes = array(), $pj = array(), $texte_secours = '' ) {
	$r    = tg_contact_reglages();
	$html = (bool) preg_grep( '/^Content-Type:\s*text\/html/i', $entetes );
	$base = array_values( preg_grep( '/^Content-Type:/i', $entetes, PREG_GREP_INVERT ) );
	$base[] = 'From: ta-gravure.art <' . $r['expediteur'] . '>';

	if ( $html ) {
		list( $ok, $erreur ) = tg_contact_tenter( $a, $sujet, tg_contact_lignes_courtes( $corps ), array_merge( $base, array( 'Content-Type: text/html; charset=UTF-8' ) ), $pj, $texte_secours );
		if ( $ok || $texte_secours === '' ) {
			tg_contact_journal( $ok ? 'mail_ok' : 'mail_echec', ( $ok ? 'Envoyé (mis en page) à ' : 'Échec vers ' ) . $a . ( $erreur ? ' : ' . $erreur : '' ) );
			return array( $ok, $erreur );
		}
		tg_contact_journal( 'mail_secours', 'Version mise en page refusée pour ' . $a . ' : ' . $erreur . ' → renvoi en texte simple' );
		$corps = $texte_secours;
	}

	list( $ok, $erreur ) = tg_contact_tenter( $a, $sujet, $corps, array_merge( $base, array( 'Content-Type: text/plain; charset=UTF-8' ) ), $pj, '' );
	tg_contact_journal( $ok ? 'mail_ok' : 'mail_echec', ( $ok ? 'Envoyé (texte) à ' : 'Échec vers ' ) . $a . ( $erreur ? ' : ' . $erreur : '' ) );
	return array( $ok, $erreur );
}

/** Une tentative d'envoi, avec la réponse du serveur SMTP capturée en cas d'échec. */
function tg_contact_tenter( $a, $sujet, $corps, $entetes, $pj, $alt ) {
	$erreur  = '';
	$serveur = array();
	$capte   = function ( $e ) use ( &$erreur ) {
		if ( is_wp_error( $e ) ) {
			$erreur = $e->get_error_message();
		}
	};
	$prepa   = function ( $phpmailer ) use ( $alt, &$serveur ) {
		$phpmailer->AltBody = $alt;
		if ( isset( $phpmailer->Mailer ) && $phpmailer->Mailer === 'smtp' ) {
			$phpmailer->SMTPDebug   = 2;
			$phpmailer->Debugoutput = function ( $ligne ) use ( &$serveur ) {
				if ( strpos( $ligne, 'SERVER -> CLIENT' ) !== false ) {
					$serveur[] = trim( str_replace( 'SERVER -> CLIENT:', '', $ligne ) );
					$serveur   = array_slice( $serveur, -3 );
				}
			};
		}
	};
	add_action( 'wp_mail_failed', $capte );
	add_action( 'phpmailer_init', $prepa, 999 );
	$ok = (bool) wp_mail( $a, $sujet, $corps, $entetes, $pj );
	remove_action( 'phpmailer_init', $prepa, 999 );
	remove_action( 'wp_mail_failed', $capte );
	global $phpmailer;
	if ( isset( $phpmailer ) && is_object( $phpmailer ) ) {
		$phpmailer->SMTPDebug = 0; // ne pas laisser le mode diagnostic aux e-mails suivants (WooCommerce…)
	}
	if ( ! $ok ) {
		if ( $erreur === '' ) {
			$erreur = 'wp_mail() a refusé l\'envoi sans donner de motif (extension SMTP mal configurée ?).';
		}
		if ( $serveur ) {
			$erreur .= ' | Réponse du serveur : ' . implode( ' / ', $serveur );
		}
	}
	return array( $ok, $erreur );
}

function tg_contact_types() {
	return array(
		'commande' => 'Commande personnalisée',
		'devis'    => 'Devis pour une entreprise',
		'suivi'    => 'Question sur une commande',
		'autre'    => 'Autre demande',
	);
}

function tg_contact_statuts() {
	return array(
		'nouvelle'   => array( 'Nouvelle', '#B3261E' ),
		'en_cours'   => array( 'En cours', '#8C5E22' ),
		'attente'    => array( 'En attente du client', '#6B5B95' ),
		'traitee'    => array( 'Traitée', '#2E7D4F' ),
		'archivee'   => array( 'Archivée', '#777777' ),
	);
}

/* -------------------------------------------------------------------------
 * Type de contenu « Demande client » (privé, visible seulement dans l'administration)
 * ---------------------------------------------------------------------- */

add_action( 'init', function () {
	register_post_type( 'tg_demande', array(
		'labels'              => array(
			'name'               => 'Demandes clients',
			'singular_name'      => 'Demande client',
			'menu_name'          => 'Demandes clients',
			'all_items'          => 'Toutes les demandes',
			'edit_item'          => 'Traiter la demande',
			'search_items'       => 'Rechercher une demande',
			'not_found'          => 'Aucune demande.',
			'not_found_in_trash' => 'Aucune demande dans la corbeille.',
		),
		'public'              => false,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'show_in_rest'        => false,
		'exclude_from_search' => true,
		'publicly_queryable'  => false,
		'menu_position'       => 56,
		'menu_icon'           => 'dashicons-email-alt',
		'supports'            => array( 'title' ),
		'capability_type'     => 'page',
		'map_meta_cap'        => true,
		'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
	) );
} );

/* -------------------------------------------------------------------------
 * Activation : dossier protégé + nettoyage quotidien
 * ---------------------------------------------------------------------- */

add_action( 'tgo_activer_module_contact', function () {
	tg_contact_dossier();
	if ( ! wp_next_scheduled( 'tg_contact_nettoyage' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tg_contact_nettoyage' );
	}
} );

add_action( 'tgo_desactiver_module_contact', function () {
	wp_clear_scheduled_hook( 'tg_contact_nettoyage' );
} );

/** Supprime les pièces jointes plus anciennes que la durée de conservation (la demande reste). */
add_action( 'tg_contact_nettoyage', function () {
	$jours = max( 7, (int) tg_contact_reglages()['conservation'] );
	$ids   = get_posts( array(
		'post_type'      => 'tg_demande',
		'post_status'    => 'any',
		'fields'         => 'ids',
		'posts_per_page' => 200,
		'date_query'     => array( array( 'before' => $jours . ' days ago' ) ),
		'meta_query'     => array( array( 'key' => '_tg_fichiers', 'compare' => 'EXISTS' ) ),
	) );
	foreach ( $ids as $id ) {
		tg_contact_supprimer_fichiers( $id, true );
	}
} );

add_action( 'before_delete_post', function ( $post_id ) {
	if ( get_post_type( $post_id ) === 'tg_demande' ) {
		tg_contact_supprimer_fichiers( $post_id, false );
	}
} );
