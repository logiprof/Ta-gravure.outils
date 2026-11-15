(function ($) {
	'use strict';

	var P = window.tgpdParams || {};

	function formatMoney(amount) {
		var decimals = parseInt(P.decimals, 10);
		if (isNaN(decimals)) { decimals = 2; }
		var fixed = Math.abs(amount).toFixed(decimals);
		var parts = fixed.split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, P.thousSep || '');
		var num = parts.length > 1 ? parts[0] + (P.decSep || '.') + parts[1] : parts[0];
		if (P.trimZeros && decimals > 0) {
			var zeros = (P.decSep || '.') + new Array(decimals + 1).join('0');
			if (num.slice(-zeros.length) === zeros) { num = num.slice(0, -zeros.length); }
		}
		var fmt = P.format || '%1$s%2$s';
		var out = fmt.replace('%1$s', '<span class="woocommerce-Price-currencySymbol">' + (P.symbol || '') + '</span>').replace('%2$s', num);
		return '<span class="woocommerce-Price-amount amount"><bdi>' + (amount < 0 ? '-' : '') + out + '</bdi></span>';
	}

	function round(v) {
		var d = parseInt(P.decimals, 10);
		if (isNaN(d)) { d = 2; }
		var f = Math.pow(10, d);
		return Math.round(v * f) / f;
	}

	function findQtyInput($wrap) {
		var $scope = $wrap.closest('.product, .summary, .wp-block-group, .entry-summary');
		var $qty = $scope.find('form.cart input.qty, form.cart input[name="quantity"]').first();
		if (!$qty.length) { $qty = $('form.cart input.qty, form.cart input[name="quantity"]').first(); }
		return $qty;
	}

	function highlight($wrap, qty) {
		$wrap.find('tr.tgpd-row').each(function () {
			var $r = $(this);
			var min = parseInt($r.data('min'), 10);
			var maxAttr = $r.attr('data-max');
			var max = maxAttr === '' ? Infinity : parseInt(maxAttr, 10);
			$r.toggleClass('tgpd-active', qty >= min && qty <= max);
		});
	}

	function updateVariablePrices($wrap, basePrice) {
		$wrap.find('tr.tgpd-row').each(function () {
			var $r = $(this);
			var type = $r.data('type');
			var value = parseFloat($r.data('value')) || 0;
			var $price = $r.find('.tgpd-price');
			var $saving = $r.find('.tgpd-saving');

			if (basePrice === null) {
				// Aucune variante choisie : on revient à l'affichage initial.
				if (type === 'base') { $price.html('<span class="tgpd-pick">prix normal</span>'); }
				else if (type === 'percent') { $price.html('<span class="tgpd-pick">selon la variante</span>'); }
				else if (type === 'fixed') {
					$price.html(formatMoney(parseFloat($r.data('fixed-display')) || 0));
					$saving.text('—');
				}
				return;
			}

			var newPrice = basePrice;
			if (type === 'percent') {
				newPrice = round(basePrice * (1 - value / 100));
			} else if (type === 'fixed') {
				var fixedDisp = parseFloat($r.data('fixed-display'));
				newPrice = isNaN(fixedDisp) ? basePrice : Math.min(basePrice, fixedDisp);
			}
			$price.html(formatMoney(newPrice));
			if (type !== 'base') {
				var pct = basePrice > 0 ? Math.round((1 - newPrice / basePrice) * 100) : 0;
				$saving.text(pct > 0 ? '-' + pct + ' %' : '—');
			}
		});
	}

	function setup($wrap) {
		var $qty = findQtyInput($wrap);
		var refresh = function () {
			var q = parseInt($qty.val(), 10);
			highlight($wrap, isNaN(q) || q < 1 ? 1 : q);
		};
		if ($qty.length) {
			$qty.on('input change keyup', refresh);
			// Boutons +/- ajoutés par certains thèmes.
			$(document).on('click', '.plus, .minus, .quantity-plus, .quantity-minus, .qty-plus, .qty-minus', function () {
				setTimeout(refresh, 30);
			});
		}
		refresh();

		if (String($wrap.data('variable')) === '1') {
			var $form = $('form.variations_form[data-product_id="' + $wrap.data('product') + '"]');
			if (!$form.length) { $form = $('form.variations_form').first(); }
			$form.on('found_variation', function (e, variation) {
				var p = variation && typeof variation.display_price !== 'undefined' ? parseFloat(variation.display_price) : null;
				updateVariablePrices($wrap, isNaN(p) ? null : p);
			});
			$form.on('reset_data hide_variation', function () {
				updateVariablePrices($wrap, null);
			});
		}
	}

	$(function () {
		$('.tgpd-table-wrap').each(function () { setup($(this)); });
	});
})(jQuery);
