<?php
/**
 * Pied commun des e-mails WooCommerce envoyés aux clients, aux couleurs de ta-gravure.art.
 *
 * Variables fournies par WooCommerce : $email (objet WC_Email, peut manquer).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tg_email  = isset( $email ) ? $email : null;
$tg_id     = ( $tg_email && isset( $tg_email->id ) ) ? $tg_email->id : '';
$tg_site   = home_url( '/' );
$tg_contact = tg_mail_adresse_contact();
$tg_etapes = tg_mail_etapes( $tg_id );
?>
</td></tr>

<?php if ( $tg_etapes ) : ?>
<tr><td style="padding:20px 36px 6px">
<h2 style="margin:0 0 12px;color:#173A2E;font-size:18px;font-weight:600">La suite</h2>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
<?php foreach ( $tg_etapes as $tg_n => $tg_txt ) : ?>
<tr><td style="width:40px;padding:6px 0;vertical-align:top"><span style="display:inline-block;width:28px;height:28px;line-height:28px;border-radius:14px;background:#173A2E;color:#E2B36A;font-weight:700;font-size:14px;text-align:center"><?php echo (int) $tg_n + 1; ?></span></td>
<td style="padding:10px 0 6px;color:#33403B;font-size:14px;line-height:1.5"><?php echo esc_html( $tg_txt ); ?></td></tr>
<?php endforeach; ?>
</table>
<p style="margin:18px 0 0;color:#33403B;font-size:14px;line-height:1.6">Une question sur votre commande ? <strong>Répondez simplement à cet e-mail.</strong></p>
</td></tr>
<?php endif; ?>

<tr><td align="center" style="padding:26px 36px 32px">
<a href="<?php echo esc_url( $tg_site ); ?>" style="display:inline-block;background:#E2B36A;color:#173A2E;text-decoration:none;font-weight:700;font-size:15px;padding:13px 30px;border-radius:40px">Découvrir la boutique</a>
</td></tr>

<tr><td align="center" style="padding:20px 24px;background:#173A2E;color:#E9E2D4;font-size:12px;line-height:1.7">
<strong style="color:#F6EFE3">ta-gravure.art</strong> · Cadeaux et décorations personnalisés en bois, gravés au laser<br>
<a href="mailto:<?php echo esc_attr( $tg_contact ); ?>" style="color:#E2B36A;text-decoration:none"><?php echo esc_html( $tg_contact ); ?></a> ·
<a href="<?php echo esc_url( $tg_site ); ?>" style="color:#E2B36A;text-decoration:none"><?php echo esc_html( wp_parse_url( $tg_site, PHP_URL_HOST ) ); ?></a><br>
<span style="color:#9FB2A8">© <?php echo esc_html( gmdate( 'Y' ) ); ?> ta-gravure.art</span>
</td></tr>

</table>
</td></tr></table>
</body>
</html>
