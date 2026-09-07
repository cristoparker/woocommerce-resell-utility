/**
 * WooCommerce Resell Utility - Frontend JavaScript
 */

(function($) {
	'use strict';

	window.wru_vars = window.wru_vars || {};
	window.wru_vars.i18n = window.wru_vars.i18n || {};

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


		// Ensure multipart enctype on registration form for NID uploads
		$('form.woocommerce-form-register, form.register').attr('enctype', 'multipart/form-data');

		// NID Image selection preview on register form
		$('#wru_reg_nid_front, #wru_reg_nid_back').on('change', function() {
			var input = this;
			if (input.files && input.files[0]) {
				var file = input.files[0];
				if (file.type.match('image.*')) {
					var reader = new FileReader();
					reader.onload = function(e) {
						var $existing = $(input).siblings('.wru-nid-local-preview');
						if ($existing.length) {
							$existing.attr('src', e.target.result);
						} else {
							$(input).after('<img class="wru-nid-local-preview" src="' + e.target.result + '" style="display:block; max-height:80px; margin-top:6px; border-radius:4px; border:1px solid #cbd5e1;" />');
						}
					};
					reader.readAsDataURL(file);
				}
			}
		});


		/* ==========================================================================
		   5. Single Product Page "Buy Now" Button Fix
		   ========================================================================== */

		var isBuyNowProcessing = false;

		$(document).on('click', '#wru-single-buy-now', function(e) {
			var $btn  = $(this);
			var $form = $btn.closest('form.cart');

			if (!$form.length) {
				return true;
			}

			// 1. Reseller price validation if reseller box exists
			var $resellerInput = $('#wru_reseller_price');
			if ($resellerInput.length) {
				var enteredPrice = parseFloat($resellerInput.val());
				var currentBasePrice = parseFloat($resellerBox.data('base-price')) || 0;
				var isMinEnforced = parseInt(wru_vars.is_min_enforced, 10) === 1;

				// Variable product check
				if ($form.hasClass('variations_form')) {
					var varId = parseInt($form.find('input[name="variation_id"]').val(), 10);
					if (!varId || varId <= 0) {
						e.preventDefault();
						alert('দয়া করে পণ্যের অপশন (যেমন সাইজ বা কালার) নির্বাচন করুন।');
						$form.find('.variations select').first().focus();
						return false;
					}
				}

				if (isNaN(enteredPrice) || enteredPrice <= 0) {
					e.preventDefault();
					alert('দয়া করে আপনার বিক্রয়মূল্য লিখুন (কাস্টমার থেকে যা কালেকশন করবেন)।');
					$resellerInput.focus();
					$('html, body').animate({
						scrollTop: $resellerInput.offset().top - 120
					}, 300);
					return false;
				}

				if (isMinEnforced && enteredPrice < currentBasePrice) {
					e.preventDefault();
					alert('বিক্রয়মূল্য অবশ্যই পাইকারি মূল্যের চেয়ে বেশি হতে হবে।');
					$resellerInput.focus();
					return false;
				}
			}

			// 2. Variable product variation validation if reseller box was absent
			if ($form.hasClass('variations_form')) {
				var variationId = parseInt($form.find('input[name="variation_id"]').val(), 10);
				if (!variationId || variationId <= 0) {
					e.preventDefault();
					alert('দয়া করে পণ্যের অপশন নির্বাচন করুন।');
					return false;
				}
			}

			// 3. Ensure 'add-to-cart' product ID is submitted with the form
			var prodId = $btn.data('product-id') || $btn.siblings('input[name="wru_buy_now_product_id"]').val();
			if (!prodId) {
				var $addToCartBtn = $form.find('button[name="add-to-cart"]');
				if ($addToCartBtn.length && $addToCartBtn.val()) {
					prodId = $addToCartBtn.val();
				} else {
					var $prodIdHidden = $form.find('input[name="product_id"]');
					if ($prodIdHidden.length && $prodIdHidden.val()) {
						prodId = $prodIdHidden.val();
					}
				}
			}

			if (prodId) {
				$form.find('input.wru-injected-add-to-cart').remove();
				if ($form.find('input[name="add-to-cart"]').length === 0) {
					$form.append('<input type="hidden" class="wru-injected-add-to-cart" name="add-to-cart" value="' + prodId + '">');
				}
			}

			// 4. Ensure 'wru_buy_now' is submitted with the form
			$form.find('input.wru-injected-buy-now').remove();
			$form.append('<input type="hidden" class="wru-injected-buy-now" name="wru_buy_now" value="1">');

			isBuyNowProcessing = true;
		});

		// Theme AJAX add-to-cart fallback: if theme uses AJAX to add product, redirect to checkout on complete
		$(document.body).on('added_to_cart', function() {
			if (isBuyNowProcessing) {
				var checkoutUrl = (wru_vars && wru_vars.checkout_url) ? wru_vars.checkout_url : '/checkout/';
				window.location.href = checkoutUrl;
			}
		});


		/* ==========================================================================
		   6. Suppress Theme / WooCommerce Blocks "Save" & "Previous price" Elements
		   ========================================================================== */

		function cleanCartCheckoutBadges() {
			if ($('body').hasClass('woocommerce-cart') || $('body').hasClass('woocommerce-checkout')) {
				$('.wc-block-components-sale-badge, .wc-block-components-product-price__regular, .wc-block-components-product-sale-badge, [class*="sale-badge"], [class*="discount-badge"], [class*="save-amount"]').remove();
				$('.woocommerce-cart del, .woocommerce-checkout del, .wc-block-cart del, .wc-block-checkout del, .shop_table del, #order_review del').remove();

				// Clean text containing 'Previous price:', 'Discounted price:', 'Save ', 'You Save', or 'সাশ্রয়'
				$('.wc-block-components-product-price, .product-price, .product-subtotal, td.product-price, td.product-subtotal, .order-total, .cart_item, .wc-block-cart-items, .wc-block-checkout__order-summary').find('span, p, div, small, strong, em, b').each(function() {
					var $el = $(this);
					if ($el.children().length === 0) {
						var text = $el.text().trim();
						if (text.indexOf('Previous price:') !== -1 || text.indexOf('Discounted price:') !== -1 || text.indexOf('Save ') === 0 || text.indexOf('You Save') !== -1 || text.indexOf('সাশ্রয়') !== -1) {
							$el.css({ display: 'none', visibility: 'hidden' }).remove();
						}
					}
				});
			}
		}

		cleanCartCheckoutBadges();
		$(document).ajaxComplete(cleanCartCheckoutBadges);

		// Real-time MutationObserver ensures even React/Vue/Blocks dynamic renders are caught immediately
		if (window.MutationObserver && document.body) {
			var saveBadgeObserver = new MutationObserver(function() {
				cleanCartCheckoutBadges();
			});
			saveBadgeObserver.observe(document.body, { childList: true, subtree: true });
		}


		/* ==========================================================================
		   7. Reseller Dashboard: Smooth Scroll to Cashout Form
		   ========================================================================== */

		$(document).on('click', '.wru-cashout-trigger-btn', function(e) {
			var $target = $('#wru-cashout-section');
			if ($target.length) {
				e.preventDefault();
				$('html, body').animate({
					scrollTop: $target.offset().top - 40
				}, 400, function() {
					$('#wru_cashout_amount').focus();
					$target.addClass('wru-pulse-highlight');
					setTimeout(function() {
						$target.removeClass('wru-pulse-highlight');
					}, 1500);
				});
			}
		});



	});
})(jQuery);


