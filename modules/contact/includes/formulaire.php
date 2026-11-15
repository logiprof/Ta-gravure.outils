<?php
/**
 * Formulaire public [tg_contact] : affichage, contrôles, enregistrement et e-mails.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Jeton anti-robots : heure d'affichage signée (sans nonce, compatible avec le cache de page). */
function tg_contact_jeton() {
	$t = (string) time();
	return $t . '.' . substr( hash_hmac( 'sha256', $t, wp_salt( 'nonce' ) ), 0, 20 );
}

function tg_contact_jeton_valide( $jeton ) {
	$p = explode( '.', (string) $jeton );
	if ( count( $p ) !== 2 || ! ctype_digit( $p[0] ) ) {
		return false;
	}
	if ( ! hash_equals( substr( hash_hmac( 'sha256', $p[0], wp_salt( 'nonce' ) ), 0, 20 ), $p[1] ) ) {
		return false;
	}
	return ( time() - (int) $p[0] ) >= 2; // un humain met plus de 2 secondes à remplir le formulaire
}

function tg_contact_ip_cle() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return 'tg_ct_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
}

/* -------------------------------------------------------------------------
 * Traitement de l'envoi
 * ---------------------------------------------------------------------- */

$GLOBALS['tg_contact_resultat'] = null;

add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['tg_contact_envoi'] ) ) {
		return;
	}
	$GLOBALS['tg_contact_resultat'] = tg_contact_traiter();
	if ( ! empty( $GLOBALS['tg_contact_resultat']['ok'] ) ) {
		$url = add_query_arg( 'demande', 'envoyee', remove_query_arg( array( 'demande', 'type' ) ) );
		wp_safe_redirect( $url . '#tg-contact' );
		exit;
	}
} );

