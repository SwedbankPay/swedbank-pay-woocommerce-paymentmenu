<?php //phpcs:disable

function customize_php_scoper_config( array $config ): array {
	$config['exclude-constants'][] = 'ABSPATH';
	$config['exclude-constants'][] = 'SWEDBANK_PAY_VERSION';
	$config['exclude-constants'][] = 'SWEDBANK_PAY_MAIN_FILE';
	$config['exclude-constants'][] = 'SWEDBANK_PAY_PLUGIN_PATH';
	$config['exclude-constants'][] = 'SWEDBANK_PAY_PLUGIN_URL';
	$config['exclude-classes'][] = 'WooCommerce';
	$config['exclude-classes'][] = 'WC_Product';
	$config['exclude-classes'][] = 'WP_Error';
	$config['exclude-classes'][] = 'WC_ABSPATH';
	$config['exclude-classes'][] = 'Swedbank_Pay_Plugin';

	$functions = array(
		'swedbank_pay_is_hpos_enabled',
		'swedbank_pay_get_order',
		'swedbank_pay_get_payment_method',
		'swedbank_pay_get_order_lines',
		'swedbank_pay_get_available_line_items_for_refund',
		'swedbank_pay_generate_payee_reference',
	);

	$config['exclude-functions'] = array_merge( $config['exclude-functions'] ?? array(), $functions );
	$config['exclude-namespaces'][] = 'Automattic';

	return $config;
}
