<?php

/**
 * Plugin Name: NCOA Returning Visitor Offer
 * Description: Offer returning visitors a special discount.
 * Version: 0.1.0
 * Author: Rohan
 */

function ncoa_get_coupon_expiry($coupon_code) {
   $coupon = new WC_Coupon($coupon_code);
   // Check if the coupon exists
   if (! $coupon->get_id()) {
      return null;
   }
   $expiry_date = $coupon->get_date_expires();
   if ($expiry_date instanceof WC_DateTime) {
      return $expiry_date->format('d-m-Y');
   }
   return null;
}

function ncoa_create_first_visit_cookie() {
   if (!isset($_COOKIE['ncoa_first_visit'])) {
      setcookie('ncoa_first_visit', time(), time() + (86400 * 7), "/"); // 7 days
   }
}

function ncoa_increment_cookie() {
   if (isset($_COOKIE['ncoa_returning_visitor'])) {
      $count = intval($_COOKIE['ncoa_returning_visitor']) + 1;
   } else {
      $count = 1;
   }
   setcookie('ncoa_returning_visitor', $count, time() + (86400 * 7), "/"); // 7 days
}

function ncoa_generate_coupon_code() {
   // Generate random 6 character alphanumeric code
   $code = substr(md5(uniqid(mt_rand(), true)), 0, 6);
   return strtoupper($code);
}

function ncoa_create_coupon() {
   // Generate a unique coupon code
   $coupon_code = ncoa_generate_coupon_code();
   // Check WooCommerce is active and create a coupon for the user
   if (class_exists('WooCommerce') && function_exists('WC')) {
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
   }

   return $coupon_code;
}

function ncoa_check_and_offer_coupon() {
   // Check if the user has visited a product page more than 3 times in the last 7 days
   if (
      isset($_COOKIE['ncoa_returning_visitor']) && intval($_COOKIE['ncoa_returning_visitor']) >= 3 &&
      isset($_COOKIE['ncoa_first_visit']) && (time() - intval($_COOKIE['ncoa_first_visit']) <= 86400 * 7) &&
      !isset($_COOKIE['ncoa_coupon_dismissed'])
   ) {
      if (!isset($_COOKIE['ncoa_coupon_offered'])) {
         $code = ncoa_create_coupon();
         $encoded_code = base64_encode($code);
         setcookie('ncoa_coupon_offered', $encoded_code, time() + (86400 * 7), "/"); // 7 days
      } else {
         $encoded_code = $_COOKIE['ncoa_coupon_offered'];
         $code = base64_decode($encoded_code);
      }
      $expiry_date = ncoa_get_coupon_expiry($code);

      echo "<div id='ncoa-coupon-message' style='position: fixed; bottom: 10px; right: 10px; color: #206143; border-radius: 4px; background: #cef0dfff; max-width: 300px; padding: 12px 20px; border-bottom: 2px solid #206143; z-index: 1000;'>
            <span id='ncoa-coupon-close' style='position: absolute; top: 5px; right: 10px; font-size: 1.75em; cursor: pointer; line-height: 1; color: #333;'>&times;</span>
            <strong>You found a secret deal!</strong> <br>As a lucky visitor, use code <strong>" . esc_html($code) . "</strong> for 10% off your next purchase! <br><strong>Expires: " . esc_html($expiry_date) . ".</strong>
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
}

add_action('woocommerce_before_single_product', 'ncoa_returning_visitors');
function ncoa_returning_visitors() {
   ncoa_create_first_visit_cookie();
   ncoa_increment_cookie();
   ncoa_check_and_offer_coupon();
}