function tg_contact_traiter() {
	$p = wp_unslash( $_POST );

	// Robots : champ piège rempli ou jeton invalide → on fait comme si tout allait bien, sans rien enregistrer,
	// mais on le note dans le journal de diagnostic (Demandes clients › Réglages).
	if ( ! empty( $p['tg_hp_zx'] ) ) {
		tg_contact_journal( 'robot', 'Champ piège rempli (' . sanitize_email( $p['tg_email'] ?? '' ) . ')' );
		return array( 'ok' => true );
	}
	if ( ! tg_contact_jeton_valide( $p['tg_t'] ?? '' ) ) {
		tg_contact_journal( 'robot', 'Envoi trop rapide ou jeton invalide (' . sanitize_email( $p['tg_email'] ?? '' ) . ')' );
		return array( 'ok' => true );
	}

	$cle = tg_contact_ip_cle();
	$nb  = (int) get_transient( $cle );
	if ( $nb >= 5 ) {
		tg_contact_journal( 'limite', 'Limite de 5 envois par heure atteinte' );
		return array( 'ok' => false, 'erreurs' => array( 'Vous avez envoyé plusieurs demandes en peu de temps. Merci de réessayer dans une heure ou de nous écrire directement par e-mail.' ) );
	}

	$types = tg_contact_types();
	$d     = array(
		'type'       => sanitize_key( $p['tg_type'] ?? '' ),
		'nom'        => sanitize_text_field( $p['tg_nom'] ?? '' ),
		'email'      => sanitize_email( $p['tg_email'] ?? '' ),
		'tel'        => sanitize_text_field( $p['tg_tel'] ?? '' ),
		'produit'    => absint( $p['tg_produit'] ?? 0 ),
		'entreprise' => sanitize_text_field( $p['tg_entreprise'] ?? '' ),
		'quantite'   => absint( $p['tg_quantite'] ?? 0 ),
		'commande'   => sanitize_text_field( $p['tg_commande'] ?? '' ),
		'message'    => sanitize_textarea_field( $p['tg_message'] ?? '' ),
		'consent'    => ! empty( $p['tg_consent'] ),
	);

	$err = array();
	if ( ! isset( $types[ $d['type'] ] ) ) {
		$err[] = 'Choisissez le type de votre demande.';
	}
	if ( mb_strlen( $d['nom'] ) < 2 || mb_strlen( $d['nom'] ) > 100 ) {
		$err[] = 'Indiquez votre nom et prénom.';
	}
	if ( ! is_email( $d['email'] ) ) {
		$err[] = 'Indiquez une adresse e-mail valide.';
	}
	if ( $d['tel'] !== '' && ! preg_match( '/^[0-9 +().\/-]{6,30}$/', $d['tel'] ) ) {
		$err[] = 'Le numéro de téléphone n\'est pas valide.';
	}
	if ( $d['produit'] && get_post_type( $d['produit'] ) !== 'product' ) {
		$d['produit'] = 0;
	}
	if ( $d['type'] === 'devis' && $d['entreprise'] === '' ) {
		$err[] = 'Indiquez le nom de votre entreprise.';
	}
	if ( mb_strlen( $d['message'] ) < 5 ) {
		$err[] = 'Écrivez votre message.';
	} elseif ( mb_strlen( $d['message'] ) > 5000 ) {
		$err[] = 'Votre message est trop long (5000 caractères max.).';
	}
	if ( ! $d['consent'] ) {
		$err[] = 'Merci d\'accepter l\'utilisation de vos données pour traiter votre demande.';
	}
	if ( $err ) {
		return array( 'ok' => false, 'erreurs' => $err, 'valeurs' => $d );
	}

	list( $fichiers, $err_f ) = tg_contact_traiter_fichiers( 'tg_fichiers' );
	if ( $err_f ) {
		return array( 'ok' => false, 'erreurs' => array_merge( $err_f, array( 'Aucun fichier n\'a été enregistré : corrigez la sélection puis renvoyez le formulaire.' ) ), 'valeurs' => $d );
	}

	// Démarchage publicitaire : supprimé directement (rien n'est enregistré, aucun e-mail n'est envoyé).
	// Seule une trace reste dans le journal de diagnostic, pour retrouver un vrai client filtré par erreur.
	list( $score, $raisons ) = tg_contact_score_spam( $d );
	if ( ! empty( tg_contact_reglages()['filtre_spam'] ) && $score >= TG_CONTACT_SEUIL_SPAM ) {
		foreach ( $fichiers as $fi ) {
			@unlink( $fi['chemin'] );
		}
		set_transient( $cle, $nb + 1, HOUR_IN_SECONDS );
		tg_contact_journal( 'spam', $d['nom'] . ' <' . $d['email'] . '> · ' . implode( ' ; ', $raisons ) . ' · « ' . mb_substr( preg_replace( '/\s+/', ' ', $d['message'] ), 0, 160 ) . '… »' );
		return array( 'ok' => true );
	}

	$id = wp_insert_post( array(
		'post_type'    => 'tg_demande',
		'post_status'  => 'publish',
		'post_title'   => $types[ $d['type'] ] . ' – ' . $d['nom'],
		'post_content' => $d['message'],
	), true );
	if ( is_wp_error( $id ) ) {
		foreach ( $fichiers as $fi ) {
			@unlink( $fi['chemin'] );
		}
		return array( 'ok' => false, 'erreurs' => array( 'Une erreur technique a empêché l\'envoi. Merci de réessayer ou de nous écrire par e-mail.' ), 'valeurs' => $d );
	}

	foreach ( array( 'type', 'nom', 'email', 'tel', 'produit', 'entreprise', 'quantite', 'commande' ) as $k ) {
		update_post_meta( $id, '_tg_' . $k, $d[ $k ] );
	}
	update_post_meta( $id, '_tg_statut', 'nouvelle' );
	update_post_meta( $id, '_tg_consent', current_time( 'mysql' ) );
	update_post_meta( $id, '_tg_page', esc_url_raw( wp_get_referer() ?: '' ) );
	if ( $fichiers ) {
		update_post_meta( $id, '_tg_fichiers', $fichiers );
	}

	set_transient( $cle, $nb + 1, HOUR_IN_SECONDS );
	tg_contact_journal( 'demande', 'Demande n° ' . $id . ' enregistrée (' . $d['email'] . ')' );
	list( $envoye, $erreur ) = tg_contact_mail_boutique( $id, $d, $fichiers );
	update_post_meta( $id, '_tg_mail_envoye', $envoye ? 'oui' : 'non' );
	if ( ! $envoye ) {
		update_post_meta( $id, '_tg_mail_erreur', $erreur );
	}
	tg_contact_mail_client( $d, $fichiers );

	return array( 'ok' => true );
}

/* -------------------------------------------------------------------------
 * E-mails
 * ---------------------------------------------------------------------- */

