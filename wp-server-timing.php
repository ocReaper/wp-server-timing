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

class WPServerTiming
{
    protected $timebootstrap = 0;
    protected $timehttprequest = 0;
    protected $timeplugins;
    protected $timesetuptheme;
    protected $timeaftersetuptheme;

    public function __construct()
    {
        add_filter('http_request_args', array($this, 'add_time_start_to_http_request'), 10, 3);

        add_filter('http_api_debug', array($this, 'calculate_duration_after_http_request'), 10, 5);

        add_action('wp_loaded', array($this, 'add_time_metric_to_wp_loaded'), PHP_INT_MAX);

        add_action('plugins_loaded', array($this, 'add_time_metric_to_plugins_loaded'), PHP_INT_MAX);

        add_action('setup_theme', array($this, 'add_time_metric_to_setup_theme'), PHP_INT_MAX);

        add_action('after_setup_theme', array($this, 'add_time_metric_to_after_setup_theme'), PHP_INT_MAX);

        add_action('send_headers', array($this, 'start_collecting_response'), -1);

        add_action('send_headers', array( $this, 'start_collecting_response'), -1);

        add_action('wp_footer', array($this, 'flush_content_and_add_timig_headers'), PHP_INT_MAX);
    }

    public function add_time_start_to_http_request($args, $url)
    {
        $args['time_start'] = microtime(true);

        return $args;
    }

    public function calculate_duration_after_http_request($response, $type, $class, $args, $url)
    {
        if ($type !== 'response') {
            return;
        }

        $args['time_stop'] = microtime(true);

        $args['duration'] = $args['time_stop'] - $args['time_start'];
        $args['duration'] *= 1000;

        $this->timehttprequest += $args['duration'];
    }

    public function calculate_query_time()
    {
        global $wpdb;

        $timewpquery = 0;

        if (!empty($wpdb->queries)) {
            foreach ($wpdb->queries as $q) {
                list($query, $elapsed, $debug) = $q;

                $timewpquery += $elapsed;
            }
        }

        return $timewpquery * 1000;
    }

    public function add_time_metric_to_wp_loaded()
    {
        $this->timebootstrap = microtime(true);
    }

    public function add_time_metric_to_plugins_loaded()
    {
        $this->timeplugins = microtime(true);
    }

    public function add_time_metric_to_setup_theme()
    {
        $this->timesetuptheme = microtime(true);
    }

    public function add_time_metric_to_after_setup_theme()
    {
        $this->timeaftersetuptheme = microtime(true);
    }

    public function start_collecting_response()
    {
        ob_start();
    }

    public function generate_timing_header( $name, $description, $duration ) {
        return "$name;desc=\"$description\";dur=\"$duration\"";
    }

    public function flush_content_and_add_timig_headers()
    {
        global $timestart;

        $requestStart = floatval($_SERVER['REQUEST_TIME_FLOAT'] ?? $timestart);

        $bootstrapTime = $this->generate_timing_header('bootstrap', 'WP setup', (($timestart - $requestStart) * 1000));
        $wpLoadTime = $this->generate_timing_header('wpload', 'Non template logic', (($this->timebootstrap - $this->timeaftersetuptheme) * 1000));
        $appTime = $this->generate_timing_header('html', 'Template processing', ((microtime(true) - $this->timebootstrap) * 1000));
        $apiTime = $this->generate_timing_header('api', 'API request processing', $this->timehttprequest);
        $dbTime = $this->generate_timing_header('db', 'WPDB', $this->calculate_query_time());
        $globalQueryTime = $this->generate_timing_header('globals', 'Global query setup', (($this->timesetuptheme - $this->timeplugins) * 1000));
        $pluginTime = $this->generate_timing_header('plugins', 'Plugins loaded', (($this->timeplugins - $timestart) * 1000));
        $themeTime = $this->generate_timing_header('theme', 'Theme loaded', (($this->timeaftersetuptheme - $this->timesetuptheme) * 1000));
        $totalTime = $this->generate_timing_header('total', 'Total application run time', ((microtime(true) - $requestStart) * 1000));

        $data = [$bootstrapTime, $dbTime, $globalQueryTime, $pluginTime, $themeTime, $wpLoadTime, $apiTime, $appTime, $totalTime];
        $data = apply_filters( 'wp_server_timing_flush_timing_headers', $data );

        header('Server-Timing: ' . implode(', ', $data));
        ob_end_flush();
    }

}

global $wpServerTiming;

$wpServerTiming= new WPServerTiming();
