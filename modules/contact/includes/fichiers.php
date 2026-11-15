<?php
/**
 * Pièces jointes : contrôle strict, stockage protégé, téléchargement réservé à l'administration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TG_CONTACT_MAX_FICHIERS = 5;
const TG_CONTACT_MAX_OCTETS   = 10485760; // 10 Mo par fichier
const TG_CONTACT_MAX_TOTAL    = 26214400; // 25 Mo au total

/** Extensions acceptées => types MIME réels acceptés (détectés par le serveur). */
function tg_contact_formats() {
	$zip_office = array( 'application/zip', 'application/octet-stream' );
	return array(
		'jpg'  => array( 'image/jpeg' ),
		'jpeg' => array( 'image/jpeg' ),
		'png'  => array( 'image/png' ),
		'webp' => array( 'image/webp' ),
		'pdf'  => array( 'application/pdf' ),
		'docx' => array_merge( array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ), $zip_office ),
		'xlsx' => array_merge( array( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ), $zip_office ),
		'txt'  => array( 'text/plain' ),
		'csv'  => array( 'text/plain', 'text/csv', 'application/csv' ),
	);
}

/** Dossier de stockage, fermé au public. */
function tg_contact_dossier() {
	$up  = wp_upload_dir( null, false );
	$dir = trailingslashit( $up['basedir'] ) . 'tg-contact-prive';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\nOptions -Indexes\n<IfModule mod_php.c>\nphp_flag engine off\n</IfModule>\n" );
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php // Silence.\n" );
	}
	return $dir;
}

/**
 * Vérifie un fichier envoyé. Retourne '' si accepté, sinon le motif du refus.
 */
function tg_contact_verifier_fichier( $chemin, $nom, $taille ) {
	$ext     = strtolower( pathinfo( $nom, PATHINFO_EXTENSION ) );
	$formats = tg_contact_formats();

	if ( ! isset( $formats[ $ext ] ) ) {
		return 'format non accepté';
	}
	// Double extension du type « photo.php.jpg » : refusée.
	if ( preg_match( '/\.(php\d?|phtml|phar|exe|js|vbs|bat|cmd|sh|ps1|scr|jar|msi|com|html?|svg)\./i', $nom ) ) {
		return 'nom de fichier suspect';
	}
	if ( $taille <= 0 || $taille > TG_CONTACT_MAX_OCTETS ) {
		return 'fichier vide ou trop lourd (10 Mo max.)';
	}

	$mime = '';
	if ( function_exists( 'finfo_open' ) ) {
		$fi   = finfo_open( FILEINFO_MIME_TYPE );
		$mime = (string) finfo_file( $fi, $chemin );
		finfo_close( $fi );
	}
	if ( $mime !== '' && ! in_array( $mime, $formats[ $ext ], true ) ) {
		return 'le contenu ne correspond pas au format annoncé';
	}

	$debut = (string) file_get_contents( $chemin, false, null, 0, 4096 );

	switch ( $ext ) {
		case 'jpg':
		case 'jpeg':
		case 'png':
		case 'webp':
			$img = @getimagesize( $chemin );
			if ( ! $img || ! in_array( $img[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ), true ) ) {
				return 'image illisible';
			}
			if ( stripos( (string) file_get_contents( $chemin ), '<?php' ) !== false ) {
				return 'image contenant du code';
			}
			break;

		case 'pdf':
			if ( strpos( $debut, '%PDF-' ) !== 0 ) {
				return 'PDF illisible';
			}
			$pdf = (string) file_get_contents( $chemin );
			if ( preg_match( '#/(JavaScript|JS|Launch|EmbeddedFile|OpenAction\s*<<[^>]*?/S\s*/JavaScript|RichMedia|XFA)\b#', $pdf ) ) {
				return 'PDF contenant du code ou des fichiers intégrés';
			}
			break;

		case 'docx':
		case 'xlsx':
			$motif = tg_contact_verifier_office( $chemin, $ext );
			if ( $motif !== '' ) {
				return $motif;
			}
			break;

		case 'txt':
		case 'csv':
			$txt = (string) file_get_contents( $chemin );
			if ( strpos( $txt, "\0" ) !== false || strpos( $txt, 'MZ' ) === 0 || preg_match( '/<\?php|<script/i', $txt ) ) {
				return 'texte contenant du code';
			}
			break;
	}
	return '';
}

/** Un .docx / .xlsx est une archive : on refuse toute macro, objet ActiveX ou contenu binaire caché. */
function tg_contact_verifier_office( $chemin, $ext ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return 'vérification impossible sur ce serveur';
	}
	$zip = new ZipArchive();
	if ( $zip->open( $chemin ) !== true ) {
		return 'document illisible';
	}
	$types     = $zip->getFromName( '[Content_Types].xml' );
	$principal = $ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
	if ( $types === false || $zip->statName( $principal ) === false ) {
		$zip->close();
		return 'document Office non valide';
	}
	if ( stripos( $types, 'macroEnabled' ) !== false || stripos( $types, 'vbaProject' ) !== false ) {
		$zip->close();
		return 'document avec macros (refusé)';
	}
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$n = (string) $zip->getNameIndex( $i );
		if ( preg_match( '#vbaProject|vbaData|activeX|/embeddings/.*\.(bin|exe|dll|js|vbs|docm|xlsm)$|macrosheets|\.bin$#i', $n ) ) {
			$zip->close();
			return 'document avec macros ou objets intégrés (refusé)';
		}
	}
	$zip->close();
	return '';
}

/**
 * Traite le champ de fichiers du formulaire. Retourne [ fichiers enregistrés, erreurs ].
 */