function tg_contact_mail_boutique( $id, $d, $fichiers ) {
	$r      = tg_contact_reglages();
	$types  = tg_contact_types();
	$lignes = array(
		'Nouvelle demande reçue sur ' . wp_parse_url( home_url(), PHP_URL_HOST ),
		'',
		'Type : ' . $types[ $d['type'] ],
		'Nom : ' . $d['nom'],
		'E-mail : ' . $d['email'],
	);
	if ( $d['tel'] ) {
		$lignes[] = 'Téléphone : ' . $d['tel'];
	}
	if ( $d['entreprise'] ) {
		$lignes[] = 'Entreprise : ' . $d['entreprise'];
	}
	if ( $d['quantite'] ) {
		$lignes[] = 'Quantité souhaitée : ' . $d['quantite'];
	}
	if ( $d['commande'] ) {
		$lignes[] = 'N° de commande : ' . $d['commande'];
	}
	if ( $d['produit'] ) {
		$lignes[] = 'Produit : ' . get_the_title( $d['produit'] ) . ' – ' . get_permalink( $d['produit'] );
	}
	$lignes[] = '';
	$lignes[] = 'Message :';
	$lignes[] = $d['message'];
	$lignes[] = '';

	$pj    = array();
	$total = 0;
	foreach ( $fichiers as $fi ) {
		$total += $fi['taille'];
	}
	if ( $fichiers ) {
		$lignes[] = 'Pièces jointes (' . count( $fichiers ) . ') : ' . implode( ', ', wp_list_pluck( $fichiers, 'nom' ) );
		if ( $total <= 15 * 1048576 ) {
			// Copie temporaire sous le nom d'origine, dans un dossier à part, pour que la pièce jointe garde son nom.
			$tmpdir = trailingslashit( get_temp_dir() ) . 'tg-' . wp_generate_password( 10, false );
			if ( wp_mkdir_p( $tmpdir ) ) {
				foreach ( $fichiers as $i => $fi ) {
					$tmp = $tmpdir . '/' . ( isset( $pj[ $fi['nom'] ] ) ? $i . '-' : '' ) . $fi['nom'];
					if ( @copy( $fi['chemin'], $tmp ) ) {
						$pj[ $fi['nom'] ] = $tmp;
					}
				}
			}
		} else {
			$lignes[] = '(trop lourdes pour l\'e-mail : à télécharger depuis l\'administration)';
		}
	}
	$lien_admin = admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
	$lignes[]   = '';
	$lignes[]   = 'Traiter la demande : ' . $lien_admin;
	$texte      = implode( "\n", $lignes );

	$html = tg_contact_html_boutique( $id, $d, $fichiers, $total <= 15 * 1048576, $lien_admin );
	$res = tg_contact_envoyer(
		$r['destinataire'],
		'[ta-gravure] ' . $types[ $d['type'] ] . ' – ' . $d['nom'],
		$html,
		array( 'Reply-To: ' . $d['nom'] . ' <' . $d['email'] . '>', 'Content-Type: text/html; charset=UTF-8' ),
		array_values( $pj ),
		$texte
	);
	foreach ( $pj as $tmp ) {
		@unlink( $tmp );
	}
	if ( ! empty( $tmpdir ) ) {
		@rmdir( $tmpdir );
	}
	return $res;
}

