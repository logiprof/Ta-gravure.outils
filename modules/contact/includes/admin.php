<?php
/**
 * Administration : historique des demandes, statuts, notes internes, réglages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function tg_contact_statut( $id ) {
	$s = get_post_meta( $id, '_tg_statut', true );
	return isset( tg_contact_statuts()[ $s ] ) ? $s : 'nouvelle';
}

function tg_contact_badge( $statut ) {
	$s = tg_contact_statuts()[ $statut ];
	return sprintf(
		'<span style="display:inline-block;padding:3px 10px;border-radius:20px;background:%1$s1a;color:%1$s;border:1px solid %1$s55;font-weight:600;font-size:12px;white-space:nowrap">%2$s</span>',
		esc_attr( $s[1] ),
		esc_html( $s[0] )
	);
}

/* ---------- Pastille du nombre de nouvelles demandes dans le menu ---------- */

add_action( 'admin_menu', function () {
	global $menu;
	$nb = (int) ( new WP_Query( array(
		'post_type'      => 'tg_demande',
		'post_status'    => 'publish',
		'fields'         => 'ids',
		'posts_per_page' => 1,
		'meta_key'       => '_tg_statut',
		'meta_value'     => 'nouvelle',
		'no_found_rows'  => false,
	) ) )->found_posts;
	if ( ! $nb ) {
		return;
	}
	foreach ( $menu as $k => $item ) {
		if ( isset( $item[2] ) && $item[2] === 'edit.php?post_type=tg_demande' ) {
			$menu[ $k ][0] .= ' <span class="awaiting-mod"><span class="pending-count">' . $nb . '</span></span>';
		}
	}
}, 99 );

/* ---------- Liste des demandes ---------- */

add_filter( 'manage_tg_demande_posts_columns', function () {
	return array(
		'cb'         => '<input type="checkbox" />',
		'tg_statut'  => 'Statut',
		'title'      => 'Demande',
		'tg_client'  => 'Client',
		'tg_fichiers'=> 'Fichiers',
		'tg_mail'    => 'E-mail reçu',
		'date'       => 'Reçue le',
	);
} );

add_action( 'manage_tg_demande_posts_custom_column', function ( $col, $id ) {
	switch ( $col ) {
		case 'tg_statut':
			echo tg_contact_badge( tg_contact_statut( $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			break;
		case 'tg_client':
			$email = get_post_meta( $id, '_tg_email', true );
			echo esc_html( get_post_meta( $id, '_tg_nom', true ) ) . '<br><a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
			$ent = get_post_meta( $id, '_tg_entreprise', true );
			if ( $ent ) {
				echo '<br><em>' . esc_html( $ent ) . '</em>';
			}
			break;
		case 'tg_fichiers':
			$f = get_post_meta( $id, '_tg_fichiers', true );
			echo is_array( $f ) && $f ? '📎 ' . count( $f ) : '—';
			break;
		case 'tg_mail':
			$m = get_post_meta( $id, '_tg_mail_envoye', true );
			echo $m === 'non' ? '<span style="color:#B3261E;font-weight:600">Échec d\'envoi</span>' : ( $m === 'filtre' ? '<span style="color:#777">Filtré</span>' : 'Oui' );
			break;
	}
}, 10, 2 );

add_filter( 'manage_edit-tg_demande_sortable_columns', function ( $c ) {
	$c['tg_statut'] = 'tg_statut';
	return $c;
} );

/* Filtre par statut au-dessus de la liste */
add_action( 'restrict_manage_posts', function ( $post_type ) {
	if ( $post_type !== 'tg_demande' ) {
		return;
	}
	$actuel = isset( $_GET['tg_statut'] ) ? sanitize_key( wp_unslash( $_GET['tg_statut'] ) ) : '';
	echo '<select name="tg_statut"><option value="">Tous les statuts</option>';
	echo '<option value="a_traiter"' . selected( $actuel, 'a_traiter', false ) . '>À traiter (nouvelles, en cours, en attente)</option>';
	foreach ( tg_contact_statuts() as $k => $s ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $actuel, $k, false ), esc_html( $s[0] ) );
	}
	echo '</select>';
	$type = isset( $_GET['tg_type'] ) ? sanitize_key( wp_unslash( $_GET['tg_type'] ) ) : '';
	echo '<select name="tg_type"><option value="">Tous les types</option>';
	foreach ( tg_contact_types() as $k => $lib ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $type, $k, false ), esc_html( $lib ) );
	}
	echo '</select>';
} );

