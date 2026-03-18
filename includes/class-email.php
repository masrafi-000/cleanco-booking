<?php
defined( 'ABSPATH' ) || exit;

class CCB_Email {

    private static $svc_names = [
        'routine'  => 'Routine Cleaning',
        'deep'     => 'Deep Cleaning',
        'movein'   => 'Move In / Move Out',
        'postreno' => 'Post-Renovation',
        'airbnb'   => 'Airbnb / Short-Term Rental',
    ];

    private static $freq_names = [
        'once'      => 'One-Time',
        'monthly'   => 'Monthly (5% off)',
        'every3w'   => 'Every 3 Weeks (7% off)',
        'biweekly'  => 'Bi-Weekly (10% off)',
        'weekly'    => 'Weekly (15% off)',
    ];

    private static $sqft_labels = [
        '≤ 650 sq ft',
        '651–850 sq ft',
        '851–1,100 sq ft',
        '1,101–1,400 sq ft',
        '1,401–1,800 sq ft',
        '1,800+ sq ft (Custom Quote)',
    ];

    /**
     * Send booking confirmation to the customer.
     */
    public static function send_customer( $booking ) {
        $to      = sanitize_email( $booking['email'] );
        $subject = 'Your CleanCo Booking is Confirmed! (' . $booking['booking_ref'] . ')';
        $message = self::customer_html( $booking );
        self::send( $to, $subject, $message );
    }

    /**
     * Send booking notification to the admin / business owner.
     */
    public static function send_admin( $booking ) {
        $to      = sanitize_email( get_option( 'ccb_admin_email', get_option( 'admin_email' ) ) );
        $subject = '🧹 New Booking: ' . $booking['first_name'] . ' ' . $booking['last_name'] . ' — ' . $booking['booking_ref'];
        $message = self::admin_html( $booking );
        self::send( $to, $subject, $message );
    }

    /**
     * Core sender — sets HTML content type.
     */
    private static function send( $to, $subject, $html ) {
        $from_name  = get_option( 'ccb_from_name', get_bloginfo( 'name' ) );
        $from_email = get_option( 'ccb_from_email', get_option( 'admin_email' ) );

        add_filter( 'wp_mail_content_type', function () { return 'text/html'; } );
        add_filter( 'wp_mail_from',         function () use ( $from_email ) { return $from_email; } );
        add_filter( 'wp_mail_from_name',    function () use ( $from_name )  { return $from_name;  } );

        wp_mail( $to, $subject, $html );
    }

    /* ── HTML builders ──────────────────────────────────────── */

