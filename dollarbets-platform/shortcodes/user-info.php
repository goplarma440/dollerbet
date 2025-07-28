<?php
// Exit if accessed directly
if (!defined("ABSPATH")) exit;

add_shortcode("dollarbets_user_greeting", function() {
    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        $first_name = $current_user->user_firstname;
        if (empty($first_name)) {
            $first_name = $current_user->display_name;
        }
        return "Hello, " . esc_html($first_name) . "!";
    } else {
        // Show Ultimate Member login form
        return do_shortcode('[ultimatemember form_id="YOUR_LOGIN_FORM_ID"]'); // Replace YOUR_LOGIN_FORM_ID with the actual ID of your UM login form
    }
});


