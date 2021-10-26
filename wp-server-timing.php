<?php

/*
 Plugin Name: WP Server Timing
 Plugin URI: https://github.com/ocReaper/wordpress-server-timing
 Description: Add Server-Timing header information from within your WordPress sites.
 Author: ocReaper
 Version: 0.2.0
 Author URI: https://akosresch.wordpress.com/
 Text Domain: wp-server-timing
 */

class WP_Server_Timing {

	protected int $time_bootstrap = 0;

	protected int $time_http_request = 0;

	protected int $time_plugins;

	protected int $time_setup_theme;

	protected int $time_after_setup_theme;

	public function __construct() {
		add_filter( 'http_request_args', array( $this, 'add_time_start_to_http_request' ), 10, 3 );

		add_filter( 'http_api_debug', array( $this, 'calculate_duration_after_http_request' ), 10, 5 );

		add_action( 'wp_loaded', array( $this, 'add_time_metric_to_wp_loaded' ), PHP_INT_MAX );

		add_action( 'plugins_loaded', array( $this, 'add_time_metric_to_plugins_loaded' ), PHP_INT_MAX );

		add_action( 'setup_theme', array( $this, 'add_time_metric_to_setup_theme' ), PHP_INT_MAX );

		add_action( 'after_setup_theme', array( $this, 'add_time_metric_to_after_setup_theme' ), PHP_INT_MAX );

		add_action( 'send_headers', array( $this, 'start_collecting_response' ), - 1 );

		add_action( 'send_headers', array( $this, 'start_collecting_response' ), - 1 );

		add_action( 'wp_footer', array( $this, 'flush_content_and_add_timing_headers' ), PHP_INT_MAX );
	}

	public function add_time_start_to_http_request( $args, $url ): array {
		$args['time_start'] = microtime( true );

		return $args;
	}

	public function calculate_duration_after_http_request( $response, $type, $class, $args, $url ) {
		if ( $type !== 'response' ) {
			return;
		}

		$args['time_stop'] = microtime( true );

		$args['duration'] = $args['time_stop'] - $args['time_start'];
		$args['duration'] *= 1000;

		$this->time_http_request += $args['duration'];
	}

	public function calculate_query_time(): int {
		global $wpdb;

		$time_wp_query = 0;

		if ( ! empty( $wpdb->queries ) ) {
			foreach ( $wpdb->queries as $q ) {
				list( $query, $elapsed, $debug ) = $q;

				$time_wp_query += $elapsed;
			}
		}

		return $time_wp_query * 1000;
	}

	public function add_time_metric_to_wp_loaded() {
		$this->time_bootstrap = microtime( true );
	}

	public function add_time_metric_to_plugins_loaded() {
		$this->time_plugins = microtime( true );
	}

	public function add_time_metric_to_setup_theme() {
		$this->time_setup_theme = microtime( true );
	}

	public function add_time_metric_to_after_setup_theme() {
		$this->time_after_setup_theme = microtime( true );
	}

	public function start_collecting_response() {
		ob_start();
	}

	public function generate_timing_header( $name, $description, $start_time, $end_time = null ): string {
		if ( is_null( $end_time ) ) {
			$end_time = microtime( true );
		}

		$time_spent = ( $end_time - $start_time ) * 1000;

		return "$name;desc=\"$description\";dur=\"$time_spent\"";
	}

	public function flush_content_and_add_timing_headers() {
		global $timestart;

		$requestStart    = floatval( $_SERVER['REQUEST_TIME_FLOAT'] ?? $timestart );
		$bootstrapTime   = $this->generate_timing_header( 'bootstrap', 'WP setup', $requestStart, $timestart );
		$wpLoadTime      = $this->generate_timing_header( 'wpload', 'Non template logic', $this->time_after_setup_theme, $this->time_bootstrap );
		$appTime         = $this->generate_timing_header( 'html', 'Template processing', $this->time_bootstrap );
		$apiTime         = 'api;desc="API request processing";dur=' . $this->time_http_request;
		$dbTime          = 'db;desc="WPDB";dur=' . $this->calculate_query_time();
		$globalQueryTime = $this->generate_timing_header( 'globals', 'Global query setup', $this->time_plugins, $this->time_setup_theme );
		$pluginTime      = $this->generate_timing_header( 'plugins', 'Plugins loaded', $timestart, $this->time_plugins );
		$themeTime       = $this->generate_timing_header( 'theme', 'Theme loaded', $this->time_setup_theme, $this->time_after_setup_theme );
		$totalTime       = $this->generate_timing_header( 'total', 'Total application run time', $requestStart );

		$data = apply_filters(
			'wp_server_timing_flush_timing_headers',
			[
				$bootstrapTime,
				$dbTime,
				$globalQueryTime,
				$pluginTime,
				$themeTime,
				$wpLoadTime,
				$apiTime,
				$appTime,
				$totalTime
			]
		);

		header( 'Server-Timing: ' . implode( ', ', $data ) );
		ob_end_flush();
	}

}

global $wp_server_timing;

$wp_server_timing = new WP_Server_Timing();
