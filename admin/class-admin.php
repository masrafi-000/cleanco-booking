<?php
defined( 'ABSPATH' ) || exit;

class CCB_Admin {

    public static function init() {
        add_action( 'admin_menu',  [ __CLASS__, 'add_menus' ] );
        add_action( 'admin_init',  [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin' ] );
        add_action( 'update_option_ccb_stripe_secret_key', [ 'CCB_Stripe', 'setup_webhook' ] );
        add_action( 'add_option_ccb_stripe_secret_key', [ 'CCB_Stripe', 'setup_webhook' ] );
    }

    /* ── Menus ── */
    public static function add_menus() {
        add_menu_page(
            'CleanCo Bookings',
            'CleanCo',
            'manage_options',
            'ccb-bookings',
            [ __CLASS__, 'page_bookings' ],
            'dashicons-calendar-alt',
            56
        );
        add_submenu_page( 'ccb-bookings', 'All Bookings',  'All Bookings',  'manage_options', 'ccb-bookings',  [ __CLASS__, 'page_bookings'  ] );
        add_submenu_page( 'ccb-bookings', 'Settings',      'Settings',      'manage_options', 'ccb-settings',  [ __CLASS__, 'page_settings'  ] );
    }

    public static function enqueue_admin( $hook ) {
        if ( ! in_array( $hook, [ 'toplevel_page_ccb-bookings', 'cleanco_page_ccb-settings' ], true ) ) {
            return;
        }
        wp_enqueue_style( 'ccb-admin', CCB_URL . 'assets/css/admin.css', [], CCB_VERSION );
    }

    /* ── Settings ── */
    public static function register_settings() {
        $settings = [
            'ccb_stripe_publishable_key' => '',
            'ccb_stripe_secret_key'      => '',
            'ccb_stripe_test_mode'       => 1,
            'ccb_admin_email'            => get_option( 'admin_email' ),
            'ccb_from_name'              => get_bloginfo( 'name' ),
            'ccb_from_email'             => get_option( 'admin_email' ),
        ];

        foreach ( $settings as $key => $default ) {
            register_setting( 'ccb_settings_group', $key, [ 'default' => $default ] );
        }
    }

    /* ════════════════════════════════════════════════════════
       BOOKINGS PAGE
    ════════════════════════════════════════════════════════ */
    public static function page_bookings() {
        $status  = sanitize_text_field( $_GET['status']  ?? '' );
        $search  = sanitize_text_field( $_GET['search']  ?? '' );
        $paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per     = 20;

        $args = [
            'status'   => $status,
            'search'   => $search,
            'paged'    => $paged,
            'per_page' => $per,
        ];

        $bookings = CCB_Database::get_bookings( $args );
        $total    = CCB_Database::count_bookings( $args );
        $pages    = ceil( $total / $per );

        // Handle single booking detail view.
        if ( ! empty( $_GET['view'] ) ) {
            $b = CCB_Database::get_booking( absint( $_GET['view'] ) );
            if ( $b ) {
                self::render_booking_detail( $b );
                return;
            }
        }

        // Handle status update.
        if ( ! empty( $_POST['ccb_update_status'] ) && check_admin_referer( 'ccb_update_status' ) ) {
            $bid = absint( $_POST['booking_id'] );
            $st  = sanitize_text_field( $_POST['new_status'] );
            CCB_Database::update_booking( $bid, [ 'status' => $st ] );
            echo '<div class="notice notice-success"><p>Booking status updated.</p></div>';
        }

        $statuses = [ '', 'pending', 'paid', 'confirmed', 'cancelled', 'failed' ];
        $status_labels = [
            ''          => 'All',
            'pending'   => 'Pending',
            'paid'      => 'Paid',
            'confirmed' => 'Confirmed',
            'cancelled' => 'Cancelled',
            'failed'    => 'Failed',
        ];
        ?>
        <div class="wrap ccb-admin">
          <h1 class="wp-heading-inline">CleanCo Bookings</h1>
          <span style="margin-left:12px;font-size:.85rem;color:#777"><?= esc_html( $total ) ?> total</span>
          <hr class="wp-header-end">

          <!-- Filters -->
          <form method="get" style="margin:16px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="page" value="ccb-bookings">
            <div class="ccb-filter-tabs">
              <?php foreach ( $statuses as $s ) : ?>
                <a href="<?= esc_url( add_query_arg( [ 'status' => $s, 'paged' => 1, 'page' => 'ccb-bookings' ], admin_url( 'admin.php' ) ) ) ?>"
                   class="ccb-ftab-admin <?= $status === $s ? 'active' : '' ?>">
                  <?= esc_html( $status_labels[ $s ] ) ?>
                  <span class="count"><?= CCB_Database::count_bookings( [ 'status' => $s ] ) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
            <input type="text" name="search" value="<?= esc_attr( $search ) ?>" placeholder="Search name / email / ref…" style="width:240px">
            <button type="submit" class="button">Search</button>
            <?php if ( $search ) : ?>
              <a href="<?= esc_url( admin_url( 'admin.php?page=ccb-bookings' ) ) ?>" class="button">Clear</a>
            <?php endif; ?>
          </form>

          <table class="wp-list-table widefat fixed striped" style="font-size:.88rem">
            <thead>
              <tr>
                <th style="width:130px">Ref</th>
                <th>Customer</th>
                <th>Service</th>
                <th>Date</th>
                <th>Total</th>
                <th style="width:100px">Status</th>
                <th style="width:80px">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php if ( $bookings ) : ?>
              <?php foreach ( $bookings as $b ) :
                $status_colors = [
                  'paid'      => '#2E7D32',
                  'confirmed' => '#1565C0',
                  'pending'   => '#E65100',
                  'cancelled' => '#C62828',
                  'failed'    => '#B71C1C',
                ];
                $sc = $status_colors[ $b->status ] ?? '#757575';
              ?>
              <tr>
                <td><strong style="color:#F57C00"><?= esc_html( $b->booking_ref ) ?></strong></td>
                <td>
                  <?= esc_html( $b->first_name . ' ' . $b->last_name ) ?><br>
                  <span style="font-size:.78rem;color:#999"><?= esc_html( $b->email ) ?></span>
                </td>
                <td><?= esc_html( $b->service_type ) ?> · <?= esc_html( $b->location ) ?></td>
                <td><?= $b->clean_date ? esc_html( date( 'M j, Y', strtotime( $b->clean_date ) ) ) : '—' ?></td>
                <td><strong>$<?= esc_html( number_format( $b->grand_total, 2 ) ) ?></strong></td>
                <td><span style="background:<?= $sc ?>;color:#fff;padding:3px 9px;border-radius:20px;font-size:.72rem;font-weight:700"><?= esc_html( ucfirst( $b->status ) ) ?></span></td>
                <td><a href="<?= esc_url( add_query_arg( [ 'view' => $b->id, 'page' => 'ccb-bookings' ], admin_url( 'admin.php' ) ) ) ?>" class="button button-small">View</a></td>
              </tr>
              <?php endforeach; ?>
            <?php else : ?>
              <tr><td colspan="7" style="text-align:center;padding:30px;color:#999">No bookings found.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>

          <!-- Pagination -->
          <?php if ( $pages > 1 ) : ?>
          <div style="margin-top:16px">
            <?= paginate_links( [
              'base'    => add_query_arg( 'paged', '%#%' ),
              'format'  => '',
              'current' => $paged,
              'total'   => $pages,
            ] ) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php
    }

    private static function render_booking_detail( $b ) {
        $back = admin_url( 'admin.php?page=ccb-bookings' );
        $svc_names = [
            'routine'  => 'Routine Cleaning',
            'deep'     => 'Deep Cleaning',
            'movein'   => 'Move In / Move Out',
            'postreno' => 'Post-Renovation',
            'airbnb'   => 'Airbnb / Short-Term Rental',
        ];
        $freq_names = [
            'once'     => 'One-Time',
            'monthly'  => 'Monthly (5%)',
            'every3w'  => 'Every 3 Weeks (7%)',
            'biweekly' => 'Bi-Weekly (10%)',
            'weekly'   => 'Weekly (15%)',
        ];
        ?>
        <div class="wrap ccb-admin">
          <h1>Booking: <span style="color:#F57C00"><?= esc_html( $b->booking_ref ) ?></span></h1>
          <a href="<?= esc_url( $back ) ?>" class="button" style="margin-bottom:16px">← Back to Bookings</a>

          <!-- Update Status -->
          <form method="post" style="display:inline-block;margin-left:12px">
            <?php wp_nonce_field( 'ccb_update_status' ); ?>
            <input type="hidden" name="booking_id" value="<?= (int) $b->id ?>">
            <input type="hidden" name="ccb_update_status" value="1">
            <select name="new_status" style="margin-right:6px">
              <?php foreach ( [ 'pending','paid','confirmed','cancelled','failed' ] as $st ) : ?>
                <option value="<?= $st ?>" <?= selected( $b->status, $st, false ) ?>><?= ucfirst( $st ) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-primary">Update Status</button>
          </form>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;max-width:900px">

            <div class="ccb-admin-card">
              <h3>👤 Customer</h3>
              <?php self::row( 'Name',    $b->first_name . ' ' . $b->last_name ); ?>
              <?php self::row( 'Email',   $b->email ); ?>
              <?php self::row( 'Phone',   $b->phone ); ?>
              <?php self::row( 'Location', $b->location ); ?>
            </div>

            <div class="ccb-admin-card">
              <h3>🏠 Property</h3>
              <?php self::row( 'Address',    $b->address . ( $b->apt_no ? ', Apt ' . $b->apt_no : '' ) ); ?>
              <?php self::row( 'Buzzer',     $b->buzzer_code ?: '—' ); ?>
              <?php self::row( 'Home Type',  ucfirst( $b->home_type ) ); ?>
              <?php self::row( 'Bedrooms',   $b->bedrooms ); ?>
              <?php self::row( 'Bathrooms',  $b->bathrooms ); ?>
              <?php self::row( 'Sq Ft Tier', $b->sqft_tier ); ?>
              <?php self::row( 'Pets',       $b->pets ? 'Yes' : 'No' ); ?>
              <?php self::row( 'Eco',        $b->eco_products ? 'Yes' : 'No' ); ?>
              <?php self::row( 'Condition',  [ 'Well maintained','Average','Extremely messy' ][ (int) $b->home_condition ] ); ?>
            </div>

            <div class="ccb-admin-card">
              <h3>🧹 Service</h3>
              <?php self::row( 'Service',   $svc_names[ $b->service_type ] ?? $b->service_type ); ?>
              <?php self::row( 'Frequency', $freq_names[ $b->frequency ]   ?? $b->frequency ); ?>
              <?php self::row( 'Extras',    $b->extras ?: 'None' ); ?>
              <?php self::row( 'Date',      $b->clean_date ? date( 'F j, Y', strtotime( $b->clean_date ) ) : '—' ); ?>
              <?php self::row( 'Time',      $b->clean_time ?: '—' ); ?>
            </div>

            <div class="ccb-admin-card">
              <h3>💳 Payment</h3>
              <?php self::row( 'Method',    ucfirst( $b->payment_method ) ); ?>
              <?php self::row( 'Stripe PI', $b->stripe_pi_id ?: '—' ); ?>
              <?php self::row( 'Status',    ucfirst( $b->status ) ); ?>
              <hr style="border-color:#eee;margin:8px 0">
              <?php self::row( 'Base',      '$' . number_format( $b->base_price, 2 ) ); ?>
              <?php self::row( 'Extras',    '$' . number_format( $b->extras_total, 2 ) ); ?>
              <?php if ( ! empty( $b->coupon_code ) ) : ?>
              <?php self::row( 'Coupon',    $b->coupon_code . ' (−$' . number_format( $b->coupon_discount, 2 ) . ')' ); ?>
              <?php endif; ?>
              <?php self::row( 'Pre-Tax',   '$' . number_format( $b->pretax_total, 2 ) ); ?>
              <?php self::row( 'Tax (13%)', '$' . number_format( $b->tax_amt, 2 ) ); ?>
              <?php self::row( 'Gratuity',  '$' . number_format( $b->tip_amt, 2 ) ); ?>
              <div style="font-size:1.1rem;font-weight:700;color:#E65100;margin-top:8px;padding-top:8px;border-top:2px solid #eee">
                Total: $<?= esc_html( number_format( $b->grand_total, 2 ) ) ?>
              </div>
            </div>
          </div>

          <p style="color:#999;font-size:.78rem;margin-top:20px">Created: <?= esc_html( $b->created_at ) ?></p>
        </div>
        <?php
    }

    private static function row( $label, $value ) {
        echo '<div style="display:flex;gap:12px;padding:5px 0;border-bottom:1px solid #f5f5f5;font-size:.85rem">'
           . '<span style="color:#999;min-width:120px">' . esc_html( $label ) . '</span>'
           . '<span style="font-weight:500">' . esc_html( $value ) . '</span>'
           . '</div>';
    }

    /* ════════════════════════════════════════════════════════
       SETTINGS PAGE
    ════════════════════════════════════════════════════════ */
    public static function page_settings() {
        // Handle DB repair request.
        if ( ! empty( $_POST['ccb_repair_db'] ) && check_admin_referer( 'ccb_repair_db' ) ) {
            CCB_Database::create_table();
            echo '<div class="notice notice-success"><p>✅ Database table has been created / repaired successfully.</p></div>';
        }

        if ( isset( $_GET['settings-updated'] ) ) {
            if ( get_option( 'ccb_stripe_secret_key' ) && ! get_option( 'ccb_stripe_webhook_secret' ) ) {
                CCB_Stripe::setup_webhook();
            }
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }
        $pk   = get_option( 'ccb_stripe_publishable_key', '' );
        $sk   = get_option( 'ccb_stripe_secret_key', '' );
        $test = get_option( 'ccb_stripe_test_mode', 1 );
        $ae   = get_option( 'ccb_admin_email', get_option( 'admin_email' ) );
        $fn   = get_option( 'ccb_from_name',  get_bloginfo( 'name' ) );
        $fe   = get_option( 'ccb_from_email', get_option( 'admin_email' ) );
        $wh_url = rest_url( 'cleanco-booking/v1/webhook' );
        ?>
        <div class="wrap ccb-admin">
          <h1>CleanCo Settings</h1>
          <form method="post" action="options.php">
            <?php settings_fields( 'ccb_settings_group' ); ?>

            <div class="ccb-admin-card" style="max-width:680px;margin-bottom:24px">
              <h2 style="margin-top:0">💳 Stripe Configuration</h2>

              <table class="form-table" role="presentation">
                <tr>
                  <th scope="row">Mode</th>
                  <td>
                    <label><input type="radio" name="ccb_stripe_test_mode" value="1" <?= checked( $test, 1, false ) ?>> Test Mode</label>&nbsp;&nbsp;
                    <label><input type="radio" name="ccb_stripe_test_mode" value="0" <?= checked( $test, 0, false ) ?>> Live Mode</label>
                    <p class="description">Use Test Mode for development. Switch to Live when ready to accept real payments.</p>
                  </td>
                </tr>
                <tr>
                  <th scope="row">Publishable Key</th>
                  <td>
                    <input type="text" name="ccb_stripe_publishable_key" value="<?= esc_attr( $pk ) ?>" class="regular-text" placeholder="pk_test_… or pk_live_…">
                    <p class="description">Found in your <a href="https://dashboard.stripe.com/apikeys" target="_blank">Stripe Dashboard → API Keys</a>.</p>
                  </td>
                </tr>
                <tr>
                  <th scope="row">Secret Key</th>
                  <td>
                    <input type="password" name="ccb_stripe_secret_key" value="<?= esc_attr( $sk ) ?>" class="regular-text" placeholder="sk_test_… or sk_live_…">
                    <p class="description">Keep this secret! Never expose it publicly.</p>
                  </td>
                </tr>
                <tr>
                  <th scope="row">Webhook Status</th>
                  <td>
                    <?php if ( get_option( 'ccb_stripe_webhook_secret' ) ) : ?>
                      <span style="color:#2E7D32;font-weight:600">✅ Configured Automatically</span>
                    <?php else : ?>
                      <span style="color:#C62828;font-weight:600">⚠️ Not Configured (Save settings with a valid Secret Key to configure)</span>
                    <?php endif; ?>
                    <p class="description">
                      The webhook endpoint (<code><?= esc_url( $wh_url ) ?></code>) is configured automatically when you save a valid Secret Key.
                    </p>
                  </td>
                </tr>
              </table>
            </div>

            <div class="ccb-admin-card" style="max-width:680px;margin-bottom:24px">
              <h2 style="margin-top:0">📧 Email Configuration</h2>
              <p style="color:#666;font-size:.88rem">CleanCo uses <strong>wp_mail()</strong> to send emails. For reliable Gmail delivery, install an SMTP plugin like <a href="https://wordpress.org/plugins/wp-mail-smtp/" target="_blank">WP Mail SMTP</a> and configure it with your Gmail App Password.</p>

              <table class="form-table" role="presentation">
                <tr>
                  <th scope="row">Admin / Business Email</th>
                  <td>
                    <input type="email" name="ccb_admin_email" value="<?= esc_attr( $ae ) ?>" class="regular-text">
                    <p class="description">New booking notifications are sent to this address.</p>
                  </td>
                </tr>
                <tr>
                  <th scope="row">From Name</th>
                  <td><input type="text" name="ccb_from_name" value="<?= esc_attr( $fn ) ?>" class="regular-text"></td>
                </tr>
                <tr>
                  <th scope="row">From Email</th>
                  <td>
                    <input type="email" name="ccb_from_email" value="<?= esc_attr( $fe ) ?>" class="regular-text">
                    <p class="description">Should match your SMTP authenticated address for best deliverability.</p>
                  </td>
                </tr>
              </table>
            </div>

            <?php submit_button( 'Save Settings' ); ?>
          </form>

          <div class="ccb-admin-card" style="max-width:680px;background:#FFF3E0;border-color:#FFCC80">
            <h3 style="margin-top:0;color:#E65100">📋 Setup Checklist</h3>
            <ol style="font-size:.9rem;line-height:2">
              <li>Create a <strong>Stripe account</strong> at <a href="https://stripe.com" target="_blank">stripe.com</a></li>
              <li>Copy your <strong>Publishable</strong> and <strong>Secret keys</strong> from the Stripe Dashboard</li>
              <li>Your Webhook is <strong>automatically configured</strong> when you save your Secret Key!</li>
              <li>Install <strong>WP Mail SMTP</strong> and configure Gmail SMTP for reliable email</li>
              <li>Create a WordPress page and add the shortcode: <code>[cleanco_booking]</code></li>
              <li>Switch to <strong>Live Mode</strong> when ready to accept real payments</li>
            </ol>
          </div>

          <div class="ccb-admin-card" style="max-width:680px;border-color:#BDBDBD">
            <h3 style="margin-top:0">🔧 Database Tools</h3>
            <p style="font-size:.88rem;color:#555">If bookings are not appearing in the list, the database table may need to be created or repaired. This is safe to run at any time — it will not delete existing data.</p>
            <form method="post">
              <?php wp_nonce_field( 'ccb_repair_db' ); ?>
              <input type="hidden" name="ccb_repair_db" value="1">
              <button type="submit" class="button button-secondary">🔧 Create / Repair Database Table</button>
            </form>
          </div>
        </div>
        <?php
    }
}