function tg_contact_traiter_fichiers( $champ ) {
	$fichiers = array();
	$erreurs  = array();
	if ( empty( $_FILES[ $champ ] ) || ! is_array( $_FILES[ $champ ]['name'] ) ) {
		return array( $fichiers, $erreurs );
	}
	$f      = $_FILES[ $champ ];
	$total  = 0;
	$valides = array();

	foreach ( $f['name'] as $i => $nom ) {
		if ( (int) $f['error'][ $i ] === UPLOAD_ERR_NO_FILE || $nom === '' ) {
			continue;
		}
		$nom = sanitize_file_name( wp_unslash( $nom ) );
		if ( (int) $f['error'][ $i ] !== UPLOAD_ERR_OK ) {
			$erreurs[] = sprintf( '« %s » : envoi interrompu ou fichier trop lourd.', $nom );
			continue;
		}
		if ( ! is_uploaded_file( $f['tmp_name'][ $i ] ) ) {
			continue;
		}
		$valides[] = array( 'nom' => $nom, 'tmp' => $f['tmp_name'][ $i ], 'taille' => (int) $f['size'][ $i ] );
	}

	if ( count( $valides ) > TG_CONTACT_MAX_FICHIERS ) {
		$erreurs[] = sprintf( 'Vous pouvez joindre %d fichiers au maximum.', TG_CONTACT_MAX_FICHIERS );
		return array( array(), $erreurs );
	}

	foreach ( $valides as $v ) {
		$motif = tg_contact_verifier_fichier( $v['tmp'], $v['nom'], $v['taille'] );
		if ( $motif !== '' ) {
			$erreurs[] = sprintf( '« %s » refusé : %s.', $v['nom'], $motif );
			continue;
		}
		$total += $v['taille'];
	}
	if ( $total > TG_CONTACT_MAX_TOTAL ) {
		$erreurs[] = 'L\'ensemble des fichiers dépasse 25 Mo.';
	}
	if ( $erreurs ) {
		return array( array(), $erreurs );
	}

	$dir = tg_contact_dossier() . '/' . gmdate( 'Y-m' );
	wp_mkdir_p( $dir );
	foreach ( $valides as $v ) {
		$ext  = strtolower( pathinfo( $v['nom'], PATHINFO_EXTENSION ) );
		$dest = $dir . '/' . wp_generate_password( 24, false, false ) . '.' . $ext;
		if ( @move_uploaded_file( $v['tmp'], $dest ) ) {
			@chmod( $dest, 0640 );
			$fichiers[] = array( 'nom' => $v['nom'], 'chemin' => $dest, 'taille' => $v['taille'] );
		} else {
			$erreurs[] = sprintf( '« %s » n\'a pas pu être enregistré.', $v['nom'] );
		}
	}
	if ( $erreurs ) {
		foreach ( $fichiers as $fi ) {
			@unlink( $fi['chemin'] );
		}
		return array( array(), $erreurs );
	}
	return array( $fichiers, array() );
}

function tg_contact_supprimer_fichiers( $post_id, $garder_trace ) {
	$fichiers = get_post_meta( $post_id, '_tg_fichiers', true );
	if ( is_array( $fichiers ) ) {
		foreach ( $fichiers as $fi ) {
			if ( ! empty( $fi['chemin'] ) && strpos( realpath( $fi['chemin'] ) ?: '', realpath( tg_contact_dossier() ) ) === 0 ) {
				@unlink( $fi['chemin'] );
			}
		}
		if ( $garder_trace ) {
			$noms = wp_list_pluck( $fichiers, 'nom' );
			update_post_meta( $post_id, '_tg_fichiers_supprimes', $noms );
		}
	}
	delete_post_meta( $post_id, '_tg_fichiers' );
}

/** Téléchargement d'une pièce jointe, réservé aux personnes qui gèrent la boutique. */
add_action( 'admin_post_tg_contact_fichier', function () {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	$i  = isset( $_GET['i'] ) ? absint( $_GET['i'] ) : 0;
	if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'tg_fichier_' . $id ) ) {
		wp_die( 'Accès refusé.', '', array( 'response' => 403 ) );
	}
	$fichiers = get_post_meta( $id, '_tg_fichiers', true );
	if ( empty( $fichiers[ $i ]['chemin'] ) ) {
		wp_die( 'Fichier introuvable (peut-être supprimé après la durée de conservation).', '', array( 'response' => 404 ) );
	}
	$chemin = realpath( $fichiers[ $i ]['chemin'] );
	if ( ! $chemin || strpos( $chemin, realpath( tg_contact_dossier() ) ) !== 0 || ! is_readable( $chemin ) ) {
		wp_die( 'Fichier introuvable.', '', array( 'response' => 404 ) );
	}
	$nom  = $fichiers[ $i ]['nom'];
	$ext  = strtolower( pathinfo( $nom, PATHINFO_EXTENSION ) );
	$mime = tg_contact_formats()[ $ext ][0] ?? 'application/octet-stream';
	nocache_headers();
	header( 'Content-Type: ' . $mime );
	header( 'X-Content-Type-Options: nosniff' );
	header( 'Content-Disposition: attachment; filename="' . rawurlencode( $nom ) . '"; filename*=UTF-8\'\'' . rawurlencode( $nom ) );
	header( 'Content-Length: ' . filesize( $chemin ) );
	readfile( $chemin );
	exit;
} );

function tg_contact_lien_fichier( $post_id, $i ) {
	return wp_nonce_url( admin_url( 'admin-post.php?action=tg_contact_fichier&id=' . (int) $post_id . '&i=' . (int) $i ), 'tg_fichier_' . (int) $post_id );
}
