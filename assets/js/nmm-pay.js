(function () {
	'use strict';

	// ---- Pay in browser wallet (EVM: MetaMask or any injected provider) ----

	var i18n = window.nmmPayI18n || {};

	function t(key, fallback) {
		return i18n[key] || fallback;
	}

	var btn = document.getElementById('nmm-wallet-pay');

	function setMsg(text) {
		var el = document.getElementById('nmm-wallet-msg');
		if (el) {
			el.textContent = text;
		}
	}

	function pad64(hexNoPrefix) {
		while (hexNoPrefix.length < 64) {
			hexNoPrefix = '0' + hexNoPrefix;
		}
		return hexNoPrefix;
	}

	function payEvm() {
		var d = btn.dataset;
		var chainHex = '0x' + parseInt(d.chain, 10).toString(16);

		window.ethereum.request({ method: 'eth_requestAccounts' }).then(function (accounts) {
			return window.ethereum
				.request({ method: 'wallet_switchEthereumChain', params: [{ chainId: chainHex }] })
				.then(function () { return accounts; });
		}).then(function (accounts) {
			var tx = { from: accounts[0] };

			if (d.contract) {
				// ERC-20 transfer(address,uint256)
				tx.to = d.contract;
				tx.value = '0x0';
				tx.data = '0xa9059cbb'
					+ pad64(d.to.replace(/^0x/, '').toLowerCase())
					+ pad64(BigInt(d.units).toString(16));
			} else {
				tx.to = d.to;
				tx.value = '0x' + BigInt(d.units).toString(16);
			}

			setMsg(t('confirmInWallet', 'Confirm the payment in your wallet…'));
			return window.ethereum.request({ method: 'eth_sendTransaction', params: [tx] });
		}).then(function (hash) {
			setMsg(t('txSent', 'Transaction sent (%s…). Waiting for the network to confirm — this page updates automatically.').replace('%s', hash.substring(0, 18)));
		}).catch(function (err) {
			if (err && err.code === 4902) {
				setMsg(t('unknownNetwork', 'Your wallet does not know this network. Please add it in your wallet and try again.'));
			} else if (err && err.code === 4001) {
				setMsg(t('cancelledInWallet', 'Payment cancelled in wallet.'));
			} else {
				setMsg((err && err.message) ? err.message : t('walletFailed', 'Could not start the wallet payment. You can still pay by scanning the QR code or copying the address.'));
			}
		});
	}

	if (btn) {
		if (window.ethereum) {
			btn.style.display = '';
			btn.addEventListener('click', payEvm);
		}
		// no injected wallet: the button stays hidden, QR + address remain
	}

	// ---- Live payment status --------------------------------------------

	var status = document.getElementById('nmm-payment-status');

	if (status && status.dataset.order) {
		var pollDelay = 15000;

		var tick = function () {
			var url = status.dataset.ajax
				+ '?action=nmm_order_status'
				+ '&order_id=' + encodeURIComponent(status.dataset.order)
				+ '&key=' + encodeURIComponent(status.dataset.key);

			fetch(url, { credentials: 'same-origin' }).then(function (r) {
				return r.json();
			}).then(function (json) {
				if (!json || !json.success) {
					return; // stop polling on auth/error responses
				}

				if (json.data.paid) {
					status.textContent = t('paid', 'Payment received — thank you!');
					status.classList.add('nmm-status-paid');
					window.setTimeout(function () { window.location.reload(); }, 2000);
					return;
				}

				if (json.data.underpaid) {
					status.textContent = t('partial', 'Partial payment received: %1$s of %2$s. Please send the remaining amount to the same address.')
						.replace('%1$s', json.data.received)
						.replace('%2$s', json.data.expected);
				}

				window.setTimeout(tick, pollDelay);
			}).catch(function () {
				window.setTimeout(tick, pollDelay * 2);
			});
		};

		window.setTimeout(tick, pollDelay);
	}
})();
