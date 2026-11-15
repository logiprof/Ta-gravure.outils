<?php
/**
 * E-mail « Nouvelle commande » reçu par la boutique, aux couleurs de ta-gravure.art.
 *
 * Variables fournies par WooCommerce : $order, $email_heading, $additional_content, $sent_to_admin, $plain_text, $email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$e     = function ( $s ) {
	return esc_html( (string) $s );
};
$nom   = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
$email_client = $order->get_billing_email();
$tel   = $order->get_billing_phone();
$cree  = $order->get_date_created();
$site  = home_url( '/' );
$sujet = rawurlencode( 'Votre commande n° ' . $order->get_order_number() . ' – ta-gravure.art' );

/* Articles et textes à graver */
$articles = array();
$a_graver = array();
foreach ( $order->get_items() as $item ) {
	$produit = $item->get_product();
	$img     = '';
	if ( $produit && $produit->get_image_id() ) {
		$img = wp_get_attachment_image_url( $produit->get_image_id(), 'thumbnail' );
	}
	$perso      = tg_mail_commande_perso( $item );
	$articles[] = array(
		'nom'   => $item->get_name(),
		'qte'   => $item->get_quantity(),
		'total' => $order->get_formatted_line_subtotal( $item ),
		'img'   => $img,
		'perso' => $perso,
		'sku'   => $produit ? $produit->get_sku() : '',
	);
	if ( $perso ) {
		$a_graver[] = array( 'nom' => $item->get_name(), 'qte' => $item->get_quantity(), 'perso' => $perso );
	}
}

/* Données ajoutées par d'autres extensions (champs de commande personnalisés…), sans doublon des adresses. */
$extra = '';
ob_start();
do_action( 'woocommerce_email_order_meta', $order, true, false, $email );
$mailer = function_exists( 'WC' ) ? WC()->mailer() : null;
if ( $mailer ) {
	$p1 = has_action( 'woocommerce_email_customer_details', array( $mailer, 'customer_details' ) );
	$p2 = has_action( 'woocommerce_email_customer_details', array( $mailer, 'email_addresses' ) );
	if ( $p1 !== false ) {
		remove_action( 'woocommerce_email_customer_details', array( $mailer, 'customer_details' ), $p1 );
	}
	if ( $p2 !== false ) {
		remove_action( 'woocommerce_email_customer_details', array( $mailer, 'email_addresses' ), $p2 );
	}
	do_action( 'woocommerce_email_customer_details', $order, true, false, $email );
	if ( $p1 !== false ) {
		add_action( 'woocommerce_email_customer_details', array( $mailer, 'customer_details' ), $p1, 4 );
	}
	if ( $p2 !== false ) {
		add_action( 'woocommerce_email_customer_details', array( $mailer, 'email_addresses' ), $p2, 4 );
	}
}
$extra = trim( ob_get_clean() );