/** E-mail reçu par la boutique, aux couleurs du site, avec boutons d'action. */
function tg_contact_html_boutique( $id, $d, $fichiers, $joints, $lien_admin ) {
	$e     = function ( $s ) {
		return esc_html( (string) $s );
	};
	$types = tg_contact_types();
	$site  = home_url( '/' );
	$sujet = rawurlencode( 'Re : votre demande – ta-gravure.art' );

	// Badge de couleur selon le type de demande.
	$couleurs = array( 'commande' => '#E2B36A', 'devis' => '#F0C987', 'suivi' => '#CFE3D8', 'autre' => '#E9E2D4' );
	$badge    = $couleurs[ $d['type'] ] ?? '#E9E2D4';

	$infos = array(
		'E-mail'    => '<a href="mailto:' . esc_attr( $d['email'] ) . '?subject=' . $sujet . '" style="color:#8C5E22;font-weight:600;text-decoration:none">' . $e( $d['email'] ) . '</a>',
	);
	if ( $d['tel'] ) {
		$infos['Téléphone'] = '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $d['tel'] ) ) . '" style="color:#8C5E22;font-weight:600;text-decoration:none">' . $e( $d['tel'] ) . '</a>';
	}
	if ( $d['entreprise'] ) {
		$infos['Entreprise'] = $e( $d['entreprise'] );
	}
	if ( $d['quantite'] ) {
		$infos['Quantité'] = $e( $d['quantite'] ) . ' pièces environ';
	}
	if ( $d['commande'] ) {
		$infos['N° de commande'] = $e( $d['commande'] );
	}
	if ( $d['produit'] ) {
		$infos['Produit'] = '<a href="' . esc_url( get_permalink( $d['produit'] ) ) . '" style="color:#8C5E22;font-weight:600;text-decoration:none">' . $e( get_the_title( $d['produit'] ) ) . '</a>';
	}
	$lignes = '';
	foreach ( $infos as $lib => $val ) {
		$lignes .= '<tr><td style="padding:9px 0;border-bottom:1px solid #E3D6C1;color:#8C5E22;font-size:13px;font-weight:600;width:140px;vertical-align:top">' . $e( $lib ) . '</td>'
			. '<td style="padding:9px 0;border-bottom:1px solid #E3D6C1;color:#33403B;font-size:14px">' . $val . '</td></tr>';
	}

	$bloc_fichiers = '';
	if ( $fichiers ) {
		$items = '';
		foreach ( $fichiers as $fi ) {
			$items .= '<tr><td style="padding:6px 0;color:#33403B;font-size:14px">📎 ' . $e( $fi['nom'] ) . ' <span style="color:#8a8f8c;font-size:12px">(' . $e( size_format( $fi['taille'] ) ) . ')</span></td></tr>';
		}
		$bloc_fichiers = '<tr><td style="padding:22px 32px 0">'
			. '<h2 style="margin:0 0 8px;color:#173A2E;font-size:17px;font-weight:600">Fichiers du client (' . count( $fichiers ) . ')</h2>'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#F6EFE3;border-radius:10px;padding:10px 16px">' . $items . '</table>'
			. '<p style="margin:8px 0 0;color:#6b706d;font-size:12px">' . ( $joints ? 'Joints à cet e-mail et disponibles dans l\'administration.' : 'Trop lourds pour l\'e-mail : à télécharger depuis l\'administration.' ) . '</p>'
			. '</td></tr>';
	}

	return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Nouvelle demande</title></head>'
		. '<body style="margin:0;padding:0;background:#F6EFE3;font-family:\'Open Sans\',Arial,Helvetica,sans-serif;">'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#F6EFE3"><tr><td align="center" style="padding:24px 12px">'
		. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#FFFBF4;border:1px solid #E3D6C1;border-radius:16px;overflow:hidden">'

		// En-tête
		. '<tr><td style="padding:18px 32px;background:#FFFBF4;border-bottom:3px solid #E2B36A">'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
		. '<td><img src="' . esc_url( tg_contact_logo() ) . '" width="150" alt="ta-gravure.art" style="display:block;width:150px;height:auto;border:0;color:#173A2E;font-weight:600"></td>'
		. '<td align="right" style="color:#8C5E22;font-size:12px">' . $e( wp_date( 'j.m.Y · H:i' ) ) . '</td>'
		. '</tr></table></td></tr>'

		// Bandeau
		. '<tr><td style="padding:28px 32px 26px;background:#173A2E">'
		. '<p style="margin:0 0 12px"><span style="display:inline-block;background:' . esc_attr( $badge ) . ';color:#173A2E;font-size:12px;font-weight:700;padding:5px 12px;border-radius:20px">' . $e( $types[ $d['type'] ] ) . '</span></p>'
		. '<h1 style="margin:0 0 6px;color:#F6EFE3;font-size:24px;line-height:1.25;font-weight:600">Nouvelle demande de ' . $e( $d['nom'] ) . '</h1>'
		. '<p style="margin:0;color:#E9E2D4;font-size:14px">Demande n° ' . (int) $id . ' · statut « Nouvelle »</p>'
		. '</td></tr>'

		// Coordonnées
		. '<tr><td style="padding:24px 32px 0">'
		. '<h2 style="margin:0 0 6px;color:#173A2E;font-size:17px;font-weight:600">Le client</h2>'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $lignes . '</table>'
		. '</td></tr>'

		// Message
		. '<tr><td style="padding:22px 32px 0">'
		. '<h2 style="margin:0 0 8px;color:#173A2E;font-size:17px;font-weight:600">Son message</h2>'
		. '<div style="background:#F6EFE3;border-left:3px solid #E2B36A;border-radius:6px;padding:16px 18px;color:#33403B;font-size:15px;line-height:1.65">' . nl2br( $e( $d['message'] ) ) . '</div>'
		. '</td></tr>'

		. $bloc_fichiers

		// Boutons
		. '<tr><td align="center" style="padding:28px 32px 30px">'
		. '<a href="mailto:' . esc_attr( $d['email'] ) . '?subject=' . $sujet . '" style="display:inline-block;margin:4px;background:#E2B36A;color:#173A2E;text-decoration:none;font-weight:700;font-size:15px;padding:12px 26px;border-radius:40px">Répondre au client</a>'
		. '<a href="' . esc_url( $lien_admin ) . '" style="display:inline-block;margin:4px;background:#173A2E;color:#F6EFE3;text-decoration:none;font-weight:700;font-size:15px;padding:12px 26px;border-radius:40px">Traiter la demande</a>'
		. '<p style="margin:14px 0 0;color:#6b706d;font-size:12px">Astuce : le bouton « Répondre » de votre messagerie écrit directement au client.</p>'
		. '</td></tr>'

		// Pied
		. '<tr><td align="center" style="padding:16px 24px;background:#173A2E;color:#9FB2A8;font-size:12px;line-height:1.6">'
		. 'Reçu via le formulaire de contact de <a href="' . esc_url( $site ) . '" style="color:#E2B36A;text-decoration:none">' . $e( wp_parse_url( $site, PHP_URL_HOST ) ) . '</a>'
		. '</td></tr>'

		. '</table></td></tr></table></body></html>';
}

/** Adresse du logo affiché en tête de l'e-mail (modifiable avec le filtre tg_contact_logo). */
function tg_contact_logo() {
	return apply_filters( 'tg_contact_logo', home_url( '/wp-content/uploads/2026/11/logo-ta-gravure-site-600.png' ) );
}

