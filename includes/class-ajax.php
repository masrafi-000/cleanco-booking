<?php
defined( 'ABSPATH' ) || exit;

class CCB_Ajax {

    /**
     * Valid coupon codes → discount percentage.
     * Edit this array to add/remove coupons.
     */
    private static $coupons = [
        'FIRSTCLEAN10' => 10,   // 10% off
    ];

    public static function init() {
        $actions = [
            'ccb_save_booking',
            'ccb_create_payment_intent',
            'ccb_confirm_booking',
            'ccb_validate_coupon',
        ];

        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_'        . $action, [ __CLASS__, $action ] );
            add_action( 'wp_ajax_nopriv_' . $action, [ __CLASS__, $action ] );
        }

        add_action( 'rest_api_init', [ __CLASS__, 'register_webhook_endpoint' ] );
    }

    /* ══════════════════════════════════════════════════════════
       COUPON VALIDATION
    ══════════════════════════════════════════════════════════ */
    public static function ccb_validate_coupon() {
        check_ajax_referer( 'ccb_nonce', 'nonce' );

        $code = strtoupper( trim( sanitize_text_field( $_POST['coupon_code'] ?? '' ) ) );

        if ( ! $code ) {
            wp_send_json_error( [ 'message' => 'Please enter a coupon code.' ] );
        }

        if ( isset( self::$coupons[ $code ] ) ) {
            wp_send_json_success( [
                'code'    => $code,
                'pct'     => self::$coupons[ $code ],
                'message' => '🎉 Coupon applied! ' . self::$coupons[ $code ] . '% off your booking.',
            ] );
        }

        wp_send_json_error( [ 'message' => 'Invalid coupon code. Please check and try again.' ] );
    }

    /* ══════════════════════════════════════════════════════════
       STEP 1 — Save booking to DB first (always, before Stripe)
    ══════════════════════════════════════════════════════════ */
    public static function ccb_save_booking() {
        check_ajax_referer( 'ccb_nonce', 'nonce' );

        $data    = self::parse_booking_data( $_POST );
        $pricing = self::compute_price( $data );
        $ref     = CCB_Database::generate_ref();

        $row = array_merge( $data, $pricing, [
            'booking_ref'  => $ref,
            'status'       => 'pending',
            'stripe_pi_id' => '',
        ] );

        // tip_pct is a calculation input only — not a DB column.
        unset( $row['tip_pct'] );

        $booking_id = CCB_Database::insert_booking( $row );

        if ( ! $booking_id ) {
            global $wpdb;
            wp_send_json_error( [
                'message' => 'Could not save your booking. Please try again.',
                'debug'   => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? $wpdb->last_error : '',
            ] );
        }

        wp_send_json_success( [
            'booking_id'  => $booking_id,
            'booking_ref' => $ref,
            'grand_total' => $pricing['grand_total'],
        ] );
    }

    /* ══════════════════════════════════════════════════════════
       STEP 2 — Create Stripe PaymentIntent for a saved booking
    ══════════════════════════════════════════════════════════ */
    public static function ccb_create_payment_intent() {
        check_ajax_referer( 'ccb_nonce', 'nonce' );

        $booking_id = absint( $_POST['booking_id'] ?? 0 );

        if ( ! $booking_id ) {
            wp_send_json_error( [ 'message' => 'Invalid booking ID.' ] );
        }

        $booking = CCB_Database::get_booking( $booking_id );
        if ( ! $booking ) {
            wp_send_json_error( [ 'message' => 'Booking not found.' ] );
        }

        $amount = (int) round( (float) $booking->grand_total * 100 );
        if ( $amount < 50 ) {
            wp_send_json_error( [ 'message' => 'Amount too small to process ($0.50 minimum).' ] );
        }

        $pi = CCB_Stripe::create_payment_intent( $amount, 'cad', [
            'booking_ref' => $booking->booking_ref,
            'customer'    => trim( $booking->first_name . ' ' . $booking->last_name ),
            'email'       => $booking->email,
            'service'     => $booking->service_type,
            'location'    => $booking->location,
        ] );

        if ( is_wp_error( $pi ) ) {
            wp_send_json_error( [ 'message' => $pi->get_error_message() ] );
        }

        CCB_Database::update_booking( $booking_id, [ 'stripe_pi_id' => $pi['id'] ] );

        wp_send_json_success( [
            'client_secret' => $pi['client_secret'],
            'pi_id'         => $pi['id'],
        ] );
    }

    /* ══════════════════════════════════════════════════════════
       STEP 3 — Confirm booking after Stripe payment succeeds
    ══════════════════════════════════════════════════════════ */
    public static function ccb_confirm_booking() {
        check_ajax_referer( 'ccb_nonce', 'nonce' );

        $pi_id      = sanitize_text_field( $_POST['payment_intent_id'] ?? '' );
        $booking_id = absint( $_POST['booking_id'] ?? 0 );

        if ( ! $pi_id || ! $booking_id ) {
            wp_send_json_error( [ 'message' => 'Missing required parameters.' ] );
        }

        $current_booking = CCB_Database::get_booking( $booking_id );
        
        if ( $current_booking && $current_booking->status === 'paid' ) {
            wp_send_json_success( [
                'message'     => 'Booking confirmed!',
                'booking_ref' => $current_booking->booking_ref,
            ] );
        }

        $pi        = CCB_Stripe::retrieve_payment_intent( $pi_id );
        $db_status = ( ! is_wp_error( $pi ) && isset( $pi['status'] ) && $pi['status'] === 'succeeded' )
                     ? 'paid' : 'pending';

        CCB_Database::update_booking( $booking_id, [
            'status'       => $db_status,
            'stripe_pi_id' => $pi_id,
        ] );

        $booking = CCB_Database::get_booking( $booking_id );

        if ( $booking && $db_status === 'paid' ) {
            CCB_Email::send_customer( (array) $booking );
            CCB_Email::send_admin(    (array) $booking );
        }

        wp_send_json_success( [
            'message'     => 'Booking confirmed!',
            'booking_ref' => $booking ? $booking->booking_ref : '',
        ] );
    }

    /* ══════════════════════════════════════════════════════════
       Stripe Webhook — /wp-json/cleanco-booking/v1/webhook
    ══════════════════════════════════════════════════════════ */
    public static function register_webhook_endpoint() {
        register_rest_route( 'cleanco-booking/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_webhook' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public static function handle_webhook( WP_REST_Request $request ) {
        $payload    = $request->get_body();
        $sig_header = $request->get_header( 'stripe_signature' );
        $event      = CCB_Stripe::verify_webhook( $payload, $sig_header );

        if ( is_wp_error( $event ) ) {
            return new WP_REST_Response( [ 'error' => $event->get_error_message() ], 400 );
        }

        switch ( $event['type'] ) {
            case 'payment_intent.succeeded':
                $pi_id   = $event['data']['object']['id'];
                $booking = CCB_Database::get_by_pi( $pi_id );
                if ( $booking && $booking->status !== 'paid' ) {
                    CCB_Database::update_by_pi( $pi_id, [ 'status' => 'paid' ] );
                    CCB_Email::send_customer( (array) $booking );
                    CCB_Email::send_admin(    (array) $booking );
                }
                break;

            case 'payment_intent.payment_failed':
                CCB_Database::update_by_pi( $event['data']['object']['id'], [ 'status' => 'failed' ] );
                break;
        }

        return new WP_REST_Response( [ 'received' => true ], 200 );
    }

    /* ══════════════════════════════════════════════════════════
       Helpers
    ══════════════════════════════════════════════════════════ */

    /**
     * Parse & sanitize all POST fields.
     * tip_pct and coupon_pct are calculation inputs — NOT DB columns.
     */
    private static function parse_booking_data( $post ) {
        return [
            // Contact
            'first_name'      => sanitize_text_field( $post['first_name']      ?? '' ),
            'last_name'       => sanitize_text_field( $post['last_name']       ?? '' ),
            'email'           => sanitize_email(      $post['email']           ?? '' ),
            'phone'           => sanitize_text_field( $post['phone']           ?? '' ),
            // Property
            'location'        => sanitize_text_field( $post['location']        ?? '' ),
            'address'         => sanitize_text_field( $post['address']         ?? '' ),
            'apt_no'          => sanitize_text_field( $post['apt_no']          ?? '' ),
            'buzzer_code'     => sanitize_text_field( $post['buzzer_code']     ?? '' ),
            'home_type'       => sanitize_text_field( $post['home_type']       ?? 'condo' ),
            'bedrooms'        => absint(              $post['bedrooms']        ?? 1 ),
            'bathrooms'       => absint(              $post['bathrooms']       ?? 1 ),
            'sqft_tier'       => absint(              $post['sqft_tier']       ?? 0 ),
            // Service
            'service_type'    => sanitize_text_field( $post['service_type']    ?? 'routine' ),
            'frequency'       => 'once',   // frequency removed from UI, always one-time
            'home_condition'  => absint(              $post['home_condition']  ?? 0 ),
            'pets'            => absint(              $post['pets']            ?? 0 ),
            'eco_products'    => absint(              $post['eco_products']    ?? 0 ),
            'extras'          => sanitize_text_field( $post['extras']          ?? '' ),
            // Schedule
            'clean_date'      => sanitize_text_field( $post['clean_date']      ?? '' ),
            'clean_time'      => sanitize_text_field( $post['clean_time']      ?? '' ),
            // Coupon
            'coupon_code'     => strtoupper( trim( sanitize_text_field( $post['coupon_code'] ?? '' ) ) ),
            // Payment
            'payment_method'  => sanitize_text_field( $post['payment_method']  ?? 'card' ),
            // Calculation inputs only (not DB columns — stripped before insert)
            'tip_pct'         => absint( $post['tip_pct']  ?? 0 ),
            'coupon_pct'      => absint( $post['coupon_pct'] ?? 0 ),
        ];
    }

    /**
     * Server-side pricing — mirrors JS calc() exactly.
     * Frequency discount removed (always 0 now).
     * Coupon discount applied after all other charges, before tax.
     */
    public static function compute_price( array $d ) {
        $sqft_prices  = [ 0, 12, 24, 36, 54, 75 ];
        $svc_mult     = [
            'routine'  => 1.00,
            'deep'     => 1.55,
            'movein'   => 1.85,
            'postreno' => 2.10,
            'airbnb'   => 1.20,
        ];
        $extra_prices = [
            'dishes'  => 22.50,
            'oven'    => 10.99,
            'fridge'  => 16.99,
            'windows' => 16.99,
            'closet'  => 34.99,
        ];
        $mess_prices = [ 0, 0, 23.99 ];

        $base   = 85.00;
        $bed_c  = max( 0, (int) $d['bedrooms']  - 1 ) * 17;
        $bath_c = max( 0, (int) $d['bathrooms'] - 1 ) * 20;
        $sq_idx = min( 5, (int) ( $d['sqft_tier'] ?? 0 ) );
        $sq_c   = $sqft_prices[ $sq_idx ];
        $mult   = $svc_mult[ $d['service_type'] ] ?? 1.0;
        $sub    = ( $base + $bed_c + $bath_c + $sq_c ) * $mult;

        // Extras
        $extras_total = 0.0;
        if ( ! empty( $d['extras'] ) ) {
            foreach ( explode( ',', $d['extras'] ) as $k ) {
                $k = trim( $k );
                if ( isset( $extra_prices[ $k ] ) ) { $extras_total += $extra_prices[ $k ]; }
            }
        }

        $pet_c  = ( (int) ( $d['pets']         ?? 0 ) === 1 ) ? 20    : 0;
        $eco_c  = ( (int) ( $d['eco_products'] ?? 0 ) === 1 ) ? 15    : 0;
        $mess_c = $mess_prices[ min( 2, (int) ( $d['home_condition'] ?? 0 ) ) ] ?? 0;

        // Subtotal before coupon/tip/tax
        $subtotal = $sub + $extras_total + $pet_c + $eco_c + $mess_c;

        // Coupon discount (validate server-side — don't trust client pct)
        $coupon_code = strtoupper( trim( $d['coupon_code'] ?? '' ) );
        $valid_coupons = [
            'FIRSTCLEAN10' => 10,
        ];
        $coupon_pct      = isset( $valid_coupons[ $coupon_code ] ) ? $valid_coupons[ $coupon_code ] : 0;
        $coupon_discount = $subtotal * ( $coupon_pct / 100 );

        $pretax = $subtotal - $coupon_discount;
        $tip    = $pretax * ( (int) ( $d['tip_pct'] ?? 0 ) / 100 );
        $tax    = $pretax * 0.13;
        $total  = $pretax + $tax + $tip;

        return [
            'base_price'      => round( $base,            2 ),
            'extras_total'    => round( $extras_total,     2 ),
            'discount_amt'    => round( $coupon_discount,  2 ), // coupon discount stored in discount_amt
            'coupon_code'     => $coupon_code,
            'coupon_discount' => round( $coupon_discount,  2 ),
            'pretax_total'    => round( $pretax,           2 ),
            'tax_amt'         => round( $tax,              2 ),
            'tip_amt'         => round( $tip,              2 ),
            'grand_total'     => round( $total,            2 ),
        ];
    }
}
