/**
 * CleanCo Booking — Frontend JS v3
 *
 * Changes vs v2:
 *  - Frequency section removed; freq hardcoded to 'once'
 *  - Deep clean / move-in-out disabled in step 1 guard
 *  - Estimated cleaning time shown live in sidebar
 *  - Coupon FIRSTCLEAN10 validated via AJAX (10% off)
 *  - Coupon discount line shown/hidden in price breakdown
 */
(function () {
  'use strict';

  var CFG   = window.CCB_CONFIG || {};
  var AJAX  = CFG.ajax_url || '/wp-admin/admin-ajax.php';
  var NONCE = CFG.nonce   || '';
  var TAX   = parseFloat( CFG.tax_rate ) || 0.13;
  var CUR   = CFG.currency || '$';

  /* ── State ── */
  var S = {
    svc:  'routine', tip:  0,
    htype:'condo',   beds: '1',  baths: '1',  sqft: '0',
    pets: 'no',      eco:  'no', mess:  '0',
    extras:     {},
    couponCode: '',
    couponPct:  0,
  };

  /* Booking IDs set after DB save */
  var bookingId  = null;
  var bookingRef = null;
  var curP       = 1;
  var stripe     = null, cardElement = null;

  /* ── Pricing tables ── */
  var SQFT_PRICES = [0, 12, 24, 36, 54, 75];
  var SQFT_LABELS = [
    '≤ 650 sq ft', '651–850 sq ft', '851–1,100 sq ft',
    '1,101–1,400 sq ft', '1,401–1,800 sq ft', '1,800+ sq ft',
  ];
  var SNAMES = {
    routine:  'Routine Cleaning',
    deep:     'Deep Cleaning',
    movein:   'Move In / Move Out',
    postreno: 'Post-Renovation',
    airbnb:   'Airbnb / Short-Term',
  };
  var SDESC = {
    routine:  '<strong>Routine Cleaning ✨</strong> — Best for well-maintained homes.',
    deep:     '<strong>Deep Cleaning 🧹</strong> — Currently not available for online booking. Please <a href="#">contact us</a> to arrange.',
    movein:   '<strong>Move In / Move Out 📦</strong> — Currently not available for online booking. Please <a href="#">contact us</a> to arrange.',
    postreno: '<strong>Post-Renovation 🔨</strong> — Removes dust, debris and construction residue from every surface.',
    airbnb:   '<strong>Airbnb / Short-Term Rental 🏨</strong> — Quick turnaround between guests with fresh presentation.',
  };
  /* Services not available for online booking */
  var UNAVAILABLE = { deep: true, movein: true };

  var SMULT = { routine: 1, deep: 1.55, movein: 1.85, postreno: 2.10, airbnb: 1.20 };
  var HTYPES = { condo: 'Condo / Apartment', house: 'House', townhouse: 'Townhouse' };
  var MESS_PRICES = [0, 0, 23.99];

  /* ── Estimated time lookup (minutes)
     Base: 1 bed, 1 bath, ≤650 sqft = 120 min
     +45 min per extra bedroom
     +30 min per extra bathroom
     Sqft add-ons: 0 / 15 / 30 / 45 / 60 / 90
     Mess: 0 / +30 / +60
     Service multiplier applied at end                        ── */
  var SQFT_TIME  = [0, 15, 30, 45, 60, 90];   // extra minutes per sqft tier
  var MESS_TIME  = [0, 30, 60];               // extra minutes per condition
  var SMULT_TIME = { routine: 1.0, deep: 1.6, movein: 1.8, postreno: 2.1, airbnb: 1.1 };

  /* ── DOM helper ── */
  function $e( id ) { return document.getElementById( id ); }
  function setT( id, v ) { var e = $e(id); if (e) e.textContent = v; }
  function showRow( rid, vid, show, val ) {
    var r = $e(rid); if (r) r.style.display = show ? 'flex' : 'none';
    if ( val !== undefined ) setT( vid, val );
  }
  function showErr( id, on ) {
    var e = $e(id); if (!e) return;
    e.classList[ on ? 'add' : 'remove' ]('ccb-on');
  }
  function markErr( el, on ) {
    if (!el) return;
    el.classList[ on ? 'add' : 'remove' ]('ccb-input-err');
  }
  function fmtD( n ) { return CUR + n.toFixed(2); }
  function fmt( n )  { return CUR + Math.round(n).toLocaleString(); }

  /* ══════════════════════════════════════════════════════════
     ESTIMATED TIME
  ══════════════════════════════════════════════════════════ */
  function estimateTime() {
    var svc   = S.svc || 'routine';
    var beds  = parseInt(S.beds)  || 1;
    var baths = parseInt(S.baths) || 1;
    var sqIdx = parseInt(S.sqft)  || 0;
    var mess  = parseInt(S.mess)  || 0;

    var mins = 120;                                 // base
    mins += Math.max(0, beds  - 1) * 45;            // extra bedrooms
    mins += Math.max(0, baths - 1) * 30;            // extra bathrooms
    mins += SQFT_TIME[ sqIdx ] || 0;                // sqft
    mins += MESS_TIME[ Math.min(2, mess) ] || 0;    // condition
    mins  = Math.round( mins * (SMULT_TIME[svc] || 1) );

    // Build range: estimate ± 15%
    var lo = Math.round( mins * 0.9 / 15 ) * 15;   // round to nearest 15 min
    var hi = Math.round( mins * 1.1 / 15 ) * 15;

    function hm( m ) {
      var h = Math.floor(m / 60);
      var r = m % 60;
      if (r === 0) return h + ' hr' + (h !== 1 ? 's' : '');
      return h + ' hr ' + r + ' min';
    }

    if (lo === hi) return '~' + hm(lo);
    return hm(lo) + ' – ' + hm(hi);
  }

  /* ══════════════════════════════════════════════════════════
     PRICING (mirrors PHP compute_price exactly)
  ══════════════════════════════════════════════════════════ */
  function calc() {
    var svc   = S.svc || 'routine';
    var BASE  = 85;
    var beds  = parseInt(S.beds)  || 1;
    var baths = parseInt(S.baths) || 1;
    var bedC  = Math.max(0, beds  - 1) * 17;
    var bathC = Math.max(0, baths - 1) * 20;
    var sqIdx = parseInt(S.sqft) || 0;
    var sqC   = SQFT_PRICES[sqIdx] || 0;
    var isCustom = (sqIdx === 5);

    var sub   = (BASE + bedC + bathC + sqC) * (SMULT[svc] || 1);
    var ext   = Object.values(S.extras).reduce(function(a,b){ return a+b; }, 0);
    var petC  = S.pets === 'yes' ? 20    : 0;
    var ecoC  = S.eco  === 'yes' ? 15    : 0;
    var messC = MESS_PRICES[ parseInt(S.mess) ] || 0;

    var subtotal       = sub + ext + petC + ecoC + messC;
    var couponDiscount = subtotal * (S.couponPct / 100);
    var pretax         = subtotal - couponDiscount;
    var tip            = pretax * (S.tip / 100);
    var tax            = pretax * TAX;
    var total          = pretax + tax + tip;

    return {
      BASE, bedC, bathC, sqC, sqIdx, sub, ext, petC, ecoC, messC,
      couponDiscount, pretax, tip, tax, total, beds, baths, isCustom,
    };
  }

  /* ══════════════════════════════════════════════════════════
     SIDEBAR UPDATE
  ══════════════════════════════════════════════════════════ */
  window.ccbCalc = function () {
    var p      = calc();
    var svc    = S.svc || 'routine';
    var bedsEl = $e('ccbBeds');

    setT('sbSvc',   SNAMES[svc] || '—');
    setT('sbHtype', HTYPES[S.htype] || S.htype);
    setT('sbBeds',  bedsEl ? bedsEl.options[bedsEl.selectedIndex].text : '—');
    setT('sbBaths', p.baths + ' Bathroom' + (p.baths > 1 ? 's' : ''));
    setT('sbSqft',  SQFT_LABELS[p.sqIdx] || '—');
    setT('sbPets',  S.pets === 'yes' ? 'Yes' : 'No');
    setT('sbEco',   S.eco  === 'yes' ? 'Eco-Friendly (+$15)' : 'No special products');

    // Estimated time
    setT('sbEst', estimateTime());

    // Price rows
    setT('pbBase', fmt(p.BASE));
    showRow('prBeds',  'pbBeds',  p.bedC  > 0, '+' + fmtD(p.bedC)  + ' (' + Math.max(0,p.beds-1)  + ' extra bed'  + (p.beds  > 2 ? 's':'') + ')');
    showRow('prBaths', 'pbBaths', p.bathC > 0, '+' + fmtD(p.bathC) + ' (' + Math.max(0,p.baths-1) + ' extra bath' + (p.baths > 2 ? 's':'') + ')');
    showRow('prSqft',  'pbSqft',  p.sqC   > 0, '+' + fmtD(p.sqC)   + ' (' + SQFT_LABELS[p.sqIdx] + ')');
    showRow('prSvc',   'pbSvc',   svc !== 'routine', SNAMES[svc] + ' ×' + SMULT[svc]);
    showRow('prXtra',  'pbXtra',  p.ext   > 0, '+' + fmtD(p.ext));
    showRow('prPets',  'pbPets',  p.petC  > 0, '+' + fmtD(p.petC));
    showRow('prEco',   'pbEco',   p.ecoC  > 0, '+' + fmtD(p.ecoC));
    showRow('prMess',  'pbMess',  p.messC > 0, '+' + fmtD(p.messC));

    // Coupon row
    var hasCoupon = S.couponPct > 0 && S.couponCode;
    showRow('prCoupon', 'pbCoupon', hasCoupon,
      hasCoupon ? '−' + fmtD(p.couponDiscount) + ' (' + S.couponPct + '% off)' : '');
    setT('pbCouponCode', hasCoupon ? S.couponCode : '—');

    showRow('prTip', 'pbTip', p.tip > 0, '+' + fmtD(p.tip));
    setT('pbPretax', fmtD(p.pretax));

    // Animate total
    var totEl = $e('pbTotal'), newV = fmtD(p.total);
    if (totEl && totEl.textContent !== newV) {
      totEl.style.transform = 'scale(1.12)';
      totEl.textContent = newV;
      setTimeout(function(){ totEl.style.transform = ''; }, 350);
    }
    setT('ccbBookTotal', fmtD(p.total));

    // Custom quote notice
    var cq = $e('ccbCustomQ');
    if (cq) cq.style.display = p.isCustom ? 'block' : 'none';
  };

  /* ══════════════════════════════════════════════════════════
     MULTI-STEP NAVIGATION
  ══════════════════════════════════════════════════════════ */
  window.ccbGoP = function (n) {
    if (n > curP) {
      if (curP === 1) {
        var svcVal = $e('ccbService') ? $e('ccbService').value : '';
        var ok = !!svcVal && !UNAVAILABLE[svcVal];
        if (!svcVal) {
          showErr('ccbE1', true); markErr($e('ccbService'), true); return;
        }
        if (UNAVAILABLE[svcVal]) {
          showErr('ccbE1', true);
          var e1 = $e('ccbE1');
          if (e1) e1.textContent = '⚠️ This service is not available for online booking. Please contact us.';
          markErr($e('ccbService'), true); return;
        }
        showErr('ccbE1', false); markErr($e('ccbService'), false);
      }
      if (curP === 2) {
        var fn = ($e('ccbFname') ? $e('ccbFname').value : '').trim();
        var ln = ($e('ccbLname') ? $e('ccbLname').value : '').trim();
        var ok = fn && ln;
        showErr('ccbE2', !ok); markErr($e('ccbFname'), !fn); markErr($e('ccbLname'), !ln);
        if (!ok) return;
      }
      if (curP === 3) {
        var ok = !!($e('ccbLoc') && $e('ccbLoc').value);
        showErr('ccbE3', !ok); markErr($e('ccbLoc'), !ok);
        if (!ok) return;
      }
    }
    var prev = $e('ccbP' + curP); if (prev) prev.classList.remove('ccb-on');
    curP = n;
    var next = $e('ccbP' + curP); if (next) next.classList.add('ccb-on');
    scrollTop();
  };

  /* ══════════════════════════════════════════════════════════
     LAUNCH MAIN FORM
  ══════════════════════════════════════════════════════════ */
  window.ccbLaunch = function () {
    var ph = ($e('ccbPhone') ? $e('ccbPhone').value : '').trim();
    if (!ph) { showErr('ccbE4', true); markErr($e('ccbPhone'), true); return; }
    showErr('ccbE4', false); markErr($e('ccbPhone'), false);

    if ($e('ccbMfFn') && $e('ccbFname')) $e('ccbMfFn').value = $e('ccbFname').value;
    if ($e('ccbMfLn') && $e('ccbLname')) $e('ccbMfLn').value = $e('ccbLname').value;
    if ($e('ccbMfPh')) $e('ccbMfPh').value = ph;

    var loc = $e('ccbLoc'), mfl = $e('ccbMfLoc');
    if (loc && mfl) {
      for (var i = 0; i < mfl.options.length; i++) {
        if (mfl.options[i].text === loc.value) { mfl.selectedIndex = i; break; }
      }
    }
    var ms = $e('ccbMfSvc');
    if (ms) {
      for (var j = 0; j < ms.options.length; j++) {
        if (ms.options[j].value === S.svc) { ms.selectedIndex = j; break; }
      }
    }

    var prev = $e('ccbP' + curP); if (prev) prev.classList.remove('ccb-on');
    var main = $e('ccbMain'); if (main) main.classList.add('ccb-on');
    initStripe();
    ccbCalc();
    scrollTop();
  };

  /* ══════════════════════════════════════════════════════════
     STRIPE INIT
  ══════════════════════════════════════════════════════════ */
  function initStripe() {
    if (stripe || !CFG.stripe_pk || typeof Stripe === 'undefined') return;
    stripe = Stripe(CFG.stripe_pk);
    var elements = stripe.elements();
    cardElement = elements.create('card', {
      style: {
        base: {
          fontFamily: "'Inter', Arial, sans-serif", fontSize: '15px', color: '#212121',
          '::placeholder': { color: '#BDBDBD' },
        },
        invalid: { color: '#D32F2F' },
      },
    });
    var mount = $e('ccbCardElement');
    if (mount) cardElement.mount('#ccbCardElement');
    cardElement.on('change', function(e) {
      var el = $e('ccbCardErrors');
      if (el) el.textContent = e.error ? e.error.message : '';
    });
  }

  /* ══════════════════════════════════════════════════════════
     COUPON — instant client-side validation
     The server ALSO validates in compute_price() on save,
     so the discount cannot be spoofed.
  ══════════════════════════════════════════════════════════ */
  var VALID_COUPONS = { 'FIRSTCLEAN10': 10 };   // code → % off

  window.ccbApplyCoupon = function () {
    var code  = ($e('ccbCoupon') ? $e('ccbCoupon').value.toUpperCase().trim() : '');
    var msgEl = $e('ccbCouponMsg');
    if (!msgEl) return;

    msgEl.style.display = 'block';

    if (!code) {
      S.couponCode = ''; S.couponPct = 0;
      msgEl.style.color   = '#C62828';
      msgEl.textContent   = '⚠️ Please enter a coupon code.';
      ccbCalc(); return;
    }

    if (VALID_COUPONS[code] !== undefined) {
      S.couponCode        = code;
      S.couponPct         = VALID_COUPONS[code];
      msgEl.style.color   = '#2E7D32';
      msgEl.textContent   = '🎉 Coupon applied! ' + S.couponPct + '% off your booking.';
    } else {
      S.couponCode = ''; S.couponPct = 0;
      msgEl.style.color   = '#C62828';
      msgEl.textContent   = '⚠️ Invalid coupon code. Please check and try again.';
    }

    ccbCalc();   // immediately re-render sidebar with new discount
  };

  /* ══════════════════════════════════════════════════════════
     PAYMENT FLOW
     1. ccb_save_booking     → DB insert → booking_id
     2. ccb_create_payment_intent → Stripe PI → client_secret
     3. stripe.confirmCardPayment
     4. ccb_confirm_booking  → status=paid, emails
  ══════════════════════════════════════════════════════════ */
  window.ccbPay = function () {
    var fn   = ($e('ccbMfFn')  ? $e('ccbMfFn').value  : '').trim();
    var ln   = ($e('ccbMfLn')  ? $e('ccbMfLn').value  : '').trim();
    var em   = ($e('ccbMfEm')  ? $e('ccbMfEm').value  : '').trim();
    var ph   = ($e('ccbMfPh')  ? $e('ccbMfPh').value  : '').trim();
    var addr = ($e('ccbAddr')  ? $e('ccbAddr').value   : '').trim();
    var agree = $e('ccbAgree') ? $e('ccbAgree').checked : true;

    var hasErr = false;
    if (!fn)   { markErr($e('ccbMfFn'), true);  hasErr = true; }
    if (!ln)   { markErr($e('ccbMfLn'), true);  hasErr = true; }
    if (!em)   { markErr($e('ccbMfEm'), true);  hasErr = true; }
    if (!ph)   { markErr($e('ccbMfPh'), true);  hasErr = true; }
    if (!addr) { markErr($e('ccbAddr'),  true);  hasErr = true; }

    if (hasErr || !agree) {
      showErr('ccbMainErr', true);
      var errEl = $e('ccbMainErr');
      if (errEl) errEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    showErr('ccbMainErr', false);

    if (!stripe || !cardElement) {
      showCardError('Payment is not ready. Please refresh the page and try again.');
      return;
    }

    setProcessing(true, 'Saving your booking…');

    ajaxPost('ccb_save_booking', buildPayload(), function(res) {
      if (!res.success) {
        setProcessing(false);
        showCardError((res.data && res.data.message) ? res.data.message : 'Could not save booking. Please try again.');
        return;
      }

      bookingId  = res.data.booking_id;
      bookingRef = res.data.booking_ref;
      setProcessing(true, 'Connecting to payment…');

      ajaxPost('ccb_create_payment_intent', { booking_id: bookingId }, function(res2) {
        if (!res2.success) {
          setProcessing(false);
          showCardError((res2.data && res2.data.message) ? res2.data.message : 'Payment setup failed. Please try again.');
          return;
        }

        setProcessing(true, 'Processing payment…');
        stripe.confirmCardPayment(res2.data.client_secret, {
          payment_method: {
            card: cardElement,
            billing_details: { name: fn + ' ' + ln, email: em, phone: ph, address: { line1: addr } },
          },
        }).then(function(result) {
          if (result.error) {
            setProcessing(false);
            showCardError(result.error.message);
            return;
          }
          if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
            setProcessing(true, 'Finalising your booking…');
            ajaxPost('ccb_confirm_booking', {
              payment_intent_id: result.paymentIntent.id,
              booking_id:        bookingId,
            }, function(res3) {
              setProcessing(false);
              showSuccess(res3.success ? res3.data.booking_ref : bookingRef, em);
            });
          }
        });
      });
    });
  };

  /* ── Build POST payload ── */
  function buildPayload() {
    var loc  = $e('ccbMfLoc');
    var ms   = $e('ccbMfSvc');
    var dt   = $e('ccbDate');
    var tm   = $e('ccbTime');
    var apt  = $e('ccbApt');
    var buzz = $e('ccbBuzz');

    return {
      first_name:     $e('ccbMfFn')  ? $e('ccbMfFn').value  : '',
      last_name:      $e('ccbMfLn')  ? $e('ccbMfLn').value  : '',
      email:          $e('ccbMfEm')  ? $e('ccbMfEm').value  : '',
      phone:          $e('ccbMfPh')  ? $e('ccbMfPh').value  : '',
      location:       loc              ? loc.value            : '',
      address:        $e('ccbAddr')  ? $e('ccbAddr').value   : '',
      apt_no:         apt              ? apt.value            : '',
      buzzer_code:    buzz             ? buzz.value           : '',
      home_type:      S.htype,
      bedrooms:       S.beds,
      bathrooms:      S.baths,
      sqft_tier:      S.sqft,
      service_type:   ms               ? ms.value             : S.svc,
      home_condition: S.mess,
      pets:           S.pets === 'yes' ? 1 : 0,
      eco_products:   S.eco  === 'yes' ? 1 : 0,
      extras:         Object.keys(S.extras).join(','),
      clean_date:     dt               ? dt.value             : '',
      clean_time:     tm               ? tm.value             : '',
      payment_method: 'card',
      tip_pct:        S.tip,
      coupon_code:    S.couponCode,
      coupon_pct:     S.couponPct,
    };
  }

  /* ── AJAX POST helper ── */
  function ajaxPost(action, data, callback) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce',  NONCE);
    for (var k in data) {
      if (Object.prototype.hasOwnProperty.call(data, k)) fd.append(k, data[k]);
    }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX, true);
    xhr.onreadystatechange = function() {
      if (xhr.readyState === 4) {
        try { callback(JSON.parse(xhr.responseText)); }
        catch(e) { callback({ success: false, data: { message: 'Server returned an invalid response.' } }); }
      }
    };
    xhr.send(fd);
  }

  /* ── UI helpers ── */
  function setProcessing(on, label) {
    var main = $e('ccbMain'), proc = $e('ccbProc'), btn = $e('ccbBookBtn');
    if (on) {
      if (main) { main.style.opacity = '0.45'; main.style.pointerEvents = 'none'; }
      if (proc) proc.classList.add('ccb-on');
      if (btn)  btn.disabled = true;
      if (label) setT('ccbProcLbl', label);
    } else {
      if (main) { main.style.opacity = ''; main.style.pointerEvents = ''; }
      if (proc) proc.classList.remove('ccb-on');
      if (btn)  btn.disabled = false;
    }
  }

  function showCardError(msg) {
    var el = $e('ccbCardErrors');
    if (el) { el.textContent = '⚠️ ' + msg; el.scrollIntoView({ behavior:'smooth', block:'center' }); }
    else      alert(msg);
  }

  function showSuccess(ref, email) {
    var main = $e('ccbMain'); if (main) main.classList.remove('ccb-on');
    var done = $e('ccbDone');
    if (done) {
      done.classList.add('ccb-on');
      var msg = $e('ccbDoneMsg');
      if (msg) {
        msg.innerHTML = 'Your payment was processed and your clean is booked.'
          + (ref ? '<br><strong>Booking Ref: ' + ref + '</strong>' : '')
          + '<br>Our team will be in touch with confirmation details.';
      }
      var mailEl = $e('ccbDoneMail');
      if (mailEl && email) mailEl.textContent = '📧 Confirmation sent to ' + email;
    }
    scrollTop();
  }

  function scrollTop() {
    var root = document.getElementById('ccbRoot') || document.querySelector('.ccb-root');
    if (root) root.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  /* ══════════════════════════════════════════════════════════
     UI EVENT HANDLERS
  ══════════════════════════════════════════════════════════ */
  window.ccbSvcDesc = function () {
    var v = $e('ccbService') ? $e('ccbService').value : '';
    S.svc = v || 'routine';
    var d = $e('ccbSDesc');
    if (v && SDESC[v]) { d.innerHTML = SDESC[v]; d.style.display = 'block'; }
    else if (d)         { d.style.display = 'none'; }
  };

  window.ccbXtra = function (el) {
    var k = el.dataset.k, p = parseFloat(el.dataset.p);
    if (S.extras[k]) { delete S.extras[k]; el.classList.remove('ccb-sel'); }
    else             { S.extras[k] = p;    el.classList.add('ccb-sel'); }
    ccbCalc();
  };

  window.ccbTip = function (el, pct) {
    document.querySelectorAll('.ccb-tip').forEach(function(t){ t.classList.remove('ccb-sel'); });
    el.classList.add('ccb-sel'); S.tip = pct; ccbCalc();
  };

  window.ccbCouponTab = function (n) {
    var t1 = $e('ccbCtab1'), t2 = $e('ccbCtab2');
    if (t1) t1.classList[ n === 1 ? 'add' : 'remove' ]('ccb-on');
    if (t2) t2.classList[ n === 2 ? 'add' : 'remove' ]('ccb-on');
  };

  /* ── Dropdown → state wiring ── */
  var drops = {
    ccbHtype:'htype', ccbBeds:'beds',  ccbBaths:'baths',
    ccbSqft: 'sqft',  ccbPets:'pets',  ccbEco:'eco',    ccbMess:'mess',
  };
  Object.keys(drops).forEach(function(id) {
    var el = $e(id); if (!el) return;
    el.addEventListener('change', function() { S[drops[id]] = el.value; ccbCalc(); });
  });
  var ms = $e('ccbMfSvc');
  if (ms) ms.addEventListener('change', function() { S.svc = ms.value; ccbCalc(); });

  /* ── Inline validation clearing ── */
  ['ccbService','ccbFname','ccbLname','ccbLoc','ccbPhone'].forEach(function(id) {
    var el = $e(id); if (!el) return;
    el.addEventListener(el.tagName === 'SELECT' ? 'change' : 'input', function() {
      if (this.value.trim()) { markErr(this, false); showErr('ccbE1', false); }
    });
  });
  ['ccbMfFn','ccbMfLn','ccbMfEm','ccbMfPh','ccbAddr'].forEach(function(id) {
    var el = $e(id); if (!el) return;
    el.addEventListener('input', function() {
      if (this.value.trim()) { markErr(this, false); showErr('ccbMainErr', false); }
    });
  });

  /* ── Coupon field: auto-uppercase ── */
  var couponEl = $e('ccbCoupon');
  if (couponEl) {
    couponEl.addEventListener('input', function() {
      var pos = this.selectionStart;
      this.value = this.value.toUpperCase();
      this.setSelectionRange(pos, pos);
    });
    couponEl.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') { e.preventDefault(); window.ccbApplyCoupon(); }
    });
  }

  /* ── Date min = today ── */
  var dt = $e('ccbDate');
  if (dt) dt.min = new Date().toISOString().split('T')[0];

  /* ── Boot ── */
  ccbCalc();

})();
