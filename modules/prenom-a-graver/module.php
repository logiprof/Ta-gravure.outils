<?php
/**
 * Module « Personnalisation à graver » (anciennement l'extension ta-gravure – Personnalisation à graver 2.4.1).
 * Ajoute des champs de personnalisation (prénoms, dates, textes, choix) sur les fiches produits choisies,
 * avec une liste de champs propre à chaque produit. Les données apparaissent dans le panier, la commande,
 * l'administration, les e-mails et les factures.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Si l'ancienne version (1.x) est encore chargée ailleurs, on ne fait rien pour éviter les doublons.
if ( function_exists( 'tg_prenom_actif' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>Personnalisation à graver 2.0</strong> est en pause : l\'ancienne version « Prénom à graver » est encore active. Supprimez-la (extension ou fichier dans wp-content/mu-plugins) pour activer la nouvelle.</p></div>';
	} );
	return;
}

// Compatibilité HPOS et blocs déclarée par le noyau de Ta-gravure outils.

const TG_PRENOM_META  = '_tg_prenom_actif';   // inchangé : les produits déjà activés le restent.
const TG_CHAMPS_META  = '_tg_perso_champs';   // liste des champs, une ligne par champ.
const TG_POST_KEY     = 'tg_perso';
define( 'TG_MAX_PIECES', max( 1, (int) tgo_reglage( 'prenom-a-graver', 'max_pieces' ) ) ); // au-delà, le client passe par le formulaire de contact
const TG_CART_KEY     = 'tg_perso_values';
const TG_SANS_META    = '_tg_perso_sans';     // « yes » (défaut) : le client peut cocher « Sans gravure ».
define( 'TG_SANS_TEXTE', (string) tgo_reglage( 'prenom-a-graver', 'texte_sans' ) );
const TG_DEFAUT_CHAMP = 'Prénom(s) à graver* | texte | 40 | Ex. : Léa ou Léa & Noah';

add_action( 'tgo_activer_module_prenom-a-graver', function () {
	if ( get_post_type( 28877 ) === 'product' && get_post_meta( 28877, TG_PRENOM_META, true ) === '' ) {
		update_post_meta( 28877, TG_PRENOM_META, 'yes' );
	}
} );

function tg_perso_actif( $product_id ) {
	return get_post_meta( $product_id, TG_PRENOM_META, true ) === 'yes';
}

const TG_CONFIG_META = '_tg_perso_config'; // configuration saisie dans l'onglet « Personnalisation ».

/** Types de champ proposés. */
function tg_perso_types() {
	return array(
		'texte'      => 'Texte court (une ligne)',
		'texte-long' => 'Texte long (plusieurs lignes)',
		'date'       => 'Date',
		'nombre'     => 'Nombre',
		'choix'      => 'Liste de choix',
	);
}

/** Met un champ au format interne, avec des valeurs par défaut sûres. */
function tg_perso_normaliser( $label, $type, $requis, $limite, $options, $aide, $par_piece = false, $sans = true ) {
	$label = trim( (string) $label );
	if ( $label === '' ) {
		return null;
	}
	$type   = array_key_exists( $type, tg_perso_types() ) ? $type : 'texte';
	$limite = (int) $limite;
	$champ  = array(
		'label'  => $label,
		'requis' => (bool) $requis,
		'type'   => $type,
		'aide'   => trim( (string) $aide ),
		'limite' => $limite,
		'max'    => 0,
		'min_n'  => null,
		'max_n'  => null,
		'choix'  => array(),
		'par_piece' => (bool) $par_piece,
		'sans'      => (bool) $sans,
	);
	if ( $type === 'texte' || $type === 'texte-long' ) {
		$champ['max'] = $limite > 0 ? $limite : ( $type === 'texte' ? 40 : 1000 );
	} elseif ( $type === 'nombre' && $limite > 0 ) {
		$champ['min_n'] = 0;
		$champ['max_n'] = $limite;
	} elseif ( $type === 'choix' ) {
		$champ['choix'] = array_values( array_filter( array_map( 'trim', explode( ';', (string) $options ) ), 'strlen' ) );
	}
	return $champ;
}

/** Ancien format texte (version 2.0.0) : « Libellé* | type | précision | aide ». */
function tg_perso_lire_texte( $raw ) {
	$champs = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $ligne ) {
		$ligne = trim( $ligne );
		if ( $ligne === '' || strpos( $ligne, '#' ) === 0 ) {
			continue;
		}
		$p      = array_map( 'trim', explode( '|', $ligne ) );
		$label  = $p[0];
		$requis = substr( $label, -1 ) === '*';
		$type   = isset( $p[1] ) && $p[1] !== '' ? strtolower( $p[1] ) : 'texte';
		$prec   = isset( $p[2] ) ? $p[2] : '';
		$limite = $type === 'nombre' && preg_match( '/-\s*(\d+)$/', $prec, $m ) ? $m[1] : $prec;
		$c      = tg_perso_normaliser( rtrim( $label, '* ' ), $type, $requis, $type === 'choix' ? 0 : $limite, $type === 'choix' ? $prec : '', isset( $p[3] ) ? $p[3] : '' );
		if ( $c ) {
			$champs[] = $c;
		}
	}
	return $champs;
}

