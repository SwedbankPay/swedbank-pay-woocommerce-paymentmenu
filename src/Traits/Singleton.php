<?php
namespace Krokedil\Swedbank\Pay\Traits;

defined( 'ABSPATH' ) || exit;

/**
 * Trait Singleton
 *
 * A trait to make a class a singleton
 */
trait Singleton {

	/**
	 * Instance of the class
	 *
	 * @var object
	 */
	private static $instance;

	/**
	 * Get the instance of the class
	 *
	 * @return object
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Prevent creating a new instance of the class
	 */
	private function __construct() {
	}

	/**
	 * Prevent cloning the instance of the class
	 */
	public function __clone() {
	}

	/**
	 * Prevent unserializing the instance of the class
	 */
	public function __wakeup() {
	}
}
