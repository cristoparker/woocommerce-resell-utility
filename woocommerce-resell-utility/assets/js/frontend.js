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

		/* ==========================================================================
		   4. Checkout Page Reseller Selling Price Live Editor
		   ========================================================================== */

		var checkoutUpdateTimer;

		// Live profit calculation on typing in checkout review table
		$(document).on('input keyup change', '.wru-checkout-price-input', function() {
			var $input     = $(this);
			var sellPrice  = parseFloat($input.val()) || 0;
			var wholesale  = parseFloat($input.data('wholesale')) || 0;
			var qty        = parseInt($input.data('qty'), 10) || 1;
			var packFee    = parseFloat($input.data('packaging-fee')) || 0;
			var packType   = $input.data('packaging-type') || 'order';
			var currSym    = wru_vars.currency_symbol || '৳';

			var totalPack  = (packType === 'item') ? (packFee * qty) : packFee;
			var profit     = Math.max(0, (sellPrice - wholesale) * qty - totalPack);

			$input.closest('.wru-checkout-edit-box').find('.wru-checkout-profit-val').text(currSym + profit.toFixed(2));

			// Recalculate live courier collection amount instantly
			recalculateCheckoutCourierCollection();
		});

		function recalculateCheckoutCourierCollection() {
			var totalItems = 0;
			var hasInputs  = false;
			$('.wru-checkout-price-input').each(function() {
				hasInputs = true;
				var val = parseFloat($(this).val());
				var ws  = parseFloat($(this).data('wholesale')) || 0;
				var p   = (!isNaN(val) && val > 0) ? val : ws;
				var q   = parseInt($(this).data('qty'), 10) || 1;
				totalItems += (p * q);
			});

			var currSym = (typeof wru_vars !== 'undefined' && wru_vars.currency_symbol) ? wru_vars.currency_symbol : '৳';

			if (!hasInputs) {
				var subtotalText = $('.cart-subtotal .amount, .order-total .amount').first().text();
				var subtotalNum  = parseFloat(subtotalText.replace(/[^0-9.]/g, '')) || 0;
				totalItems = subtotalNum;
			}

			var shippingText = $('.woocommerce-shipping-totals .amount, .shipping .amount').first().text();
			var shippingNum  = parseFloat(shippingText.replace(/[^0-9.]/g, '')) || 0;
			var totalCol     = totalItems + shippingNum;

			if (totalCol <= 0 && typeof wru_vars !== 'undefined' && wru_vars.courier_collection_amount) {
				totalCol = parseFloat(wru_vars.courier_collection_amount);
			}

			if (totalCol > 0) {
				$('.wru-checkout-collection-amount').html('<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">' + currSym + '</span>' + totalCol.toFixed(2) + '</bdi></span>');
			}
		}

		// Debounced AJAX update when reseller changes value
		$(document).on('change blur', '.wru-checkout-price-input', function() {
			if (typeof wru_vars === 'undefined' || !wru_vars.ajax_url) {
				return;
			}
			var $input   = $(this);
			var cartKey  = $input.data('cart-key');
			var newPrice = parseFloat($input.val()) || 0;

			clearTimeout(checkoutUpdateTimer);
			checkoutUpdateTimer = setTimeout(function() {
				$.ajax({
					url: wru_vars.ajax_url,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'wru_update_checkout_reseller_price',
						nonce: wru_vars.nonce,
						cart_key: cartKey,
						new_price: newPrice
					},
					success: function() {
						$(document.body).trigger('update_checkout');
					}
				});
			}, 300);
		});

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


		/* ==========================================================================
		   8. Checkout Page Customization (Dropshipping Customer Fields & COD Display)
		   ========================================================================== */

		function applyCheckoutCustomizations() {
			if (!$('body').hasClass('woocommerce-checkout') && !$('form.checkout').length && !$('.wc-block-checkout').length) {
				return;
			}

			// Pre-populate reseller email if available and field is empty
			if (typeof wru_vars !== 'undefined' && wru_vars.user_email) {
				var $bEmail = $('#billing_email');
				if ($bEmail.length && !$bEmail.val()) {
					$bEmail.val(wru_vars.user_email);
				}
			}

			// 1. Customer Name -> "কাস্টমারের নাম" (Required)
			var $fnField = $('#billing_first_name_field');
			if ($fnField.length) {
				$fnField.find('label').first().html('কাস্টমারের নাম <abbr class="required" title="required">*</abbr>');
				$('#billing_first_name').attr('placeholder', 'কাস্টমারের সম্পূর্ণ নাম লিখুন').prop('required', true);
				$fnField.removeClass('form-row-first form-row-last').addClass('form-row-wide');
			}

			// 2. Customer Phone -> "কাস্টমারের মোবাইল নাম্বার" (Required)
			var $phoneField = $('#billing_phone_field');
			if ($phoneField.length) {
				$phoneField.find('label').first().html('কাস্টমারের মোবাইল নাম্বার <abbr class="required" title="required">*</abbr>');
				$('#billing_phone').attr('placeholder', 'যেমন: 017XXXXXXXX').prop('required', true);
				$phoneField.removeClass('form-row-first form-row-last').addClass('form-row-wide');
			}

			// 3. Customer Address -> "কাস্টমারের ঠিকানা" (Required)
			var $addr1Field = $('#billing_address_1_field');
			if ($addr1Field.length) {
				$addr1Field.find('label').first().html('কাস্টমারের ঠিকানা <abbr class="required" title="required">*</abbr>');
				$('#billing_address_1').attr('placeholder', 'বাসা/রোড নম্বর, এলাকা বা গ্রাম, থানা ও জেলা লিখুন').prop('required', true);
				$addr1Field.addClass('form-row-wide');
			}

			// 4. Postal / Zip Code -> "জিপ কোড (ঐচ্ছিক)" (Optional)
			var $postcodeField = $('#billing_postcode_field');
			if ($postcodeField.length) {
				$postcodeField.find('label').first().html('জিপ কোড (ঐচ্ছিক)');
				$('#billing_postcode').attr('placeholder', 'যেমন: 1205 (ঐচ্ছিক)').prop('required', false);
				$postcodeField.removeClass('validate-required').addClass('form-row-wide');
				$postcodeField.find('.required').remove();
			}

			// 5. Reseller Email -> "রিসেলারের ইমেইল" (Required)
			var $emailField = $('#billing_email_field');
			if ($emailField.length) {
				$emailField.find('label').first().html('রিসেলারের ইমেইল <abbr class="required" title="required">*</abbr>');
				$('#billing_email').attr('placeholder', 'রিসেলারের ইমেইল লিখুন').prop('required', true);
				$emailField.removeClass('form-row-first form-row-last').addClass('form-row-wide');
			}

			// Hide all additional / unnecessary checkout fields
			$('#billing_last_name_field, #billing_company_field, #billing_address_2_field, #billing_city_field, #billing_state_field, #billing_country_field, #billing_reseller_phone_field, .woocommerce-additional-fields, #order_comments_field, #ship-to-different-address, .woocommerce-shipping-fields, #shipping_first_name_field, #shipping_last_name_field, #shipping_phone_field, #shipping_address_1_field, #shipping_address_2_field, #shipping_city_field, #shipping_state_field, #shipping_postcode_field, #shipping_country_field').hide().css('display', 'none');

			// Support for WooCommerce Checkout Blocks (Gutenberg)
			$('.wc-block-checkout').each(function() {
				$(this).find('.wc-block-checkout__additional-fields, .wc-block-components-order-note, .wc-block-checkout__shipping-option').hide();
				$(this).find('#billing-company, #shipping-company, #billing-city, #shipping-city, #billing-state, #shipping-state, #billing-address_2, #shipping-address_2').closest('.wc-block-components-text-input').hide();

				$(this).find('#email, input[name="email"], input[type="email"]').each(function() {
					$(this).attr('placeholder', 'রিসেলারের ইমেইল লিখুন');
					var $lbl = $(this).closest('.wc-block-components-text-input').find('label');
					if ($lbl.length) {
						$lbl.text('রিসেলারের ইমেইল');
					}
				});
				$(this).find('#billing-first_name, input[name="billing_first_name"], #shipping-first_name, input[name="shipping_first_name"]').each(function() {
					$(this).attr('placeholder', 'কাস্টমারের সম্পূর্ণ নাম লিখুন');
					var $lbl = $(this).closest('.wc-block-components-text-input').find('label');
					if ($lbl.length) {
						$lbl.text('কাস্টমারের নাম');
					}
				});
				$(this).find('#billing-last_name, input[name="billing_last_name"], #shipping-last_name, input[name="shipping_last_name"]').closest('.wc-block-components-text-input').hide();
				$(this).find('#billing-address_1, input[name="billing_address_1"], #shipping-address_1, input[name="shipping_address_1"]').each(function() {
					$(this).attr('placeholder', 'বাসা/রোড নম্বর, এলাকা বা গ্রাম, থানা ও জেলা লিখুন');
					var $lbl = $(this).closest('.wc-block-components-text-input').find('label');
					if ($lbl.length) {
						$lbl.text('কাস্টমারের ঠিকানা');
					}
				});
				$(this).find('#billing-phone, input[name="billing_phone"], #shipping-phone, input[name="shipping_phone"]').each(function() {
					$(this).attr('placeholder', 'যেমন: 017XXXXXXXX');
					var $lbl = $(this).closest('.wc-block-components-text-input').find('label');
					if ($lbl.length) {
						$lbl.text('কাস্টমারের মোবাইল নাম্বার');
					}
				});
				$(this).find('#billing-postcode, input[name="billing_postcode"], #shipping-postcode, input[name="shipping_postcode"]').each(function() {
					$(this).attr('placeholder', 'যেমন: 1205 (ঐচ্ছিক)');
					var $lbl = $(this).closest('.wc-block-components-text-input').find('label');
					if ($lbl.length) {
						$lbl.text('জিপ কোড (ঐচ্ছিক)');
					}
				});
			});

			// Ensure Courier Collection COD row in Review Table
			ensureCourierCollectionRow();

			// Ensure restriction banner if non-reseller visits checkout
			ensureResellerCheckoutNotice();
		}

		function ensureResellerCheckoutNotice() {
			if (typeof wru_vars === 'undefined') return;
			if (wru_vars.is_reseller === 1 || wru_vars.is_reseller === '1') return;

			var $checkoutForm = $('form.checkout, .wc-block-checkout');
			if ($checkoutForm.length && !$('.wru-checkout-restriction-banner').length) {
				var accountUrl = wru_vars.myaccount_url || '/my-account/';
				var status = wru_vars.reseller_status || '';
				var detailMsg = 'অর্ডার কনফার্ম করতে অনুমোদিত রিসেলার রোল থাকা আবশ্যক। অনুগ্রহ করে আপনার <a href="' + accountUrl + '" style="text-decoration:underline; font-weight:700; color:#991b1b;">রিসেলার একাউন্টে লগইন করুন</a> অথবা নতুন রিসেলার হিসেবে আবেদন করুন।';

				if (status === 'pending') {
					detailMsg = 'আপনার রিসেলার একাউন্টের আবেদনটি বর্তমানে পেন্ডিং (অনুমোদনের অপেক্ষায়) রয়েছে। অ্যাডমিন অনুমোদন প্রদান না করা পর্যন্ত অর্ডার কনফার্ম হবে না।';
				} else if (status === 'rejected') {
					detailMsg = 'আপনার রিসেলার আবেদনটি বাতিল করা হয়েছে। বিস্তারিত জানতে অ্যাডমিনের সাথে যোগাযোগ করুন।';
				}

				var bannerHtml = '<div class="wru-checkout-restriction-banner" style="background: #fef2f2; border: 1.5px solid #f87171; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px;">' +
					'<div style="display:flex; align-items:flex-start; gap:12px;">' +
					'<span style="font-size:24px; line-height:1; flex-shrink:0;">⛔</span>' +
					'<div>' +
					'<h4 style="margin: 0 0 6px 0; color: #991b1b; font-size: 16px; font-weight: 700;">শুধুমাত্র অনুমোদিত রিসেলারদের জন্য</h4>' +
					'<p style="margin: 0; color: #b91c1c; font-size: 13.5px; line-height: 1.6;">' + detailMsg + '</p>' +
					'</div>' +
					'</div>' +
					'</div>';

				$checkoutForm.first().before(bannerHtml);
			}
		}

		// Ensure last name is populated from first name before form submits so WC core validator never rejects
		$(document).on('submit', 'form.checkout', function() {
			var fn = $('#billing_first_name').val() || '';
			if (!$('#billing_last_name').val()) {
				$('#billing_last_name').val(fn);
			}
			var sfn = $('#shipping_first_name').val() || '';
			if (!$('#shipping_last_name').val()) {
				$('#shipping_last_name').val(sfn || fn);
			}
		});

		// Scroll to top on checkout error notice
		$(document.body).on('checkout_error', function() {
			var $target = $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .wru-checkout-restriction-banner').first();
			if ($target.length) {
				$('html, body').animate({
					scrollTop: Math.max(0, $target.offset().top - 80)
				}, 350);
			}
		});

		function ensureCourierCollectionRow() {
			var currSym = (typeof wru_vars !== 'undefined' && wru_vars.currency_symbol) ? wru_vars.currency_symbol : '৳';
			var initialAmount = (typeof wru_vars !== 'undefined' && wru_vars.courier_collection_amount) ? parseFloat(wru_vars.courier_collection_amount) : 0;
			var displayPrice = currSym + (initialAmount > 0 ? initialAmount.toFixed(2) : '0.00');

			// 1. Inside Review Order Table
			var $table = $('table.woocommerce-checkout-review-order-table, #order_review table.shop_table, .shop_table.woocommerce-checkout-review-order-table');
			if ($table.length && !$('.wru-checkout-courier-collection-row').length) {
				var rowHtml = '<tr class="wru-checkout-courier-collection-row" style="background:#ecfdf5; border-top:2px solid #10b981;">' +
					'<th style="padding:12px 14px; font-weight:700; color:#065f46;">' +
					'<span class="wru-collection-title" style="display:block; font-size:14px;">কুরিয়ার কালেকশন এমাউন্ট (COD)</span>' +
					'<small class="wru-collection-subtext" style="display:block; font-size:11px; color:#047857; font-weight:normal;">(কাস্টমারের কাছ থেকে কুরিয়ার এই মোট টাকা সংগ্রহ করবে)</small>' +
					'</th>' +
					'<td style="padding:12px 14px; text-align:right;">' +
					'<strong class="wru-checkout-collection-amount" style="font-size:17px; font-weight:800; color:#065f46;">' + displayPrice + '</strong>' +
					'</td>' +
					'</tr>';

				if ($table.find('tr.order-total').length) {
					$table.find('tr.order-total').after(rowHtml);
				} else if ($table.find('tfoot').length) {
					$table.find('tfoot').append(rowHtml);
				} else {
					$table.append(rowHtml);
				}
			}

			// 2. Standalone Summary Card before payment / place order button
			if (!$('.wru-checkout-courier-collection-box').length) {
				var cardHtml = '<div class="wru-checkout-courier-collection-box" style="background:#ecfdf5; border:2px solid #10b981; border-radius:10px; padding:15px 18px; margin:16px 0 20px 0; box-shadow:0 1px 3px rgba(16,185,129,0.12);">' +
					'<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">' +
					'<div style="display:flex; align-items:center; gap:10px;">' +
					'<span style="font-size:24px; line-height:1;">🚚</span>' +
					'<div>' +
					'<strong style="display:block; font-size:15px; color:#065f46; font-weight:700;">কুরিয়ার কালেকশন এমাউন্ট (COD)</strong>' +
					'<small style="display:block; font-size:12px; color:#047857;">(কাস্টমারের কাছ থেকে কুরিয়ার এই মোট টাকা সংগ্রহ করবে)</small>' +
					'</div>' +
					'</div>' +
					'<div style="text-align:right;">' +
					'<strong class="wru-checkout-collection-amount" style="font-size:22px; font-weight:800; color:#065f46;">' + displayPrice + '</strong>' +
					'</div>' +
					'</div>' +
					'</div>';

				var $payment = $('#payment, .wc-block-checkout__payment-method, #place_order');
				if ($payment.length) {
					$payment.first().before(cardHtml);
				} else if ($table.length) {
					$table.after(cardHtml);
				}
			}

			recalculateCheckoutCourierCollection();
		}

		// Run checkout customizations on ready & on ajax updates
		applyCheckoutCustomizations();
		$(document.body).on('updated_checkout init_checkout', function() {
			applyCheckoutCustomizations();
		});
		$(document).ajaxComplete(function() {
			if ($('body').hasClass('woocommerce-checkout') || $('form.checkout').length) {
				applyCheckoutCustomizations();
			}
		});

	});
})(jQuery);


