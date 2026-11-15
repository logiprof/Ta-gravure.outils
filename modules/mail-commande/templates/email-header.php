<?php
/**
 * En-tête commun des e-mails WooCommerce envoyés aux clients, aux couleurs de ta-gravure.art
 * (même présentation que l'e-mail de confirmation du formulaire de contact).
 *
 * Variables fournies par WooCommerce : $email_heading, $email (objet WC_Email, peut manquer).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tg_email   = isset( $email ) ? $email : null;
$tg_id      = ( $tg_email && isset( $tg_email->id ) ) ? $tg_email->id : '';
$tg_site    = home_url( '/' );
$tg_etiq    = tg_mail_etiquette( $tg_id );
$tg_intro   = tg_mail_intro( $tg_email );
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light only">
<meta name="supported-color-schemes" content="light only">
<title><?php echo esc_html( get_bloginfo( 'name', 'display' ) ); ?></title>
</head>
<body <?php echo is_rtl() ? 'rightmargin' : 'leftmargin'; ?>="0" marginwidth="0" topmargin="0" marginheight="0" offset="0" class="tg-mail" style="margin:0;padding:0;background:#F6EFE3;font-family:'Open Sans',Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" id="tg_fond" style="background:#F6EFE3"><tr><td align="center" style="padding:28px 12px">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" id="tg_carte" style="font-family:'Open Sans',Arial,Helvetica,sans-serif;width:100%;max-width:600px;background:#FFFBF4;border:1px solid #E3D6C1;border-radius:16px;overflow:hidden">

<tr><td align="center" style="padding:26px 24px 20px;background:#FFFBF4;border-bottom:3px solid #E2B36A">
<a href="<?php echo esc_url( $tg_site ); ?>" style="text-decoration:none"><img src="<?php echo esc_url( tg_mail_commande_logo() ); ?>" width="220" alt="ta-gravure.art – l'art de la gravure laser" style="display:block;width:220px;max-width:70%;height:auto;border:0;color:#173A2E;font-size:20px;font-weight:600"></a>
</td></tr>

<tr><td id="tg_bandeau" style="padding:34px 36px 30px;background:#173A2E">
<?php if ( $tg_etiq ) : ?>
<p style="margin:0 0 10px;color:#E2B36A;font-size:12px;font-weight:700;letter-spacing:3px;text-transform:uppercase"><?php echo esc_html( $tg_etiq ); ?></p>
<?php endif; ?>
<h1 style="margin:0;color:#F6EFE3;font-size:26px;line-height:1.25;font-weight:600"><?php echo esc_html( wp_strip_all_tags( (string) $email_heading ) ); ?></h1>
<?php if ( $tg_intro ) : ?>
<p style="margin:12px 0 0;color:#E9E2D4;font-size:16px;line-height:1.6"><?php echo wp_kses( $tg_intro, array( 'strong' => array( 'style' => true ) ) ); ?></p>
<?php endif; ?>
</td></tr>

<tr><td id="body_content_inner" class="tg-corps" style="padding:30px 36px 10px;color:#33403B;font-size:15px;line-height:1.6">
