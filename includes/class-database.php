<?php
defined( 'ABSPATH' ) || exit;

class CCB_Database {

    /** All valid column names — used to whitelist before insert/update */
    private static $columns = [
        'booking_ref', 'status', 'stripe_pi_id', 'payment_method',
        'first_name', 'last_name', 'email', 'phone',
        'location', 'address', 'apt_no', 'buzzer_code',
        'home_type', 'bedrooms', 'bathrooms', 'sqft_tier',
        'service_type', 'frequency', 'home_condition', 'pets', 'eco_products', 'extras',
        'clean_date', 'clean_time',
        'coupon_code', 'coupon_discount',
        'base_price', 'extras_total', 'discount_amt', 'pretax_total', 'tax_amt', 'tip_amt', 'grand_total',
    ];

    /** Strip any keys that are not actual table columns */
    private static function sanitize_row( array $data ) {
        return array_intersect_key( $data, array_flip( self::$columns ) );
    }

    /**
     * Create (or upgrade) the bookings table.
     * Safe to run multiple times — dbDelta only adds missing columns.
     */
    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . CCB_DB_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_ref     VARCHAR(20)         NOT NULL,
            status          VARCHAR(20)         NOT NULL DEFAULT 'pending',
            stripe_pi_id    VARCHAR(100)                 DEFAULT NULL,
            payment_method  VARCHAR(20)         NOT NULL DEFAULT 'card',

            -- Customer
            first_name      VARCHAR(100)        NOT NULL DEFAULT '',
            last_name       VARCHAR(100)        NOT NULL DEFAULT '',
            email           VARCHAR(200)        NOT NULL DEFAULT '',
            phone           VARCHAR(50)         NOT NULL DEFAULT '',

            -- Property
            location        VARCHAR(100)        NOT NULL DEFAULT '',
            address         VARCHAR(255)        NOT NULL DEFAULT '',
            apt_no          VARCHAR(50)                  DEFAULT NULL,
            buzzer_code     VARCHAR(50)                  DEFAULT NULL,
            home_type       VARCHAR(50)         NOT NULL DEFAULT '',
            bedrooms        TINYINT(2) UNSIGNED NOT NULL DEFAULT 1,
            bathrooms       TINYINT(2) UNSIGNED NOT NULL DEFAULT 1,
            sqft_tier       TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,

            -- Service
            service_type    VARCHAR(50)         NOT NULL DEFAULT 'routine',
            frequency       VARCHAR(20)         NOT NULL DEFAULT 'once',
            home_condition  TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            pets            TINYINT(1)          NOT NULL DEFAULT 0,
            eco_products    TINYINT(1)          NOT NULL DEFAULT 0,
            extras          TEXT                         DEFAULT NULL,

            -- Schedule
            clean_date      DATE                         DEFAULT NULL,
            clean_time      VARCHAR(20)                  DEFAULT NULL,

            -- Coupon
            coupon_code     VARCHAR(50)                  DEFAULT NULL,
            coupon_discount DECIMAL(10,2)       NOT NULL DEFAULT 0,

            -- Pricing
            base_price      DECIMAL(10,2)       NOT NULL DEFAULT 0,
            extras_total    DECIMAL(10,2)       NOT NULL DEFAULT 0,
            discount_amt    DECIMAL(10,2)       NOT NULL DEFAULT 0,
            pretax_total    DECIMAL(10,2)       NOT NULL DEFAULT 0,
            tax_amt         DECIMAL(10,2)       NOT NULL DEFAULT 0,
            tip_amt         DECIMAL(10,2)       NOT NULL DEFAULT 0,
            grand_total     DECIMAL(10,2)       NOT NULL DEFAULT 0,

            -- Timestamps
            created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY booking_ref (booking_ref),
            KEY status (status),
            KEY stripe_pi_id (stripe_pi_id),
            KEY email (email(191))
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Also ensure coupon columns exist on already-installed tables.
        self::maybe_add_columns( $table );