add_action( 'pre_get_posts', function ( $q ) {
	if ( ! is_admin() || ! $q->is_main_query() || $q->get( 'post_type' ) !== 'tg_demande' ) {
		return;
	}
	$mq = array();
	$s  = isset( $_GET['tg_statut'] ) ? sanitize_key( wp_unslash( $_GET['tg_statut'] ) ) : '';
	if ( $s === 'a_traiter' ) {
		$mq[] = array( 'key' => '_tg_statut', 'value' => array( 'nouvelle', 'en_cours', 'attente' ), 'compare' => 'IN' );
	} elseif ( $s && isset( tg_contact_statuts()[ $s ] ) ) {
		$mq[] = array( 'key' => '_tg_statut', 'value' => $s );
	}
	$t = isset( $_GET['tg_type'] ) ? sanitize_key( wp_unslash( $_GET['tg_type'] ) ) : '';
	if ( $t && isset( tg_contact_types()[ $t ] ) ) {
		$mq[] = array( 'key' => '_tg_type', 'value' => $t );
	}
	if ( $mq ) {
		$q->set( 'meta_query', $mq );
	}
	if ( $q->get( 'orderby' ) === 'tg_statut' ) {
		$q->set( 'meta_key', '_tg_statut' );
		$q->set( 'orderby', 'meta_value' );
	}
	// La recherche porte aussi sur le nom, l'e-mail et l'entreprise du client.
	$search = $q->get( 's' );
	if ( $search ) {
		$ids = get_posts( array(
			'post_type'      => 'tg_demande',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 200,
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => '_tg_nom', 'value' => $search, 'compare' => 'LIKE' ),
				array( 'key' => '_tg_email', 'value' => $search, 'compare' => 'LIKE' ),
				array( 'key' => '_tg_entreprise', 'value' => $search, 'compare' => 'LIKE' ),
				array( 'key' => '_tg_commande', 'value' => $search, 'compare' => 'LIKE' ),
			),
		) );
		if ( $ids ) {
			$q->set( 's', '' );
			$q->set( 'post__in', $ids );
		}
	}
} );

/* Actions groupées : changer le statut de plusieurs demandes */
add_filter( 'bulk_actions-edit-tg_demande', function ( $a ) {
	unset( $a['edit'] );
	foreach ( tg_contact_statuts() as $k => $s ) {
		$a[ 'tg_statut_' . $k ] = 'Marquer : ' . $s[0];
	}
	$a['tg_spam'] = 'Indésirable : supprimer et bloquer';
	return $a;
} );

add_filter( 'handle_bulk_actions-edit-tg_demande', function ( $url, $action, $ids ) {
	if ( $action === 'tg_spam' ) {
		$n = 0;
		foreach ( $ids as $id ) {
			if ( current_user_can( 'delete_post', $id ) ) {
				tg_contact_supprimer_spam( $id );
				$n++;
			}
		}
		return add_query_arg( 'tg_spam_ok', $n, remove_query_arg( 'tg_maj', $url ) );
	}
	if ( strpos( $action, 'tg_statut_' ) !== 0 ) {
		return $url;
	}
	$nouveau = substr( $action, 10 );
	if ( ! isset( tg_contact_statuts()[ $nouveau ] ) ) {
		return $url;
	}
	foreach ( $ids as $id ) {
		if ( current_user_can( 'edit_post', $id ) ) {
			tg_contact_changer_statut( $id, $nouveau, '' );
		}
	}
	return add_query_arg( 'tg_maj', count( $ids ), $url );
}, 10, 3 );

