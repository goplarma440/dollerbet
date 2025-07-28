<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * Register "Prediction" custom post type
 */
add_action('init', function () {
    register_post_type('prediction', [
        'labels' => [
            'name' => 'Predictions',
            'singular_name' => 'Prediction',
            'add_new' => 'Add New Prediction',
            'add_new_item' => 'Add New Prediction',
            'edit_item' => 'Edit Prediction',
            'new_item' => 'New Prediction',
            'view_item' => 'View Prediction',
            'search_items' => 'Search Predictions',
            'not_found' => 'No Predictions Found',
        ],
        'public' => true,
        'show_in_rest' => true,
        'has_archive' => true,
        'menu_icon' => 'dashicons-chart-bar',
        'supports' => ['title', 'editor', 'custom-fields'],
        'rewrite' => ['slug' => 'predictions'],
        'taxonomies' => ['prediction_category'],
    ]);
});

/**
 * Register custom taxonomy "Prediction Category"
 */
add_action('init', function () {
    register_taxonomy('prediction_category', 'prediction', [
        'labels' => [
            'name' => 'Categories',
            'singular_name' => 'Category',
            'search_items' => 'Search Categories',
            'all_items' => 'All Categories',
            'edit_item' => 'Edit Category',
            'update_item' => 'Update Category',
            'add_new_item' => 'Add New Category',
            'new_item_name' => 'New Category Name',
            'menu_name' => 'Categories',
        ],
        'hierarchical' => true,
        'show_ui' => true,
        'show_admin_column' => true,
        'show_in_rest' => true,
        'rewrite' => ['slug' => 'prediction-category'],
    ]);
});

/**
 * Add meta box for "Ending Date"
 */
add_action('add_meta_boxes', function () {
    add_meta_box(
        'prediction_ending_date',
        'Ending Date',
        'render_prediction_ending_date_meta_box',
        'prediction',
        'side',
        'default'
    );
});

function render_prediction_ending_date_meta_box($post) {
    $value = get_post_meta($post->ID, '_prediction_ending_date', true);
    echo '<label for="prediction_ending_date">Enter end date:</label>';
    echo '<input type="date" id="prediction_ending_date" name="prediction_ending_date" value="' . esc_attr($value) . '" class="widefat">';
}

/**
 * Save "Ending Date" meta field
 */
add_action('save_post_prediction', function ($post_id) {
    if (isset($_POST['prediction_ending_date'])) {
        update_post_meta(
            $post_id,
            '_prediction_ending_date',
            sanitize_text_field($_POST['prediction_ending_date'])
        );
    }
});

/**
 * Register meta fields: votes and closing date
 */
add_action('init', function () {
    $meta_args = [
        'type' => 'integer',
        'single' => true,
        'show_in_rest' => true,
        'auth_callback' => function () {
            return true;
        },
        'default' => 0,
    ];

    register_meta('post', '_votes_yes', $meta_args);
    register_meta('post', '_votes_no', $meta_args);

    register_meta('post', '_prediction_ending_date', [
        'type' => 'string',
        'single' => true,
        'show_in_rest' => true,
        'auth_callback' => function () {
            return true;
        },
    ]);
});

/**
 * Expose custom REST fields
 */
add_action('rest_api_init', function () {
    // Prediction Category
    register_rest_field('prediction', 'category', [
        'get_callback' => function ($post) {
            $terms = get_the_terms($post['id'], 'prediction_category');
            return $terms && !is_wp_error($terms) ? $terms[0]->name : 'Uncategorized';
        },
        'schema' => ['type' => 'string'],
    ]);

    // Group meta fields together
    register_rest_field('prediction', 'meta', [
        'get_callback' => function ($post) {
            return [
                'closing_date' => get_post_meta($post['id'], '_prediction_ending_date', true),
                'votes_yes' => (int) get_post_meta($post['id'], '_votes_yes', true),
                'votes_no' => (int) get_post_meta($post['id'], '_votes_no', true),
            ];
        },
        'schema' => null,
    ]);
});


/**
 * Add Ending Date to Quick Edit
 */
add_action(
    'quick_edit_custom_box',
    'dollarbets_add_ending_date_quick_edit',
    10,
    2
);
function dollarbets_add_ending_date_quick_edit($column_name, $post_type) {
    if ($post_type != 'prediction' || $column_name != 'date') return;

    static $printNonce = TRUE;
    if ($printNonce) {
        wp_nonce_field('quick_edit_action', 'quick_edit_nonce');
        $printNonce = FALSE;
    }

    echo '<fieldset class="inline-edit-col-right">
        <div class="inline-edit-col">
            <label>
                <span class="title">Ending Date</span>
                <input type="date" name="_prediction_ending_date" value="">
            </label>
        </div>
    </fieldset>';
}

/**
 * Save Ending Date from Quick Edit
 */
add_action('save_post', 'dollarbets_save_ending_date_quick_edit', 10, 2);
function dollarbets_save_ending_date_quick_edit($post_id, $post) {
    if ($post->post_type != 'prediction') return;

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    if (!isset($_POST['quick_edit_nonce']) || !wp_verify_nonce($_POST['quick_edit_nonce'], 'quick_edit_action')) return;

    if (isset($_REQUEST['_prediction_ending_date'])) {
        update_post_meta(
            $post_id,
            '_prediction_ending_date',
            sanitize_text_field($_REQUEST['_prediction_ending_date'])
        );
    }
}


