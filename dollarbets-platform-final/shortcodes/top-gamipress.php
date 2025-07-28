<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Shortcode: [top_gamipress_achievement]
 * Automatically shows the first achievement by menu order
 */
add_shortcode('top_gamipress_achievement', function () {
    $args = [
        'post_type' => 'gamipress_achievement',
        'posts_per_page' => 1,
        'orderby' => 'menu_order',
        'order' => 'ASC'
    ];

    $query = new WP_Query($args);
    if ($query->have_posts()) {
        $query->the_post();
        $achievement_id = get_the_ID();
        wp_reset_postdata();
        return do_shortcode('[gamipress_achievement id="' . $achievement_id . '"]');
    }

    return '<p>No achievements found.</p>';
});

/**
 * Shortcode: [top_gamipress_rank]
 * Automatically shows the top rank
 */
add_shortcode('top_gamipress_rank', function () {
    $args = [
        'post_type' => 'gamipress_rank',
        'posts_per_page' => 1,
        'orderby' => 'menu_order',
        'order' => 'ASC'
    ];

    $query = new WP_Query($args);
    if ($query->have_posts()) {
        $query->the_post();
        $rank_id = get_the_ID();
        wp_reset_postdata();
        return do_shortcode('[gamipress_rank id="' . $rank_id . '"]');
    }

    return '<p>No ranks found.</p>';
});
