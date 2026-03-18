<?php
defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the Stripe REST API.
 * Uses WordPress HTTP functions — no Composer/SDK required.
 */
class CCB_Stripe {

    private static function secret_key() {
        return get_option( 'ccb_stripe_secret_key', '' );
    }

    private static function webhook_secret() {
        return get_option( 'ccb_stripe_webhook_secret', '' );
    }

    public static function publishable_key() {
        return get_option( 'ccb_stripe_publishable_key', '' );
    }

    public static function is_test_mode() {
        return (bool) get_option( 'ccb_stripe_test_mode', 1 );
    }

    /**
     * Send a request to the Stripe API.
     *
     * @param string $method  GET | POST
     * @param string $endpoint  e.g. /payment_intents
     * @param array  $body
     * @return array|WP_Error  Parsed response body or WP_Error.
     */
    private static function request( $method, $endpoint, $body = [] ) {
        $key = self::secret_key();
        if ( ! $key ) {
            return new WP_Error( 'ccb_stripe_no_key', __( 'Stripe secret key not configured.', 'cleanco-booking' ) );
        }

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Stripe-Version' => '2023-10-16',
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'timeout' => 30,
        ];

        if ( ! empty( $body ) ) {
            $args['body'] = http_build_query( $body );
        }

        $response = wp_remote_request( 'https://api.stripe.com/v1' . $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $parsed = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status >= 400 ) {
            $message = isset( $parsed['error']['message'] ) ? $parsed['error']['message'] : 'Unknown Stripe error.';
            return new WP_Error( 'ccb_stripe_api_' . $status, $message );
        }

        return $parsed;
    }

    /**
     * Create a Payment Intent.
     *
     * @param int    $amount_cents  Amount in smallest currency unit (e.g. cents).
     * @param string $currency
     * @param array  $metadata
     * @return array|WP_Error
     */
    public static function create_payment_intent( $amount_cents, $currency = 'cad', $metadata = [] ) {
        $body = [
            'amount'                    => absint( $amount_cents ),
            'currency'                  => strtolower( $currency ),
            'payment_method_types[]'    => 'card',
            'capture_method'            => 'automatic',
        ];

        foreach ( $metadata as $k => $v ) {
            $body[ 'metadata[' . $k . ']' ] = $v;
        }

        return self::request( 'POST', '/payment_intents', $body );
    }

    /**
     * Retrieve a Payment Intent by ID.
     *
     * @param string $pi_id
     * @return array|WP_Error
     */
    public static function retrieve_payment_intent( $pi_id ) {
        return self::request( 'GET', '/payment_intents/' . sanitize_text_field( $pi_id ) );
    }

    /**
     * Verify and parse a Stripe webhook payload.
     *
     * @param string $payload    Raw POST body.
     * @param string $sig_header Value of Stripe-Signature header.
     * @return array|WP_Error   Parsed event or WP_Error.
     */
    public static function verify_webhook( $payload, $sig_header ) {
        $secret = self::webhook_secret();

        if ( ! $secret ) {
            // No webhook secret configured — trust blindly in dev mode only.
            $event = json_decode( $payload, true );
            return $event ?: new WP_Error( 'ccb_webhook_parse', 'Could not parse webhook payload.' );
        }

        // Parse Stripe-Signature header.
        $parts = [];
        foreach ( explode( ',', $sig_header ) as $pair ) {
            list( $k, $v ) = array_pad( explode( '=', $pair, 2 ), 2, '' );
            $parts[ trim( $k ) ] = trim( $v );
        }

        if ( empty( $parts['t'] ) || empty( $parts['v1'] ) ) {
            return new WP_Error( 'ccb_webhook_sig', 'Invalid Stripe signature header.' );
        }

        $signed_payload = $parts['t'] . '.' . $payload;
        $expected       = hash_hmac( 'sha256', $signed_payload, $secret );

        if ( ! hash_equals( $expected, $parts['v1'] ) ) {
            return new WP_Error( 'ccb_webhook_sig', 'Stripe signature mismatch.' );
        }

        // Replay attack window: 5 minutes.
        if ( abs( time() - (int) $parts['t'] ) > 300 ) {
            return new WP_Error( 'ccb_webhook_replay', 'Webhook timestamp too old.' );
        }

        $event = json_decode( $payload, true );
        return $event ?: new WP_Error( 'ccb_webhook_parse', 'Could not parse webhook payload.' );
    }

    /**
     * Set up Stripe webhook automatically.
     */
    public static function setup_webhook() {
        $key = self::secret_key();
        if ( ! $key ) return;

        $wh_url = rest_url( 'cleanco-booking/v1/webhook' );

        // 1. List webhooks
        $endpoints = self::request( 'GET', '/webhook_endpoints' );
        if ( is_wp_error( $endpoints ) ) {
            return;
        }

        $found_id = null;
        if ( ! empty( $endpoints['data'] ) ) {
            foreach ( $endpoints['data'] as $ep ) {
                if ( $ep['url'] === $wh_url ) {
                    $found_id = $ep['id'];
                    break;
                }
            }
        }

        // 2. If found, delete it to get a new secret
        if ( $found_id ) {
            self::request( 'DELETE', '/webhook_endpoints/' . $found_id );
        }

        // 3. Create new webhook
        $body = [
            'url' => $wh_url,
            'enabled_events[0]' => 'payment_intent.succeeded',
            'enabled_events[1]' => 'payment_intent.payment_failed',
        ];

        $created = self::request( 'POST', '/webhook_endpoints', $body );

        if ( ! is_wp_error( $created ) && ! empty( $created['secret'] ) ) {
            update_option( 'ccb_stripe_webhook_secret', $created['secret'] );
        }
    }
}
