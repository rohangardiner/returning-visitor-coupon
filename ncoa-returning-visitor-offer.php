<?php

/**
 * Plugin Name: NCOA Returning Visitor Offer
 * Description: Offer returning visitors a special discount.
 * Version: 0.1.0
 * Author: Rohan
 * Requires at least: 6.5
 * Requires Plugins: woocommerce
 */

if (! defined('ABSPATH')) {
   exit;
}

function ncoa_get_coupon_expiry($coupon_code)
{
   $coupon = new WC_Coupon($coupon_code);
   // Check if the coupon exists
   if (! $coupon->get_id()) {
      return null;
   }
   $expiry_date = $coupon->get_date_expires();
   if ($expiry_date instanceof WC_DateTime) {
      return $expiry_date->date_i18n('d-m-Y');
   }
   return null;
}

function ncoa_is_coupon_valid($coupon_code)
{
   $coupon = new WC_Coupon($coupon_code);
   if (! $coupon->get_id()) {
      return false;
   }
   $expiry_date = $coupon->get_date_expires();
   if ($expiry_date instanceof WC_DateTime && $expiry_date->getTimestamp() < time()) {
      return false;
   }
   if ($coupon->get_usage_limit() && $coupon->get_usage_count() >= $coupon->get_usage_limit()) {
      return false;
   }
   return true;
}

function ncoa_create_first_visit_cookie()
{
   if (!isset($_COOKIE['ncoa_first_visit'])) {
      setcookie('ncoa_first_visit', time(), time() + (86400 * 7), "/"); // 7 days
      $_COOKIE['ncoa_first_visit'] = time();
      // Start a fresh 7 day window so old visits aren't counted
      unset($_COOKIE['ncoa_returning_visitor']);
   }
}

function ncoa_increment_cookie()
{
   if (isset($_COOKIE['ncoa_returning_visitor'])) {
      $count = intval($_COOKIE['ncoa_returning_visitor']) + 1;
   } else {
      $count = 1;
   }
   // Expire with the first visit cookie so the count only covers the 7 day window
   $expires = intval($_COOKIE['ncoa_first_visit']) + (86400 * 7);
   setcookie('ncoa_returning_visitor', $count, $expires, "/");
   return $count;
}

function ncoa_generate_coupon_code()
{
   // Generate random 6 character alphanumeric code, retrying if it already exists
   do {
      $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
   } while (wc_get_coupon_id_by_code($code));
   return $code;
}

function ncoa_create_coupon()
{
   // Generate a unique coupon code
   $coupon_code = ncoa_generate_coupon_code();

   $coupon = new WC_Coupon();
   $coupon->set_code($coupon_code);
   $coupon->set_description('Returning visitor coupon code');
   $coupon->set_amount(10);
   $coupon->set_discount_type('percent');
   $coupon->set_minimum_amount(201); // Minimum spend amount in dollars
   $coupon->set_maximum_amount(2000); // Maximum spend amount in dollars
   $coupon->set_date_expires(strtotime('+2 days')); // Expires in 2 days
   $coupon->set_individual_use(true); // Cannot be used in conjunction with other coupons
   $coupon->set_usage_limit(1); // Limit to one use
   $coupon->save();

   return $coupon_code;
}

// Cookies must be set before any page output, so track visits on template_redirect
add_action('template_redirect', 'ncoa_returning_visitors');
function ncoa_returning_visitors()
{
   if (! class_exists('WooCommerce') || ! is_product()) {
      return;
   }

   ncoa_create_first_visit_cookie();
   $count = ncoa_increment_cookie();

   // Offer the coupon once the user has viewed a product page more than 3 times in 7 days
   if ($count <= 3 || isset($_COOKIE['ncoa_coupon_dismissed'])) {
      return;
   }

   if (isset($_COOKIE['ncoa_coupon_offered'])) {
      $code = sanitize_text_field(base64_decode($_COOKIE['ncoa_coupon_offered']));
   } else {
      $code = ncoa_create_coupon();
      setcookie('ncoa_coupon_offered', base64_encode($code), time() + (86400 * 7), "/"); // 7 days
   }

   // Don't show a coupon that has expired or already been used
   if (ncoa_is_coupon_valid($code)) {
      $GLOBALS['ncoa_coupon_code'] = $code;
   }
}

add_action('woocommerce_before_single_product', 'ncoa_display_coupon');
function ncoa_display_coupon()
{
   if (empty($GLOBALS['ncoa_coupon_code'])) {
      return;
   }

   $code = $GLOBALS['ncoa_coupon_code'];
   $expiry_date = ncoa_get_coupon_expiry($code);

   echo "<div id='ncoa-coupon-message' style='position: fixed; bottom: 10px; right: 10px; color: #206143; border-radius: 4px; background: #cef0dfff; max-width: 300px; padding: 12px 20px; border-bottom: 2px solid #206143; z-index: 1000;'>
         <span id='ncoa-coupon-close' style='position: absolute; top: 5px; right: 10px; font-size: 1.75em; cursor: pointer; line-height: 1; color: #333;'>&times;</span>
         <strong>You found a secret deal!</strong> <br>As a lucky visitor, use code <strong>" . esc_html($code) . "</strong> for 10% off your next purchase!" .
      ($expiry_date ? " <br><strong>Expires: " . esc_html($expiry_date) . ".</strong>" : "") . "
         </div>";

   echo "
     <script>
         document.addEventListener('DOMContentLoaded', function() {
             const closeButton = document.getElementById('ncoa-coupon-close');
             if (closeButton) {
                 closeButton.addEventListener('click', function() {
                     const messageDiv = document.getElementById('ncoa-coupon-message');
                     if (messageDiv) {
                         messageDiv.style.display = 'none';
                         const now = new Date();
                         const time = now.getTime();
                         const expireTime = time + 4 * 60 * 60 * 1000; // 4 hours in milliseconds
                         now.setTime(expireTime);
                         document.cookie = 'ncoa_coupon_dismissed=1; expires=' + now.toUTCString() + '; path=/';
                     }
                 });
             }
         });
     </script>
     ";
}
