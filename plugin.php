<?php
/**
 * Plugin Name:  Cron Runner
 * Description:  This mu-plugin lets you run WP cron for a site / a network site via a single endpoint.
 * Version:      1.0.3
 * Authors:      Ville Siltala & Ville Pietarinen / Geniem Oy
 * Author URI:   https://www.geniem.fi/
 * License:      MIT License
 */

namespace Geniem;

use WP_Error;

// We would use filter_input() for this,
// but it has a known bug on some PHP versions.
// See: https://bugs.php.net/bug.php?id=49184
$request_uri = $_SERVER['REQUEST_URI'];

// Remove the trailing forward slash.
$request_uri = rtrim( $request_uri, '/' );

// Execute our plugin only on exact url match.
if ( $request_uri === '/run-cron' ) {

    // Prevent invalid requests.
    if ( ! empty( $_POST ) || defined( 'DOING_AJAX' ) || defined( 'DOING_CRON' ) ) {
        die( 'WP Cron Runner: Invalid request.' );
    }

    $cron_excecuted = [];
    $scheme         = defined( 'REQUEST_SCHEME' ) ? REQUEST_SCHEME : 'https';

    // Multisite
    if ( defined( 'WP_ALLOW_MULTISITE' ) && WP_ALLOW_MULTISITE === true ) {
        global $wpdb;
        $sql = "SELECT domain, path FROM $wpdb->blogs WHERE archived=0 AND deleted=0";

        $results = $wpdb->get_results( $sql );

        if ( is_wp_error( $results ) ) {

            // A database error occurred.
            wp_die(
                $results->get_error_message(),
                'WP Cron Runner',
                [ 'response' => 500 ]
            );
        }
        elseif ( ! empty( $results ) ) {
            foreach ( $results as $blog ) {

                if ( empty( $blog->domain ) ) {

                    // Skip invalid data.
                    continue;
                }

                $home_url = $scheme . '://' . $blog->domain;
                // Subfolder multisite needs path also
                if ( $blog->path !== '/' ) {
                    $home_url .= $blog->path;
                }
                run_cron( $home_url );
                $cron_excecuted[] = $home_url;
            }
        }
    }
    // Single site
    else {
        $home_url = get_home_url( null, '', $scheme );
        run_cron( $home_url );
        $cron_excecuted[] = $home_url;
    }

    ob_start();
    ?>
    <h1>WP Cron Runner executed for sites:</h1>
    <?php
    if ( ! empty( $cron_excecuted ) ) {
        echo '<ul>';
        foreach ( $cron_excecuted as $exc_url ) {
            echo '<li>' . esc_url( $exc_url ) . '</li>';
        }
        echo '</ul>';
    }
    else {
        echo '<strong>0 sites.</strong>';
    }
    ?>
    <?php
    // End the PHP process.
    wp_die(
        ob_get_clean(),
        'WP Cron Runner',
        [ 'response' => 200 ]
    );

} // End if().

/**
 * Excecutes a wp_remote_get() call to run WP cron for a specific site.
 *
 * @param string $home_url The full site url: https://www.example.com.
 * @return WP_Error|array The response or WP_Error on failure.
 */
function run_cron( $home_url ) {
    global $wp_version;

    $args = array(
        'timeout'    => 10,
        'user-agent' => 'WordPress/' . $wp_version . '; ' . home_url(),
        'blocking'   => true,
        'sslverify'  => false,
        'headers'    => [],
    );

    // If basic auth is used, define these constants.
    $user = defined( 'WP_CRON_RUNNER_AUTH_USER' ) ? WP_CRON_RUNNER_AUTH_USER : null;
    $pw   = defined( 'WP_CRON_RUNNER_AUTH_PW' ) ? WP_CRON_RUNNER_AUTH_PW : null;

    if ( ! $user && defined( 'BASIC_AUTH_USER' ) ) {
        $user = BASIC_AUTH_USER;
    }

    if ( ! $pw && defined( 'BASIC_AUTH_PASSWORD' ) ) {
        $pw = BASIC_AUTH_PASSWORD;
    }

    if ( ! $pw && defined( 'BASIC_AUTH_PASSWORD_HASH' ) ) {
        $pw = str_replace( '{PLAIN}', '', BASIC_AUTH_PASSWORD_HASH );
    }

    if ( ! empty( $user ) && ! empty( $pw ) ) {

        // If using plain env strip plain from the basic auth password.
        $pw = str_replace( '{PLAIN}', '', $pw );

        // Set basic auth.
        $args['headers']['Authorization'] = 'Basic ' . base64_encode( $user . ':' . $pw );
    }

    $cron_url = $home_url . '/wp-cron.php';
    $response = wp_remote_get( $cron_url, $args );

    return $response;
}