$h2   = 'margin:0 0 10px;color:#173A2E;font-size:17px;font-weight:600;font-family:Arial,Helvetica,sans-serif';
$lab  = 'padding:8px 0;border-bottom:1px solid #E3D6C1;color:#8C5E22;font-size:13px;font-weight:600;width:130px;vertical-align:top';
$val  = 'padding:8px 0;border-bottom:1px solid #E3D6C1;color:#33403B;font-size:14px';
$lien = 'color:#8C5E22;font-weight:600;text-decoration:none';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $e( 'Nouvelle commande n° ' . $order->get_order_number() ); ?></title>
</head>
<body style="margin:0;padding:0;background:#F6EFE3;font-family:'Open Sans',Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#F6EFE3"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;background:#FFFBF4;border:1px solid #E3D6C1;border-radius:16px;overflow:hidden">

	<!-- En-tête -->
	<tr><td style="padding:18px 32px;background:#FFFBF4;border-bottom:3px solid #E2B36A">
		<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>
			<td><img src="<?php echo esc_url( tg_mail_commande_logo() ); ?>" width="150" alt="ta-gravure.art" style="display:block;width:150px;height:auto;border:0;color:#173A2E;font-weight:600"></td>
			<td align="right" style="color:#8C5E22;font-size:12px"><?php echo $cree ? $e( wc_format_datetime( $cree, 'j.m.Y · H:i' ) ) : ''; ?></td>
		</tr></table>
	</td></tr>

	<!-- Bandeau -->
	<tr><td style="padding:28px 32px 26px;background:#173A2E">
		<p style="margin:0 0 12px"><span style="display:inline-block;background:#E2B36A;color:#173A2E;font-size:12px;font-weight:700;padding:5px 12px;border-radius:20px">Nouvelle commande</span>
		<?php if ( $a_graver ) : ?>
			<span style="display:inline-block;background:#F6EFE3;color:#8C5E22;font-size:12px;font-weight:700;padding:5px 12px;border-radius:20px;margin-left:4px">Personnalisation à graver</span>
		<?php endif; ?></p>
		<h1 style="margin:0 0 6px;color:#F6EFE3;font-size:26px;line-height:1.25;font-weight:600;font-family:Arial,Helvetica,sans-serif">Commande n° <?php echo $e( $order->get_order_number() ); ?> · <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></h1>
		<p style="margin:0;color:#E9E2D4;font-size:14px;line-height:1.6">
			<?php echo $e( $nom ?: $email_client ); ?>
			<?php if ( $order->get_payment_method_title() ) : ?> · <?php echo $e( $order->get_payment_method_title() ); ?><?php endif; ?>
			<?php if ( $order->get_shipping_method() ) : ?> · <?php echo $e( $order->get_shipping_method() ); ?><?php endif; ?>
		</p>
	</td></tr>

	<?php if ( $a_graver ) : ?>
	<!-- À graver -->
	<tr><td style="padding:26px 32px 0">
		<h2 style="<?php echo esc_attr( $h2 ); ?>">À graver</h2>
		<?php foreach ( $a_graver as $g ) : ?>
			<div style="background:#F6EFE3;border:1px solid #E3D6C1;border-left:4px solid #E2B36A;border-radius:10px;padding:14px 18px;margin:0 0 12px">
				<p style="margin:0 0 8px;color:#173A2E;font-size:14px;font-weight:700"><?php echo $e( $g['nom'] ); ?> <span style="color:#8C5E22">× <?php echo (int) $g['qte']; ?></span></p>
				<?php
				// Regroupe « Pièce 1 – Message », « Pièce 1 – Prénom »… sous un même intertitre « Pièce 1 ».
				$piece_courante = null;
				foreach ( $g['perso'] as $p ) :
					$cle   = $p['cle'];
					$piece = null;
					if ( preg_match( '/^Pièce (\d+)(?:\s+–\s+(.+))?$/u', $cle, $m ) ) {
						$piece = (int) $m[1];
						$cle   = isset( $m[2] ) ? $m[2] : '';
					}
					if ( $piece !== null && $piece !== $piece_courante ) :
						$piece_courante = $piece;
						?>
						<p style="margin:12px 0 6px;padding-top:10px;border-top:1px dashed #E3D6C1;color:#173A2E;font-size:13px;font-weight:700">Pièce <?php echo (int) $piece; ?></p>
					<?php endif; ?>
					<?php if ( $cle !== '' ) : ?>
						<p style="margin:0 0 4px;color:#8C5E22;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:1px"><?php echo $e( $cle ); ?></p>
					<?php endif; ?>
					<?php if ( $p['val'] === 'Sans gravure' ) : ?>
						<p style="margin:0 0 10px;color:#6b706d;font-size:15px;font-style:italic">Sans gravure</p>
					<?php else : ?>
						<p style="margin:0 0 10px;color:#173A2E;font-size:18px;line-height:1.5;font-weight:600;font-family:Georgia,'Times New Roman',serif"><?php echo nl2br( $e( $p['val'] ) ); ?></p>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	</td></tr>
	<?php endif; ?>

	<!-- Articles -->
	<tr><td style="padding:24px 32px 0">
		<h2 style="<?php echo esc_attr( $h2 ); ?>">Articles</h2>
		<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
			<?php foreach ( $articles as $a ) : ?>
			<tr>
				<td style="width:64px;padding:10px 0;border-bottom:1px solid #E3D6C1;vertical-align:top">
					<?php if ( $a['img'] ) : ?>
						<img src="<?php echo esc_url( $a['img'] ); ?>" width="52" height="52" alt="" style="display:block;width:52px;height:52px;border-radius:8px;object-fit:cover;border:0">
					<?php else : ?>
						<div style="width:52px;height:52px;border-radius:8px;background:#EFE4D2"></div>
					<?php endif; ?>
				</td>
				<td style="padding:10px 10px;border-bottom:1px solid #E3D6C1;vertical-align:top;color:#33403B;font-size:14px;line-height:1.45">
					<strong style="color:#173A2E"><?php echo $e( $a['nom'] ); ?></strong>
					<?php if ( $a['sku'] ) : ?><br><span style="color:#8a8f8c;font-size:12px">Réf. <?php echo $e( $a['sku'] ); ?></span><?php endif; ?>
					<?php if ( $a['perso'] ) : ?><br><span style="color:#8C5E22;font-size:12px">Personnalisé · voir « À graver »</span><?php endif; ?>
				</td>
				<td align="center" style="padding:10px 6px;border-bottom:1px solid #E3D6C1;vertical-align:top;color:#33403B;font-size:14px;white-space:nowrap">× <?php echo (int) $a['qte']; ?></td>
				<td align="right" style="padding:10px 0;border-bottom:1px solid #E3D6C1;vertical-align:top;color:#173A2E;font-size:14px;font-weight:600;white-space:nowrap"><?php echo wp_kses_post( $a['total'] ); ?></td>
			</tr>
			<?php endforeach; ?>
		</table>
		<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:6px">
			<?php
			$totaux = $order->get_order_item_totals();
			$n      = count( $totaux );
			$i      = 0;
			foreach ( $totaux as $t ) :
				$i++;
				$dernier = $i === $n;
				?>
				<tr>
					<td style="padding:6px 0;color:<?php echo $dernier ? '#173A2E' : '#6b706d'; ?>;font-size:<?php echo $dernier ? '16px' : '13px'; ?>;font-weight:<?php echo $dernier ? '700' : '400'; ?>"><?php echo wp_kses_post( rtrim( $t['label'], ' :' ) ); ?></td>
					<td align="right" style="padding:6px 0;color:<?php echo $dernier ? '#8C5E22' : '#33403B'; ?>;font-size:<?php echo $dernier ? '18px' : '14px'; ?>;font-weight:<?php echo $dernier ? '700' : '400'; ?>"><?php echo wp_kses_post( $t['value'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
	</td></tr>

	<?php if ( $order->get_customer_note() ) : ?>
	<!-- Note du client -->
	<tr><td style="padding:22px 32px 0">
		<h2 style="<?php echo esc_attr( $h2 ); ?>">Note du client</h2>
		<div style="background:#F6EFE3;border-left:3px solid #E2B36A;border-radius:6px;padding:14px 16px;color:#33403B;font-size:15px;line-height:1.6"><?php echo nl2br( $e( $order->get_customer_note() ) ); ?></div>
	</td></tr>
	<?php endif; ?>

	<!-- Client -->
	<tr><td style="padding:24px 32px 0">
		<h2 style="<?php echo esc_attr( $h2 ); ?>">Le client</h2>
		<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
			<tr><td style="<?php echo esc_attr( $lab ); ?>">Nom</td><td style="<?php echo esc_attr( $val ); ?>"><?php echo $e( $nom ); ?><?php echo $order->get_billing_company() ? ' · ' . $e( $order->get_billing_company() ) : ''; ?></td></tr>
			<?php if ( $email_client ) : ?>
			<tr><td style="<?php echo esc_attr( $lab ); ?>">E-mail</td><td style="<?php echo esc_attr( $val ); ?>"><a href="mailto:<?php echo esc_attr( $email_client ); ?>?subject=<?php echo esc_attr( $sujet ); ?>" style="<?php echo esc_attr( $lien ); ?>"><?php echo $e( $email_client ); ?></a></td></tr>
			<?php endif; ?>
			<?php if ( $tel ) : ?>
			<tr><td style="<?php echo esc_attr( $lab ); ?>">Téléphone</td><td style="<?php echo esc_attr( $val ); ?>"><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $tel ) ); ?>" style="<?php echo esc_attr( $lien ); ?>"><?php echo $e( $tel ); ?></a></td></tr>
			<?php endif; ?>
		</table>
		<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:14px"><tr>
			<td style="width:50%;vertical-align:top;padding-right:8px">
				<div style="background:#F6EFE3;border-radius:10px;padding:14px 16px;color:#33403B;font-size:14px;line-height:1.55">
					<p style="margin:0 0 6px;color:#8C5E22;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px">Facturation</p>
					<?php echo wp_kses_post( $order->get_formatted_billing_address() ?: '—' ); ?>
				</div>
			</td>
			<td style="width:50%;vertical-align:top;padding-left:8px">
				<div style="background:#F6EFE3;border-radius:10px;padding:14px 16px;color:#33403B;font-size:14px;line-height:1.55">
					<p style="margin:0 0 6px;color:#8C5E22;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px">Livraison</p>
					<?php
					if ( $order->needs_shipping_address() && $order->get_formatted_shipping_address() ) {
						echo wp_kses_post( $order->get_formatted_shipping_address() );
					} elseif ( $order->get_shipping_method() ) {
						echo $e( $order->get_shipping_method() );
					} else {
						echo 'Identique à la facturation';
					}
					?>
				</div>
			</td>
		</tr></table>
	</td></tr>

	<?php if ( $extra !== '' ) : ?>
	<!-- Informations ajoutées par d'autres extensions -->
	<tr><td style="padding:22px 32px 0;color:#33403B;font-size:14px;line-height:1.55">
		<h2 style="<?php echo esc_attr( $h2 ); ?>">Informations complémentaires</h2>
		<div style="background:#F6EFE3;border-radius:10px;padding:14px 16px"><?php echo wp_kses_post( $extra ); ?></div>
	</td></tr>
	<?php endif; ?>

	<!-- Boutons -->
	<tr><td align="center" style="padding:28px 32px 30px">
		<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>" style="display:inline-block;margin:4px;background:#173A2E;color:#F6EFE3;text-decoration:none;font-weight:700;font-size:15px;padding:12px 26px;border-radius:40px">Voir la commande</a>
		<?php if ( $email_client ) : ?>
			<a href="mailto:<?php echo esc_attr( $email_client ); ?>?subject=<?php echo esc_attr( $sujet ); ?>" style="display:inline-block;margin:4px;background:#E2B36A;color:#173A2E;text-decoration:none;font-weight:700;font-size:15px;padding:12px 26px;border-radius:40px">Écrire au client</a>
		<?php endif; ?>
		<?php if ( $additional_content ) : ?>
			<div style="margin:16px 0 0;color:#6b706d;font-size:13px;line-height:1.5"><?php echo wp_kses_post( str_replace( '<p>', '<p style="margin:0;color:#6b706d;font-size:13px">', wpautop( wptexturize( $additional_content ) ) ) ); ?></div>
		<?php endif; ?>
	</td></tr>

	<!-- Pied -->
	<tr><td align="center" style="padding:16px 24px;background:#173A2E;color:#9FB2A8;font-size:12px;line-height:1.6">
		Commande passée sur <a href="<?php echo esc_url( $site ); ?>" style="color:#E2B36A;text-decoration:none"><?php echo $e( wp_parse_url( $site, PHP_URL_HOST ) ); ?></a>
	</td></tr>

</table>
</td></tr></table>
</body>
</html>
