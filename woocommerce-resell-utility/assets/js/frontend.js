/**
 * WooCommerce Resell Utility - Frontend JavaScript
 */

(function($) {
	'use strict';

	$(document).ready(function() {

		/* ==========================================================================
		   1. Single Product Live Profit Calculator
		   ========================================================================== */

		var $resellerBox = $('.wru-reseller-box');

		if ($resellerBox.length) {
			var baseWholesalePrice = parseFloat($resellerBox.data('base-price')) || 0;
			var packagingFeeRate   = parseFloat($resellerBox.data('packaging-fee')) || 0;
			var currencySymbol     = wru_vars.currency_symbol || '৳';
			var packagingType      = wru_vars.packaging_type || 'order';
			var isMinEnforced      = parseInt(wru_vars.is_min_enforced, 10) === 1;

			var $inputPrice   = $('#wru_reseller_price');
			var $profitDisplay = $('#wru-profit-display');
			var $warningBox   = $('#wru-price-warning');
			var $wholesaleDisp = $('#wru-wholesale-display');
			var $packagingDisp = $('#wru-packaging-display');

			function formatPrice(val) {
				return currencySymbol + Math.max(0, val).toFixed(2);
			}

			function recalculateProfit() {
				var sellingPrice = parseFloat($inputPrice.val());
				var qty = parseInt($('form.cart input[name="quantity"]').val(), 10) || 1;

				if (isNaN(sellingPrice) || sellingPrice <= 0) {
					$profitDisplay.text(currencySymbol + '0.00');
					$warningBox.hide();
					return;
				}

				// Check minimum price rule
				if (isMinEnforced && sellingPrice < baseWholesalePrice) {
					$warningBox.show();
				} else {
					$warningBox.hide();
				}

				var totalPackaging = (packagingType === 'item') ? (packagingFeeRate * qty) : packagingFeeRate;
				var totalProfit    = (sellingPrice - baseWholesalePrice) * qty - totalPackaging;

				if (totalProfit < 0) {
					$profitDisplay.text(currencySymbol + '0.00');
				} else {
					$profitDisplay.text(formatPrice(totalProfit));
				}
			}

			$inputPrice.on('input change keyup', recalculateProfit);
			$('form.cart').on('change', 'input[name="quantity"]', recalculateProfit);

			// Variable product variation change listener
			$('form.variations_form').on('found_variation', function(event, variation) {
				if (variation && variation.display_price) {
					baseWholesalePrice = parseFloat(variation.display_price);
					$wholesaleDisp.html(currencySymbol + baseWholesalePrice.toFixed(2));
					$inputPrice.attr('min', baseWholesalePrice);
					recalculateProfit();
				}
			});

			$('form.variations_form').on('reset_data', function() {
				baseWholesalePrice = parseFloat($resellerBox.data('base-price')) || 0;
				$wholesaleDisp.html(currencySymbol + baseWholesalePrice.toFixed(2));
				recalculateProfit();
			});
		}


		/* ==========================================================================
		   2. Reseller Quick Tools: Copy Description & Toast
		   ========================================================================== */

		var $copyBtn = $('#wru-copy-details-btn');
		var $toast   = $('#wru-toast');

		function showToast(message) {
			if (!$toast.length) {
				$('body').append('<div id="wru-toast" class="wru-toast"></div>');
				$toast = $('#wru-toast');
			}
			$toast.text(message).addClass('wru-toast-visible');
			setTimeout(function() {
				$toast.removeClass('wru-toast-visible');
			}, 2500);
		}

		if ($copyBtn.length) {
			$copyBtn.on('click', function(e) {
				e.preventDefault();
				var textToCopy = $(this).data('copy') || '';
				if (!textToCopy) return;

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(textToCopy).then(function() {
						showToast(wru_vars.i18n.copied || 'ডেসক্রিপশন কপি হয়েছে!');
					});
				} else {
					// Fallback for older browsers
					var $temp = $('<textarea>');
					$('body').append($temp);
					$temp.val(textToCopy).select();
					document.execCommand('copy');
					$temp.remove();
					showToast(wru_vars.i18n.copied || 'ডেসক্রিপশন কপি হয়েছে!');
				}
			});
		}


		/* ==========================================================================
		   3. Shop Page Quick Buy Modal
		   ========================================================================== */

		var $modal        = $('#wru-quick-buy-modal');
		var currentProdId = 0;
		var modalWholesale = 0;
		var modalPackaging = 0;

		if ($modal.length) {
			var $modalTitle    = $('#wru-modal-product-title');
			var $modalWholeDisp = $('#wru-modal-wholesale');
			var $modalPackDisp = $('#wru-modal-packaging');
			var $modalInput    = $('#wru_modal_reseller_price');
			var $modalQty      = $('#wru_modal_qty');
			var $modalProfit   = $('#wru-modal-profit-display');
			var $modalWarning  = $('#wru-modal-warning');
			var $modalSubmit   = $('#wru-modal-confirm-btn');

			function recalculateModalProfit() {
				var sellPrice = parseFloat($modalInput.val());
				var qty       = parseInt($modalQty.val(), 10) || 1;
				var currSym   = wru_vars.currency_symbol || '৳';

				if (isNaN(sellPrice) || sellPrice <= 0) {
					$modalProfit.text(currSym + '0.00');
					$modalWarning.hide();
					return;
				}

				if (sellPrice < modalWholesale) {
					$modalWarning.show();
				} else {
					$modalWarning.hide();
				}

				var packType   = wru_vars.packaging_type || 'order';
				var totalPack  = (packType === 'item') ? (modalPackaging * qty) : modalPackaging;
				var profit     = Math.max(0, (sellPrice - modalWholesale) * qty - totalPack);

				$modalProfit.text(currSym + profit.toFixed(2));
			}

			// Open Modal
			$(document).on('click', '.wru-trigger-quick-buy', function(e) {
				e.preventDefault();
				var $btn = $(this);
				currentProdId  = $btn.data('product-id');
				modalWholesale = parseFloat($btn.data('product-price')) || 0;
				modalPackaging = parseFloat($btn.data('packaging-fee')) || 0;
				var currSym    = wru_vars.currency_symbol || '৳';

				$modalTitle.text($btn.data('product-title') || 'পণ্য অর্ডার করুন');
				$modalWholeDisp.text(currSym + modalWholesale.toFixed(2));
				$modalPackDisp.text(currSym + modalPackaging.toFixed(2));
				$modalInput.val('').attr('min', modalWholesale);
				$modalQty.val(1);
				$modalProfit.text(currSym + '0.00');
				$modalWarning.hide();

				$modal.fadeIn(200);
				setTimeout(function() {
					$modalInput.focus();
				}, 100);
			});

			$modalInput.on('input change keyup', recalculateModalProfit);
			$modalQty.on('input change keyup', recalculateModalProfit);

			// Close Modal
			$modal.find('.wru-modal-close, .wru-modal-overlay').on('click', function() {
				$modal.fadeOut(150);
			});

			// Submit Quick Buy via AJAX
			$modalSubmit.on('click', function(e) {
				e.preventDefault();
				var sellPrice = parseFloat($modalInput.val());
				var qty       = parseInt($modalQty.val(), 10) || 1;

				if (isNaN(sellPrice) || sellPrice <= 0) {
					alert('দয়া করে আপনার বিক্রয়মূল্য লিখুন।');
					$modalInput.focus();
					return;
				}

				if (sellPrice < modalWholesale) {
					alert('বিক্রয়মূল্য অবশ্যই পাইকারি মূল্যের চেয়ে বেশি হতে হবে।');
					$modalInput.focus();
					return;
				}

				$modalSubmit.prop('disabled', true).text(wru_vars.i18n.processing_order || 'অর্ডার প্রসেস হচ্ছে...');

				$.ajax({
					url: wru_vars.ajax_url,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'wru_quick_buy_now',
						nonce: wru_vars.nonce,
						product_id: currentProdId,
						reseller_price: sellPrice,
						quantity: qty
					},
					success: function(response) {
						if (response && response.success && response.data.redirect_url) {
							window.location.href = response.data.redirect_url;
						} else {
							alert(response && response.data && response.data.message ? response.data.message : 'ত্রুটি হয়েছে।');
							$modalSubmit.prop('disabled', false).text('অর্ডার কনফার্ম করুন (চেকআউট)');
						}
					},
					error: function() {
						alert('সার্ভার যোগাযোগে ত্রুটি হয়েছে।');
						$modalSubmit.prop('disabled', false).text('অর্ডার কনফার্ম করুন (চেকআউট)');
					}
				});
			});
		}

	});
})(jQuery);
