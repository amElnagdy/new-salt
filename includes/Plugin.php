<?php

namespace SaltShaker;

class Plugin {
	/**
	 * The single instance of the class.
	 *
	 * @var Plugin
	 */
	protected static $instance = null;

	/**
	 * Main Plugin instance.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new Plugin();
		}

		return self::$instance;
	}

	public function run(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		$options = new Options();
		$core    = new Core();
		$admin   = new Admin( $core, $options );
		$admin->init();
	}

	// Load the text domain
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'salt-shaker',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}
}
