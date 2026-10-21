# Elite Transfer Booking (ETB)

![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)
![License](https://img.shields.io/badge/license-Proprietary-red.svg)

A premium, high-end WordPress booking engine designed for private chauffeur and luxury transfer services. Inspired by industry leaders like Blacklane, this plugin provides a seamless, Apple/Google-standard UI/UX from the first quote to the final secure payment.

## ✨ Features

### 🚘 Booking Experience
*   **Minimalist Fare Calculator Widget:** A beautiful, glassmorphism-style widget for instant price estimation (Point A to Point B) or hourly charters.
*   **Multi-Trip Cart:** Allow clients to schedule multiple legs (one-way, hourly) in a single session.
*   **Urgent Booking Logic:** Automatic detection of short-notice pickups (< 24h) requiring manual dispatch confirmation.
*   **Dynamic Pricing Engine:** Supports base fares, hourly rates, 10-hour packages, extra hour rates, and child seats (with free quotas and paid extras).
*   **Smart Autocomplete:** Integration with Google Places API or Mapbox for precise geolocation.

### 💳 Checkout & Payment
*   **Frictionless Checkout:** Multi-step form with auto-fill phone formatting (intl-tel-input).
*   **Stripe Integration:** Built-in Stripe Elements with 3D Secure support.
*   **Pre-Authorization Model (Blacklane Style):** Supports manual capture (hold funds, capture later) or immediate capture.
*   **Apple Pay & Google Pay:** Native wallet support via Stripe PaymentRequest.
*   **Custom Quotes:** Out-of-zone routes automatically convert to "Custom Quote" requests sent to dispatch.
*   **Secure Payment Links:** Cryptographically signed (HMAC) payment URLs sent via email/WhatsApp.

### 🚀 Dispatch & Automation
*   **LimoExpress Integration:** Automatic real-time sync of bookings, passenger details, flight numbers, and pricing to your LimoExpress dashboard.
*   **Modular Dispatcher Manager:** Architecture ready to support other dispatch APIs (Kymark, etc.) in the future.
*   **Automated Emails:** Beautiful, branded HTML emails for confirmations, pending payments, custom quotes, and abandoned checkout follow-ups (+1h).
*   **Status Sync:** When a payment is captured, the booking is automatically marked as "Paid" and "Confirmed" in LimoExpress.

### 🎨 UI/UX & Design
*   **Dark / Light Mode:** Infallible theme switcher with system preference detection.
*   **Micro-interactions:** Animated Noto emojis for driver tips, smooth transitions, and hover effects.
*   **Fully Responsive:** Carefully crafted CSS Grid and Flexbox layouts for mobile and desktop.

### 🔒 Security & Compliance
*   **Anti-Fraud Engine:** Server-side price recalculation (ignores client-side price tampering).
*   **HMAC Signatures:** Prevents URL manipulation on payment links.
*   **Rate Limiting:** Protects AJAX endpoints from brute-force and spam.
*   **Honeypot & Timestamp Tokens:** Blocks bots on checkout forms.
*   **PCI Compliance:** Card data is never handled by the server; all sensitive data goes directly to Stripe.

---

## 📋 Requirements

*   **WordPress:** 5.8 or higher
*   **PHP:** 7.4 or higher (PHP 8.1+ recommended)
*   **Extensions:** `curl`, `json`, `mbstring`
*   **External Services:**
    *   Stripe Account (for payments)
    *   LimoExpress API Token (for dispatch)
    *   Google Maps API Key or Mapbox Token (for address autocomplete)

---

## 🛠️ Installation

1. Download the `elite-transfer-booking` folder.
2. Upload it to the `/wp-content/plugins/` directory of your WordPress installation.
3. Activate the plugin through the 'Plugins' menu in WordPress.
4. Upon activation, the plugin will automatically create the necessary database tables and Custom Post Types (CPTs).

---

## ⚙️ Configuration

Once activated, navigate to **Tour Booking > Réglages** in your WordPress admin panel.

1.  **General Settings:** Set your currency, minimum booking delay (for urgent logic), and WhatsApp number.
2.  **Identity & Brand:** Upload your logo, set your legal company name, and physical address (used on emails and invoices).
3.  **Geolocation:** Choose between Google Places or Mapbox and enter your API keys.
4.  **Stripe Payment:** Enable Stripe, enter your Public/Secret keys (`pk_test_...`, `sk_test_...`), and select your capture method (Manual / Immediate).
5.  **Dispatch:** Select LimoExpress and enter your API Bearer Token.
6.  **Vehicles:** Go to **Tour Booking > Véhicules** to create your fleet, set max pax/baggage, and map them to your LimoExpress vehicle classes.

---

## 📝 Shortcodes

Place these shortcodes on your WordPress pages to deploy the booking flow:

### 1. `[etb_transfer]`
The main booking widget (Fare Calculator). Place this on your homepage or dedicated booking page.

### 2. `[etb_checkout]`
The detailed checkout form. This is where the quote from the widget is sent for passenger details and payment. (Make sure to set this page URL in the plugin settings).

### 3. `[etb_payment]`
The standalone payment page for custom quotes or pending payments. (Set this page URL in the plugin settings).

---

## 🏗️ Architecture Overview (For Developers)

The plugin is built using Object-Oriented PHP and follows the WordPress Coding Standards.

*   `elite-transfer-booking.php`: Main plugin entry point and hook registration.
*   `includes/class-etb-ajax.php`: Central AJAX router for frontend requests (pricing, checkout, Stripe intents).
*   `includes/class-etb-security.php`: Helper class for rate limiting, HMAC signatures, and anti-spam.
*   `includes/class-etb-stripe.php`: Wrapper for the Stripe API (PaymentIntents, Customers).
*   `includes/class-etb-limoexpress.php`: Wrapper for the LimoExpress API (Bookings, Clients, Vehicle Classes).
*   `includes/class-etb-pricing-engine.php`: Core logic for calculating vehicle and extras pricing.
*   `includes/class-etb-settings.php`: Admin UI and options sanitization.
*   `public/js/booking-widget.js`: Vanilla JS frontend engine handling all UI logic, Stripe Elements mounting, and AJAX calls.

---

## 📝 Changelog

### [1.0.0] - 2026-10-21
*   Initial public release.
*   Complete Stripe & LimoExpress integration.
*   Added Multi-Trip cart system.
*   Added custom quote workflow.
*   Added Dark/Light mode UI.
*   Implemented strict server-side price validation and HMAC security.