/** Liste des champs d'un produit. */
function tg_perso_champs( $product_id ) {
	$cfg = get_post_meta( $product_id, TG_CONFIG_META, true );
	if ( is_array( $cfg ) && $cfg ) {
		$champs = array();
		foreach ( $cfg as $r ) {
			$c = tg_perso_normaliser(
				isset( $r['label'] ) ? $r['label'] : '',
				isset( $r['type'] ) ? $r['type'] : 'texte',
				! empty( $r['requis'] ),
				isset( $r['limite'] ) ? $r['limite'] : 0,
				isset( $r['options'] ) ? $r['options'] : '',
				isset( $r['aide'] ) ? $r['aide'] : '',
				! empty( $r['par_piece'] ),
				! isset( $r['sans'] ) || ! empty( $r['sans'] )
			);
			if ( $c ) {
				$champs[] = $c;
			}
		}
		if ( $champs ) {
			return $champs;
		}
	}
	$raw = trim( (string) get_post_meta( $product_id, TG_CHAMPS_META, true ) );
	return tg_perso_lire_texte( $raw !== '' ? $raw : TG_DEFAUT_CHAMP );
}

/* -------------------------------------------------------------------------
 * Administration : onglet « Personnalisation » de la fiche produit
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_product_data_tabs', function ( $tabs ) {
	$tabs['tg_perso'] = array(
		'label'    => 'Personnalisation',
		'target'   => 'tg_perso_panel',
		'class'    => array(),
		'priority' => 15,
	);
	return $tabs;
} );

/** Une ligne du tableau de configuration. */
function tg_perso_ligne_admin( $i, $c ) {
	$n = 'tg_cfg[' . $i . ']';
	?>
	<tr class="tg-row">
		<td><input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $c['label'] ); ?>" placeholder="Ex. : Prénom à graver" style="width:100%;"></td>
		<td>
			<select name="<?php echo esc_attr( $n ); ?>[type]" class="tg-type" style="width:100%;">
				<?php foreach ( tg_perso_types() as $k => $lib ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $c['type'], $k ); ?>><?php echo esc_html( $lib ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
		<td style="text-align:center;"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[requis]" value="1" <?php checked( $c['requis'] ); ?>></td>
		<td style="text-align:center;"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[par_piece]" value="1" <?php checked( ! empty( $c['par_piece'] ) ); ?> title="Répéter ce champ autant de fois que la quantité commandée"></td>
		<td style="text-align:center;"><input type="hidden" name="<?php echo esc_attr( $n ); ?>[sans]" value="0"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[sans]" value="1" <?php checked( ! isset( $c['sans'] ) || ! empty( $c['sans'] ) ); ?> title="Le client peut cocher « Sans gravure » pour ce champ"></td>
		<td><input type="number" min="0" name="<?php echo esc_attr( $n ); ?>[limite]" value="<?php echo $c['limite'] ? (int) $c['limite'] : ''; ?>" class="tg-limite" placeholder="40" style="width:100%;"></td>
		<td><input type="text" name="<?php echo esc_attr( $n ); ?>[options]" value="<?php echo esc_attr( implode( '; ', $c['choix'] ) ); ?>" class="tg-options" placeholder="Choix 1; Choix 2" style="width:100%;"></td>
		<td><input type="text" name="<?php echo esc_attr( $n ); ?>[aide]" value="<?php echo esc_attr( $c['aide'] ); ?>" placeholder="Ex. : Léa ou Léa &amp; Noah" style="width:100%;"></td>
		<td style="text-align:center;"><button type="button" class="button tg-del" title="Supprimer ce champ">✕</button></td>
	</tr>
	<?php
}

