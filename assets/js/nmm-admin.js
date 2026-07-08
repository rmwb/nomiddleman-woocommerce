(function ($) {
	'use strict';

	// ---- Tabs -------------------------------------------------------------

	function activateTab(tabId) {
		$('.nmm-tab-nav a').removeClass('nmm-tab-active');
		$('.nmm-tab-nav a[data-tab="' + tabId + '"]').addClass('nmm-tab-active');
		$('.nmm-tab-panel').hide();
		$('#nmm-tab-' + tabId).show();

		if (window.history && window.history.replaceState) {
			var url = new URL(window.location.href);
			url.searchParams.set('tab', tabId);
			window.history.replaceState(null, '', url.toString());
		}
	}

	// ---- Mode-dependent rows ----------------------------------------------

	function refreshModeRows($panel) {
		var mode = $panel.find('.nmm-mode-select input[type=radio]:checked').val();

		$panel.find('.nmm-requires-mode').each(function () {
			var modes = String($(this).data('modes')).split(',');
			$(this).toggle(mode !== undefined && modes.indexOf(mode) !== -1);
		});
	}

	// ---- Multi text (wallet addresses) -------------------------------------

	function bindMultiText($container) {
		$container.on('click', '.nmm-multi-text-add', function () {
			var $rows = $container.find('.nmm-multi-text-row');
			var $newRow = $rows.first().clone();
			$newRow.find('input').val('');
			$newRow.insertAfter($rows.last());
		});

		$container.on('click', '.nmm-multi-text-remove', function () {
			var $rows = $container.find('.nmm-multi-text-row');
			if ($rows.length > 1) {
				$(this).closest('.nmm-multi-text-row').remove();
			} else {
				$rows.find('input').val('');
			}
		});
	}

	// ---- MPK sample addresses ----------------------------------------------

	var xhr = {};

	function validMpk(mpk) {
		var start = mpk.substring(0, 5);
		return (start === 'xpub6' || start === 'ypub6' || start === 'zpub6') && mpk.length === 111;
	}

	function setSamples(cryptoId, text) {
		for (var i = 0; i < 3; i++) {
			$('#' + cryptoId + '_hd_mpk_sample_addresses-' + i).val(text);
		}
	}

	function generateSamples(cryptoId) {
		var mpk = $('#' + cryptoId + '_hd_mpk-textarea').val().trim();
		var $inputs = $('.nmm-sample-addresses[data-crypto="' + cryptoId + '"] input');

		if (xhr[cryptoId]) {
			xhr[cryptoId].abort();
			xhr[cryptoId] = null;
		}

		if (!validMpk(mpk)) {
			setSamples(cryptoId, mpk === '' ? '' : 'Please enter a valid MPK');
			$inputs.removeClass('nmm-flash-green nmm-flash-yellow nmm-flash-red');
			return;
		}

		xhr[cryptoId] = $.ajax({
			type: 'POST',
			url: window.ajaxurl || 'admin-ajax.php',
			data: {
				action: 'firstmpkaddress',
				mpk: mpk,
				cryptoId: cryptoId,
				hdMode: '0'
			},
			beforeSend: function () {
				setSamples(cryptoId, 'Generating HD addresses...');
				$inputs.removeClass('nmm-flash-green nmm-flash-red').addClass('nmm-flash-yellow');
			}
		}).fail(function (response) {
			$inputs.removeClass('nmm-flash-yellow');
			if (response.status === 0) {
				return;
			}
			setSamples(cryptoId, 'Address creation failed, please check your MPK.');
			$inputs.addClass('nmm-flash-red');
		}).done(function (responseJson) {
			$inputs.removeClass('nmm-flash-yellow').addClass('nmm-flash-green');
			var addresses;
			try {
				addresses = JSON.parse(responseJson);
			} catch (e) {
				setSamples(cryptoId, 'Address creation failed, please check your MPK.');
				$inputs.removeClass('nmm-flash-green').addClass('nmm-flash-red');
				return;
			}
			for (var i = 0; i < 3; i++) {
				$('#' + cryptoId + '_hd_mpk_sample_addresses-' + i).val(addresses[i] || '');
			}
		});
	}

	// ---- Boot ---------------------------------------------------------------

	$(function () {
		$('.nmm-tab-nav').on('click', 'a', function (e) {
			e.preventDefault();
			activateTab($(this).data('tab'));
		});

		var params = new URLSearchParams(window.location.search);
		var initial = params.get('tab');
		if (!initial || $('.nmm-tab-nav a[data-tab="' + initial + '"]').length === 0) {
			initial = $('.nmm-tab-nav a').first().data('tab');
		}
		activateTab(initial);

		$('.nmm-crypto-panel').each(function () {
			var $panel = $(this);
			refreshModeRows($panel);
			$panel.on('change', '.nmm-mode-select input[type=radio]', function () {
				refreshModeRows($panel);
			});
		});

		$('.nmm-multi-text').each(function () {
			bindMultiText($(this));
		});

		$('.nmm-mpk-input').each(function () {
			var cryptoId = $(this).data('crypto');
			if (validMpk($(this).val().trim())) {
				generateSamples(cryptoId);
			}
			$(this).on('keyup', function () {
				generateSamples(cryptoId);
			});
		});
	});
})(jQuery);