add_filter( 'post_row_actions', function ( $actions, $post ) {
	if ( $post->post_type === 'tg_demande' ) {
		unset( $actions['inline hide-if-no-js'], $actions['view'] );
		$actions['edit']    = '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">Ouvrir et traiter</a>';
		$actions['tg_spam'] = '<a href="' . esc_url( tg_contact_lien_spam( $post->ID ) ) . '" style="color:#b32d2e" onclick="return confirm(\'Supprimer définitivement cette demande et bloquer son adresse ?\')">Indésirable : supprimer et bloquer</a>';
	}
	return $actions;
}, 10, 2 );

function tg_contact_changer_statut( $id, $nouveau, $note ) {
	$ancien = tg_contact_statut( $id );
	if ( $ancien === $nouveau && $note === '' ) {
		return;
	}
	update_post_meta( $id, '_tg_statut', $nouveau );
	$h   = get_post_meta( $id, '_tg_historique', true );
	$h   = is_array( $h ) ? $h : array();
	$u   = wp_get_current_user();
	$h[] = array(
		'date'   => current_time( 'mysql' ),
		'qui'    => $u ? $u->display_name : '',
		'de'     => $ancien,
		'vers'   => $nouveau,
		'note'   => $note,
	);
	update_post_meta( $id, '_tg_historique', $h );
}

/* ---------- Écran « Traiter la demande » ---------- */

add_action( 'add_meta_boxes_tg_demande', function () {
	remove_meta_box( 'submitdiv', 'tg_demande', 'side' );
	remove_meta_box( 'slugdiv', 'tg_demande', 'normal' );
	add_meta_box( 'tg_details', 'Demande du client', 'tg_contact_box_details', 'tg_demande', 'normal', 'high' );
	add_meta_box( 'tg_suivi', 'Traitement', 'tg_contact_box_suivi', 'tg_demande', 'side', 'high' );
	add_meta_box( 'tg_histo', 'Historique du traitement', 'tg_contact_box_histo', 'tg_demande', 'normal', 'default' );
} );

/* Le titre n'est pas modifiable : c'est un historique. */
add_action( 'admin_head-post.php', function () {
	if ( get_current_screen() && get_current_screen()->post_type === 'tg_demande' ) {
		echo '<style>#titlediv #title{background:#f6f7f7;pointer-events:none}#edit-slug-box,#post-preview,.page-title-action{display:none}</style>';
	}
} );

