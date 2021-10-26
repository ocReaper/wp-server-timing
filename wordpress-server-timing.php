<?php

/*
 Plugin Name: WordPress Server Timing
 Plugin URI: https://github.com/ocReaper/wordpress-server-timing
 Description: Add Server-Timing header information from within your WordPress sites.
 Author: ocReaper
 Version: 0.1.0
 Author URI: https://akosresch.wordpress.com/
 Text Domain: wordpress-server-timing
 */

global $timebootstrap, $timehttprequest;

$timebootstrap = 0;
$timehttprequest = 0;

function add_time_start_to_http_request( $args, $url ) {
    $args['time_start'] = microtime( true );

    return $args;
}

function calculate_duration_after_http_request( $response, $type, $class, $args, $url ) {
    global $timehttprequest;

    if ( $type !== 'response' ) {
        return;
    }

    $args['time_stop'] = microtime( true );

    $args['duration'] = $args['time_stop'] - $args['time_start'];
    $args['duration'] *= 1000;

    $timehttprequest += $args['duration'];
}

add_filter( 'http_request_args', 'add_time_start_to_http_request', 10, 3 );
add_filter( 'http_api_debug', 'calculate_duration_after_http_request', 10, 5 );

function calculate_query_time() {
    global $wpdb;

    $timewpquery = 0;

    if ( ! empty( $wpdb->queries ) ) {
        foreach ( $wpdb->queries as $q ) {
            list( $query, $elapsed, $debug ) = $q;

            $timewpquery += $elapsed;
        }
    }

    return $timewpquery * 1000;
}

function add_time_metric_to_wp_loaded() {
    global $timebootstrap;

    $timebootstrap = microtime( true );
}

add_action('wp_loaded', 'add_time_metric_to_wp_loaded', PHP_INT_MAX);

function add_time_metric_to_plugins_loaded() {
    global $timeplugins;

    $timeplugins = microtime( true );
}

add_action('plugins_loaded', 'add_time_metric_to_plugins_loaded', PHP_INT_MAX);

function add_time_metric_to_setup_theme() {
    global $timesetuptheme;

    $timesetuptheme = microtime( true );
}

add_action('setup_theme', 'add_time_metric_to_setup_theme', PHP_INT_MAX);

function add_time_metric_to_after_setup_theme() {
    global $timeaftersetuptheme;

    $timeaftersetuptheme = microtime( true );
}

add_action('after_setup_theme', 'add_time_metric_to_after_setup_theme', PHP_INT_MAX);

function start_collecting_response() {
    ob_start();
}

add_action('send_headers', 'start_collecting_response', -1);

function flush_content_and_add_timig_headers() {
    global $timestart, $timeend, $timebootstrap, $timehttprequest, $timeplugins, $timesetuptheme, $timeaftersetuptheme;

    $requestStart = floatval($_SERVER['REQUEST_TIME_FLOAT']);
    $bootstrapTime = 'bootstrap;desc="WP setup";dur='.(($timestart - $requestStart) * 1000);
    $wpLoadTime = 'wpload;desc="Non template logic";dur='.(($timebootstrap - $timeaftersetuptheme) * 1000);
    $appTime = 'html;desc="Template processing";dur='.((microtime(true) - $timebootstrap) * 1000);
    $apiTime = 'api;desc="API request processing";dur='.$timehttprequest;
    $dbTime = 'db;desc="WPDB";dur='.calculate_query_time();
    $globalQueryTime = 'globals;desc="Global query setup";dur='.(($timesetuptheme - $timeplugins) * 1000);
    $pluginTime = 'plugins;desc="Plugins loaded";dur='.(($timeplugins - $timestart) * 1000);
    $themeTime = 'theme;desc="Theme loaded";dur='.(($timeaftersetuptheme - $timesetuptheme) * 1000);
    $totalTime = 'total;desc="Total application run time";dur='.((microtime(true) - $requestStart) * 1000);

    $data = [$bootstrapTime, $dbTime, $globalQueryTime, $pluginTime, $themeTime, $wpLoadTime, $apiTime, $appTime, $totalTime];
    $data = apply_filters( 'wp_server_timing_flush_timing_headers', $data );

    header('Server-Timing: ' . implode(', ', $data));
    ob_end_flush();
}

add_action('wp_footer', 'flush_content_and_add_timig_headers', PHP_INT_MAX);