add_action( 'woocommerce_product_data_panels', function () {
	global $post;
	$champs = tg_perso_champs( $post->ID );
	$vide   = array( 'label' => '', 'type' => 'texte', 'requis' => true, 'limite' => 0, 'choix' => array(), 'aide' => '', 'par_piece' => false, 'sans' => true );
	?>
	<div id="tg_perso_panel" class="panel woocommerce_options_panel hidden">
		<div class="options_group">
			<?php
			woocommerce_wp_checkbox( array(
				'id'          => TG_PRENOM_META,
				'label'       => 'Activer',
				'description' => 'Demander au client les informations à graver sur ce produit.',
			) );
			woocommerce_wp_checkbox( array(
				'id'          => TG_SANS_META,
				'label'       => '« Sans gravure »',
				'value'       => tg_perso_sans_permis( $post->ID ) ? 'yes' : 'no',
				'description' => 'Proposer au client une case « Sans gravure » à côté des champs choisis (colonne « « Sans gravure » possible »).',
			) );
			?>
		</div>
		<div class="options_group" style="padding:12px;">
			<table class="widefat striped" style="table-layout:fixed;">
				<thead>
					<tr>
						<th style="width:18%;">Libellé du champ</th>
						<th style="width:16%;">Type</th>
						<th style="width:8%;text-align:center;">Obligatoire</th>
						<th style="width:8%;text-align:center;" title="Répéter ce champ autant de fois que la quantité commandée (ex. 3 boules = 3 prénoms)">Un par pièce</th>
						<th style="width:8%;text-align:center;" title="Le client peut cocher « Sans gravure » pour ce champ">« Sans gravure » possible</th>
						<th style="width:8%;" title="Texte : caractères max. · Nombre : valeur max.">Limite</th>
						<th style="width:13%;" title="Pour « Liste de choix » : options séparées par ;">Choix</th>
						<th style="width:15%;">Exemple / aide</th>
						<th style="width:6%;"></th>
					</tr>
				</thead>
				<tbody id="tg-rows">
					<?php foreach ( $champs as $i => $c ) { tg_perso_ligne_admin( $i, $c ); } ?>
				</tbody>
			</table>
			<p style="margin:10px 0 0;padding:0;">
				<button type="button" class="button" id="tg-add">+ Ajouter un champ</button>
			</p>
			<p class="description" style="margin:10px 0 0;padding:0;">
				Limite : nombre de caractères max. pour un texte (40 par défaut), valeur max. pour un nombre.
				Choix : uniquement pour « Liste de choix », options séparées par un point-virgule.<br>
				Un par pièce : le champ se répète selon la quantité choisie par le client (3 boules = 3 prénoms à saisir).<br>
				« Sans gravure » possible : le client peut cocher « Sans gravure » à côté de ce champ (ex. pas de prénom sur une pièce). La case générale « « Sans gravure » » ci-dessus active ou coupe l'option pour tout le produit.
			</p>
			<script type="text/html" id="tg-row-tpl"><?php tg_perso_ligne_admin( '__i__', $vide ); ?></script>
		</div>
	</div>
	<script>
	jQuery(function ($) {
		var $rows = $('#tg-rows');
		function maj($tr) {
			var t = $tr.find('.tg-type').val();
			$tr.find('.tg-limite').prop('disabled', !(t === 'texte' || t === 'texte-long' || t === 'nombre'))
				.attr('placeholder', t === 'texte-long' ? '1000' : (t === 'nombre' ? '30' : '40'));
			$tr.find('.tg-options').prop('disabled', t !== 'choix');
		}
		$rows.find('.tg-row').each(function () { maj($(this)); });
		$rows.on('change', '.tg-type', function () { maj($(this).closest('tr')); });
		$rows.on('click', '.tg-del', function () { $(this).closest('tr').remove(); });
		$('#tg-add').on('click', function () {
			var $tr = $($('#tg-row-tpl').html().replace(/__i__/g, 'n' + Date.now()));
			$rows.append($tr); maj($tr); $tr.find('input:first').trigger('focus');
		});
	});
	</script>
	<?php
} );

add_action( 'woocommerce_admin_process_product_object', function ( $product ) {
	$product->update_meta_data( TG_PRENOM_META, isset( $_POST[ TG_PRENOM_META ] ) ? 'yes' : 'no' );
	if ( isset( $_POST['tg_cfg'] ) || isset( $_POST[ TG_PRENOM_META ] ) ) {
		$product->update_meta_data( TG_SANS_META, isset( $_POST[ TG_SANS_META ] ) ? 'yes' : 'no' );
	}
	if ( ! isset( $_POST['tg_cfg'] ) && ! isset( $_POST[ TG_PRENOM_META ] ) ) {
		return;
	}
	$cfg = array();
	$in  = isset( $_POST['tg_cfg'] ) && is_array( $_POST['tg_cfg'] ) ? wp_unslash( $_POST['tg_cfg'] ) : array();
	foreach ( $in as $r ) {
		$label = isset( $r['label'] ) ? sanitize_text_field( $r['label'] ) : '';
		if ( $label === '' ) {
			continue;
		}
		$cfg[] = array(
			'label'   => $label,
			'type'    => isset( $r['type'] ) ? sanitize_key( $r['type'] ) : 'texte',
			'requis'  => ! empty( $r['requis'] ),
			'limite'  => isset( $r['limite'] ) ? absint( $r['limite'] ) : 0,
			'options' => isset( $r['options'] ) ? sanitize_text_field( $r['options'] ) : '',
			'aide'    => isset( $r['aide'] ) ? sanitize_text_field( $r['aide'] ) : '',
			'par_piece' => ! empty( $r['par_piece'] ),
			'sans'      => ! empty( $r['sans'] ),
		);
	}
	$product->update_meta_data( TG_CONFIG_META, $cfg );
	$product->delete_meta_data( TG_CHAMPS_META ); // l'ancien format texte n'est plus utilisé.
} );

/* -------------------------------------------------------------------------
 * Fiche produit : affichage des champs
 * ---------------------------------------------------------------------- */

