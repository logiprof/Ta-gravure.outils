<?php
/**
 * Filtre anti-démarchage : les messages publicitaires (SEO, prospection, IA, crypto…) sont classés
 * « Indésirable » sans envoyer d'e-mail, ni à la boutique ni à l'expéditeur.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TG_CONTACT_SEUIL_SPAM = 5;

/** Expressions typiques du démarchage commercial (anglais et français). */
function tg_contact_mots_spam() {
	return array(
		'seo', 'backlink', 'lead generation', 'leads', 'lead ', 'marketing', 'ai agent', 'voice agent', 'chatbot', 'ai-powered',
		'ranking', 'first page of google', 'google ranking', 'traffic', 'web design', 'website redesign', 'redesign your website',
		'guest post', 'sponsored post', 'crypto', 'bitcoin', 'forex', 'investment opportunity', 'loan', 'casino', 'viagra',
		'outsourcing', 'virtual assistant', 'hey team', 'quick question', 'unsubscribe', 'opt out', 'book a call', 'schedule a call',
		'calendly', 'free trial', 'increase your sales', 'grow your business', 'appointment setting', 'cold email', 'b2b',
		'référencement', 'première page de google', 'prospection', 'campagne publicitaire', 'agence digitale', 'site internet professionnel',
		'augmenter vos ventes', 'devis gratuit pour votre site', 'rendez-vous qualifiés',
	);
}

/**
 * Évalue un message. Retourne [ score, raisons ].
 */
function tg_contact_score_spam( $d ) {
	$score   = 0;
	$raisons = array();
	$texte   = mb_strtolower( $d['message'] . ' ' . $d['nom'] . ' ' . $d['entreprise'] );
	$email   = mb_strtolower( $d['email'] );
	$domaine = substr( strrchr( $email, '@' ), 1 );

	// Liste noire réglable (adresses, @domaines, mots).
	foreach ( tg_contact_liste_noire() as $entree ) {
		if ( $entree === '' ) {
			continue;
		}
		if ( $entree[0] === '@' && $domaine === substr( $entree, 1 ) ) {
			$score    += 10;
			$raisons[] = 'domaine en liste noire (' . $entree . ')';
		} elseif ( strpos( $entree, '@' ) !== false && $entree === $email ) {
			$score    += 10;
			$raisons[] = 'adresse en liste noire';
		} elseif ( strpos( $entree, '@' ) === false && mb_strpos( $texte, $entree ) !== false ) {
			$score    += 5;
			$raisons[] = 'mot en liste noire (' . $entree . ')';
		}
	}

	// Liens dans le message.
	$liens = preg_match_all( '#(https?://|www\.)\S+#i', $d['message'] );
	if ( $liens ) {
		$score    += min( 4, 2 * $liens );
		$raisons[] = $liens . ' lien(s)';
	}

	// Vocabulaire de démarchage.
	$trouves = array();
	foreach ( tg_contact_mots_spam() as $mot ) {
		if ( preg_match( '/(^|[^a-zà-ÿ])' . preg_quote( trim( $mot ), '/' ) . '([^a-zà-ÿ]|$)/u', $texte ) ) {
			$trouves[] = trim( $mot );
		}
	}
	$trouves = array_unique( $trouves );
	if ( $trouves ) {
		$score    += min( 6, 2 + count( $trouves ) );
		$raisons[] = 'démarchage : ' . implode( ', ', array_slice( $trouves, 0, 6 ) );
	}

	// Message entièrement en anglais (le site est en français).
	$mots = preg_split( '/[^a-zà-ÿ\']+/u', $texte, -1, PREG_SPLIT_NO_EMPTY );
	$en   = count( array_intersect( $mots, array( 'the', 'and', 'you', 'your', 'we', 'our', 'with', 'for', 'this', 'that', 'is', 'are', 'would', 'can', 'will', 'they', 'them' ) ) );
	$fr   = count( array_intersect( $mots, array( 'le', 'la', 'les', 'de', 'des', 'et', 'vous', 'votre', 'nous', 'pour', 'une', 'est', 'je', 'avec', 'du', 'mon', 'ma', 'merci', 'bonjour' ) ) );
	if ( $en >= 6 && $en > 3 * $fr ) {
		$score    += 2;
		$raisons[] = 'message en anglais';
	}

	// Écritures étrangères au site (cyrillique, chinois…).
	if ( preg_match( '/[\x{0400}-\x{04FF}\x{4E00}-\x{9FFF}\x{0600}-\x{06FF}]/u', $texte ) ) {
		$score    += 3;
		$raisons[] = 'alphabet inhabituel';
	}

	return array( $score, $raisons );
}

function tg_contact_liste_noire() {
	$brut = (string) ( tg_contact_reglages()['liste_noire'] ?? '' );
	return array_values( array_filter( array_map( function ( $l ) {
		return mb_strtolower( trim( $l ) );
	}, preg_split( '/\r\n|\r|\n/', $brut ) ) ) );
}

/** Quand une demande est marquée « Indésirable » à la main, son adresse rejoint la liste noire. */
function tg_contact_ajouter_liste_noire( $email ) {
	$email = mb_strtolower( trim( (string) $email ) );
	if ( ! is_email( $email ) ) {
		return;
	}
	$r = tg_contact_reglages(); // réglages complets : ne réinitialise rien d'autre
	$l = (string) $r['liste_noire'];
	if ( ! in_array( $email, tg_contact_liste_noire(), true ) ) {
		$r['liste_noire'] = trim( $l . "\n" . $email );
		update_option( 'tg_contact_reglages', $r );
	}
}