function tg_contact_mail_client( $d, $fichiers = array() ) {
	$r     = tg_contact_reglages();
	$types = tg_contact_types();
	$type  = $types[ $d['type'] ];

	// Version texte (pour les messageries qui n'affichent pas le HTML).
	$texte = "Bonjour {$d['nom']},\n\n"
		. "Merci pour votre message : nous avons bien reçu votre demande (« {$type} »).\n"
		. "Nous vous répondons sous {$r['delai']}.\n\n"
		. "Pour compléter votre demande, répondez simplement à cet e-mail.\n\n"
		. "Belle journée,\nCarlos – ta-gravure.art\n" . home_url( '/' ) . "\n";

	$html = tg_contact_html_confirmation( $d, $type, $fichiers, $r );

	tg_contact_envoyer(
		$d['email'],
		'Nous avons bien reçu votre demande – ta-gravure.art',
		$html,
		array( 'Reply-To: ta-gravure.art <' . $r['destinataire'] . '>', 'Content-Type: text/html; charset=UTF-8' ),
		array(),
		$texte
	);
}

/** E-mail de confirmation aux couleurs du site (tableaux et styles en ligne : lisible dans Gmail, Outlook, Apple Mail). */
function tg_contact_html_confirmation( $d, $type, $fichiers, $r ) {
	$e      = function ( $s ) {
		return esc_html( (string) $s );
	};
	$prenom = $d['nom']; // nom complet : les clients écrivent parfois le nom avant le prénom
	$lignes = array( 'Type de demande' => $type );
	if ( ! empty( $d['entreprise'] ) ) {
		$lignes['Entreprise'] = $d['entreprise'];
	}
	if ( ! empty( $d['quantite'] ) ) {
		$lignes['Quantité'] = (string) $d['quantite'];
	}
	if ( ! empty( $d['commande'] ) ) {
		$lignes['N° de commande'] = $d['commande'];
	}
	if ( ! empty( $d['produit'] ) ) {
		$lignes['Produit'] = get_the_title( $d['produit'] );
	}
	if ( $fichiers ) {
		$lignes['Fichiers joints'] = implode( ', ', wp_list_pluck( $fichiers, 'nom' ) );
	}
	$recap = '';
	foreach ( $lignes as $lib => $val ) {
		$recap .= '<tr><td style="padding:8px 0;border-bottom:1px solid #E3D6C1;color:#8C5E22;font-size:13px;font-weight:600;width:150px;vertical-align:top">' . $e( $lib ) . '</td>'
			. '<td style="padding:8px 0;border-bottom:1px solid #E3D6C1;color:#33403B;font-size:14px">' . $e( $val ) . '</td></tr>';
	}
	$message = nl2br( $e( mb_strlen( $d['message'] ) > 600 ? mb_substr( $d['message'], 0, 600 ) . '…' : $d['message'] ) );
	$site    = home_url( '/' );
	$logo    = tg_contact_logo();
	$annee   = gmdate( 'Y' );

	return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
		. '<title>Nous avons bien reçu votre demande</title></head>'
		. '<body style="margin:0;padding:0;background:#F6EFE3;font-family:\'Open Sans\',Arial,Helvetica,sans-serif;">'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#F6EFE3"><tr><td align="center" style="padding:28px 12px">'
		. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;background:#FFFBF4;border:1px solid #E3D6C1;border-radius:16px;overflow:hidden">'

		// En-tête : logo sur fond clair, liseré doré.
		. '<tr><td align="center" style="padding:26px 24px 20px;background:#FFFBF4;border-bottom:3px solid #E2B36A">'
		. '<a href="' . esc_url( $site ) . '" style="text-decoration:none"><img src="' . esc_url( $logo ) . '" width="220" alt="ta-gravure.art – l\'art de la gravure laser" style="display:block;width:220px;max-width:70%;height:auto;border:0;color:#173A2E;font-size:20px;font-weight:600"></a>'
		. '</td></tr>'

		// Bandeau vert, comme la page d'accueil.
		. '<tr><td style="padding:34px 36px 30px;background:#173A2E">'
		. '<p style="margin:0 0 10px;color:#E2B36A;font-size:12px;font-weight:700;letter-spacing:3px;text-transform:uppercase">Demande bien reçue</p>'
		. '<h1 style="margin:0 0 12px;color:#F6EFE3;font-size:26px;line-height:1.25;font-weight:600">Merci ' . $e( $prenom ) . ' !</h1>'
		. '<p style="margin:0;color:#E9E2D4;font-size:16px;line-height:1.6">Votre message nous est bien parvenu. Nous l\'étudions avec soin et vous répondons sous <strong style="color:#E2B36A">' . $e( $r['delai'] ) . '</strong>.</p>'
		. '</td></tr>'

		// Récapitulatif.
		. '<tr><td style="padding:30px 36px 8px">'
		. '<h2 style="margin:0 0 12px;color:#173A2E;font-size:18px;font-weight:600">Récapitulatif de votre demande</h2>'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $recap . '</table>'
		. '<p style="margin:18px 0 6px;color:#8C5E22;font-size:13px;font-weight:600">Votre message</p>'
		. '<div style="background:#F6EFE3;border-left:3px solid #E2B36A;border-radius:6px;padding:14px 16px;color:#33403B;font-size:14px;line-height:1.6">' . $message . '</div>'
		. '</td></tr>'

		// Étapes suivantes.
		. '<tr><td style="padding:26px 36px 6px">'
		. '<h2 style="margin:0 0 12px;color:#173A2E;font-size:18px;font-weight:600">La suite</h2>'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
		. tg_contact_html_etape( '1', 'Nous étudions votre demande et vos fichiers.' )
		. tg_contact_html_etape( '2', 'Nous vous envoyons une proposition, un aperçu ou un devis.' )
		. tg_contact_html_etape( '3', 'Après votre accord, nous gravons votre pièce à l\'atelier et l\'expédions.' )
		. '</table>'
		. '<p style="margin:18px 0 0;color:#33403B;font-size:14px;line-height:1.6">Un détail à ajouter, un fichier oublié ? <strong>Répondez simplement à cet e-mail.</strong></p>'
		. '</td></tr>'

		// Bouton.
		. '<tr><td align="center" style="padding:28px 36px 34px">'
		. '<a href="' . esc_url( $site ) . '" style="display:inline-block;background:#E2B36A;color:#173A2E;text-decoration:none;font-weight:700;font-size:15px;padding:13px 30px;border-radius:40px">Découvrir la boutique</a>'
		. '</td></tr>'

		// Pied.
		. '<tr><td align="center" style="padding:20px 24px;background:#173A2E;color:#E9E2D4;font-size:12px;line-height:1.7">'
		. '<strong style="color:#F6EFE3">ta-gravure.art</strong> · Cadeaux et décorations personnalisés en bois, gravés au laser<br>'
		. '<a href="mailto:' . esc_attr( $r['destinataire'] ) . '" style="color:#E2B36A;text-decoration:none">' . $e( $r['destinataire'] ) . '</a> · '
		. '<a href="' . esc_url( $site ) . '" style="color:#E2B36A;text-decoration:none">' . $e( wp_parse_url( $site, PHP_URL_HOST ) ) . '</a><br>'
		. '<span style="color:#9FB2A8">Vous recevez cet e-mail car vous nous avez écrit via le formulaire de contact. © ' . $annee . '</span>'
		. '</td></tr>'

		. '</table></td></tr></table></body></html>';
}