/** Un champ de saisie (texte, zone de texte, date, nombre ou liste). */
function tg_perso_input( $c, $id, $name, $value, $extra_class = '' ) {
	$css = 'width:100%;max-width:420px;padding:10px 12px;border:1px solid #c9b699;border-radius:6px;font-size:16px;';
	$req = $c['requis'] ? ' required' : '';
	$cls = $extra_class ? ' class="' . esc_attr( $extra_class ) . '"' : '';
	switch ( $c['type'] ) {
		case 'texte-long':
			return sprintf( '<textarea id="%s" name="%s"%s rows="6" maxlength="%d" placeholder="%s" style="%s"%s>%s</textarea>',
				esc_attr( $id ), esc_attr( $name ), $cls, (int) $c['max'], esc_attr( $c['aide'] ), esc_attr( $css ), $req, esc_textarea( $value ) );
		case 'date':
			return sprintf( '<input type="date" id="%s" name="%s"%s value="%s" style="%s"%s>', esc_attr( $id ), esc_attr( $name ), $cls, esc_attr( $value ), esc_attr( $css ), $req );
		case 'nombre':
			return sprintf( '<input type="number" id="%s" name="%s"%s value="%s" %s %s style="%s"%s>',
				esc_attr( $id ), esc_attr( $name ), $cls, esc_attr( $value ),
				$c['min_n'] !== null ? 'min="' . (int) $c['min_n'] . '"' : '',
				$c['max_n'] !== null ? 'max="' . (int) $c['max_n'] . '"' : '',
				esc_attr( $css ), $req );
		case 'choix':
			$h = sprintf( '<select id="%s" name="%s"%s style="%s"%s><option value="">— Choisir —</option>', esc_attr( $id ), esc_attr( $name ), $cls, esc_attr( $css ), $req );
			foreach ( $c['choix'] as $opt ) {
				$h .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( $opt ) );
			}
			return $h . '</select>';
		default:
			return sprintf( '<input type="text" id="%s" name="%s"%s value="%s" maxlength="%d" placeholder="%s" style="%s"%s>',
				esc_attr( $id ), esc_attr( $name ), $cls, esc_attr( $value ), (int) $c['max'], esc_attr( $c['aide'] ), esc_attr( $css ), $req );
	}
}

function tg_perso_sans_permis( $product_id ) {
	return get_post_meta( $product_id, TG_SANS_META, true ) !== 'no';
}

function tg_perso_a_par_piece( $product_id ) {
	foreach ( tg_perso_champs( $product_id ) as $c ) {
		if ( ! empty( $c['par_piece'] ) ) {
			return true;
		}
	}
	return false;
}