function tg_contact_box_details( $post ) {
	$m = function ( $k ) use ( $post ) {
		return get_post_meta( $post->ID, '_tg_' . $k, true );
	};
	$types = tg_contact_types();
	$rows  = array(
		'Reçue le'     => get_the_date( 'j F Y à H:i', $post ),
		'Type'         => $types[ $m( 'type' ) ] ?? $m( 'type' ),
		'Nom'          => $m( 'nom' ),
		'E-mail'       => '<a href="mailto:' . esc_attr( $m( 'email' ) ) . '?subject=' . rawurlencode( 'Re : votre demande – ta-gravure.art' ) . '">' . esc_html( $m( 'email' ) ) . '</a>',
		'Téléphone'    => $m( 'tel' ) ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $m( 'tel' ) ) ) . '">' . esc_html( $m( 'tel' ) ) . '</a>' : '',
		'Entreprise'   => $m( 'entreprise' ),
		'Quantité'     => $m( 'quantite' ) ? (string) $m( 'quantite' ) : '',
		'N° commande'  => $m( 'commande' ),
		'Produit'      => $m( 'produit' ) ? '<a href="' . esc_url( get_permalink( $m( 'produit' ) ) ) . '" target="_blank">' . esc_html( get_the_title( $m( 'produit' ) ) ) . '</a>' : '',
		'Consentement' => $m( 'consent' ) ? 'Donné le ' . esc_html( mysql2date( 'j.m.Y H:i', $m( 'consent' ) ) ) : '',
		'E-mail reçu'  => $m( 'mail_envoye' ) === 'filtre' ? 'Non envoyé : message classé indésirable' : ( $m( 'mail_envoye' ) === 'non' ? '<strong style="color:#B3261E">Non — l\'envoi de l\'e-mail a échoué, la demande n\'est visible qu\'ici.</strong>' . ( $m( 'mail_erreur' ) ? '<br><code>' . esc_html( $m( 'mail_erreur' ) ) . '</code>' : '' ) : 'Oui' ),
	);
	echo '<table class="widefat striped" style="border:0"><tbody>';
	foreach ( $rows as $lib => $val ) {
		if ( $val === '' || $val === null ) {
			continue;
		}
		$safe = in_array( $lib, array( 'E-mail', 'Téléphone', 'Produit', 'E-mail reçu' ), true ) ? $val : esc_html( $val );
		echo '<tr><th style="width:160px">' . esc_html( $lib ) . '</th><td>' . $safe . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</tbody></table>';

	echo '<h3 style="margin:20px 0 8px">Message</h3>';
	echo '<div style="background:#fffbf4;border:1px solid #e3d6c1;border-radius:8px;padding:14px 16px;white-space:pre-wrap;font-size:14px;line-height:1.6">' . esc_html( $post->post_content ) . '</div>';

	$f = get_post_meta( $post->ID, '_tg_fichiers', true );
	echo '<h3 style="margin:20px 0 8px">Pièces jointes</h3>';
	if ( is_array( $f ) && $f ) {
		echo '<ul style="margin:0">';
		foreach ( $f as $i => $fi ) {
			printf(
				'<li>📎 <a href="%s">%s</a> <span style="color:#777">(%s)</span></li>',
				esc_url( tg_contact_lien_fichier( $post->ID, $i ) ),
				esc_html( $fi['nom'] ),
				esc_html( size_format( $fi['taille'] ) )
			);
		}
		echo '</ul>';
		printf( '<p class="description">Les fichiers sont supprimés automatiquement %d jours après la demande.</p>', (int) tg_contact_reglages()['conservation'] );
	} else {
		$sup = get_post_meta( $post->ID, '_tg_fichiers_supprimes', true );
		echo $sup ? '<p class="description">Supprimés après la durée de conservation : ' . esc_html( implode( ', ', (array) $sup ) ) . '</p>' : '<p class="description">Aucune.</p>';
	}
}

function tg_contact_box_suivi( $post ) {
	wp_nonce_field( 'tg_suivi_' . $post->ID, 'tg_suivi_nonce' );
	$actuel = tg_contact_statut( $post->ID );
	echo '<p><label for="tg_statut_sel"><strong>Statut</strong></label><br><select id="tg_statut_sel" name="tg_statut_sel" style="width:100%">';
	foreach ( tg_contact_statuts() as $k => $s ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $actuel, $k, false ), esc_html( $s[0] ) );
	}
	echo '</select></p>';
	echo '<p><label for="tg_note"><strong>Note interne</strong> <span class="description">(visible seulement ici)</span></label>';
	echo '<textarea id="tg_note" name="tg_note" rows="4" style="width:100%" placeholder="Ex. : devis envoyé le 12.11, relancer vendredi"></textarea></p>';
	echo '<p><button type="submit" class="button button-primary button-large" style="width:100%">Enregistrer</button></p>';
	$email = get_post_meta( $post->ID, '_tg_email', true );
	if ( $email ) {
		printf(
			'<p><a class="button" style="width:100%%;text-align:center" href="mailto:%s?subject=%s">Répondre au client par e-mail</a></p>',
			esc_attr( $email ),
			rawurlencode( 'Re : votre demande – ta-gravure.art' )
		);
	}
	echo '<p style="margin-top:14px"><a href="' . esc_url( get_delete_post_link( $post->ID ) ) . '" style="color:#b32d2e">Mettre à la corbeille</a></p>';
	echo '<p><a href="' . esc_url( tg_contact_lien_spam( $post->ID ) ) . '" style="color:#b32d2e" onclick="return confirm(\'Supprimer définitivement cette demande et bloquer son adresse ?\')">Indésirable : supprimer et bloquer l\'adresse</a></p>';
}

