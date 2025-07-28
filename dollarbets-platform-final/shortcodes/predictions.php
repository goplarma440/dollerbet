<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

// Register the [dollarbets_app] shortcode
add_shortcode('dollarbets_app', function () {
    ob_start();

    // Load the output template
    $template_path = DOLLARBETS_PATH . 'templates/shortcode-predictions.php';

    if (file_exists($template_path)) {
        include $template_path;
    } else {
        echo '<p>Prediction interface not available.</p>';
    }

    return ob_get_clean();
});
