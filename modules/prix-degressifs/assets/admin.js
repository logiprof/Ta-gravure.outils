jQuery(function ($) {
	// Ajouter un palier.
	$(document).on('click', '.tgpd-add', function (e) {
		e.preventDefault();
		var $wrap = $(this).closest('.tgpd-repeater');
		var tpl = $wrap.find('template.tgpd-row-template').get(0);
		var html = tpl && tpl.innerHTML ? tpl.innerHTML : '';
		var $rows = $wrap.find('tbody');
		$rows.append(html);

		// Suggestion de quantité : palier précédent + 6.
		var $mins = $rows.find('input[name$="[min][]"]');
		if ($mins.length > 1) {
			var prev = parseInt($mins.eq($mins.length - 2).val(), 10);
			if (!isNaN(prev)) {
				$mins.last().attr('placeholder', 'ex. ' + (prev + 6));
			}
		}
		$mins.last().trigger('focus');
	});

	// Supprimer un palier.
	$(document).on('click', '.tgpd-remove', function (e) {
		e.preventDefault();
		$(this).closest('tr').remove();
	});

	// Choix du mode sur la fiche produit.
	function toggleMode() {
		var mode = $('#tgpd_mode').val();
		$('.tgpd-custom-wrap').toggle(mode === 'custom');
		$('.tgpd-inherited').toggle(mode === 'category');
	}
	$(document).on('change', '#tgpd_mode', toggleMode);
	toggleMode();

	// Après l'ajout d'une catégorie (AJAX), vider les paliers saisis.
	$(document).ajaxComplete(function (event, xhr, settings) {
		if (settings && typeof settings.data === 'string' && settings.data.indexOf('action=add-tag') !== -1) {
			$('#addtag .tgpd-repeater tbody').empty();
		}
	});
});
