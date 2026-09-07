/**
 * WooCommerce Resell Utility - Admin Scripts
 */

(function($) {
	'use strict';

	$(document).ready(function() {

		/* ==========================================================================
		   1. Copy Courier Note Box
		   ========================================================================== */

		var $copyCourierBtn = $('#wru_btn_copy_courier');

		if ($copyCourierBtn.length) {
			$copyCourierBtn.on('click', function(e) {
				e.preventDefault();
				var textToCopy = $('#wru_courier_copy_text').val() || $(this).data('copy');

				if (!textToCopy) {
					return;
				}

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(textToCopy).then(function() {
						indicateCopied();
					});
				} else {
					var $textarea = $('#wru_courier_copy_text');
					$textarea.select();
					document.execCommand('copy');
					indicateCopied();
				}
			});

			function indicateCopied() {
				var originalText = $copyCourierBtn.html();
				$copyCourierBtn.html(wru_admin.copied_text || 'কুরিয়ার নোট কপি হয়েছে!');
				$copyCourierBtn.css('background', '#16a34a').css('border-color', '#15803d');

				setTimeout(function() {
					$copyCourierBtn.html(originalText);
					$copyCourierBtn.css('background', '').css('border-color', '');
				}, 2200);
			}
		}


		/* ==========================================================================
		   2. Reseller Admin Hub Modals
		   ========================================================================== */

		// Close any admin modal
		function closeModals() {
			$('.wru-admin-modal').fadeOut(180);
		}

		$(document).on('click', '.wru-modal-close, .wru-modal-cancel, .wru-modal-overlay', function(e) {
			e.preventDefault();
			closeModals();
		});

		// Escape key closes modal
		$(document).on('keyup', function(e) {
			if (e.key === 'Escape') {
				closeModals();
			}
		});

		// 1. Balance Adjustment Modal
		$(document).on('click', '.wru-btn-adjust-balance', function(e) {
			e.preventDefault();
			var userId         = $(this).data('user-id');
			var userName       = $(this).data('user-name');
			var currentBalance = $(this).data('current-balance');

			$('#wru_adj_reseller_id').val(userId);
			$('#wru_adj_reseller_name').text(userName);
			$('#wru_adj_current_balance').html(currentBalance);
			$('#wru_adj_amount').val('');
			$('#wru_adj_reason').val('');

			$('#wru-adjust-modal').fadeIn(200);
		});

		// 2. Approve Cashout Modal (Mark as Paid)
		$(document).on('click', '.wru-btn-approve-cashout', function(e) {
			e.preventDefault();
			var cashoutId = $(this).data('id');
			var userName  = $(this).data('user');
			var amount    = $(this).data('amount');
			var method    = $(this).data('method');

			$('#wru_co_id').val(cashoutId);
			$('#wru_co_user').text(userName);
			$('#wru_co_amount').html(amount);
			$('#wru_co_method').text(method);
			$('#wru_trx_id').val('');
			$('#wru_admin_note').val('');

			$('#wru-cashout-modal').fadeIn(200);
		});

		// 3. Reject Cashout Modal
		$(document).on('click', '.wru-btn-reject-cashout', function(e) {
			e.preventDefault();
			var cashoutId = $(this).data('id');

			$('#wru_rej_id').val(cashoutId);
			$('#wru_rej_reason').val('');

			$('#wru-reject-modal').fadeIn(200);
		});

		// 4. View Ledger Modal (AJAX)
		$(document).on('click', '.wru-btn-view-ledger', function(e) {
			e.preventDefault();
			var userId = $(this).data('user-id');
			var ajaxUrl = (typeof wru_admin !== 'undefined' && wru_admin.ajax_url) ? wru_admin.ajax_url : (window.ajaxurl || '/wp-admin/admin-ajax.php');

			$('#wru-ledger-modal-body').html('<p style="padding:20px; text-align:center;">তথ্য লোড হচ্ছে, অপেক্ষা করুন...</p>');
			$('#wru-ledger-modal').fadeIn(200);

			$.ajax({
				url: ajaxUrl,
				type: 'GET',
				data: {
					action: 'wru_get_reseller_ledger',
					user_id: userId
				},
				success: function(response) {
					if (response && response.success && response.data && response.data.html) {
						$('#wru-ledger-modal-body').html(response.data.html);
					} else {
						$('#wru-ledger-modal-body').html('<p style="padding:20px; color:#dc2626;">তথ্য লোড করা সম্ভব হয়নি।</p>');
					}
				},
				error: function() {
					$('#wru-ledger-modal-body').html('<p style="padding:20px; color:#dc2626;">সার্ভার সংযোগে ত্রুটি দেখা দিয়েছে।</p>');
				}
			});
		});

		// 5. Delete Reseller Modal
		$(document).on('click', '.wru-btn-delete-reseller', function(e) {
			e.preventDefault();
			var userId   = $(this).data('user-id');
			var userName = $(this).data('user-name');

			$('#wru_del_reseller_id').val(userId);
			$('#wru_del_reseller_name').text(userName);

			$('#wru-delete-reseller-modal').fadeIn(200);
		});

		// 6. Reject Reseller Applicant Modal
		$(document).on('click', '.wru-btn-reject-applicant', function(e) {
			e.preventDefault();
			var userId   = $(this).data('user-id');
			var userName = $(this).data('user-name');

			$('#wru_rej_applicant_id').val(userId);
			$('#wru_rej_applicant_name').text(userName);
			$('#wru_applicant_rej_reason').val('');

			$('#wru-reject-applicant-modal').fadeIn(200);
		});

		// 7. Delete Reseller Applicant Modal
		$(document).on('click', '.wru-btn-delete-applicant', function(e) {
			e.preventDefault();
			var userId   = $(this).data('user-id');
			var userName = $(this).data('user-name');

			$('#wru_del_applicant_id').val(userId);
			$('#wru_del_applicant_name').text(userName);

			$('#wru-delete-applicant-modal').fadeIn(200);
		});

		// 8. View Reseller Profile & NID Modal (Clicking Reseller Name, Card, or Button)
		$(document).on('click', '.wru-btn-view-reseller, .wru-clickable-reseller', function(e) {
			e.preventDefault();
			var $btn = $(this);
			var userId = $btn.data('user-id') || $btn.closest('[data-user-id]').data('user-id') || $btn.attr('data-user-id');

			// Fallback: Check inline JSON
			var inlineData = $btn.data('user-json') || $btn.attr('data-user-json');
			if (!userId && inlineData) {
				if (typeof inlineData === 'string') {
					try { inlineData = JSON.parse(inlineData); } catch (err) { inlineData = null; }
				}
				if (inlineData && inlineData.id) {
					userId = inlineData.id;
				}
			}

			if (!userId && (!inlineData || typeof inlineData !== 'object')) {
				return;
			}

			// Show modal immediately with loading indicator
			$('#wru-view-reseller-modal .wru-modal-header h3').text('রিসেলার প্রোফাইল ও NID (ID: #' + (userId || '') + ')');
			$('#wru_view_profile_pdf_holder').empty();
			$('#wru_view_profile_body').html(
				'<div style="text-align:center; padding:50px 20px; color:#475569;">' +
				'  <div style="font-size:16px; font-weight:700; margin-bottom:8px; color:#0f172a;">তথ্য ও NID লোড হচ্ছে...</div>' +
				'  <p style="margin:0; font-size:13px; color:#64748b;">অনুগ্রহ করে অপেক্ষা করুন</p>' +
				'</div>'
			);
			$('#wru-view-reseller-modal').fadeIn(200);

			var ajaxUrl = (typeof wru_admin !== 'undefined' && wru_admin.ajax_url) ? wru_admin.ajax_url : (window.ajaxurl || '/wp-admin/admin-ajax.php');
			var nonce = (typeof wru_admin !== 'undefined' && wru_admin.nonce) ? wru_admin.nonce : '';

			$.ajax({
				url: ajaxUrl,
				type: 'GET',
				data: {
					action: 'wru_get_reseller_profile',
					user_id: userId,
					_ajax_nonce: nonce
				},
				dataType: 'json',
				success: function(resp) {
					if (!resp || !resp.success || !resp.data) {
						var errMsg = (resp && resp.data && resp.data.message) ? resp.data.message : 'ডাটা লোড করা সম্ভব হয়নি।';
						$('#wru_view_profile_body').html('<div style="padding:30px; text-align:center; color:#dc2626; font-size:14px;">' + errMsg + '</div>');
						return;
					}
					renderResellerProfileModal(resp.data);
				},
				error: function() {
					if (inlineData && typeof inlineData === 'object' && inlineData.name) {
						renderResellerProfileModal(inlineData);
					} else {
						$('#wru_view_profile_body').html('<div style="padding:30px; text-align:center; color:#dc2626; font-size:14px;">সার্ভার সংযোগে ত্রুটি হয়েছে। অনুগ্রহ করে আবার চেষ্টা করুন।</div>');
					}
				}
			});
		});

		function renderResellerProfileModal(userData) {
			if (!userData) {
				return;
			}

			// Set Modal Title
			$('#wru-view-reseller-modal .wru-modal-header h3').text(
				(userData.name ? userData.name : 'রিসেলার') + ' - বিস্তারিত প্রোফাইল ও NID (ID: #' + (userData.id || '') + ')'
			);

			// Format WhatsApp Link
			var waLink = '';
			if (userData.whatsapp) {
				var cleanWa = String(userData.whatsapp).replace(/[^0-9]/g, '');
				if (String(userData.whatsapp).indexOf('http') === 0) {
					waLink = userData.whatsapp;
				} else if (cleanWa.length === 11 && cleanWa.indexOf('01') === 0) {
					waLink = 'https://wa.me/88' + cleanWa;
				} else if (cleanWa.length > 5) {
					waLink = 'https://wa.me/' + cleanWa;
				}
			}

			// Format Dossier HTML
			var html = '';
			html += '<div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">';

			// Column 1: Personal & Store Info
			html += '  <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">';
			html += '    <h4 style="margin:0 0 12px 0; color:#0f172a; font-size:13.5px; border-bottom:1.5px solid #e2e8f0; padding-bottom:6px;">ব্যক্তিগত ও যোগাযোগের তথ্য</h4>';
			html += '    <p style="margin:0 0 7px 0; font-size:13px;"><strong>পূর্ণ নাম:</strong> ' + $('<div>').text(userData.name || '').html() + '</p>';
			html += '    <p style="margin:0 0 7px 0; font-size:13px;"><strong>ইউজারনেম:</strong> <code>' + $('<div>').text(userData.username || '').html() + '</code></p>';
			html += '    <p style="margin:0 0 7px 0; font-size:13px;"><strong>ইমেইল:</strong> <a href="mailto:' + encodeURI(userData.email || '') + '" style="color:#0284c7;">' + $('<div>').text(userData.email || '').html() + '</a></p>';
			html += '    <p style="margin:0 0 7px 0; font-size:13px;"><strong>মোবাইল:</strong> ' + (userData.phone ? '<a href="tel:' + encodeURI(userData.phone) + '" style="color:#0f172a; font-weight:600;">' + $('<div>').text(userData.phone).html() + '</a>' : '<span style="color:#94a3b8; font-style:italic;">দেওয়া হয়নি</span>') + '</p>';
			html += '    <p style="margin:0 0 7px 0; font-size:13px;"><strong>শপ / পেজের নাম:</strong> ' + (userData.company ? '<strong style="color:#0284c7;">' + $('<div>').text(userData.company).html() + '</strong>' : '<span style="color:#94a3b8; font-style:italic;">দেওয়া হয়নি</span>') + '</p>';
			html += '    <p style="margin:0 0 7px 0; font-size:13px;"><strong>WhatsApp:</strong> ' + (userData.whatsapp ? (waLink ? '<a href="' + encodeURI(waLink) + '" target="_blank" rel="noopener noreferrer" style="color:#16a34a; font-weight:700;">' + $('<div>').text(userData.whatsapp).html() + ' &rarr;</a>' : $('<div>').text(userData.whatsapp).html()) : '<span style="color:#94a3b8; font-style:italic;">দেওয়া হয়নি</span>') + '</p>';
			if (userData.store_url) {
				html += '    <p style="margin:0; font-size:13px;"><strong>ফেসবুক / ওয়েবসাইট:</strong> <a href="' + encodeURI(userData.store_url) + '" target="_blank" rel="noopener noreferrer" style="color:#0284c7; font-weight:600; text-decoration:underline;">' + $('<div>').text(userData.store_url).html() + ' &rarr;</a></p>';
			}
			html += '  </div>';

			// Column 2: Financial & Payout Info
			html += '  <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">';
			html += '    <h4 style="margin:0 0 12px 0; color:#0f172a; font-size:13.5px; border-bottom:1.5px solid #e2e8f0; padding-bottom:6px;">আর্থিক বিবরণী ও পেআউট</h4>';
			var balanceColor = (userData.raw_balance && userData.raw_balance < 0) ? '#dc2626' : '#16a34a';
			html += '    <p style="margin:0 0 7px 0; font-size:13px;"><strong>উত্তোলনযোগ্য ব্যালেন্স:</strong> <strong style="font-size:15px; color:' + balanceColor + ';">' + (userData.balance || '৳0.00') + '</strong></p>';
			html += '    <p style="margin:0 0 7px 0; font-size:13px;"><strong>মোট অর্জিত লাভ:</strong> <strong style="color:#16a34a;">' + (userData.earned || '৳0.00') + '</strong></p>';
			html += '    <p style="margin:0 0 7px 0; font-size:13px;"><strong>পরিশোধিত ক্যাশআউট:</strong> ' + (userData.withdrawn || '৳0.00') + '</p>';
			html += '    <p style="margin:0 0 7px 0; font-size:13px;"><strong>মোট সম্পন্ন অর্ডার:</strong> <strong>' + (userData.completed || 0) + '</strong> / ' + (userData.orders_count || 0) + '</p>';
			html += '    <p style="margin:0 0 7px 0; font-size:13px;"><strong>পেআউট মাধ্যম:</strong> <strong>' + $('<div>').text(userData.p_method || 'সেট করা নেই').html() + '</strong></p>';
			html += '    <p style="margin:0 0 7px 0; font-size:13px;"><strong>পেআউট নাম্বার:</strong> <code>' + $('<div>').text(userData.p_number || 'সেট করা নেই').html() + '</code></p>';
			html += '    <p style="margin:0; font-size:12px; color:#64748b;"><strong>রেজিস্ট্রেশন তারিখ:</strong> ' + (userData.registered || '') + '</p>';
			html += '  </div>';
			html += '</div>';

			// NID Section
			html += '<div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">';
			html += '  <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1.5px solid #e2e8f0; padding-bottom:8px; margin-bottom:14px;">';
			html += '    <h4 style="margin:0; color:#0f172a; font-size:14px; font-weight:700;">জাতীয় পরিচয়পত্র (National ID Card Documents)</h4>';
			if (userData.nid_front || userData.nid_back) {
				html += '    <span style="background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; border-radius:4px; padding:2px 8px; font-size:11px; font-weight:700;">NID ভেরিফাইড আপলোড</span>';
			} else {
				html += '    <span style="background:#fef2f2; color:#991b1b; border:1px solid #fecaca; border-radius:4px; padding:2px 8px; font-size:11px;">NID আপলোড করা হয়নি</span>';
			}
			html += '  </div>';
			html += '  <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">';

			// Front
			html += '    <div style="text-align:center; background:#ffffff; border:1.5px solid #cbd5e1; border-radius:8px; padding:14px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">';
			html += '      <strong style="display:block; font-size:12.5px; margin-bottom:10px; color:#1e293b;">NID কার্ডের সামনের অংশ (Front)</strong>';
			if (userData.nid_front) {
				if (/\.(pdf)$/i.test(userData.nid_front)) {
					html += '      <div style="padding:24px 10px;">';
					html += '        <p style="margin-bottom:12px; font-weight:600; color:#475569;">PDF ফরম্যাট ডকুমেন্ট</p>';
					html += '        <a href="' + encodeURI(userData.nid_front) + '" target="_blank" class="button button-primary" style="font-size:12px;">PDF ডকুমেন্ট দেখুন / ডাউনলোড</a>';
					html += '      </div>';
				} else {
					html += '      <div style="background:#f1f5f9; border-radius:6px; padding:8px; margin-bottom:10px; min-height:160px; display:flex; align-items:center; justify-content:center;">';
					html += '        <a href="' + encodeURI(userData.nid_front) + '" target="_blank" title="বড় করে দেখতে ক্লিক করুন">';
					html += '          <img src="' + encodeURI(userData.nid_front) + '" style="max-width:100%; max-height:190px; object-fit:contain; border-radius:4px;" alt="NID Front" />';
					html += '        </a>';
					html += '      </div>';
					html += '      <div style="display:flex; gap:8px; justify-content:center;">';
					html += '        <a href="' + encodeURI(userData.nid_front) + '" target="_blank" class="button button-small" style="font-size:11.5px;">বড় করে দেখুন</a>';
					html += '        <a href="' + encodeURI(userData.nid_front) + '" download class="button button-small" style="font-size:11.5px;">ডাউনলোড</a>';
					html += '      </div>';
				}
			} else {
				html += '      <div style="padding:30px 10px; color:#94a3b8; font-size:12.5px; font-style:italic;">';
				html += '        কোনো NID সামনের ছবি আপলোড করা নেই';
				html += '      </div>';
			}
			html += '    </div>';

			// Back
			html += '    <div style="text-align:center; background:#ffffff; border:1.5px solid #cbd5e1; border-radius:8px; padding:14px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">';
			html += '      <strong style="display:block; font-size:12.5px; margin-bottom:10px; color:#1e293b;">NID কার্ডের পেছনের অংশ (Back)</strong>';
			if (userData.nid_back) {
				if (/\.(pdf)$/i.test(userData.nid_back)) {
					html += '      <div style="padding:24px 10px;">';
					html += '        <p style="margin-bottom:12px; font-weight:600; color:#475569;">PDF ফরম্যাট ডকুমেন্ট</p>';
					html += '        <a href="' + encodeURI(userData.nid_back) + '" target="_blank" class="button button-primary" style="font-size:12px;">PDF ডকুমেন্ট দেখুন / ডাউনলোড</a>';
					html += '      </div>';
				} else {
					html += '      <div style="background:#f1f5f9; border-radius:6px; padding:8px; margin-bottom:10px; min-height:160px; display:flex; align-items:center; justify-content:center;">';
					html += '        <a href="' + encodeURI(userData.nid_back) + '" target="_blank" title="বড় করে দেখতে ক্লিক করুন">';
					html += '          <img src="' + encodeURI(userData.nid_back) + '" style="max-width:100%; max-height:190px; object-fit:contain; border-radius:4px;" alt="NID Back" />';
					html += '        </a>';
					html += '      </div>';
					html += '      <div style="display:flex; gap:8px; justify-content:center;">';
					html += '        <a href="' + encodeURI(userData.nid_back) + '" target="_blank" class="button button-small" style="font-size:11.5px;">বড় করে দেখুন</a>';
					html += '        <a href="' + encodeURI(userData.nid_back) + '" download class="button button-small" style="font-size:11.5px;">ডাউনলোড</a>';
					html += '      </div>';
				}
			} else {
				html += '      <div style="padding:30px 10px; color:#94a3b8; font-size:12.5px; font-style:italic;">';
				html += '        কোনো NID পেছনের ছবি আপলোড করা নেই';
				html += '      </div>';
			}
			html += '    </div>';

			html += '  </div>';
			html += '</div>';

			$('#wru_view_profile_body').html(html);

			// PDF Export button in footer
			if (userData.pdf_url) {
				$('#wru_view_profile_pdf_holder').html('<a href="' + userData.pdf_url + '" target="_blank" class="button button-primary" style="background:#0284c7; border-color:#0369a1; color:#fff; font-weight:700; padding:4px 14px; font-size:13px; display:inline-flex; align-items:center; gap:5px;">এক ক্লিকে সম্পূর্ণ PDF এক্সপোর্ট (NID সহ)</a>');
			} else {
				$('#wru_view_profile_pdf_holder').empty();
			}

			$('#wru-view-reseller-modal').fadeIn(200);
		}


		/* ==========================================================================
		   3. Product Edit Screen Price Label Renaming
		   (Regular price -> Market price, Sale price -> Resell price)
		   ========================================================================== */

		function replaceProductPriceLabels() {
			if (!$('body').hasClass('post-type-product')) {
				return;
			}

			// Simple Product General tab labels
			$('label[for="_regular_price"]').each(function() {
				var text = $(this).html();
				if (text && text.indexOf('Market price') === -1) {
					$(this).html(text.replace(/Regular price/gi, 'Market price'));
				}
			});

			$('label[for="_sale_price"]').each(function() {
				var text = $(this).html();
				if (text && text.indexOf('Resell price') === -1) {
					$(this).html(text.replace(/Sale price/gi, 'Resell price'));
				}
			});

			// Variable Product Variations labels
			$('label[for^="variable_regular_price_"], .variable_pricing label').each(function() {
				var text = $(this).html();
				if (text && text.indexOf('Market price') === -1 && /Regular price/i.test(text)) {
					$(this).html(text.replace(/Regular price/gi, 'Market price'));
				}
			});

			$('label[for^="variable_sale_price_"], .variable_pricing label').each(function() {
				var text = $(this).html();
				if (text && text.indexOf('Resell price') === -1 && /Sale price/i.test(text)) {
					$(this).html(text.replace(/Sale price/gi, 'Resell price'));
				}
			});

			// Variation input placeholders
			$('input[name^="variable_regular_price"]').attr('placeholder', function(i, val) {
				return val ? val.replace(/Regular price/gi, 'Market price') : val;
			});
			$('input[name^="variable_sale_price"]').attr('placeholder', function(i, val) {
				return val ? val.replace(/Sale price/gi, 'Resell price') : val;
			});

			// Variations bulk action dropdown options
			$('#field_to_edit option').each(function() {
				var optText = $(this).text();
				if (optText.indexOf('Regular price') !== -1) {
					$(this).text(optText.replace(/Regular price/gi, 'Market price'));
				}
				if (optText.indexOf('Sale price') !== -1) {
					$(this).text(optText.replace(/Sale price/gi, 'Resell price'));
				}
			});

			// Quick Edit in Products List table
			$('.inline-edit-col label').each(function() {
				var $title = $(this).find('.title');
				if ($title.length) {
					var t = $title.text();
					if (t.indexOf('Regular price') !== -1) {
						$title.text(t.replace(/Regular price/gi, 'Market price'));
					}
					if (t.indexOf('Sale price') !== -1) {
						$title.text(t.replace(/Sale price/gi, 'Resell price'));
					}
				}
			});
		}

		replaceProductPriceLabels();

		$(document).on('click', '.editinline', function() {
			setTimeout(replaceProductPriceLabels, 100);
		});

		// Trigger on variation events and tab switching
		$(document).on('woocommerce_variations_loaded woocommerce_variations_added woocommerce_variations_saved_ajax', function() {
			replaceProductPriceLabels();
		});

		$('#woocommerce-product-data').on('woocommerce_variations_loaded', function() {
			replaceProductPriceLabels();
		});

		// Check when clicking product data tabs (e.g. variations tab)
		$(document).on('click', '.product_data_tabs a', function() {
			setTimeout(replaceProductPriceLabels, 150);
		});

	});
})(jQuery);