function tg_contact_box_histo( $post ) {
	$h = get_post_meta( $post->ID, '_tg_historique', true );
	if ( ! is_array( $h ) || ! $h ) {
		echo '<p class="description">Aucun changement pour l\'instant : la demande est « Nouvelle ».</p>';
		return;
	}
	$st = tg_contact_statuts();
	echo '<table class="widefat striped"><thead><tr><th style="width:140px">Date</th><th style="width:120px">Par</th><th style="width:220px">Statut</th><th>Note</th></tr></thead><tbody>';
	foreach ( array_reverse( $h ) as $l ) {
		$changement = $l['de'] !== $l['vers'] ? esc_html( $st[ $l['de'] ][0] ?? $l['de'] ) . ' → ' . tg_contact_badge( isset( $st[ $l['vers'] ] ) ? $l['vers'] : 'nouvelle' ) : '—';
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td style="white-space:pre-wrap">%s</td></tr>',
			esc_html( mysql2date( 'j.m.Y H:i', $l['date'] ) ),
			esc_html( $l['qui'] ),
			$changement, // phpcs:ignore WordPress.Security.EscapeOutput
			esc_html( $l['note'] )
		);
	}
	echo '</tbody></table>';
}

add_action( 'save_post_tg_demande', function ( $id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( empty( $_POST['tg_suivi_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tg_suivi_nonce'] ) ), 'tg_suivi_' . $id ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return;
	}
	$nouveau = isset( $_POST['tg_statut_sel'] ) ? sanitize_key( wp_unslash( $_POST['tg_statut_sel'] ) ) : '';
	$note    = isset( $_POST['tg_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['tg_note'] ) ) : '';
	if ( isset( tg_contact_statuts()[ $nouveau ] ) ) {
		tg_contact_changer_statut( $id, $nouveau, $note );
	}
} );

/* Le formulaire d'édition ne doit jamais modifier le message ni le titre d'origine. */
add_filter( 'wp_insert_post_data', function ( $data, $postarr ) {
	if ( $data['post_type'] === 'tg_demande' && ! empty( $postarr['ID'] ) ) {
		$orig = get_post( $postarr['ID'] );
		if ( $orig ) {
			$data['post_title']   = $orig->post_title;
			$data['post_content'] = $orig->post_content;
			// Une demande est soit visible (« publish »), soit à la corbeille.
			$data['post_status']  = $data['post_status'] === 'trash' ? 'trash' : 'publish';
		}
	}
	return $data;
}, 10, 2 );

add_filter( 'post_updated_messages', function ( $m ) {
	$m['tg_demande'] = array_fill( 0, 11, 'Demande mise à jour.' );
	return $m;
} );

/* ---------- Réglages ---------- */

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=tg_demande', 'Réglages du formulaire', 'Réglages', 'manage_options', 'tg-contact-reglages', 'tg_contact_page_reglages' );
} );