/** Styles de la zone de personnalisation (couleurs du site). */
function tg_perso_styles() {
	static $fait = false;
	if ( $fait ) {
		return '';
	}
	$fait = true;
	return '<style>
	.tg-perso-fields{--v:#173A2E;--b:#8C5E22;--o:#E2B36A;--bd:#E3D6C1;--t:#33403B}
	.tg-perso-fields .tg-perso-field{margin:0 0 14px}
	.tg-perso-fields .tg-tete{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:0 0 6px}
	.tg-perso-fields .tg-tete label.tg-lib{margin:0;font-weight:600;color:var(--v);font-size:15px}
	.tg-perso-fields .tg-req{color:#B3261E;text-decoration:none;border:0}
	.tg-perso-fields .tg-opt{font-weight:400;color:#777}
	.tg-perso-fields .tg-perso-field label.tg-sans{display:inline-flex!important;align-items:center;gap:7px;margin:0!important;font-size:13px!important;line-height:1.3!important;font-weight:600!important;color:var(--b)!important;cursor:pointer;white-space:nowrap;padding:5px 12px!important;border:1px solid var(--bd);border-radius:20px;background:#fff;transition:background .2s,color .2s}
	.tg-perso-fields .tg-perso-field label.tg-sans input{width:16px;height:16px;margin:0!important;accent-color:var(--v);cursor:pointer;flex:0 0 auto}
	.tg-perso-fields .tg-perso-field label.tg-sans.tg-coche,.tg-perso-fields .tg-perso-field label.tg-sans:has(input:checked){background:var(--v)!important;border-color:var(--v)!important;color:#F6EFE3!important}
	.tg-perso-fields .tg-perso-field label.tg-sans.tg-coche input,.tg-perso-fields .tg-perso-field label.tg-sans:has(input:checked) input{accent-color:var(--o)}
	.tg-perso-fields .tg-perso-field.tg-off .tg-saisie{opacity:.4}
	.tg-perso-fields .tg-perso-field input[type=text],.tg-perso-fields .tg-perso-field input[type=number],.tg-perso-fields .tg-perso-field input[type=date],.tg-perso-fields .tg-perso-field select,.tg-perso-fields .tg-perso-field textarea{width:100%!important;max-width:100%!important;box-sizing:border-box}
	.tg-perso-fields .tg-aide{display:block;margin-top:5px;color:#6b706d;font-size:13px;line-height:1.45}
	.tg-perso-fields .tg-zone-titre{margin:4px 0 10px;font-weight:600;color:var(--v);font-size:16px}
	.tg-perso-fields .tg-zone-titre span{font-weight:400;color:var(--b)}
	.tg-perso-fields .tg-piece{position:relative;border:1px solid var(--bd);border-radius:12px;padding:30px 18px 6px;margin:0 0 14px;background:#fff;min-width:0}
	.tg-perso-fields .tg-piece p.tg-piece-titre{position:absolute;top:-12px;left:14px;margin:0!important;padding:2px 12px!important;border-radius:20px;background:var(--v)!important;color:#F6EFE3!important;font-size:12px!important;font-weight:700!important;letter-spacing:.5px;line-height:20px!important}
	.tg-perso-fields .tg-piece p.tg-piece-titre span{color:#F6EFE3!important}
	.tg-perso-fields .tg-pp-liste.tg-une .tg-piece{border:0;padding:0;background:transparent}
	.tg-perso-fields .tg-pp-liste.tg-une .tg-piece-titre{display:none}
	@media (max-width:480px){.tg-perso-fields .tg-piece{padding:28px 12px 4px}}
	</style>';
}

/** Un champ complet : libellé, case « Sans gravure » éventuelle, saisie, aide. */
function tg_perso_champ_html( $c, $id, $name, $val, $sans_name, $sans_coche ) {
	$h  = '<div class="tg-perso-field' . ( $sans_coche ? ' tg-off' : '' ) . '">';
	$h .= '<div class="tg-tete">';
	$h .= sprintf( '<label class="tg-lib" for="%s">%s%s</label>', esc_attr( $id ), esc_html( $c['label'] ),
		$c['requis'] ? ' <abbr class="tg-req" title="obligatoire">*</abbr>' : ' <span class="tg-opt">(facultatif)</span>' );
	if ( $sans_name ) {
		$h .= sprintf( '<label class="tg-sans%s"><input type="checkbox" name="%s" value="1"%s> Sans gravure</label>', $sans_coche ? ' tg-coche' : '', esc_attr( $sans_name ), $sans_coche ? ' checked' : '' );
	}
	$h   .= '</div><div class="tg-saisie">' . tg_perso_input( $c, $id, $name, $val );
	$info = in_array( $c['type'], array( 'texte', 'texte-long' ), true ) ? sprintf( '%d caractères max., gravé tel quel.', (int) $c['max'] ) : '';
	if ( $info ) {
		$h .= '<small class="tg-aide">' . esc_html( $info ) . '</small>';
	}
	return $h . '</div></div>';
}

/** Bloc d'une pièce : tous ses champs « un par pièce » ensemble. */
function tg_perso_bloc_piece( $champs_pp, $k, $posted, $sans, $permis, $modele = false ) {
	$kk = $modele ? '__k__' : (string) $k;
	$h  = '<div class="tg-piece"><p class="tg-piece-titre">Pièce <span class="tg-piece-num">' . esc_html( $modele ? '__n__' : (string) ( $k + 1 ) ) . '</span></p>';
	foreach ( $champs_pp as $i => $c ) {
		$val   = ! $modele && isset( $posted[ $i ] ) && is_array( $posted[ $i ] ) && isset( $posted[ $i ][ $k ] ) ? (string) $posted[ $i ][ $k ] : '';
		$coche = ! $modele && isset( $sans[ $i ] ) && is_array( $sans[ $i ] ) && ! empty( $sans[ $i ][ $k ] );
		$h    .= tg_perso_champ_html( $c, 'tg_perso_' . $i . '_' . $kk, TG_POST_KEY . '[' . $i . '][' . $kk . ']', $val,
			$permis && $c['sans'] && $c['requis'] ? 'tg_sans[' . $i . '][' . $kk . ']' : '', $coche );
	}
	return $h . '</div>';
}

add_action( 'woocommerce_before_add_to_cart_button', function () {
	global $product;
	if ( ! $product || ! tg_perso_actif( $product->get_id() ) ) {
		return;
	}
	$pid    = $product->get_id();
	$posted = isset( $_POST[ TG_POST_KEY ] ) && is_array( $_POST[ TG_POST_KEY ] ) ? wp_unslash( $_POST[ TG_POST_KEY ] ) : array();
	$sans   = isset( $_POST['tg_sans'] ) && is_array( $_POST['tg_sans'] ) ? wp_unslash( $_POST['tg_sans'] ) : array();
	$qte    = isset( $_POST['quantity'] ) ? max( 1, min( TG_MAX_PIECES, absint( $_POST['quantity'] ) ) ) : max( 1, (int) $product->get_min_purchase_quantity() );
	$permis = tg_perso_sans_permis( $pid );

	$champs_pp = array();
	$champs_gl = array();
	foreach ( tg_perso_champs( $pid ) as $i => $c ) {
		if ( ! empty( $c['par_piece'] ) ) {
			$champs_pp[ $i ] = $c;
		} else {
			$champs_gl[ $i ] = $c;
		}
	}

	echo tg_perso_styles(); // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<div class="tg-perso-fields" style="margin:0 0 18px;">';

	// Champs communs à toute la commande de cet article.
	foreach ( $champs_gl as $i => $c ) {
		echo tg_perso_champ_html( $c, 'tg_perso_' . $i, TG_POST_KEY . '[' . $i . ']', isset( $posted[ $i ] ) && ! is_array( $posted[ $i ] ) ? (string) $posted[ $i ] : '',
			$permis && $c['sans'] && $c['requis'] ? 'tg_sans[' . $i . ']' : '', isset( $sans[ $i ] ) && ! is_array( $sans[ $i ] ) && ! empty( $sans[ $i ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// Un bloc par pièce : les infos de chaque pièce restent ensemble.
	if ( $champs_pp ) {
		echo '<div class="tg-pp-zone">';
		echo '<p class="tg-zone-titre">Personnalisation <span class="tg-pp-nb"></span></p>';
		echo '<div class="tg-pp-liste' . ( $qte === 1 ? ' tg-une' : '' ) . '">';
		for ( $k = 0; $k < $qte; $k++ ) {
			echo tg_perso_bloc_piece( $champs_pp, $k, $posted, $sans, $permis ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
		echo '<template class="tg-pp-modele">' . tg_perso_bloc_piece( $champs_pp, 0, array(), array(), $permis, true ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<small class="tg-aide">Un bloc par pièce : il s\'ajuste à la quantité choisie.</small>';
		echo '</div>';
	} else {
		echo '<small class="tg-aide">Pour une personnalisation différente, ajoutez ce produit une nouvelle fois au panier.</small>';
	}
	echo '</div>';
	?>
	<script>
	(function () {
	function init() {
		var bloc = document.querySelector('form.cart .tg-perso-fields');
		var form = bloc ? bloc.closest('form.cart') : null;
		if (!form || form.dataset.tgPp) return;
		form.dataset.tgPp = '1';
		var MAX = <?php echo (int) TG_MAX_PIECES; ?>;
		function qte() {
			var q = form.querySelector('input.qty, input[name=quantity]');
			var n = q ? parseInt(q.value, 10) : 1;
			return isNaN(n) || n < 1 ? 1 : Math.min(n, MAX);
		}
		// « Sans gravure » : les champs concernés sont grisés et ne sont plus envoyés ni obligatoires.
		function sans(cb) {
			var champ = cb.closest('.tg-perso-field');
			if (!champ) return;
			champ.querySelectorAll('.tg-saisie input, .tg-saisie select, .tg-saisie textarea').forEach(function (el) { el.disabled = cb.checked; });
			champ.classList.toggle('tg-off', cb.checked);
			cb.closest('label').classList.toggle('tg-coche', cb.checked);
			indices();
		}
		function champs() {
			return Array.prototype.filter.call(
				form.querySelectorAll('.tg-perso-fields .tg-saisie input, .tg-perso-fields .tg-saisie select, .tg-perso-fields .tg-saisie textarea'),
				function (el) { return el.offsetParent !== null && !el.disabled && !el.closest('template'); }
			);
		}
		function indices() {
			var l = champs();
			l.forEach(function (el, i) { if (el.tagName !== 'TEXTAREA') el.setAttribute('enterkeyhint', i < l.length - 1 ? 'next' : 'done'); });
		}
		function maj() {
			var zone = form.querySelector('.tg-pp-zone');
			if (zone) {
				var n = qte(), liste = zone.querySelector('.tg-pp-liste'), modele = zone.querySelector('.tg-pp-modele').innerHTML;
				var blocs = liste.querySelectorAll('.tg-piece');
				for (var k = blocs.length; k < n; k++) {
					var div = document.createElement('div');
					div.innerHTML = modele.replace(/__k__/g, k).replace(/__n__/g, k + 1);
					liste.appendChild(div.firstElementChild);
				}
				for (var j = blocs.length - 1; j >= n; j--) { blocs[j].remove(); }
				zone.querySelector('.tg-pp-nb').textContent = n > 1 ? '(' + n + ' pièces)' : '';
				liste.classList.toggle('tg-une', n === 1);
			}
			form.querySelectorAll('.tg-sans input').forEach(sans);
			indices();
		}
		form.addEventListener('change', function (e) {
			if (e.target.matches('.tg-sans input')) sans(e.target);
			if (e.target.matches('input.qty, input[name=quantity]')) maj();
		});
		form.addEventListener('input', function (e) { if (e.target.matches('input.qty, input[name=quantity]')) maj(); });
		form.addEventListener('click', function (e) { if (e.target.closest('.plus, .minus, .quantity button')) setTimeout(maj, 50); });
		// Entrée = champ suivant (au lieu d'ajouter au panier) ; sur le dernier champ, on va au bouton.
		form.addEventListener('keydown', function (e) {
			if (e.key !== 'Enter' || e.isComposing || !e.target.closest('.tg-saisie') || e.target.tagName === 'TEXTAREA') return;
			e.preventDefault();
			var l = champs(), i = l.indexOf(e.target);
			if (i > -1 && i < l.length - 1) { l[i + 1].focus(); if (l[i + 1].select) l[i + 1].select(); return; }
			var btn = form.querySelector('[name=add-to-cart][type=submit], button.single_add_to_cart_button, button[type=submit]');
			if (btn) btn.focus();
		});
		maj();
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
	})();
	</script>
	<?php
} );

/* -------------------------------------------------------------------------
 * Validation et enregistrement
 * ---------------------------------------------------------------------- */

/** Contrôle une valeur. Retourne [ valeur nettoyée, erreur ou '' ]. */
function tg_perso_valider( $c, $v, $nom ) {
	$v = $c['type'] === 'texte-long' ? trim( sanitize_textarea_field( (string) $v ) ) : trim( sanitize_text_field( (string) $v ) );
	if ( $v === '' ) {
		return array( '', $c['requis'] ? sprintf( 'Merci de remplir « %s » avant d\'ajouter ce produit au panier.', $nom ) : '' );
	}
	switch ( $c['type'] ) {
		case 'texte':
		case 'texte-long':
			if ( mb_strlen( $v ) > $c['max'] ) {
				return array( $v, sprintf( '« %s » ne doit pas dépasser %d caractères.', $nom, $c['max'] ) );
			}
			break;
		case 'date':
			$d = DateTime::createFromFormat( 'Y-m-d', $v );
			if ( ! $d ) {
				return array( $v, sprintf( '« %s » : date non valide.', $nom ) );
			}
			$v = $d->format( 'd.m.Y' );
			break;
		case 'nombre':
			if ( ! is_numeric( $v ) || ( $c['min_n'] !== null && ( $v < $c['min_n'] || $v > $c['max_n'] ) ) ) {
				return array( $v, sprintf( '« %s » : nombre non valide.', $nom ) );
			}
			break;
		case 'choix':
			if ( ! in_array( $v, $c['choix'], true ) ) {
				return array( $v, sprintf( '« %s » : choix non valide.', $nom ) );
			}
			break;
	}
	return array( $v, '' );
}

/**
 * Nettoie et valide les valeurs envoyées. Retourne [ valeurs, erreurs ].
 * Les champs « un par pièce » sont rangés pièce par pièce : « Pièce 1 – Message », « Pièce 1 – Prénom », « Pièce 2 – … ».
 */
function tg_perso_lire( $product_id, $qte = 1 ) {
	$posted  = isset( $_POST[ TG_POST_KEY ] ) && is_array( $_POST[ TG_POST_KEY ] ) ? wp_unslash( $_POST[ TG_POST_KEY ] ) : array();
	$sans    = tg_perso_sans_permis( $product_id ) && isset( $_POST['tg_sans'] ) && is_array( $_POST['tg_sans'] ) ? wp_unslash( $_POST['tg_sans'] ) : array();
	$qte     = max( 1, (int) $qte );
	$valeurs = array();
	$erreurs = array();
	$pp      = array();
	$gl      = array();
	foreach ( tg_perso_champs( $product_id ) as $i => $c ) {
		if ( ! empty( $c['par_piece'] ) ) {
			$pp[ $i ] = $c;
		} else {
			$gl[ $i ] = $c;
		}
	}

	$permis = tg_perso_sans_permis( $product_id );

	// Champs communs.
	foreach ( $gl as $i => $c ) {
		if ( $permis && $c['sans'] && isset( $sans[ $i ] ) && ! is_array( $sans[ $i ] ) && ! empty( $sans[ $i ] ) ) {
			$valeurs[ $c['label'] ] = TG_SANS_TEXTE;
			continue;
		}
		list( $v, $e ) = tg_perso_valider( $c, isset( $posted[ $i ] ) && ! is_array( $posted[ $i ] ) ? $posted[ $i ] : '', $c['label'] );
		if ( $e !== '' && $v === '' ) {
			$erreurs[] = sprintf( 'Merci de remplir « %s »%s.', $c['label'], $permis && $c['sans'] ? ' ou de cocher « Sans gravure »' : '' );
			continue;
		}
		if ( $e !== '' ) {
			$erreurs[] = $e;
		}
		if ( $v !== '' ) {
			$valeurs[ $c['label'] ] = $v;
		}
	}

	// Une pièce après l'autre : ses informations restent ensemble.
	if ( $pp ) {
		if ( $qte > TG_MAX_PIECES ) {
			$erreurs[] = sprintf( 'Pour plus de %d pièces personnalisées, contactez-nous : nous préparerons votre commande ensemble.', TG_MAX_PIECES );
			return array( $valeurs, $erreurs );
		}
		for ( $k = 0; $k < $qte; $k++ ) {
			$prefixe   = $qte > 1 ? 'Pièce ' . ( $k + 1 ) . ' – ' : '';
			$manquants = array();
			$sans_ok   = false;
			foreach ( $pp as $i => $c ) {
				if ( $permis && $c['sans'] && isset( $sans[ $i ] ) && is_array( $sans[ $i ] ) && ! empty( $sans[ $i ][ $k ] ) ) {
					$valeurs[ $prefixe . $c['label'] ] = TG_SANS_TEXTE;
					continue;
				}
				$brut = isset( $posted[ $i ] ) && is_array( $posted[ $i ] ) && isset( $posted[ $i ][ $k ] ) ? $posted[ $i ][ $k ] : '';
				list( $v, $e ) = tg_perso_valider( $c, $brut, $prefixe . $c['label'] );
				if ( $e !== '' && $v === '' ) {
					$manquants[] = '« ' . $c['label'] . ' »';
					$sans_ok     = $sans_ok || ( $permis && $c['sans'] );
					continue;
				}
				if ( $e !== '' ) {
					$erreurs[] = $e;
				}
				if ( $v !== '' ) {
					$valeurs[ $prefixe . $c['label'] ] = $v;
				}
			}
			if ( $manquants ) {
				$erreurs[] = sprintf(
					'%s de remplir %s%s.',
					$qte > 1 ? 'Pièce ' . ( $k + 1 ) . ' : merci' : 'Merci',
					implode( ', ', $manquants ),
					$sans_ok ? ' ou de cocher « Sans gravure »' : ''
				);
			}
		}
	}
	return array( $valeurs, $erreurs );
}

add_filter( 'woocommerce_add_to_cart_validation', function ( $passed, $product_id, $quantity = 1 ) {
	if ( ! tg_perso_actif( $product_id ) ) {
		return $passed;
	}
	list( , $erreurs ) = tg_perso_lire( $product_id, $quantity );
	foreach ( $erreurs as $e ) {
		wc_add_notice( $e, 'error' );
	}
	return $erreurs ? false : $passed;
}, 10, 3 );

add_filter( 'woocommerce_add_cart_item_data', function ( $data, $product_id, $variation_id = 0, $quantity = 1 ) {
	if ( tg_perso_actif( $product_id ) ) {
		list( $valeurs ) = tg_perso_lire( $product_id, $quantity );
		if ( $valeurs ) {
			$data[ TG_CART_KEY ] = $valeurs;
			$data['tg_unique']   = md5( wp_json_encode( $valeurs ) . microtime() ); // chaque ajout reste une ligne à part
		}
		if ( tg_perso_a_par_piece( $product_id ) ) {
			$data['tg_par_piece'] = (int) $quantity;
		}
	}
	return $data;
}, 10, 4 );

/* Quantité verrouillée dans le panier pour les articles « un par pièce » :
   elle doit rester égale au nombre de prénoms saisis. */
add_filter( 'woocommerce_cart_item_quantity', function ( $html, $key, $item ) {
	if ( ! empty( $item['tg_par_piece'] ) ) {
		return sprintf( '<span title="Pour changer la quantité, retirez l\'article puis ajoutez-le à nouveau avec les prénoms.">%d</span><input type="hidden" name="cart[%s][qty]" value="%d">', (int) $item['quantity'], esc_attr( $key ), (int) $item['quantity'] );
	}
	return $html;
}, 10, 3 );

add_filter( 'woocommerce_update_cart_validation', function ( $passed, $key, $values, $quantity ) {
	if ( ! empty( $values['tg_par_piece'] ) && (int) $quantity !== (int) $values['quantity'] && (int) $quantity > 0 ) {
		wc_add_notice( 'La quantité d\'un article personnalisé ne peut pas être modifiée dans le panier : retirez-le puis ajoutez-le à nouveau avec le bon nombre de prénoms.', 'error' );
		return false;
	}
	return $passed;
}, 10, 4 );

add_filter( 'woocommerce_store_api_product_quantity_editable', function ( $editable, $product, $item = null ) {
	return is_array( $item ) && ! empty( $item['tg_par_piece'] ) ? false : $editable;
}, 10, 3 );

add_filter( 'woocommerce_get_item_data', function ( $item_data, $cart_item ) {
	if ( ! empty( $cart_item[ TG_CART_KEY ] ) && is_array( $cart_item[ TG_CART_KEY ] ) ) {
		foreach ( $cart_item[ TG_CART_KEY ] as $label => $v ) {
			$item_data[] = array( 'key' => $label, 'value' => nl2br( esc_html( $v ) ) );
		}
	}
	// Articles ajoutés au panier avec l'ancienne version (un seul prénom).
	if ( ! empty( $cart_item['tg_prenom_a_graver'] ) ) {
		$item_data[] = array( 'key' => 'Prénom(s) à graver', 'value' => esc_html( $cart_item['tg_prenom_a_graver'] ) );
	}
	return $item_data;
}, 10, 2 );

add_action( 'woocommerce_checkout_create_order_line_item', function ( $item, $cart_item_key, $values ) {
	if ( ! empty( $values[ TG_CART_KEY ] ) && is_array( $values[ TG_CART_KEY ] ) ) {
		foreach ( $values[ TG_CART_KEY ] as $label => $v ) {
			$item->add_meta_data( $label, $v, true );
		}
	}
	if ( ! empty( $values['tg_prenom_a_graver'] ) ) {
		$item->add_meta_data( 'Prénom(s) à graver', $values['tg_prenom_a_graver'], true );
	}
}, 10, 3 );

/* -------------------------------------------------------------------------
 * Listes de produits et paiements express
 * ---------------------------------------------------------------------- */

// Le bouton des listes mène à la fiche pour saisir la personnalisation.
add_filter( 'woocommerce_product_add_to_cart_url', function ( $url, $product ) {
	return tg_perso_actif( $product->get_id() ) ? get_permalink( $product->get_id() ) : $url;
}, 10, 2 );

add_filter( 'woocommerce_product_add_to_cart_text', function ( $text, $product ) {
	return tg_perso_actif( $product->get_id() ) ? 'Personnaliser' : $text;
}, 10, 2 );

add_filter( 'woocommerce_product_supports', function ( $supports, $feature, $product ) {
	if ( $feature === 'ajax_add_to_cart' && tg_perso_actif( $product->get_id() ) ) {
		return false;
	}
	return $supports;
}, 10, 3 );

// Apple Pay / Google Pay sur la fiche produit contourneraient les champs :
// on les masque sur ces produits (ils restent disponibles au panier et au paiement).
foreach ( array( 'wcpay_payment_request_is_product_supported', 'wcpay_express_checkout_is_product_supported' ) as $tg_filtre ) {
	add_filter( $tg_filtre, function ( $supported, $product = null ) {
		if ( $product instanceof WC_Product && tg_perso_actif( $product->get_id() ) ) {
			return false;
		}
		return $supported;
	}, 10, 2 );
}

add_action( 'wp_head', function () {
	if ( function_exists( 'is_product' ) && is_product() && tg_perso_actif( get_queried_object_id() ) ) {
		echo '<style>#wcpay-express-checkout-element,#wcpay-express-checkout-wrapper,.wcpay-payment-request-wrapper,#wcpay-payment-request-wrapper,#wcpay-express-checkout-button-separator,#wcpay-payment-request-button-separator,.wc-block-components-express-payment{display:none!important}</style>' . "\n";
	}
} );
