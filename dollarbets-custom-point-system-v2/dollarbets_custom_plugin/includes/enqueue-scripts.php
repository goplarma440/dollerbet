<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

add_action('wp_enqueue_scripts', function () {
    global $post;

    if (!is_singular() || !isset($post) || !has_shortcode($post->post_content, 'dollarbets_app')) {
        return;
    }

    // ✅ Tailwind + Fonts
    wp_enqueue_script(
        'dollarbets-tailwind',
        'https://cdn.tailwindcss.com',
        [],
        null,
        false
    );

    wp_enqueue_style(
        'dollarbets-inter-font',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap',
        [],
        null
    );

    // ✅ Custom Styles
    wp_enqueue_style(
        'dollarbets-style',
        DOLLARBETS_URL . 'assets/css/style.css',
        [],
        filemtime(DOLLARBETS_PATH . 'assets/css/style.css')
    );

    // ✅ Frontend App
    wp_enqueue_script(
        'dollarbets-frontend',
        DOLLARBETS_URL . 'assets/js/frontend-app.js',
        ['wp-element'], // includes React & ReactDOM
        filemtime(DOLLARBETS_PATH . 'assets/js/frontend-app.js'),
        true
    );

    // ✅ News Auto-Fetch
    wp_enqueue_script(
        'dollarbets-automation',
        DOLLARBETS_URL . 'assets/js/content-automation.js',
        [],
        filemtime(DOLLARBETS_PATH . 'assets/js/content-automation.js'),
        true
    );

    // ✅ Localize REST settings, keys, and login URL
    $config = [
        'restUrl'     => esc_url_raw(rest_url()),
        'nonce'       => wp_create_nonce('wp_rest'),
        'newsApiKey'  => get_option('dollarbets_news_api_key', ''),
        'loginUrl'    => home_url('/login/'), // <-- Make sure this matches your UM login page URL
        'isLoggedIn'  => is_user_logged_in(),
    ];

    wp_localize_script('dollarbets-frontend', 'dollarbetsConfig', $config);
    wp_localize_script('dollarbets-automation', 'dollarbetsConfig', $config);
});