function tg_contact_html_etape( $n, $texte ) {
	return '<tr><td style="width:40px;padding:6px 0;vertical-align:top">'
		. '<span style="display:inline-block;width:28px;height:28px;line-height:28px;border-radius:14px;background:#173A2E;color:#E2B36A;font-weight:700;font-size:14px;text-align:center">' . esc_html( $n ) . '</span></td>'
		. '<td style="padding:10px 0 6px;color:#33403B;font-size:14px;line-height:1.5">' . esc_html( $texte ) . '</td></tr>';
}

/* -------------------------------------------------------------------------
 * Affichage du formulaire
 * ---------------------------------------------------------------------- */

add_shortcode( 'tg_contact', function () {
	$res     = $GLOBALS['tg_contact_resultat'];
	$v       = is_array( $res ) && isset( $res['valeurs'] ) ? $res['valeurs'] : array();
	$val     = function ( $k, $def = '' ) use ( $v ) {
		return isset( $v[ $k ] ) ? $v[ $k ] : $def;
	};
	$type_def = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'commande';
	$type     = $val( 'type', isset( tg_contact_types()[ $type_def ] ) ? $type_def : 'commande' );
	$r        = tg_contact_reglages();

	ob_start();
	tg_contact_styles();
	echo '<div id="tg-contact" class="tg-contact">';

	if ( isset( $_GET['demande'] ) && $_GET['demande'] === 'envoyee' ) {
		printf(
			'<div class="tg-ok" role="status"><strong>Merci, votre demande est bien envoyée.</strong><br>Un e-mail de confirmation vient de vous être adressé. Nous vous répondons sous %s.</div>',
			esc_html( $r['delai'] )
		);
	}
	if ( is_array( $res ) && ! empty( $res['erreurs'] ) ) {
		echo '<div class="tg-err" role="alert"><strong>Merci de corriger :</strong><ul>';
		foreach ( $res['erreurs'] as $e ) {
			echo '<li>' . esc_html( $e ) . '</li>';
		}
		echo '</ul></div>';
	}

	$produits = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'title', 'order' => 'ASC' ) );
	?>
	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( remove_query_arg( 'demande' ) ); ?>#tg-contact" novalidate>
		<input type="hidden" name="tg_contact_envoi" value="1">
		<input type="hidden" name="tg_t" value="<?php echo esc_attr( tg_contact_jeton() ); ?>">
		<div class="tg-piege" aria-hidden="true">
			<label for="tg_hp_zx">Ne pas remplir</label><input type="text" id="tg_hp_zx" name="tg_hp_zx" value="" tabindex="-1" autocomplete="new-password" data-lpignore="true" data-1p-ignore data-form-type="other">
		</div>

		<fieldset class="tg-types">
			<legend>Votre demande concerne <span class="tg-req">*</span></legend>
			<?php foreach ( tg_contact_types() as $k => $lib ) : ?>
				<label class="tg-type">
					<input type="radio" name="tg_type" value="<?php echo esc_attr( $k ); ?>" <?php checked( $type, $k ); ?> required>
					<span><?php echo esc_html( $lib ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>

		<div class="tg-grille">
			<p><label for="tg_nom">Nom et prénom <span class="tg-req">*</span></label>
				<input type="text" id="tg_nom" name="tg_nom" maxlength="100" autocomplete="name" required value="<?php echo esc_attr( $val( 'nom' ) ); ?>"></p>
			<p><label for="tg_email">E-mail <span class="tg-req">*</span></label>
				<input type="email" id="tg_email" name="tg_email" autocomplete="email" required value="<?php echo esc_attr( $val( 'email' ) ); ?>"></p>
			<p><label for="tg_tel">Téléphone <span class="tg-opt">(facultatif)</span></label>
				<input type="tel" id="tg_tel" name="tg_tel" autocomplete="tel" maxlength="30" value="<?php echo esc_attr( $val( 'tel' ) ); ?>"></p>
			<p><label for="tg_produit">Produit concerné <span class="tg-opt">(facultatif)</span></label>
				<select id="tg_produit" name="tg_produit">
					<option value="0">— Pas encore choisi / autre —</option>
					<?php foreach ( $produits as $pr ) : ?>
						<option value="<?php echo (int) $pr->ID; ?>" <?php selected( (int) $val( 'produit', 0 ), $pr->ID ); ?>><?php echo esc_html( $pr->post_title ); ?></option>
					<?php endforeach; ?>
				</select></p>
			<p class="tg-si tg-si-devis"><label for="tg_entreprise">Entreprise <span class="tg-req">*</span></label>
				<input type="text" id="tg_entreprise" name="tg_entreprise" maxlength="120" autocomplete="organization" value="<?php echo esc_attr( $val( 'entreprise' ) ); ?>"></p>
			<p class="tg-si tg-si-devis"><label for="tg_quantite">Quantité souhaitée <span class="tg-opt">(environ)</span></label>
				<input type="number" id="tg_quantite" name="tg_quantite" min="1" max="100000" value="<?php echo $val( 'quantite' ) ? (int) $val( 'quantite' ) : ''; ?>"></p>
			<p class="tg-si tg-si-suivi"><label for="tg_commande">N° de commande</label>
				<input type="text" id="tg_commande" name="tg_commande" maxlength="30" value="<?php echo esc_attr( $val( 'commande' ) ); ?>"></p>
		</div>

		<p><label for="tg_message">Votre message <span class="tg-req">*</span></label>
			<textarea id="tg_message" name="tg_message" rows="7" maxlength="5000" required placeholder="Décrivez votre projet : texte ou prénoms à graver, dimensions, délai souhaité…"><?php echo esc_textarea( $val( 'message' ) ); ?></textarea></p>

		<div class="tg-fichiers">
			<label for="tg_fichiers">Fichiers <span class="tg-opt">(facultatif)</span></label>
			<input type="file" id="tg_fichiers" name="tg_fichiers[]" multiple
				accept=".jpg,.jpeg,.png,.webp,.pdf,.docx,.xlsx,.txt,.csv,image/jpeg,image/png,image/webp,application/pdf">
			<small>Photo, logo ou liste de prénoms : jusqu'à 5 fichiers de 10 Mo.<br>
				Formats acceptés : JPEG, PNG, WebP, PDF, Word (.docx), Excel (.xlsx), texte (.txt, .csv).
				Les fichiers avec macros (.docm, .xlsm, .doc, .xls) ne sont pas acceptés.</small>
			<ul class="tg-liste" aria-live="polite"></ul>
		</div>

		<p class="tg-consent"><label>
			<input type="checkbox" name="tg_consent" value="1" required <?php checked( $val( 'consent' ) ); ?>>
			J'accepte que mes données et fichiers soient utilisés pour traiter ma demande.
			<?php if ( get_privacy_policy_url() ) : ?>
				<a href="<?php echo esc_url( get_privacy_policy_url() ); ?>" target="_blank" rel="noopener">Politique de confidentialité</a>
			<?php endif; ?>
			<span class="tg-req">*</span></label></p>

		<p><button type="submit" class="tg-envoyer">Envoyer ma demande</button></p>
		<p class="tg-note">Réponse sous <?php echo esc_html( $r['delai'] ); ?>. Les champs marqués <span class="tg-req">*</span> sont obligatoires.</p>
	</form>
	<script>
	(function () {
		var f = document.querySelector('#tg-contact form');
		if (!f) return;
		function maj() {
			var t = (f.querySelector('input[name=tg_type]:checked') || {}).value;
			f.querySelectorAll('.tg-si').forEach(function (el) {
				el.style.display = el.classList.contains('tg-si-' + t) ? '' : 'none';
			});
			var e = f.querySelector('#tg_entreprise');
			if (e) e.required = (t === 'devis');
		}
		f.querySelectorAll('input[name=tg_type]').forEach(function (r) { r.addEventListener('change', maj); });
		maj();
		var ok = ['jpg','jpeg','png','webp','pdf','docx','xlsx','txt','csv'];
		var inp = f.querySelector('#tg_fichiers'), liste = f.querySelector('.tg-liste');
		inp.addEventListener('change', function () {
			liste.innerHTML = '';
			var pb = [];
			if (inp.files.length > 5) pb.push('5 fichiers au maximum.');
			Array.prototype.forEach.call(inp.files, function (fi) {
				var ext = fi.name.split('.').pop().toLowerCase(), li = document.createElement('li');
				li.textContent = fi.name + ' (' + Math.round(fi.size / 1024) + ' Ko)';
				if (ok.indexOf(ext) < 0) { li.className = 'tg-ko'; pb.push(fi.name + ' : format non accepté.'); }
				else if (fi.size > 10485760) { li.className = 'tg-ko'; pb.push(fi.name + ' : plus de 10 Mo.'); }
				liste.appendChild(li);
			});
			inp.setCustomValidity(pb.join(' '));
			if (pb.length) inp.reportValidity();
		});
		f.addEventListener('submit', function (ev) {
			if (!f.checkValidity()) { ev.preventDefault(); f.reportValidity(); return; }
			var b = f.querySelector('.tg-envoyer'); b.disabled = true; b.textContent = 'Envoi en cours…';
		});
	})();
	</script>
	<?php
	echo '</div>';
	return ob_get_clean();
} );