        update_option( 'ccb_db_version', CCB_VERSION );
    }

    /** Add any missing columns to an existing table (safe, non-destructive) */
    private static function maybe_add_columns( $table ) {
        global $wpdb;
        $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );

        if ( ! in_array( 'coupon_code', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN coupon_code VARCHAR(50) DEFAULT NULL AFTER clean_time" );
        }
        if ( ! in_array( 'coupon_discount', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$table} ADD COLUMN coupon_discount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER coupon_code" );
        }
    }

    /** Insert a new booking record */
    public static function insert_booking( array $data ) {
        global $wpdb;
        $table = $wpdb->prefix . CCB_DB_TABLE;

        $defaults = [
            'booking_ref'     => self::generate_ref(),
            'status'          => 'pending',
            'stripe_pi_id'    => '',
            'payment_method'  => 'card',
            'first_name'      => '',
            'last_name'       => '',
            'email'           => '',
            'phone'           => '',
            'location'        => '',
            'address'         => '',
            'apt_no'          => '',
            'buzzer_code'     => '',
            'home_type'       => 'condo',
            'bedrooms'        => 1,
            'bathrooms'       => 1,
            'sqft_tier'       => 0,
            'service_type'    => 'routine',
            'frequency'       => 'once',
            'home_condition'  => 0,
            'pets'            => 0,
            'eco_products'    => 0,
            'extras'          => '',
            'clean_date'      => null,
            'clean_time'      => '',
            'coupon_code'     => '',
            'coupon_discount' => 0,
            'base_price'      => 0,
            'extras_total'    => 0,
            'discount_amt'    => 0,
            'pretax_total'    => 0,
            'tax_amt'         => 0,
            'tip_amt'         => 0,
            'grand_total'     => 0,
        ];

        $row    = self::sanitize_row( array_merge( $defaults, $data ) );
        $result = $wpdb->insert( $table, $row );

        if ( $result === false ) {
            error_log( '[CleanCo Booking] DB insert failed: ' . $wpdb->last_error );
        }

        return $result ? $wpdb->insert_id : false;
    }

    /** Update booking by ID */
    public static function update_booking( $id, array $data ) {
        global $wpdb;
        return $wpdb->update( $wpdb->prefix . CCB_DB_TABLE, $data, [ 'id' => $id ] );
    }

    /** Update booking by Stripe Payment Intent ID */
    public static function update_by_pi( $pi_id, array $data ) {
        global $wpdb;
        return $wpdb->update( $wpdb->prefix . CCB_DB_TABLE, $data, [ 'stripe_pi_id' => $pi_id ] );
    }

    /** Get one booking by ID */
    public static function get_booking( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . CCB_DB_TABLE;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    /** Get one booking by Stripe PI ID */
    public static function get_by_pi( $pi_id ) {
        global $wpdb;
        $table = $wpdb->prefix . CCB_DB_TABLE;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE stripe_pi_id = %s", $pi_id ) );
    }

    /** Get paginated bookings list */
    public static function get_bookings( $args = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . CCB_DB_TABLE;

        $defaults = [ 'per_page' => 20, 'paged' => 1, 'status' => '', 'search' => '', 'orderby' => 'created_at', 'order' => 'DESC' ];
        $args     = wp_parse_args( $args, $defaults );

        $where = 'WHERE 1=1'; $values = [];
        if ( $args['status'] ) { $where .= ' AND status = %s'; $values[] = $args['status']; }
        if ( $args['search'] ) {
            $where .= ' AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR booking_ref LIKE %s)';
            $s = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values = array_merge( $values, [ $s, $s, $s, $s ] );
        }

        $allowed = [ 'id', 'created_at', 'status', 'grand_total', 'booking_ref' ];
        $orderby = in_array( $args['orderby'], $allowed ) ? $args['orderby'] : 'created_at';
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $offset  = ( absint( $args['paged'] ) - 1 ) * absint( $args['per_page'] );

        $sql = "SELECT * FROM {$table} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $values[] = absint( $args['per_page'] );
        $values[] = $offset;

        return $wpdb->get_results( $values ? $wpdb->prepare( $sql, ...$values ) : $sql );
    }

    /** Count bookings with optional filters */
    public static function count_bookings( $args = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . CCB_DB_TABLE;

        $defaults = [ 'status' => '', 'search' => '' ];
        $args     = wp_parse_args( $args, $defaults );

        $where = 'WHERE 1=1'; $values = [];
        if ( $args['status'] ) { $where .= ' AND status = %s'; $values[] = $args['status']; }
        if ( $args['search'] ) {
            $where .= ' AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR booking_ref LIKE %s)';
            $s = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values = array_merge( $values, [ $s, $s, $s, $s ] );
        }

        $sql = "SELECT COUNT(*) FROM {$table} {$where}";
        return (int) $wpdb->get_var( $values ? $wpdb->prepare( $sql, ...$values ) : $sql );
    }

    /** Generate a unique booking reference */
    public static function generate_ref() {
        return 'CLEAN-' . strtoupper( wp_generate_password( 8, false ) );
    }
}
