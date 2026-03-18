# CleanCo Booking — WordPress Plugin

A complete, production-ready WordPress booking plugin for a cleaning service business.

## Features

- ✅ Multi-step booking form (4 pre-steps + full booking form)
- ✅ Live pricing calculator (mirrors server-side validation)
- ✅ Stripe payment integration (Payment Intents API + Card Element)
- ✅ Automatic database table creation on activation
- ✅ HTML email notifications to customer and admin
- ✅ Admin dashboard (bookings list, detail view, status management)
- ✅ Settings page (Stripe keys, email config)
- ✅ Stripe webhook support (payment_intent.succeeded / failed)
- ✅ Responsive design (mobile-friendly)
- ✅ No Composer required — uses WordPress HTTP API

---

## Installation

1. **Upload** the `cleanco-booking` folder to `/wp-content/plugins/`
2. **Activate** the plugin in WordPress Admin → Plugins
   - The `ccb_bookings` database table is created automatically on activation
3. **Configure** the plugin: Admin → CleanCo → Settings

---

## Setup Checklist

### 1. Stripe Configuration

1. Create a free account at [stripe.com](https://stripe.com)
2. Go to **Stripe Dashboard → Developers → API Keys**
3. Copy your **Publishable Key** and **Secret Key**
4. Paste them into **CleanCo → Settings → Stripe Configuration**
5. Use **Test Mode** during development, switch to **Live Mode** when ready

### 2. Stripe Webhook (required for payment confirmation fallback)

1. Go to **Stripe Dashboard → Developers → Webhooks**
2. Click **Add endpoint**
3. Enter your webhook URL:
   ```
   https://yoursite.com/wp-json/cleanco-booking/v1/webhook
   ```
4. Select events to listen to:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
5. Copy the **Signing Secret** (starts with `whsec_`)
6. Paste it into **CleanCo → Settings → Stripe Configuration → Webhook Secret**

### 3. Email Configuration (Gmail)

WordPress uses `wp_mail()` for sending emails. For reliable Gmail delivery:

1. Install **[WP Mail SMTP](https://wordpress.org/plugins/wp-mail-smtp/)** (free)
2. In WP Mail SMTP settings, choose **Gmail** as the mailer
3. Follow the OAuth setup or use an **App Password**:
   - Go to your Google Account → Security → 2-Step Verification → App Passwords
   - Create an App Password for "Mail" / "WordPress"
   - Enter it in WP Mail SMTP
4. Set your **Admin Email** and **From Email** in CleanCo → Settings → Email Configuration

### 4. Add the Shortcode

1. Create a new WordPress page (e.g. "Book a Cleaning")
2. Add the shortcode to the page content:
   ```
   [cleanco_booking]
   ```
3. Publish the page

---

## Database Table

The plugin creates a table `wp_ccb_bookings` (prefix may vary) with the following key columns:

| Column | Description |
|--------|-------------|
| `id` | Auto-increment primary key |
| `booking_ref` | Unique booking reference (e.g. `CLEAN-ABC12345`) |
| `status` | `pending`, `paid`, `confirmed`, `cancelled`, `failed` |
| `stripe_pi_id` | Stripe Payment Intent ID |
| `first_name`, `last_name` | Customer name |
| `email`, `phone` | Customer contact |
| `location`, `address` | Service location |
| `home_type`, `bedrooms`, `bathrooms`, `sqft_tier` | Property details |
| `service_type`, `frequency`, `extras` | Service selection |
| `grand_total`, `tax_amt`, `tip_amt` | Pricing breakdown |
| `clean_date`, `clean_time` | Scheduled appointment |
| `created_at`, `updated_at` | Timestamps |

---

## Admin Dashboard

Navigate to **Admin → CleanCo → All Bookings** to:
- View all bookings with status, customer, total
- Filter by status (Pending, Paid, Confirmed, etc.)
- Search by name, email, or booking reference
- View full booking details
- Manually update booking status

---

## Payment Flow

```
Customer fills form
       ↓
[AJAX] ccb_create_payment_intent
  → PHP creates Stripe PaymentIntent
  → Booking saved to DB as "pending"
  → Returns client_secret to frontend
       ↓
Stripe.js confirmCardPayment()
  → Customer enters card in Stripe Card Element
  → Stripe processes payment securely
       ↓
[AJAX] ccb_confirm_booking
  → PHP retrieves PaymentIntent from Stripe API
  → Verifies status === "succeeded"
  → Updates DB record to "paid"
  → Sends email to customer
  → Sends email to admin
       ↓
Success screen shown
```

**Webhook** (backup): If the AJAX confirm call fails (e.g. browser closed), the Stripe webhook at `/wp-json/cleanco-booking/v1/webhook` catches `payment_intent.succeeded` and completes the booking automatically.

---

## Pricing Model

| Item | Price |
|------|-------|
| Base (1 bed, 1 bath, ≤650 sq ft) | $85 |
| Each additional bedroom | +$17 |
| Each additional bathroom | +$20 |
| 651–850 sq ft | +$12 |
| 851–1,100 sq ft | +$24 |
| 1,101–1,400 sq ft | +$36 |
| 1,401–1,800 sq ft | +$54 |
| 1,800+ sq ft | Custom quote |
| Deep Cleaning | ×1.55 |
| Move In/Out | ×1.85 |
| Post-Renovation | ×2.10 |
| Airbnb | ×1.20 |
| Pets | +$20 |
| Eco products | +$15 |
| Extremely messy | +$23.99 |
| Tax (HST) | 13% |

**Frequency Discounts:**
- Monthly: 5% | 3 Weeks: 7% | Bi-Weekly: 10% | Weekly: 15%

---

## File Structure

```
cleanco-booking/
├── cleanco-booking.php          # Main plugin file
├── README.md
├── includes/
│   ├── class-database.php       # DB table creation + CRUD
│   ├── class-stripe.php         # Stripe API wrapper (no SDK)
│   ├── class-email.php          # HTML email notifications
│   ├── class-shortcode.php      # [cleanco_booking] shortcode
│   └── class-ajax.php           # AJAX + webhook handlers
├── admin/
│   └── class-admin.php          # Admin menus, settings, bookings list
└── assets/
    ├── css/
    │   ├── cleanco-booking.css  # Frontend styles
    │   └── admin.css            # Admin styles
    └── js/
        └── cleanco-booking.js   # Frontend JS (pricing + Stripe)
```

---

## Extending / Customising

- **Add locations**: Edit the `<option>` list in `class-shortcode.php`
- **Change pricing**: Update constants in `class-ajax.php → compute_price()` and `cleanco-booking.js`
- **Add coupon codes**: Extend `ccbApplyCoupon()` in JS and add a new AJAX action in PHP
- **Change tax rate**: Update `0.13` in both `class-ajax.php` and the `wp_localize_script` call in `class-shortcode.php`
- **Email templates**: Edit `class-email.php → customer_html()` and `admin_html()`

---

## Security

- All AJAX actions protected with `wp_nonce_field` / `check_ajax_referer`
- All user inputs sanitized with WordPress sanitization functions
- Stripe secret key never exposed to the frontend
- Webhook signature verified with HMAC-SHA256 before processing
- Pricing recalculated server-side on every booking (frontend pricing is display-only)