add_action( 'admin_init', function () {
	register_setting( 'tg_contact', 'tg_contact_reglages', array(
		'type'              => 'array',
		'sanitize_callback' => function ( $in ) {
			$in = is_array( $in ) ? $in : array();
			return array(
				'destinataire' => is_email( $in['destinataire'] ?? '' ) ? sanitize_email( $in['destinataire'] ) : tg_contact_reglages()['destinataire'],
				'expediteur'   => is_email( $in['expediteur'] ?? '' ) ? sanitize_email( $in['expediteur'] ) : tg_contact_reglages()['destinataire'],
				'delai'        => sanitize_text_field( $in['delai'] ?? '' ) ?: '48 heures (jours ouvrables)',
				'conservation' => max( 7, min( 730, absint( $in['conservation'] ?? 90 ) ) ),
				'filtre_spam'  => empty( $in['filtre_spam'] ) ? 0 : 1,
				'liste_noire'  => sanitize_textarea_field( $in['liste_noire'] ?? '' ),
			);
		},
	) );
} );

function tg_contact_page_reglages() {
	$r = tg_contact_reglages();
	?>
	<div class="wrap">
		<h1>Réglages du formulaire de contact</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'tg_contact' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="tg_dest">E-mail qui reçoit les demandes</label></th>
					<td><input type="email" id="tg_dest" class="regular-text" name="tg_contact_reglages[destinataire]" value="<?php echo esc_attr( $r['destinataire'] ); ?>"></td></tr>
				<tr><th><label for="tg_exp">Adresse d'expédition</label></th>
					<td><input type="email" id="tg_exp" class="regular-text" name="tg_contact_reglages[expediteur]" value="<?php echo esc_attr( $r['expediteur'] ); ?>">
						<p class="description">Doit être une vraie boîte de votre domaine chez Infomaniak (ex. carlos@ta-gravure.art), sinon les e-mails sont rejetés.</p></td></tr>
				<tr><th><label for="tg_delai">Délai de réponse annoncé</label></th>
					<td><input type="text" id="tg_delai" class="regular-text" name="tg_contact_reglages[delai]" value="<?php echo esc_attr( $r['delai'] ); ?>">
						<p class="description">Affiché sous le formulaire et dans l'e-mail de confirmation au client.</p></td></tr>
				<tr><th><label for="tg_cons">Conservation des fichiers (jours)</label></th>
					<td><input type="number" id="tg_cons" min="7" max="730" name="tg_contact_reglages[conservation]" value="<?php echo (int) $r['conservation']; ?>">
						<p class="description">Les pièces jointes sont ensuite supprimées ; la demande et son historique restent.</p></td></tr>
				<tr><th>Filtre anti-démarchage</th>
					<td><label><input type="hidden" name="tg_contact_reglages[filtre_spam]" value="0"><input type="checkbox" name="tg_contact_reglages[filtre_spam]" value="1" <?php checked( ! empty( $r['filtre_spam'] ) ); ?>>
						Supprimer automatiquement les messages publicitaires (SEO, prospection, IA, crypto, liens…)</label>
						<p class="description">Rien n'est enregistré et aucun e-mail n'est envoyé, ni à vous ni à l'expéditeur. Une ligne reste dans le journal ci-dessous (adresse, raison, début du message) pour retrouver un vrai client filtré par erreur.</p></td></tr>
				<tr><th><label for="tg_ln">Liste noire</label></th>
					<td><textarea id="tg_ln" name="tg_contact_reglages[liste_noire]" rows="6" class="large-text code" placeholder="spam@exemple.com&#10;@domaine-indesirable.com&#10;mot interdit"><?php echo esc_textarea( $r['liste_noire'] ); ?></textarea>
						<p class="description">Une entrée par ligne : une adresse e-mail, un domaine précédé de @, ou un mot. Le lien « Indésirable : supprimer et bloquer » d'une demande ajoute son adresse ici automatiquement.</p></td></tr>
			</table>
			<?php submit_button( 'Enregistrer' ); ?>
		</form>
		<hr>
		<h2>Tester l'envoi des e-mails</h2>
		<?php
		if ( isset( $_GET['tg_test'] ) ) {
			$ok_test = $_GET['tg_test'] === 'ok';
			$err     = get_transient( 'tg_contact_test_erreur' );
			printf(
				'<div class="notice notice-%s"><p>%s</p></div>',
				$ok_test ? 'success' : 'error',
				$ok_test ? 'WordPress a bien remis l\'e-mail de test au serveur d\'envoi. S\'il n\'arrive pas dans quelques minutes (pensez aux indésirables), le problème est chez le fournisseur d\'envoi : vérifiez WP Mail SMTP.' : '<strong>L\'e-mail de test n\'est pas parti.</strong> Motif : <code>' . esc_html( (string) $err ) . '</code>'
			);
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tg_contact_test">
			<?php wp_nonce_field( 'tg_contact_test' ); ?>
			<p>Envoie un exemple de l'e-mail de confirmation (mis en page) à <strong><?php echo esc_html( $r['destinataire'] ); ?></strong> depuis <strong><?php echo esc_html( $r['expediteur'] ); ?></strong> et affiche l'erreur exacte en cas d'échec.</p>
			<?php submit_button( 'Envoyer un e-mail de test', 'secondary', 'submit', false ); ?>
		</form>
		<h2 style="margin-top:30px">Journal de diagnostic (40 derniers événements)</h2>
		<?php tg_contact_afficher_journal(); ?>
		<p>Code court à placer dans une page : <code>[tg_contact]</code> · Lien qui présélectionne un devis entreprise : <code><?php echo esc_html( home_url( '/contact/?type=devis' ) ); ?></code></p>
	</div>
	<?php
}


/* ---------- Diagnostic : test d'envoi, journal, alerte ---------- */

add_action( 'admin_post_tg_contact_test', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'tg_contact_test' ) ) {
		wp_die( 'Accès refusé.' );
	}
	$r = tg_contact_reglages();
	// Exemple réel de la confirmation client (mise en page HTML + version texte).
	$exemple = array( 'nom' => 'Client Test', 'email' => $r['destinataire'], 'type' => 'commande', 'entreprise' => '', 'quantite' => 0, 'commande' => '', 'produit' => 0, 'message' => "Ceci est un test du formulaire de contact.\nS'il vous parvient, les demandes et confirmations partiront aussi." );
	$texte   = "Test du formulaire de contact – ta-gravure.art\n\n" . $exemple['message'] . "\n\n" . home_url( '/' );
	// Test volontairement SANS secours texte : il montre si la version mise en page passe chez Infomaniak.
	list( $ok, $err ) = tg_contact_envoyer(
		$r['destinataire'],
		'Test du formulaire de contact – ta-gravure.art',
		tg_contact_html_confirmation( $exemple, tg_contact_types()['commande'], array(), $r ),
		array( 'Content-Type: text/html; charset=UTF-8' )
	);
	if ( ! $ok ) {
		// Second essai en texte simple, pour distinguer « mise en page refusée » de « aucun e-mail ne part ».
		list( $ok_txt, $err_txt ) = tg_contact_envoyer( $r['destinataire'], 'Test (texte simple) – ta-gravure.art', $texte );
		$err .= $ok_txt ? ' — En revanche, la version texte simple est bien partie : les demandes vous parviendront en texte.' : ' — La version texte simple échoue aussi : ' . $err_txt;
	}
	set_transient( 'tg_contact_test_erreur', $err, 10 * MINUTE_IN_SECONDS );
	wp_safe_redirect( admin_url( 'edit.php?post_type=tg_demande&page=tg-contact-reglages&tg_test=' . ( $ok ? 'ok' : 'echec' ) ) );
	exit;
} );

