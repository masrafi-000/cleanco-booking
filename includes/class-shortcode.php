<?php
defined( 'ABSPATH' ) || exit;

class CCB_Shortcode {

    public static function init() {
        add_shortcode( 'cleanco_booking', [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function enqueue() {
        global $post;
        if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'cleanco_booking' ) ) {
            return;
        }

        wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', [], null, true );

        wp_enqueue_style(
            'cleanco-booking',
            CCB_URL . 'assets/css/cleanco-booking.css',
            [],
            CCB_VERSION
        );

        wp_enqueue_script(
            'cleanco-booking',
            CCB_URL . 'assets/js/cleanco-booking.js',
            [ 'stripe-js' ],
            CCB_VERSION,
            true
        );

        wp_localize_script( 'cleanco-booking', 'CCB_CONFIG', [
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'ccb_nonce' ),
            'stripe_pk'    => CCB_Stripe::publishable_key(),
            'currency'     => '$',
            'tax_rate'     => 0.13,
            'is_test_mode' => CCB_Stripe::is_test_mode(),
        ] );
    }

    public static function render( $atts ) {
        ob_start();
        ?>
<div class="ccb-root" id="ccbRoot">
<div class="ccb-layout">
<div class="ccb-form">

  <!-- STEP 1: Service -->
  <div class="ccb-pre ccb-on" id="ccbP1">
    <span class="ccb-pnum">Step 1 of 4</span>
    <span class="ccb-title">Get Your Instant Cleaning Quote</span>
    <div class="ccb-fg">
      <div class="ccb-lbl">What Service Are You Interested In? <span class="ccb-req">*</span></div>
      <select id="ccbService" onchange="ccbSvcDesc()">
        <option value="">— Select a Service —</option>
        <option value="routine">Routine Cleaning</option>
        <option value="deep" disabled class="ccb-opt-na">Deep Cleaning (Not Available)</option>
        <option value="movein" disabled class="ccb-opt-na">Move In / Move Out (Not Available)</option>
<!--         <option value="postreno">Post-Renovation</option>
        <option value="airbnb">Airbnb / Short-Term Rental</option> -->
      </select>
      <span class="ccb-hint">Need help choosing? <a href="#">View Cleaning Packages</a> or <a href="#">Contact Us</a>.</span>
    </div>
    <div class="ccb-sdesc" id="ccbSDesc"></div>
    <div class="ccb-err" id="ccbE1">⚠️ Please select an available service to continue.</div>
    <div class="ccb-btnrow"><button class="ccb-next" onclick="ccbGoP(2)">Next ›</button></div>
  </div>

  <!-- STEP 2: Name -->
  <div class="ccb-pre" id="ccbP2">
    <span class="ccb-pnum">Step 2 of 4</span>
    <span class="ccb-title">Get Your Instant Cleaning Quote</span>
    <div class="ccb-fg">
      <div class="ccb-lbl">Your Name <span class="ccb-req">*</span></div>
      <div class="ccb-r2">
        <input type="text" id="ccbFname" placeholder="First Name">
        <input type="text" id="ccbLname" placeholder="Last Name">
      </div>
    </div>
    <div class="ccb-err" id="ccbE2">⚠️ Please enter both your first and last name.</div>
    <div class="ccb-btnrow">
      <button class="ccb-back" onclick="ccbGoP(1)">‹ Back</button>
      <button class="ccb-next" onclick="ccbGoP(3)">Next ›</button>
    </div>
  </div>

  <!-- STEP 3: Location -->
  <div class="ccb-pre" id="ccbP3">
    <span class="ccb-pnum">Step 3 of 4</span>
    <span class="ccb-title">Get Your Instant Cleaning Quote</span>
    <div class="ccb-fg">
      <div class="ccb-lbl">Choose your Location <span class="ccb-req">*</span></div>
      <select id="ccbLoc">
        <option value="">— Select a Location —</option>
        <option>Toronto</option><option>Mississauga</option><option>Brampton</option>
        <option>Vaughan</option><option>Markham</option><option>Richmond Hill</option><option>Oakville</option>
      </select>
      <span class="ccb-hint">This helps us reflect accurate availability</span>
    </div>
    <div class="ccb-err" id="ccbE3">⚠️ Please select your location to continue.</div>
    <div class="ccb-btnrow">
      <button class="ccb-back" onclick="ccbGoP(2)">‹ Back</button>
      <button class="ccb-next" onclick="ccbGoP(4)">Next ›</button>
    </div>
  </div>

  <!-- STEP 4: Phone -->
  <div class="ccb-pre" id="ccbP4">
    <span class="ccb-pnum">Step 4 of 4</span>
    <span class="ccb-title">Get Your Instant Cleaning Quote</span>
    <span class="ccb-note">You will be redirected to our booking system. You will <u><strong>not</strong></u> be added to a mailing list.</span>
    <div class="ccb-fg">
      <div class="ccb-lbl">Phone <span class="ccb-req">*</span></div>
      <input type="tel" id="ccbPhone" placeholder="(235) 265-2562">
    </div>
    <div class="ccb-err" id="ccbE4">⚠️ Please enter your phone number to continue.</div>
    <div class="ccb-btnrow">
      <button class="ccb-back" onclick="ccbGoP(3)">‹ Back</button>
      <button class="ccb-next" onclick="ccbLaunch()">Next ›</button>
    </div>
  </div>

  <!-- ═══════════════════ MAIN BOOKING FORM ═══════════════════ -->
  <div class="ccb-main" id="ccbMain">

    <div class="ccb-fg">
      <div class="ccb-lbl">Location</div>
      <select id="ccbMfLoc" onchange="ccbCalc()">
        <option>Toronto</option><option>Mississauga</option><option>Brampton</option>
        <option>Vaughan</option><option>Markham</option><option>Richmond Hill</option>
      </select>
    </div>

    <div class="ccb-sh">
      <h3>What Type of Cleaning Do You Need?</h3>
      <p>Each service includes a checklist. <a href="#">Services outlined here</a>.</p>
    </div>
    <div class="ccb-fg">
      <select id="ccbMfSvc" onchange="ccbCalc()">
        <option value="routine">Routine Cleaning</option>
        <option value="deep"     disabled>Deep Cleaning (Not Available)</option>
        <option value="movein"   disabled>Move In / Move Out (Not Available)</option>
<!--         <option value="postreno">Post-Renovation</option>
        <option value="airbnb">Airbnb / Short-Term Rental</option> -->
      </select>
    </div>

    <!-- ── NO FREQUENCY SECTION (removed per client request) ── -->

    <div class="ccb-fg" style="margin-top:8px">
      <div class="ccb-lbl">When Was Your Last Clean With Us?</div>
      <select>
        <option value="">Select Option</option>
        <option>First time</option>
        <option>Less than 1 month ago</option>
        <option>1–3 months ago</option>
        <option>3–6 months ago</option>
        <option>Over 6 months ago</option>
      </select>
    </div>

    <div class="ccb-sh"><h3>Step 1 — Tell Us About Your Home</h3></div>
    <div class="ccb-r2" style="margin-bottom:14px">
      <div class="ccb-fg" style="margin-bottom:0">
        <div class="ccb-lbl">Home Type</div>
        <select id="ccbHtype" onchange="ccbCalc()">
          <option value="condo">Condo / Apartment</option>
          <option value="house">House</option>
          <option value="townhouse">Townhouse</option>
        </select>
      </div>
      <div class="ccb-fg" style="margin-bottom:0">
        <div class="ccb-lbl"># Bedrooms (Including Dens)</div>
        <select id="ccbBeds" onchange="ccbCalc()">
          <option value="1">1 Bedroom</option>
          <option value="2">2 Bedrooms (+$17)</option>
          <option value="3">3 Bedrooms (+$34)</option>
          <option value="4">4 Bedrooms (+$51)</option>
          <option value="5">5+ Bedrooms (+$68)</option>
        </select>
      </div>
    </div>
    <div class="ccb-r2" style="margin-bottom:14px">
      <div class="ccb-fg" style="margin-bottom:0">
        <div class="ccb-lbl"># Bathrooms</div>
        <select id="ccbBaths" onchange="ccbCalc()">
          <option value="1">1 Bathroom</option>
          <option value="2">2 Bathrooms (+$20)</option>
          <option value="3">3 Bathrooms (+$40)</option>
          <option value="4">4+ Bathrooms (+$60)</option>
        </select>
      </div>
      <div class="ccb-fg" style="margin-bottom:0">
        <div class="ccb-lbl">Sq Ft</div>
        <select id="ccbSqft" onchange="ccbCalc()">
          <option value="0">≤ 650 sq ft (Base)</option>
          <option value="1">651 – 850 sq ft (+$12)</option>
          <option value="2">851 – 1,100 sq ft (+$24)</option>
          <option value="3">1,101 – 1,400 sq ft (+$36)</option>
          <option value="4">1,401 – 1,800 sq ft (+$54)</option>
          <option value="5">1,800+ sq ft (+$75 — Custom Quote)</option>
        </select>
        <div class="ccb-customq" id="ccbCustomQ">
          🏠 For homes over 1,800 sq ft a custom quote is required.
          Please <a href="#" style="color:#E65100">contact us</a> for accurate pricing.
        </div>
      </div>
    </div>
    <div class="ccb-r2" style="margin-bottom:22px">
      <div class="ccb-fg" style="margin-bottom:0">
        <div class="ccb-lbl">Do You Have Pets?</div>
        <select id="ccbPets" onchange="ccbCalc()">
          <option value="no">No</option>
          <option value="yes">Yes (+$20)</option>
        </select>
      </div>
      <div class="ccb-fg" style="margin-bottom:0">
        <div class="ccb-lbl">Use Eco-Friendly Products?</div>
        <select id="ccbEco" onchange="ccbCalc()">
          <option value="no">No special products</option>
          <option value="yes">Yes — Eco-Friendly (+$15)</option>
        </select>
      </div>
    </div>

    <div class="ccb-fg">
      <div class="ccb-lbl">Home Condition</div>
      <div style="font-size:.8rem;color:#9E9E9E;margin-bottom:8px">Helps us send the right team.</div>
      <select id="ccbMess" onchange="ccbCalc()">
        <option value="0">Well maintained — light clean needed</option>
        <option value="1">Average — some areas need attention</option>
        <option value="2">Extremely messy / dirty (+$23.99)</option>
      </select>
    </div>

    <div class="ccb-sh">
      <h3>Step 2 — Optional Add-Ons</h3>
      <p>These are <strong>not included</strong> in your routine clean. <a href="#">See full checklist here</a>.</p>
    </div>
    <div class="ccb-extras">
      <div class="ccb-extra" data-k="dishes"  data-p="22.5"  onclick="ccbXtra(this)"><div class="ccb-echk">✓</div><div class="ccb-eico">🍽️</div><div class="ccb-ename">Dishes + Dishwasher Loading</div><div class="ccb-epx">+$22.50</div></div>
      <div class="ccb-extra" data-k="oven"    data-p="10.99" onclick="ccbXtra(this)"><div class="ccb-echk">✓</div><div class="ccb-eico">🍳</div><div class="ccb-ename">Inside Oven</div><div class="ccb-epx">+$10.99</div></div>
      <div class="ccb-extra" data-k="fridge"  data-p="16.99" onclick="ccbXtra(this)"><div class="ccb-echk">✓</div><div class="ccb-eico">🧊</div><div class="ccb-ename">Inside Fridge</div><div class="ccb-epx">+$16.99</div></div>
      <div class="ccb-extra" data-k="windows" data-p="16.99" onclick="ccbXtra(this)"><div class="ccb-echk">✓</div><div class="ccb-eico">🪟</div><div class="ccb-ename">Interior Windows</div><div class="ccb-epx">+$16.99</div></div>
      <div class="ccb-extra" data-k="closet"  data-p="34.99" onclick="ccbXtra(this)"><div class="ccb-echk">✓</div><div class="ccb-eico">🗂️</div><div class="ccb-ename">Closet Organizing</div><div class="ccb-epx">+$34.99</div></div>
    </div>

    <div class="ccb-sh" style="margin-top:28px"><h3>Step 3 — When would you like us to come?</h3></div>
    <div class="ccb-fg"><div class="ccb-lbl">Select Date</div><input type="date" id="ccbDate"></div>
    <div class="ccb-fg">
      <div class="ccb-lbl">Preferred Time</div>
      <select id="ccbTime">
        <option value="">Select a time</option>
        <option>8:00 AM</option><option>9:00 AM</option><option>10:00 AM</option>
        <option>11:00 AM</option><option>12:00 PM</option><option>1:00 PM</option>
        <option>2:00 PM</option><option>3:00 PM</option><option>4:00 PM</option>
      </select>
    </div>

    <div class="ccb-fg">
      <div class="ccb-lbl">Optional Gratuity</div>
      <div style="font-size:.78rem;color:#9E9E9E;margin-bottom:8px">100% goes directly to your cleaning professional.</div>
      <div class="ccb-tips">
        <div class="ccb-tip ccb-sel" onclick="ccbTip(this,0)">0%</div>
        <div class="ccb-tip" onclick="ccbTip(this,10)">10%</div>
        <div class="ccb-tip" onclick="ccbTip(this,15)">15%</div>
        <div class="ccb-tip" onclick="ccbTip(this,18)">18%</div>
        <div class="ccb-tip" onclick="ccbTip(this,20)">20%</div>
        <div class="ccb-tip" onclick="ccbTip(this,25)">25%</div>
      </div>
    </div>

    <div class="ccb-sh"><h3>Step 4 — Contact Information</h3></div>
    <div class="ccb-r2" style="margin-bottom:14px">
      <div class="ccb-fg" style="margin-bottom:0"><div class="ccb-lbl">First Name <span class="ccb-req">*</span></div><input type="text" id="ccbMfFn" placeholder="Christine" oninput="ccbCalc()"></div>
      <div class="ccb-fg" style="margin-bottom:0"><div class="ccb-lbl">Last Name <span class="ccb-req">*</span></div><input type="text" id="ccbMfLn" placeholder="Rose" oninput="ccbCalc()"></div>
    </div>
    <div class="ccb-fg"><div class="ccb-lbl">Email Address <span class="ccb-req">*</span></div><input type="email" id="ccbMfEm" placeholder="hi@example.com" oninput="ccbCalc()"></div>
    <div class="ccb-fg">
      <div class="ccb-lbl">Phone No <span class="ccb-req">*</span></div>
      <div class="ccb-phonerow">
        <select><option>🇨🇦 +1</option><option>🇺🇸 +1</option></select>
        <input type="tel" id="ccbMfPh" placeholder="(235) 265-2562" oninput="ccbCalc()">
      </div>
    </div>
    <div class="ccb-fg">
      <label style="display:flex;align-items:center;gap:8px;font-size:.82rem;color:#424242;cursor:pointer">
        <input type="checkbox" checked style="accent-color:#F57C00;width:16px;height:16px;padding:0;flex-shrink:0">
        Send me reminders about my booking via text message
      </label>
    </div>

    <div class="ccb-sh"><h3>Address For The Clean</h3></div>
    <div class="ccb-r2" style="margin-bottom:14px">
      <div class="ccb-fg" style="margin-bottom:0"><div class="ccb-lbl">Address <span class="ccb-req">*</span></div><input type="text" id="ccbAddr" placeholder="Street Address"></div>
      <div class="ccb-fg" style="margin-bottom:0"><div class="ccb-lbl">Apt. No.</div><input type="text" id="ccbApt" placeholder="#"></div>
    </div>
    <div class="ccb-fg"><div class="ccb-lbl">Buzzer Code (if applicable)</div><input type="text" id="ccbBuzz" placeholder="Buzzer Code(s)"></div>

    <!-- COUPON -->
    <div class="ccb-fg">
      <div class="ccb-ctabs">
        <div class="ccb-ctab ccb-on" id="ccbCtab1" onclick="ccbCouponTab(1)">Coupon Code</div>
        <div class="ccb-ctab" id="ccbCtab2" onclick="ccbCouponTab(2)">Gift Cards</div>
      </div>
      <div style="font-size:.78rem;font-weight:600;color:#424242;margin-bottom:6px">Enter Coupon Code</div>
      <div class="ccb-crow">
        <input type="text" id="ccbCoupon" placeholder="Enter your coupon is here" style="text-transform:uppercase">
        <button class="ccb-apply" onclick="ccbApplyCoupon()">Apply</button>
      </div>
      <div id="ccbCouponMsg" style="font-size:.78rem;margin-top:6px;display:none"></div>
    </div>

    <!-- VALIDATION ERROR BANNER -->
    <div class="ccb-err" id="ccbMainErr">⚠️ Please fill in all required fields before booking.</div>

    <div class="ccb-sh"><h3>Payment Information</h3></div>
    <div class="ccb-pmethods">
      <div class="ccb-pm ccb-card ccb-sel" id="ccbPmCard">
        <div class="ccb-pmico">💳</div>
        <div class="ccb-pmlb">Credit / Debit Card</div>
        <div class="ccb-pmsb" style="color:#635BFF">via Stripe</div>
      </div>
    </div>

    <!-- Stripe Card Element -->
    <div class="ccb-spanel ccb-on" id="ccbSPanel">
      <?php if ( CCB_Stripe::is_test_mode() ) : ?>
      <div class="ccb-demo">🧪 Test Mode — Card: 4242 4242 4242 4242 · Exp: 12/34 · CVV: 123</div>
      <?php endif; ?>
      <div class="ccb-fg">
        <div class="ccb-lbl">Billing Address</div>
        <input type="text" id="ccbBillAddr" placeholder="Billing Address">
      </div>
      <div class="ccb-fg">
        <div class="ccb-lbl">Card Details <span class="ccb-req">*</span></div>
        <div id="ccbCardElement" style="padding:11px 14px;border:1.5px solid #D9D9D9;border-radius:6px;background:#fff;min-height:44px"></div>
        <div id="ccbCardErrors" style="color:#D32F2F;font-size:.8rem;margin-top:6px"></div>
      </div>
    </div>

    <!-- Processing spinner -->
    <div class="ccb-proc" id="ccbProc">
      <div class="ccb-spin"></div>
      <div style="font-weight:600;margin-bottom:4px" id="ccbProcLbl">Processing payment…</div>
      <div style="font-size:.82rem;color:#9E9E9E">Please don't close this window</div>
    </div>

    <div style="margin-top:22px" id="ccbBookSection">
      <div class="ccb-agree">
        <input type="checkbox" id="ccbAgree" checked>
        <label for="ccbAgree">By checking this box, I acknowledge that I have read, understood, and agreed to CleanCo's <a href="#">policies, cancellation terms, and service checklist</a>.</label>
      </div>
      <div class="ccb-hold">ⓘ A card hold will be placed before your cleaning, but your card will only be charged after the service is completed.</div>
      <button class="ccb-bookbtn" id="ccbBookBtn" onclick="ccbPay()">📅 Book My Clean — <span id="ccbBookTotal">$0</span></button>
      <div class="ccb-secure" style="margin-top:12px">
        <span>🔒 SSL Encrypted</span><span>🛡️ PCI Compliant</span><span>✅ Powered by Stripe</span>
      </div>
    </div>

  </div><!-- /ccb-main -->

  <!-- SUCCESS SCREEN -->
  <div class="ccb-done" id="ccbDone">
    <div style="text-align:center;padding:40px 0">
      <div class="ccb-sico">✓</div>
      <span class="ccb-stitle">Booking Confirmed!</span>
      <span class="ccb-ssub" id="ccbDoneMsg">Your payment was processed and your clean is booked.<br>Our team will be in touch with your confirmation details.</span>
      <div class="ccb-smail" id="ccbDoneMail">📧 Confirmation sent to your email address.</div>
    </div>
  </div>

</div><!-- /ccb-form -->

<!-- ═══════════════════ SIDEBAR ═══════════════════ -->
<div class="ccb-sidebar">
  <div class="ccb-sbt">Booking Summary</div>

  <!-- Details block -->
  <div class="ccb-sumblock">
    <div class="ccb-sumrow"><span class="ccb-sl">Service</span><span class="ccb-sv" id="sbSvc">—</span></div>
    <div class="ccb-sumrow"><span class="ccb-sl">Home Type</span><span class="ccb-sv" id="sbHtype">Condo / Apartment</span></div>
    <div class="ccb-sumrow"><span class="ccb-sl"># Bedrooms</span><span class="ccb-sv" id="sbBeds">1 Bedroom</span></div>
    <div class="ccb-sumrow"><span class="ccb-sl"># Bathrooms</span><span class="ccb-sv" id="sbBaths">1 Bathroom</span></div>
    <div class="ccb-sumrow"><span class="ccb-sl">Sq Ft</span><span class="ccb-sv" id="sbSqft">≤ 650 sq ft</span></div>
    <div class="ccb-sumrow"><span class="ccb-sl">Pets?</span><span class="ccb-sv" id="sbPets">No</span></div>
    <div class="ccb-sumrow"><span class="ccb-sl">Eco Products?</span><span class="ccb-sv" id="sbEco">No special products</span></div>
    <!-- Estimated time row -->
    <div class="ccb-sumrow" id="sbEstRow">
      <span class="ccb-sl">Est. Duration</span>
      <span class="ccb-sv" id="sbEst" style="color:#F57C00;font-weight:700">—</span>
    </div>
  </div>

  <!-- Price breakdown block -->
  <div class="ccb-prblock">
    <div class="ccb-prrow"><span class="ccb-prl">Base Price</span><span class="ccb-prv" id="pbBase">$85</span></div>
    <div class="ccb-prrow" id="prBeds"  style="display:none"><span class="ccb-prl">Bedrooms</span><span class="ccb-prv" id="pbBeds">$0</span></div>
    <div class="ccb-prrow" id="prBaths" style="display:none"><span class="ccb-prl">Bathrooms</span><span class="ccb-prv" id="pbBaths">$0</span></div>
    <div class="ccb-prrow" id="prSqft"  style="display:none"><span class="ccb-prl">Size factor</span><span class="ccb-prv" id="pbSqft">$0</span></div>
    <div class="ccb-prrow" id="prSvc"   style="display:none"><span class="ccb-prl">Service type</span><span class="ccb-prv" id="pbSvc">$0</span></div>
    <div class="ccb-prrow addon" id="prXtra" style="display:none"><span class="ccb-prl">Extras</span><span class="ccb-prv" id="pbXtra">$0</span></div>
    <div class="ccb-prrow addon" id="prPets" style="display:none"><span class="ccb-prl">Pet surcharge</span><span class="ccb-prv" id="pbPets">$0</span></div>
    <div class="ccb-prrow addon" id="prEco"  style="display:none"><span class="ccb-prl">Eco products</span><span class="ccb-prv" id="pbEco">$0</span></div>
    <div class="ccb-prrow addon" id="prMess" style="display:none"><span class="ccb-prl">Messy surcharge</span><span class="ccb-prv" id="pbMess">$0</span></div>
    <!-- Coupon discount row (shown when a valid coupon is applied) -->
    <div class="ccb-prrow disc" id="prCoupon" style="display:none">
      <span class="ccb-prl">Coupon (<span id="pbCouponCode">—</span>)</span>
      <span class="ccb-prv" id="pbCoupon">$0</span>
    </div>
    <div class="ccb-prrow addon" id="prTip" style="display:none"><span class="ccb-prl">Gratuity</span><span class="ccb-prv" id="pbTip">$0</span></div>
    <hr class="ccb-divider">
    <div class="ccb-prrow"><span class="ccb-prl">Before Tax</span><span class="ccb-prv" id="pbPretax">$0</span></div>
    <div class="ccb-prrow ptotal">
      <span class="ccb-prl">Total (Inc. 13% Tax)</span>
      <span class="ccb-prv" id="pbTotal">$0</span>
    </div>
  </div>

  <div class="ccb-badges" style="margin-top:20px">
    <div class="ccb-badge">🛡️ Insured &amp; background-checked cleaners</div>
    <div class="ccb-badge">♻️ Eco-friendly products available</div>
    <div class="ccb-badge">⭐ 4.9/5 from 1,200+ reviews</div>
    <div class="ccb-badge">💳 Charged only after service is complete</div>
  </div>
</div>

</div></div>
        <?php
        return ob_get_clean();
    }
}
