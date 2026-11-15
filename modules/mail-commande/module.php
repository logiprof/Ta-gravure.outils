<?php
/**
 * Module « E-mails et factures » (anciennement l'extension ta-gravure – E-mails et factures 2.0.1).
 * Met aux couleurs du site tous les e-mails WooCommerce (commande reçue, en cours, terminée, facture,
 * remboursement, note, compte, mot de passe), l'e-mail « Nouvelle commande » de la boutique et les
 * factures PDF (PDF Invoices & Packing Slips). Désactiver le module rend les présentations d'origine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TG_MAIL_COMMANDE_DIR', plugin_dir_path( __FILE__ ) );

// Compatibilité HPOS déclarée par le noyau de Ta-gravure outils.

/** Logo affiché en tête (réglage du module, modifiable aussi avec le filtre tg_mail_commande_logo). */
function tg_mail_commande_logo() {
	return apply_filters( 'tg_mail_commande_logo', TGO_Core::url_absolue( tgo_reglage( 'mail-commande', 'logo' ) ) );
}

/* Gabarit HTML de l'e-mail « Nouvelle commande » (la version texte de WooCommerce reste inchangée). */
add_filter( 'wc_get_template', function ( $template, $template_name ) {
	$gabarits = array( 'emails/admin-new-order.php', 'emails/email-header.php', 'emails/email-footer.php' );
	if ( in_array( $template_name, $gabarits, true ) ) {
		$perso = TG_MAIL_COMMANDE_DIR . 'templates/' . basename( $template_name );
		if ( file_exists( $perso ) ) {
			return $perso;
		}
	}
	return $template;
}, 20, 2 );

/* Styles de base de WooCommerce allégés pour cet e-mail : notre gabarit porte ses propres styles. */
add_filter( 'woocommerce_email_styles', function ( $css, $email = null ) {
	if ( $email && isset( $email->id ) && $email->id === 'new_order' ) {
		return 'body{margin:0;padding:0;background:#F6EFE3}img{border:0;outline:none}a{text-decoration:none}';
	}
	return $css . tg_mail_css();
}, 20, 2 );