function tg_contact_afficher_journal() {
	$j = get_option( 'tg_contact_journal', array() );
	if ( ! is_array( $j ) || ! $j ) {
		echo '<p class="description">Aucun événement pour l\'instant.</p>';
		return;
	}
	$lib = array(
		'demande'    => array( 'Demande enregistrée', '#2E7D4F' ),
		'mail_ok'    => array( 'E-mail envoyé', '#2E7D4F' ),
		'mail_echec' => array( 'E-mail en échec', '#B3261E' ),
		'mail_secours' => array( 'Renvoyé en texte', '#8C5E22' ),
		'robot'      => array( 'Envoi écarté (robot)', '#8C5E22' ),
		'limite'     => array( 'Limite atteinte', '#8C5E22' ),
		'spam'       => array( 'Supprimé (démarchage)', '#777777' ),
		'bloque'     => array( 'Supprimé et bloqué', '#777777' ),
	);
	echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th style="width:140px">Date</th><th style="width:170px">Événement</th><th>Détail</th></tr></thead><tbody>';
	foreach ( $j as $l ) {
		$x = $lib[ $l['type'] ] ?? array( $l['type'], '#555' );
		printf(
			'<tr><td>%s</td><td><strong style="color:%s">%s</strong></td><td>%s</td></tr>',
			esc_html( mysql2date( 'j.m.Y H:i', $l['date'] ) ),
			esc_attr( $x[1] ),
			esc_html( $x[0] ),
			esc_html( $l['detail'] )
		);
	}
	echo '</tbody></table>';
}

