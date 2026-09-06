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

	});
})(jQuery);