function tg_contact_styles() {
	static $fait = false;
	if ( $fait ) {
		return;
	}
	$fait = true;
	?>
	<style>
	.tg-contact{--v:#173A2E;--b:#8C5E22;--o:#E2B36A;--c:#F6EFE3;--i:#FFFBF4;--t:#33403B;--bd:#E3D6C1;--bf:#C9B699;
		background:var(--i);border:1px solid var(--bd);border-radius:18px;padding:32px;max-width:820px;box-shadow:0 10px 30px rgba(23,58,46,.10);color:var(--t)}
	.tg-contact label,.tg-contact legend{display:block;font-weight:600;color:var(--v);margin-bottom:6px;font-size:15px}
	.tg-contact .tg-req{color:#B3261E}.tg-contact .tg-opt{font-weight:400;color:#777}
	.tg-contact input[type=text],.tg-contact input[type=email],.tg-contact input[type=tel],.tg-contact input[type=number],.tg-contact select,.tg-contact textarea{
		width:100%;background:#fff;border:1px solid var(--bf);border-radius:8px;padding:11px 14px;font-size:16px;color:var(--t);box-sizing:border-box}
	.tg-contact input:focus,.tg-contact select:focus,.tg-contact textarea:focus{border-color:var(--v);outline:2px solid rgba(226,179,106,.45)}
	.tg-contact p{margin:0 0 18px;padding:0}
	.tg-grille{display:grid;grid-template-columns:1fr 1fr;gap:0 20px}
	.tg-types{border:0;padding:0;margin:0 0 22px;display:flex;flex-wrap:wrap;gap:10px}
	.tg-types legend{width:100%;margin-bottom:10px}
	.tg-type{margin:0!important;cursor:pointer}
	.tg-type input{position:absolute;opacity:0}
	.tg-type span{display:inline-block;padding:10px 18px;border:1px solid var(--bf);border-radius:40px;background:#fff;color:var(--v);font-weight:600;font-size:14px;transition:.2s}
	.tg-type input:checked+span{background:var(--v);border-color:var(--v);color:var(--c)}
	.tg-type input:focus-visible+span{outline:2px solid var(--o);outline-offset:2px}
	.tg-fichiers{background:var(--c);border:1px dashed var(--bf);border-radius:12px;padding:18px;margin:0 0 18px}
	.tg-fichiers input[type=file]{font-size:15px;margin:4px 0 8px}
	.tg-fichiers small{display:block;color:#5d625f;line-height:1.5}
	.tg-liste{margin:8px 0 0;padding-left:18px}.tg-liste .tg-ko{color:#B3261E}
	.tg-consent label{font-weight:400;color:var(--t);font-size:14px}.tg-consent input{margin-right:6px}
	.tg-envoyer{background:var(--o);color:var(--v);border:0;border-radius:40px;padding:14px 34px;font-size:17px;font-weight:600;cursor:pointer;transition:.2s}
	.tg-envoyer:hover{background:var(--v);color:var(--c)}.tg-envoyer:disabled{opacity:.6;cursor:wait}
	.tg-note{font-size:13px;color:#777}
	.tg-ok,.tg-err{border-radius:10px;padding:16px 18px;margin:0 0 22px;border:1px solid var(--bd);background:#fff}
	.tg-ok{border-left:4px solid #2E7D4F}.tg-err{border-left:4px solid #B3261E}.tg-err ul{margin:6px 0 0 18px}
	.tg-piege{position:absolute!important;left:-9999px!important;height:1px;overflow:hidden}
	@media (max-width:700px){.tg-contact{padding:20px}.tg-grille{grid-template-columns:1fr}}
	</style>
	<?php
}