/* Alerte visible dans l'administration tant que le dernier envoi a échoué. */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	$e = get_option( 'tg_contact_derniere_erreur' );
	if ( ! $e ) {
		return;
	}
	printf(
		'<div class="notice notice-error"><p><strong>Formulaire de contact : les e-mails ne partent pas</strong> (dernier échec le %s). Motif : <code>%s</code><br>Les demandes restent consultables dans <a href="%s">Demandes clients</a>. Vérifiez la configuration de WP Mail SMTP puis faites un <a href="%s">e-mail de test</a>.</p></div>',
		esc_html( mysql2date( 'j.m.Y H:i', $e['date'] ) ),
		esc_html( $e['detail'] ),
		esc_url( admin_url( 'edit.php?post_type=tg_demande' ) ),
		esc_url( admin_url( 'edit.php?post_type=tg_demande&page=tg-contact-reglages' ) )
	);
} );


/* ---------- Indésirables : suppression définitive + liste noire ---------- */

function tg_contact_supprimer_spam( $id ) {
	$email = get_post_meta( $id, '_tg_email', true );
	tg_contact_ajouter_liste_noire( $email );
	tg_contact_journal( 'bloque', $email . ' · « ' . mb_substr( preg_replace( '/\s+/', ' ', (string) get_post_field( 'post_content', $id ) ), 0, 120 ) . '… »' );
	tg_contact_supprimer_fichiers( $id, false );
	wp_delete_post( $id, true );
}

function tg_contact_lien_spam( $id ) {
	return wp_nonce_url( admin_url( 'admin-post.php?action=tg_contact_spam&id=' . (int) $id ), 'tg_spam_' . (int) $id );
}

add_action( 'admin_post_tg_contact_spam', function () {
	$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	if ( ! $id || get_post_type( $id ) !== 'tg_demande' || ! current_user_can( 'delete_post', $id ) || ! check_admin_referer( 'tg_spam_' . $id ) ) {
		wp_die( 'Accès refusé.' );
	}
	tg_contact_supprimer_spam( $id );
	wp_safe_redirect( admin_url( 'edit.php?post_type=tg_demande&tg_spam_ok=1' ) );
	exit;
} );

add_action( 'admin_notices', function () {
	if ( isset( $_GET['tg_spam_ok'] ) && get_current_screen() && get_current_screen()->post_type === 'tg_demande' ) {
		printf( '<div class="notice notice-success is-dismissible"><p>%d demande(s) supprimée(s) ; adresse(s) ajoutée(s) à la liste noire.</p></div>', absint( $_GET['tg_spam_ok'] ) );
	}
} );
