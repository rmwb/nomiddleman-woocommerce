(function () {
	'use strict';

	if (!window.wc || !window.wc.wcBlocksRegistry || !window.wp || !window.wp.element) {
		return;
	}

	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var el = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var decodeEntities = window.wp.htmlEntities.decodeEntities;

	var settings = getSetting('nmmpro_gateway_data', {});
	var i18n = settings.i18n || {};
	var cryptos = settings.cryptos || [];
	var defaultCrypto = cryptos.length > 0 ? cryptos[0].id : '';
	var label = decodeEntities(settings.title || i18n.defaultTitle || 'Pay with cryptocurrency');

	function Content(props) {
		var stateHook = useState(defaultCrypto);
		var selected = stateHook[0];
		var setSelected = stateHook[1];
		var onPaymentSetup = props.eventRegistration.onPaymentSetup;
		var responseTypes = props.emitResponse.responseTypes;

		useEffect(
			function () {
				var unsubscribe = onPaymentSetup(function () {
					if (!selected) {
						return {
							type: responseTypes.ERROR,
							message: i18n.chooseError || 'Please choose a cryptocurrency.'
						};
					}

					return {
						type: responseTypes.SUCCESS,
						meta: {
							paymentMethodData: {
								nmm_currency_id: selected
							}
						}
					};
				});

				return unsubscribe;
			},
			[onPaymentSetup, selected, responseTypes]
		);

		return el(
			'div',
			{ className: 'nmm-blocks-payment-method' },
			el(
				'label',
				{ htmlFor: 'nmm_currency_id', style: { display: 'block', marginBottom: '4px' } },
				i18n.chooseLabel || 'Choose a cryptocurrency'
			),
			el(
				'select',
				{
					id: 'nmm_currency_id',
					value: selected,
					style: { width: '100%' },
					onChange: function (event) {
						setSelected(event.target.value);
					}
				},
				cryptos.map(function (crypto) {
					return el('option', { key: crypto.id, value: crypto.id }, crypto.name);
				})
			)
		);
	}

	registerPaymentMethod({
		name: 'nmmpro_gateway',
		label: label,
		ariaLabel: label,
		content: el(Content, null),
		edit: el(Content, null),
		canMakePayment: function () {
			return cryptos.length > 0;
		},
		supports: {
			features: settings.supports || ['products']
		}
	});
})();