    private static function customer_html( $d ) {
        $name    = esc_html( $d['first_name'] . ' ' . $d['last_name'] );
        $ref     = esc_html( $d['booking_ref'] );
        $svc     = esc_html( self::$svc_names[ $d['service_type'] ] ?? $d['service_type'] );
        $freq    = esc_html( self::$freq_names[ $d['frequency'] ] ?? $d['frequency'] );
        $date    = $d['clean_date'] ? esc_html( date( 'F j, Y', strtotime( $d['clean_date'] ) ) ) : '—';
        $time    = esc_html( $d['clean_time'] ?? '—' );
        $addr    = esc_html( trim( $d['address'] . ( $d['apt_no'] ? ', Apt ' . $d['apt_no'] : '' ) ) );
        $total   = '$' . number_format( (float) $d['grand_total'], 2 );
        $orange  = '#F57C00';

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><style>
body{font-family:Inter,Arial,sans-serif;background:#f5f5f5;margin:0;padding:0}
.wrap{max-width:600px;margin:30px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)}
.hdr{background:{$orange};padding:28px 32px;text-align:center;color:#fff}
.hdr h1{margin:0 0 4px;font-size:1.5rem}
.hdr p{margin:0;opacity:.9;font-size:.9rem}
.body{padding:28px 32px}
.body p{color:#424242;line-height:1.7;font-size:.9rem}
.ref{background:#FFF3E0;border:1px solid #FFCC80;border-radius:8px;padding:12px 18px;font-size:1rem;font-weight:700;color:{$orange};text-align:center;margin:18px 0;letter-spacing:1px}
table{width:100%;border-collapse:collapse;margin:16px 0}
td{padding:8px 0;font-size:.87rem;border-bottom:1px solid #F5F5F5;vertical-align:top}
td:first-child{color:#9E9E9E;width:44%;font-weight:400}
td:last-child{color:#212121;font-weight:600}
.total-row td{border-bottom:none;padding-top:12px;border-top:2px solid #EEE;font-size:1rem}
.total-row td:last-child{color:{$orange};font-size:1.2rem}
.footer{background:#FAFAFA;padding:20px 32px;text-align:center;font-size:.78rem;color:#9E9E9E;border-top:1px solid #EEE}
.btn{display:inline-block;background:{$orange};color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:16px}
</style></head>
<body>
<div class="wrap">
  <div class="hdr">
    <h1>✅ Booking Confirmed!</h1>
    <p>Your cleaning is scheduled. See details below.</p>
  </div>
  <div class="body">
    <p>Hi <strong>{$name}</strong>,</p>
    <p>Thank you for booking with us! Your cleaning appointment has been confirmed and payment processed successfully. Here's a summary of your booking:</p>
    <div class="ref">Booking Reference: {$ref}</div>
    <table>
      <tr><td>Service</td><td>{$svc}</td></tr>
      <tr><td>Frequency</td><td>{$freq}</td></tr>
      <tr><td>Date</td><td>{$date}</td></tr>
      <tr><td>Time</td><td>{$time}</td></tr>
      <tr><td>Address</td><td>{$addr}</td></tr>
      <tr class="total-row"><td>Total Charged</td><td>{$total}</td></tr>
    </table>
    <p style="font-size:.82rem;color:#757575">💳 Your card has been charged after service confirmation. A receipt has been processed.</p>
    <p>Our team will contact you before your scheduled cleaning to confirm any final details. If you need to make changes or have questions, please reply to this email.</p>
    <p>Thank you for choosing CleanCo! 🌿</p>
  </div>
  <div class="footer">
    <p>CleanCo — Professional Cleaning Services</p>
    <p>This email was sent to confirm your booking. Do not reply if you did not make this booking.</p>
  </div>
</div>
</body></html>
HTML;
    }

    private static function admin_html( $d ) {
        $name    = esc_html( $d['first_name'] . ' ' . $d['last_name'] );
        $ref     = esc_html( $d['booking_ref'] );
        $svc     = esc_html( self::$svc_names[ $d['service_type'] ] ?? $d['service_type'] );
        $freq    = esc_html( self::$freq_names[ $d['frequency'] ] ?? $d['frequency'] );
        $sqft    = esc_html( self::$sqft_labels[ (int) $d['sqft_tier'] ] ?? '—' );
        $date    = $d['clean_date'] ? esc_html( date( 'F j, Y', strtotime( $d['clean_date'] ) ) ) : '—';
        $time    = esc_html( $d['clean_time'] ?? '—' );
        $addr    = esc_html( trim( $d['address'] . ( $d['apt_no'] ? ', Apt ' . $d['apt_no'] : '' ) . ( $d['buzzer_code'] ? ' [Buzzer: ' . $d['buzzer_code'] . ']' : '' ) ) );
        $htype   = esc_html( ucfirst( $d['home_type'] ) );
        $beds    = (int) $d['bedrooms'];
        $baths   = (int) $d['bathrooms'];
        $pets    = $d['pets'] ? 'Yes (+$20)' : 'No';
        $eco     = $d['eco_products'] ? 'Yes (+$15)' : 'No';
        $cond    = [ 'Well maintained', 'Average', 'Extremely messy (+$23.99)' ][ (int)$d['home_condition'] ] ?? '—';
        $extras  = esc_html( $d['extras'] ?: 'None' );
        $email   = esc_html( $d['email'] );
        $phone   = esc_html( $d['phone'] );
        $loc     = esc_html( $d['location'] );
        $pm      = esc_html( $d['payment_method'] === 'card' ? 'Stripe Card' : 'PayPal' );
        $total   = '$' . number_format( (float) $d['grand_total'], 2 );
        $pretax  = '$' . number_format( (float) $d['pretax_total'], 2 );
        $tax     = '$' . number_format( (float) $d['tax_amt'], 2 );
        $tip     = '$' . number_format( (float) $d['tip_amt'], 2 );
        $orange  = '#F57C00';
        $admin_url = admin_url( 'admin.php?page=ccb-bookings' );

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><style>
body{font-family:Inter,Arial,sans-serif;background:#f5f5f5;margin:0;padding:0}
.wrap{max-width:640px;margin:30px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)}
.hdr{background:{$orange};padding:22px 32px;color:#fff;display:flex;align-items:center;gap:12px}
.hdr h1{margin:0;font-size:1.25rem}
.body{padding:24px 32px}
.ref{background:#FFF3E0;border:1px solid #FFCC80;border-radius:8px;padding:10px 16px;font-weight:700;color:{$orange};margin-bottom:18px;letter-spacing:1px;font-size:.95rem}
h2{font-size:.9rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#9E9E9E;margin:20px 0 8px}
table{width:100%;border-collapse:collapse}
td{padding:7px 0;font-size:.86rem;border-bottom:1px solid #F5F5F5;vertical-align:top}
td:first-child{color:#9E9E9E;width:42%}
td:last-child{color:#212121;font-weight:600}
.total-row td{border-bottom:none;padding-top:12px;border-top:2px solid #EEE;font-size:.95rem}
.total-row td:last-child{color:{$orange};font-size:1.1rem}
.btn{display:inline-block;background:{$orange};color:#fff;padding:10px 22px;border-radius:8px;text-decoration:none;font-weight:700;margin-top:18px;font-size:.88rem}
.footer{background:#FAFAFA;padding:16px 32px;font-size:.75rem;color:#9E9E9E;border-top:1px solid #EEE;text-align:center}
</style></head>
<body>
<div class="wrap">
  <div class="hdr"><span style="font-size:1.5rem">🧹</span><h1>New Booking Received</h1></div>
  <div class="body">
    <div class="ref">📋 Ref: {$ref}</div>

    <h2>Customer</h2>
    <table>
      <tr><td>Name</td><td>{$name}</td></tr>
      <tr><td>Email</td><td>{$email}</td></tr>
      <tr><td>Phone</td><td>{$phone}</td></tr>
      <tr><td>Location</td><td>{$loc}</td></tr>
    </table>

    <h2>Property</h2>
    <table>
      <tr><td>Address</td><td>{$addr}</td></tr>
      <tr><td>Home Type</td><td>{$htype}</td></tr>
      <tr><td>Bedrooms</td><td>{$beds}</td></tr>
      <tr><td>Bathrooms</td><td>{$baths}</td></tr>
      <tr><td>Sq Ft Range</td><td>{$sqft}</td></tr>
      <tr><td>Pets</td><td>{$pets}</td></tr>
      <tr><td>Eco Products</td><td>{$eco}</td></tr>
      <tr><td>Home Condition</td><td>{$cond}</td></tr>
    </table>

    <h2>Service</h2>
    <table>
      <tr><td>Service Type</td><td>{$svc}</td></tr>
      <tr><td>Frequency</td><td>{$freq}</td></tr>
      <tr><td>Extras</td><td>{$extras}</td></tr>
      <tr><td>Date</td><td>{$date}</td></tr>
      <tr><td>Time</td><td>{$time}</td></tr>
    </table>

    <h2>Payment</h2>
    <table>
      <tr><td>Method</td><td>{$pm}</td></tr>
      <tr><td>Pre-Tax</td><td>{$pretax}</td></tr>
      <tr><td>Tax (13% HST)</td><td>{$tax}</td></tr>
      <tr><td>Gratuity</td><td>{$tip}</td></tr>
      <tr class="total-row"><td>Total Charged</td><td>{$total}</td></tr>
    </table>

    <a href="{$admin_url}" class="btn">View in Dashboard →</a>
  </div>
  <div class="footer">CleanCo Booking System</div>
</div>
</body></html>
HTML;
    }
}