/* Objet de l'e-mail : numéro, client et montant visibles dans la boîte de réception. */
add_filter( 'woocommerce_email_subject_new_order', function ( $sujet, $order ) {
	if ( ! $order instanceof WC_Order ) {
		return $sujet;
	}
	$nom = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	return sprintf(
		ltrim( tgo_reglage( 'mail-commande', 'prefixe_sujet' ) . ' ' ) . 'Nouvelle commande n° %s – %s – %s',
		$order->get_order_number(),
		$nom ?: $order->get_billing_email(),
		str_replace( "\xC2\xA0", ' ', html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' ) )
	);
}, 20, 2 );

/* Répondre à l'e-mail écrit directement au client. */
add_filter( 'woocommerce_email_headers', function ( $entetes, $email_id, $order ) {
	if ( $email_id === 'new_order' && $order instanceof WC_Order && is_email( $order->get_billing_email() ) ) {
		$nom      = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$entetes .= 'Reply-To: ' . ( $nom ? $nom . ' ' : '' ) . '<' . $order->get_billing_email() . ">\r\n";
	}
	return $entetes;
}, 20, 3 );

/**
 * Textes de personnalisation d'un article : toutes les données ajoutées à l'article
 * (prénoms, dates, choix…) sauf les données techniques cachées.
 */
function tg_mail_commande_perso( $item ) {
	$res = array();
	foreach ( $item->get_formatted_meta_data( '_' ) as $meta ) {
		$cle = wp_strip_all_tags( $meta->display_key );
		$val = trim( wp_strip_all_tags( $meta->display_value ) );
		if ( $cle === '' || $val === '' ) {
			continue;
		}
		$res[] = array( 'cle' => $cle, 'val' => $val );
	}
	return $res;
}

/* =========================================================================
 * E-mails envoyés aux clients : en-tête et pied communs (templates/email-header.php
 * et templates/email-footer.php), mêmes couleurs que l'e-mail du formulaire de contact.
 * ====================================================================== */

/** Adresse de contact affichée en pied d'e-mail. */
function tg_mail_adresse_contact() {
	if ( function_exists( 'tg_contact_reglages' ) ) {
		$r = tg_contact_reglages();
		if ( ! empty( $r['destinataire'] ) && is_email( $r['destinataire'] ) ) {
			return $r['destinataire'];
		}
	}
	$de = get_option( 'woocommerce_email_from_address' );
	return is_email( $de ) ? $de : get_option( 'admin_email' );
}

/** Petite étiquette dorée au-dessus du titre, selon l'e-mail. */
function tg_mail_etiquette( $id ) {
	$etiquettes = array(
		'customer_on_hold_order'    => 'Commande reçue',
		'customer_processing_order' => 'Commande confirmée',
		'customer_completed_order'  => 'Commande terminée',
		'customer_refunded_order'   => 'Remboursement',
		'customer_invoice'          => 'Votre commande',
		'customer_note'             => 'Message de l’atelier',
		'customer_reset_password'   => 'Mot de passe',
		'customer_new_account'      => 'Bienvenue',
		'cancelled_order'           => 'Commande annulée',
		'failed_order'              => 'Paiement échoué',
	);
	$res = isset( $etiquettes[ $id ] ) ? $etiquettes[ $id ] : 'ta-gravure.art';
	return apply_filters( 'tg_mail_etiquette', $res, $id );
}

/** Ligne sous le titre : numéro et date de la commande. */
function tg_mail_intro( $email ) {
	if ( ! $email || empty( $email->object ) || ! $email->object instanceof WC_Order ) {
		return '';
	}
	$order = $email->object;
	$date  = $order->get_date_created();
	$txt   = 'Commande <strong style="color:#E2B36A">n° ' . esc_html( $order->get_order_number() ) . '</strong>';
	if ( $date ) {
		$txt .= ' du ' . esc_html( wc_format_datetime( $date, 'j F Y' ) );
	}
	return $txt;
}

/** Étapes « La suite » affichées sous la commande. */
function tg_mail_etapes( $id ) {
	$etapes = array();
	if ( in_array( $id, array( 'customer_processing_order', 'customer_on_hold_order' ), true ) ) {
		$etapes = array(
			$id === 'customer_on_hold_order' ? 'Dès réception de votre paiement, nous lançons votre commande.' : 'Nous vérifions vos textes à graver.',
			'Nous gravons vos pièces à l’atelier, de manière artisanale.',
			'Nous les expédions et vous prévenons par e-mail.',
		);
	}
	return apply_filters( 'tg_mail_etapes', $etapes, $id );
}

/* Titre personnalisé avec le prénom du client. */
foreach ( array( 'customer_processing_order', 'customer_on_hold_order', 'customer_completed_order' ) as $tg_id_titre ) {
	add_filter( 'woocommerce_email_heading_' . $tg_id_titre, function ( $titre, $order ) use ( $tg_id_titre ) {
		if ( ! $order instanceof WC_Order ) {
			return $titre;
		}
		$prenom = trim( $order->get_billing_first_name() );
		$prenom = $prenom ? ' ' . mb_convert_case( $prenom, MB_CASE_TITLE, 'UTF-8' ) : '';
		if ( $tg_id_titre === 'customer_completed_order' ) {
			return 'Votre commande est prête' . ( $prenom ? ',' . $prenom : '' ) . ' !';
		}
		return 'Merci' . $prenom . ' !';
	}, 20, 2 );
}

/** Styles des contenus WooCommerce (tableau de commande, adresses…), intégrés en ligne à l'envoi. */
function tg_mail_css() {
	return '
body,#tg_fond{background:#F6EFE3 !important}
#body_content_inner,#body_content_inner p,#body_content_inner td,#body_content_inner th,#body_content_inner li{font-family:"Open Sans",Arial,Helvetica,sans-serif !important;color:#33403B !important;font-size:15px;line-height:1.6}
#body_content_inner p{margin:0 0 14px}
#body_content_inner h2,#body_content_inner h3{font-family:"Open Sans",Arial,Helvetica,sans-serif !important;color:#173A2E !important;font-size:18px !important;font-weight:600 !important;margin:24px 0 12px !important;text-align:left !important;line-height:1.3 !important}
#body_content_inner h2 a{color:#173A2E !important}
#body_content_inner a{color:#8C5E22 !important;font-weight:600}
#body_content_inner .td,#body_content_inner table.td{border:1px solid #E3D6C1 !important;border-collapse:collapse !important;color:#33403B !important;background:#FFFBF4 !important}
#body_content_inner table.td th,#body_content_inner table.td td{border-color:#E3D6C1 !important;padding:10px 12px !important}
#body_content_inner table.td thead th{background:#173A2E !important;color:#F6EFE3 !important;font-size:13px !important;font-weight:700 !important;border-color:#173A2E !important}
#body_content_inner table.td tfoot th{color:#8C5E22 !important;font-size:14px !important}
#body_content_inner table.td tfoot tr:last-child th,#body_content_inner table.td tfoot tr:last-child td{background:#F6EFE3 !important;color:#173A2E !important;font-size:16px !important;font-weight:700 !important;border-top:2px solid #E2B36A !important}
#body_content_inner .wc-item-meta,#body_content_inner .wc-item-meta li,#body_content_inner .wc-item-meta p,#body_content_inner .email-order-item-meta,#body_content_inner .email-order-item-meta *{color:#8C5E22 !important;font-size:13px !important}
#body_content_inner .wc-item-meta-label{color:#173A2E !important}
#body_content_inner address,#body_content_inner .address{background:#F6EFE3 !important;border:0 !important;border-left:3px solid #E2B36A !important;border-radius:6px !important;padding:14px 16px !important;color:#33403B !important;font-style:normal !important;line-height:1.6 !important}
#body_content_inner .email-additional-content,#body_content_inner .email-additional-content p{color:#33403B !important;text-align:left !important}
#body_content_inner .email-order-detail-heading span,#body_content_inner .email-order-detail-heading{color:#173A2E !important}
#body_content_inner img{border:0;border-radius:6px}
#body_content_inner .order-totals-total th,#body_content_inner .order-totals-total td{color:#173A2E !important;font-weight:700 !important}
#body_content_inner .button,#body_content_inner a.button{background:#E2B36A !important;color:#173A2E !important;border-radius:40px !important;padding:12px 26px !important;text-decoration:none !important;display:inline-block}
';
}

/* =========================================================================
 * Factures et bons de livraison PDF (extension « PDF Invoices & Packing Slips »,
 * modèle « Simple ») : couleurs du site et nouveau logo.
 * ====================================================================== */

/** Logo des PDF : fichier local de préférence (plus rapide et plus fiable pour le moteur PDF). */
function tg_mail_logo_pdf() {
	$dossier = wp_upload_dir();
	$url     = tg_mail_commande_logo();
	$local   = '';
	// Le logo est dans la médiathèque : on passe le fichier du disque au moteur PDF.
	if ( strpos( $url, $dossier['baseurl'] ) === 0 ) {
		$local = $dossier['basedir'] . substr( $url, strlen( $dossier['baseurl'] ) );
	} elseif ( strpos( set_url_scheme( $url, 'https' ), set_url_scheme( $dossier['baseurl'], 'https' ) ) === 0 ) {
		$local = $dossier['basedir'] . substr( set_url_scheme( $url, 'https' ), strlen( set_url_scheme( $dossier['baseurl'], 'https' ) ) );
	}
	return apply_filters( 'tg_mail_logo_pdf', ( $local && file_exists( $local ) ) ? $local : $url );
}

add_filter( 'wpo_wcpdf_header_logo_img_element', function ( $img, $attachment = null, $document = null ) {
	return '<img src="' . esc_attr( tg_mail_logo_pdf() ) . '" alt="ta-gravure.art" style="width:62mm;height:auto">';
}, 20, 3 );

add_action( 'wpo_wcpdf_before_document', function ( $type = '', $order = null ) {
	echo '<div class="tg-bandeau"></div>';
}, 10, 2 );

add_action( 'wpo_wcpdf_custom_styles', function ( $type = '', $document = null ) {
	?>
	body { color: #33403B; }
	.tg-bandeau { height: 3mm; background: #173A2E; border-bottom: 1mm solid #E2B36A; margin: 0 0 8mm 0; }
	table.head { margin-bottom: 10mm; }
	.header img { max-width: 62mm; max-height: 28mm; }
	.shop-info { color: #33403B; }
	.shop-info .shop-name h3, .shop-name h3 { color: #173A2E; font-size: 12pt; }
	h1, .document-type-label { color: #173A2E; font-size: 20pt; letter-spacing: 1pt; padding-bottom: 2mm; border-bottom: 0.8mm solid #E2B36A; display: inline-block; margin-bottom: 6mm; }
	table.order-data-addresses td.address { color: #33403B; }
	table.order-data-addresses h3 { color: #8C5E22; font-size: 9pt; text-transform: uppercase; letter-spacing: 1pt; }
	table.order-data-addresses td.order-data { background: #F6EFE3; padding: 3mm 4mm; border-left: 0.8mm solid #E2B36A; }
	table.order-data-addresses td.order-data th { color: #8C5E22; font-weight: bold; }
	table.order-details { margin-top: 4mm; }
	.order-details thead th { color: #F6EFE3; background-color: #173A2E; border-color: #173A2E; padding: 2.5mm 2mm; }
	.order-details tbody tr td { border-bottom: 0.3mm solid #E3D6C1; padding: 2.5mm 2mm; }
	.order-details tbody tr:nth-child(even) td { background: #FFFBF4; }
	.order-details .item-meta, .order-details .item-meta *, .order-details .wc-item-meta, .order-details .wc-item-meta * { color: #8C5E22; }
	.order-details .item-meta .label, .order-details dl.meta dt, .order-details .wc-item-meta-label { color: #173A2E; }
	table.totals th, table.totals td { border-top: 0.3mm solid #E3D6C1; border-bottom: 0; color: #33403B; }
	table.totals tr.order_total th, table.totals tr.order_total td { border-top: 0.8mm solid #E2B36A; border-bottom: 0.8mm solid #E2B36A; background: #F6EFE3; color: #173A2E; font-size: 11pt; }
	.notes, .customer-notes { background: #F6EFE3; padding: 3mm 4mm; border-left: 0.8mm solid #E2B36A; color: #33403B; }
	#footer { border-top: 0.6mm solid #E2B36A; color: #173A2E; }
	#footer, #footer * { color: #173A2E; }
	<?php
}, 20, 2 );
