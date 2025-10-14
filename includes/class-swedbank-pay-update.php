<?php

namespace SwedbankPay\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * @SuppressWarnings(PHPMD.CamelCaseClassName)
 * @SuppressWarnings(PHPMD.CamelCaseMethodName)
 * @SuppressWarnings(PHPMD.CamelCaseParameterName)
 * @SuppressWarnings(PHPMD.CamelCasePropertyName)
 * @SuppressWarnings(PHPMD.CamelCaseVariableName)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class Swedbank_Pay_Update {

	/** @var array DB updates that need to be run */
	private static $db_updates = array();

	/**
	 * Handle updates
	 */
	public static function update() {
		$current_version = get_option( Swedbank_Pay_Plugin::DB_VERSION_SLUG, '1.0.0' );
		foreach ( self::$db_updates as $version => $updater ) {
			if ( version_compare( $current_version, $version, '<' ) ) {
				include __DIR__ . '/../' . $updater;
				self::update_db_version( $version );
			}
		}
	}

	/**
	 * Update DB version.
	 *
	 * @param string $version
	 */
	private static function update_db_version( $version ) {
		delete_option( Swedbank_Pay_Plugin::DB_VERSION_SLUG );
		add_option( Swedbank_Pay_Plugin::DB_VERSION_SLUG, $version );
	}
}
