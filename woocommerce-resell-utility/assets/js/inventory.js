/* global jQuery, wruInv */
/**
 * Inventory screens: adjust-stock dialog, delete confirmations and the purchase form.
 */
(function ($) {
	'use strict';

	var cfg = window.wruInv || { i18n: {}, currency: '', decimals: 2 };
	var t = cfg.i18n || {};

	function num(v) {
		var n = parseFloat(v);
		return isFinite(n) && n > 0 ? n : 0;
	}

	function money(n) {
		var d = parseInt(cfg.decimals, 10);
		if (isNaN(d)) {
			d = 2;
		}
		return cfg.currency + Number(n).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d });
	}

	/* ------------------------------------------------------------------
	 * Confirmations
	 * ---------------------------------------------------------------- */
	$(document).on('submit', 'form.js-wru-confirm', function (e) {
		var msg = $(this).data('confirm');
		if (msg && !window.confirm(msg)) {
			e.preventDefault();
		}
	});

	/* ------------------------------------------------------------------
	 * Adjust stock dialog
	 * ---------------------------------------------------------------- */
	var dialog = document.getElementById('wru-inv-adjust');
	var currentStock = 0;

	function adjustPreview() {
		if (!dialog) {
			return;
		}
		var $d = $(dialog);
		var mode = $d.find('input[name="mode"]:checked').val();
		var raw = $d.find('input[name="qty"]').val();
		var qty = parseInt(raw, 10);
		var $out = $d.find('.js-wru-adjust-preview');
		if (raw === '' || isNaN(qty) || qty < 0) {
			$out.text('');
			return;
		}
		var next = mode === 'add' ? currentStock + qty : (mode === 'remove' ? currentStock - qty : qty);
		$out.empty().append(document.createTextNode((t.newStock || 'New stock will be') + ' ')).append($('<strong/>').text(next));
		$out.toggleClass('is-negative', next < 0);
	}

	$(document).on('click', '.js-wru-adjust', function () {
		if (!dialog || typeof dialog.showModal !== 'function') {
			return;
		}
		var $b = $(this);
		var $d = $(dialog);
		currentStock = parseInt($b.data('stock'), 10) || 0;
		$d.find('input[name="product_id"]').val($b.data('id'));
		$d.find('.js-wru-adjust-name').text($b.data('name'));
		$d.find('input[name="qty"]').val('');
		$d.find('input[name="note"]').val('');
		$d.find('input[name="mode"][value="add"]').prop('checked', true);
		adjustPreview();
		dialog.showModal();
		$d.find('input[name="qty"]').trigger('focus');
	});

	$(document).on('click', '.js-wru-dialog-close', function () {
		var d = $(this).closest('dialog')[0];
		if (d) {
			d.close();
		}
	});

	if (dialog) {
		$(dialog).on('input change', 'input[name="qty"], input[name="mode"]', adjustPreview);
		// Click on the backdrop closes the dialog.
		dialog.addEventListener('click', function (e) {
			if (e.target === dialog) {
				dialog.close();
			}
		});
	}

	/* ------------------------------------------------------------------
	 * Purchase form
	 * ---------------------------------------------------------------- */
	var $form = $('form.js-wru-purchase');
	if (!$form.length) {
		return;
	}

	var $lines = $form.find('.js-wru-lines');
	var template = document.getElementById('wru-inv-line-template');

	function reindex() {
		$lines.find('.js-wru-line').each(function (i) {
			$(this).find('input[name^="items["]').each(function () {
				this.name = this.name.replace(/^items\[[^\]]*\]/, 'items[' + i + ']');
			});
		});
		$form.find('.js-wru-empty-lines').prop('hidden', $lines.find('.js-wru-line').length > 0);
	}

	function recalc() {
		var subtotal = 0;
		var units = 0;
		var lines = [];

		$lines.find('.js-wru-line').each(function () {
			var $r = $(this);
			var qty = Math.floor(num($r.find('.js-wru-line-qty').val()));
			var cost = num($r.find('.js-wru-line-cost').val());
			var value = qty * cost;
			subtotal += value;
			units += qty;
			lines.push({ $r: $r, qty: qty, value: value });
		});

		var extra = num($form.find('[name="transport_cost"]').val()) + num($form.find('[name="labour_cost"]').val()) + num($form.find('[name="other_cost"]').val());
		var discount = num($form.find('[name="discount"]').val());
		var total = subtotal + extra - discount;
		var net = extra - discount;

		lines.forEach(function (l) {
			var share = subtotal > 0 ? l.value / subtotal : (units > 0 ? l.qty / units : 0);
			var landed = l.qty > 0 ? Math.max(0, (l.value + share * net) / l.qty) : 0;
			l.$r.find('.js-wru-line-total').text(money(l.value));
			l.$r.find('.js-wru-line-landed').text(money(landed));
		});

		$form.find('.js-wru-units').text(units);
		$form.find('.js-wru-subtotal').text(money(subtotal));
		$form.find('.js-wru-extra').text('+ ' + money(extra));
		$form.find('.js-wru-discount').text('− ' + money(discount));
		$form.find('.js-wru-total').text(money(Math.max(0, total)));

		var due = total - num($form.find('[name="paid"]').val());
		var $due = $form.find('.js-wru-due');
		$due.toggleClass('is-owed', due > 0.004).toggleClass('is-paid', due <= 0.004);
		$form.find('.js-wru-due-label').text(due > 0.004 ? t.owed : (due < -0.004 ? t.overpaid : t.paidFull));
		$form.find('.js-wru-due-value').text(money(Math.abs(due)));

		return { lines: lines.length, subtotal: subtotal, extra: extra, discount: discount };
	}

	function addLine(item) {
		// Same product again: bump its quantity instead of adding a duplicate line.
		var $existing = $lines.find('.js-wru-line-id').filter(function () {
			return String(this.value) === String(item.id);
		}).closest('.js-wru-line');
		if ($existing.length) {
			var $q = $existing.find('.js-wru-line-qty');
			$q.val(Math.floor(num($q.val())) + 1).trigger('focus');
			recalc();
			return;
		}

		var html = template.innerHTML.replace(/__i__/g, '0');
		var $row = $($.parseHTML($.trim(html)));
		$row.find('.js-wru-line-id').val(item.id);
		$row.find('.js-wru-line-name').text(item.name || item.text);
		$row.find('.js-wru-line-stock').text((t.inStock || 'In stock now:') + ' ' + (item.tracked ? item.stock : (t.notTracked || 'not tracked yet')));
		if (item.cost !== '' && item.cost !== null && item.cost !== undefined) {
			$row.find('.js-wru-line-cost').val(item.cost);
		}
		$lines.append($row);
		reindex();
		recalc();
		$row.find('.js-wru-line-cost').trigger('focus');
	}

	$lines.on('input change', 'input', recalc);
	$form.on('input change', '.js-wru-money', recalc);

	$lines.on('click', '.js-wru-line-remove', function () {
		$(this).closest('.js-wru-line').remove();
		reindex();
		recalc();
	});

	$form.find('.js-wru-supplier').on('change', function () {
		var isNew = this.value === 'new';
		var $box = $form.find('.js-wru-new-supplier');
		$box.prop('hidden', !isNew);
		if (isNew) {
			$box.find('input').first().trigger('focus');
		}
	});

	$form.on('submit', function (e) {
		reindex();
		var r = recalc();
		var $err = $form.find('.js-wru-client-error');
		var msg = '';
		if (!r.lines) {
			msg = t.noItems;
		} else if (r.discount > r.subtotal + r.extra) {
			msg = t.discountHigh;
		}
		if (msg) {
			e.preventDefault();
			$err.text(msg).prop('hidden', false);
			return;
		}
		$err.prop('hidden', true);
	});

	// Product search (selectWoo ships with WooCommerce).
	var $search = $form.find('.js-wru-product-search');
	if ($search.length && $.fn.selectWoo) {
		$search.selectWoo({
			placeholder: t.search,
			allowClear: false,
			minimumInputLength: 2,
			width: '100%',
			language: {
				inputTooShort: function () { return t.typeMore; },
				noResults: function () { return t.noResults; }
			},
			ajax: {
				url: cfg.ajaxUrl,
				dataType: 'json',
				delay: 250,
				data: function (params) {
					return { action: 'wru_inv_search_products', security: cfg.searchNonce, term: params.term };
				},
				processResults: function (res) {
					return { results: res && res.success ? res.data : [] };
				},
				cache: true
			},
			templateResult: function (item) {
				if (!item.id) {
					return item.text;
				}
				var $el = $('<span class="wru-inv-opt"/>');
				$el.append($('<span class="wru-inv-opt-name"/>').text(item.text));
				$el.append($('<span class="wru-inv-opt-meta"/>').text((t.inStock || 'In stock now:') + ' ' + (item.tracked ? item.stock : (t.notTracked || ''))));
				return $el;
			}
		}).on('select2:select', function (e) {
			addLine(e.params.data);
			$search.val(null).trigger('change');
		});
	}

	reindex();
	recalc();
})(jQuery);
