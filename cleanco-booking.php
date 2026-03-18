<?php
/**
 * Plugin Name: CleanCo Booking 
 * Plugin URI:  https://example.com/cleanco-booking
 * Description: Multi-step cleaning service booking form with Stripe payments, database storage, and email notifications.
 * Version:     1.0.0
 * Author:      CleanCo
 * License:     GPL-2.0+
 * Text Domain: cleanco-booking
 */

defined( 'ABSPATH' ) || exit;

/* ── Constants ── */
define( 'CCB_VERSION',  '1.0.0' );
define( 'CCB_FILE',     __FILE__ );
define( 'CCB_DIR',      plugin_dir_path( __FILE__ ) );
define( 'CCB_URL',      plugin_dir_url( __FILE__ ) );
define( 'CCB_DB_TABLE', 'ccb_bookings' );

/* ── Autoload includes ── */
foreach ( [
    'includes/class-database.php',
    'includes/class-stripe.php',
    'includes/class-email.php',
    'includes/class-shortcode.php',
    'includes/class-ajax.php',
    'admin/class-admin.php',
] as $file ) {
    require_once CCB_DIR . $file;
}

/* ── Activation / Deactivation ── */
register_activation_hook( CCB_FILE, [ 'CCB_Database', 'create_table' ] );
register_deactivation_hook( CCB_FILE, '__return_false' );

/* ── Bootstrap ── */
add_action( 'plugins_loaded', function () {
    CCB_Shortcode::init();
    CCB_Ajax::init();
    CCB_Admin::init();
} );
