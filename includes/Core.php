<?php

namespace SaltShaker;

use Error;
use Exception;

class Core {
	private const SALT_KEYS = [
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT'
	];

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( 'salt_shaker_change_salts', array( $this, 'shuffleSalts' ) );
	}

	/**
	 * Get the current salt values from wp-config.php
	 *
	 * @return array
	 */
	public function getSaltsArray(): array {
		$salts = [];
		foreach ( self::SALT_KEYS as $key ) {
			try {
				$key           = trim( $key, ",'" );  // Clean up the key
				$value         = defined( $key ) ? constant( $key ) : '';
				$salts[ $key ] = $value;
			} catch ( Error|Exception $e ) {
				$salts[ $key ] = '';
			}
		}

		return $salts;
	}

	/**
	 * Change WordPress salt keys
	 *
	 * @return bool
	 */
	public function shuffleSalts(): bool {
		$http_salts = wp_remote_get( 'https://api.wordpress.org/secret-key/1.1/salt/' );

		// Check for API failures or invalid responses
		if (
			is_wp_error( $http_salts ) ||
			wp_remote_retrieve_response_code( $http_salts ) !== 200 ||
			empty( wp_remote_retrieve_body( $http_salts ) ) ||
			strpos( wp_remote_retrieve_body( $http_salts ), '404 Not Found' ) !== false
		) {
			// API call failed or invalid format, generate salts locally
			$returned_salts = $this->generateLocalSalts();
		} else {
			$raw_salts       = wp_remote_retrieve_body( $http_salts );
			$processed_salts = $this->processSalts( $raw_salts );
			$returned_salts  = $processed_salts ? $processed_salts : $this->generateLocalSalts();
		}

		$new_salts = explode( "\n", $returned_salts );

		// Adding filters for additional salts.
		$new_salts = apply_filters( 'salt_shaker_salts', $new_salts );
		$salt_keys = apply_filters( 'salt_shaker_salt_ids', self::SALT_KEYS );

		return $this->writeSalts( $salt_keys, $new_salts );
	}

	/**
	 * Generate salt keys locally if WP.org API fails
	 *
	 * @return string
	 */
	private function generateLocalSalts(): string {
		$salts = '';
		foreach ( self::SALT_KEYS as $salt ) {
			$generated_password = wp_generate_password( 64, true, true );
			$salts              .= "define('" . $salt . "', '" . $generated_password . "');\n";
		}

		return $salts;
	}

	/**
	 * Process and validate salt keys
	 *
	 * @param string $salts
	 *
	 * @return string|false
	 */
	private function processSalts( string $salts ) {
		// First validate the overall format
		if ( ! preg_match( "/define\(\s*'[A-Z_]+'\s*,\s*'[^']+'\s*\);/", $salts ) ) {
			return false;
		}

		$lines           = explode( "\n", $salts );
		$processed_lines = array_map( function ( $line ) {
			if ( empty( trim( $line ) ) ) {
				return '';
			}

			// Handle escaped backslashes at the end of the salt value
			$line = preg_replace( "/'([^']*?)\\\\'$/", "'$1'", $line );

			// Ensure the line is properly formatted
			if ( ! preg_match( "/^define\(\s*'[A-Z_]+'\s*,\s*'[^']+'\s*\);$/", $line ) ) {
				return '';
			}

			return $line;
		}, $lines );

		$processed_lines = array_filter( $processed_lines );

		return implode( "\n", $processed_lines );
	}

	/**
	 * Write new salt keys to wp-config.php
	 *
	 * @param array $salts_array
	 * @param array $new_salts
	 *
	 * @return bool
	 */
	private function writeSalts( array $salts_array, array $new_salts ): bool {
		$config_file = $this->getConfigFile();
		if ( ! $config_file ) {
			return false;
		}

		// Get current file permissions
		$perms = fileperms( $config_file );

		// Make file writable if it's not
		if ( ! is_writable( $config_file ) ) {
			if ( ! @chmod( $config_file, 0644 ) ) {
				return false;
			}
		}

		$config_content = file_get_contents( $config_file );
		if ( $config_content === false ) {
			return false;
		}

		foreach ( $salts_array as $key => $salt ) {
			// Clean up the key by removing any quotes and commas
			$clean_key = trim( $salt, ",'" );
			// Create a pattern that matches the define statement with any whitespace
			$pattern        = "/define\s*\(\s*['\"]?" . preg_quote( $clean_key, '/' ) . "['\"]?\s*,\s*'[^']*'\s*\);/";
			$config_content = preg_replace( $pattern, trim( $new_salts[ $key ] ), $config_content );
		}

		$result = (bool) file_put_contents( $config_file, $config_content );

		// Restore original file permissions
		@chmod( $config_file, $perms );

		return $result;
	}

	/**
	 * Get wp-config.php file path
	 *
	 * @return string|false
	 */
	private function getConfigFile() {
		// Check if the file name is wp-salt.php used in some hosting providers
		$wp_salts_file   = 'wp-salt';
		$salts_file_name = ( file_exists( ABSPATH . $wp_salts_file . '.php' ) )
			? $wp_salts_file
			: apply_filters( 'salt_shaker_salts_file', 'wp-config' );

		$config_file    = ABSPATH . $salts_file_name . '.php';
		$config_file_up = ABSPATH . '../' . $salts_file_name . '.php';

		if ( file_exists( $config_file ) && is_writable( $config_file ) ) {
			return $config_file;
		} elseif ( file_exists( $config_file_up ) && is_writable( $config_file_up ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return $config_file_up;
		}

		return false;
	}

	/**
	 * Add custom cron schedules
	 *
	 * @param array $schedules
	 *
	 * @return array
	 */
	public function add_cron_schedule( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( 'Weekly', 'salt-shaker' )
			);
		}

		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Monthly', 'salt-shaker' )
			);
		}

		if ( ! isset( $schedules['quarterly'] ) ) {
			$schedules['quarterly'] = array(
				'interval' => 90 * DAY_IN_SECONDS, // 3 months
				'display'  => __( 'Quarterly', 'salt-shaker' )
			);
		}

		if ( ! isset( $schedules['biannually'] ) ) {
			$schedules['biannually'] = array(
				'interval' => 180 * DAY_IN_SECONDS, // 6 months
				'display'  => __( 'Biannually', 'salt-shaker' )
			);
		}

		return $schedules;
	}

	/**
	 * Check if wp-config.php is writable
	 *
	 * @return array {
	 *     Status information about the config file
	 *
	 * @type bool $writable Whether the file is writable
	 * @type string $message Error message if file is not writable
	 * @type string $file The path to the config file
	 * }
	 */
	public function checkConfigFilePermissions(): array {
		$config_file = $this->getConfigFile();

		if ( ! $config_file ) {
			return [
				'writable' => false,
				'message'  => __( 'wp-config.php file not found or not accessible.', 'salt-shaker' ),
				'file'     => ''
			];
		}

		if ( ! is_writable( $config_file ) ) {
			return [
				'writable' => false,
				'message'  => sprintf(
				/* translators: %s: wp-config.php file path */
					__( 'wp-config.php file (%s) is not writable. Please check file permissions.', 'salt-shaker' ),
					$config_file
				),
				'file'     => $config_file
			];
		}

		return [
			'writable' => true,
			'message'  => '',
			'file'     => $config_file
		];
	}
}
