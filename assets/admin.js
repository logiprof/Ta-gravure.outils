/* Ta-gravure outils – choix d'une image dans la médiathèque. */
jQuery( function ( $ ) {
	$( document ).on( 'click', '.tgo-choisir-image', function ( e ) {
		e.preventDefault();
		var bloc = $( this ).closest( '.tgo-image' );
		var cadre = wp.media( { title: 'Choisir une image', button: { text: 'Utiliser cette image' }, library: { type: 'image' }, multiple: false } );
		cadre.on( 'select', function () {
			var img = cadre.state().get( 'selection' ).first().toJSON();
			var url = img.sizes && img.sizes.medium ? img.sizes.medium.url : img.url;
			bloc.find( 'input' ).val( img.id );
			bloc.find( 'img' ).attr( 'src', url ).show();
			bloc.find( '.description' ).text( 'N° ' + img.id );
		} );
		cadre.open();
	} );
	$( document ).on( 'click', '.tgo-retirer-image', function ( e ) {
		e.preventDefault();
		var bloc = $( this ).closest( '.tgo-image' );
		bloc.find( 'input' ).val( 0 );
		bloc.find( 'img' ).attr( 'src', '' ).hide();
		bloc.find( '.description' ).text( '' );
	} );
} );
