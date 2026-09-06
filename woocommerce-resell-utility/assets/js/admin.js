/**
 * WooCommerce Resell Utility - Admin Scripts
 */

(function($) {
	'use strict';

	$(document).ready(function() {
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
	});
})(jQuery);
