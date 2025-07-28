<?php
if (!defined('ABSPATH')) exit;

/**
 * Give 1000 BetCoins on user registration (default GamiPress point type)
 */
add_action('user_register', function($user_id) {
    if (!db_gamipress_available()) return;
    
    $current = gamipress_get_user_points($user_id, 'betcoins');
    if ($current <= 0) {
        update_user_meta($user_id, '_gamipress_betcoins_points', 1000);
    }
}, 100);

/**
 * Manual point subtraction (for GamiPress Free)
 */
function db_subtract_points_manual($user_id, $amount, $point_type = 'betcoins', $reason = '') {
    if (!db_gamipress_available()) return false;

    // Use GamiPress native function if available, otherwise fallback to manual
    if (function_exists('gamipress_deduct_points')) {
        gamipress_deduct_points($user_id, $amount, $point_type, ['reason' => $reason]);
    } else {
        $current_balance = gamipress_get_user_points($user_id, $point_type);
        $new_balance = max(0, $current_balance - $amount);
        update_user_meta($user_id, '_gamipress_' . $point_type . '_points', $new_balance);

        db_log_transaction($user_id, 'bet', $amount, $reason ?: 'Points deducted for bet', ['status' => 'completed']);
    }
    return gamipress_get_user_points($user_id, $point_type);
}

function db_gamipress_available() {
    return function_exists('gamipress_get_user_points');
}

add_action('rest_api_init', function () {
    register_rest_route('dollarbets/v1', '/predictions', [
        'methods' => 'GET',
        'callback' => 'db_get_predictions',
        'permission_callback' => '__return_true'
    ]);

    register_rest_route('dollarbets/v1', '/predictions', [
        'methods' => 'POST',
        'callback' => 'db_create_prediction',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ]);

    register_rest_route('dollarbets/v1', '/user-balance', [
        'methods' => 'GET',
        'callback' => 'db_get_user_balance',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ]);

    register_rest_route('dollarbets/v1', '/place-bet', [
        'methods' => 'POST',
        'callback' => 'db_place_bet',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ]);
});

function db_get_user_balance() {
    if (!is_user_logged_in()) {
        return new WP_Error('not_logged_in', 'User must be logged in.', ['status' => 401]);
    }
    
    $user_id = get_current_user_id();
    $balance = 0;
    
    if (function_exists('gamipress_get_user_points')) {
        $balance = gamipress_get_user_points($user_id, 'betcoins');
        
        // If balance is 0 or null, check if user is new and give initial points
        if ($balance <= 0) {
            // Check if user has ever had points awarded
            $points_meta = get_user_meta($user_id, '_gamipress_betcoins_points', true);
            if (empty($points_meta)) {
                // New user - give initial 1000 BetCoins
                update_user_meta($user_id, '_gamipress_betcoins_points', 1000);
                $balance = 1000;
            }
        }
    } else {
        // Fallback if GamiPress is not available
        $balance = get_user_meta($user_id, '_gamipress_betcoins_points', true);
        if (empty($balance)) {
            $balance = 1000; // Default for new users
            update_user_meta($user_id, '_gamipress_betcoins_points', $balance);
        }
    }
    
    return ['success' => true, 'balance' => intval($balance)];
}

function db_place_bet(WP_REST_Request $req) {
    if (!db_gamipress_available()) {
        return new WP_Error('gamipress_missing', 'GamiPress points API not available.', ['status' => 500]);
    }

    $body = $req->get_json_params();
    $pid = absint($body['prediction_id'] ?? 0);
    $choice = sanitize_text_field($body['choice'] ?? '');
    $amt = absint($body['amount'] ?? 0);

    if (!$pid || !in_array($choice, ['yes', 'no'], true) || $amt <= 0) {
        return new WP_Error('invalid_data', 'prediction_id, choice or amount invalid.', ['status' => 400]);
    }

    $user = get_current_user_id();
    $bal = gamipress_get_user_points($user, 'betcoins');

    if ($amt > $bal) {
        return new WP_Error('insufficient_funds', 'You don\'t have enough BetCoins. You have ' . $bal . ' BetCoins available.', ['status' => 400]);
    }

    $new_balance = db_subtract_points_manual($user, $amt, 'betcoins', 'DollarBets wager');

    // REMOVED: No more meta field updates - we calculate from bet history
    // $meta = ($choice === 'yes' ? '_votes_yes' : '_votes_no');
    // $cur = intval(get_post_meta($pid, $meta, true));
    // update_post_meta($pid, $meta, $cur + $amt);

    $bet_entry = [
        'prediction_id' => $pid,
        'choice' => $choice,
        'amount' => $amt,
        'timestamp' => current_time('mysql'),
        'remaining' => $new_balance,
    ];

    $history = get_user_meta($user, 'db_bet_history', true);
    if (!is_array($history)) $history = [];
    $history[] = $bet_entry;

    // Save only user's history
    update_user_meta($user, 'db_bet_history', $history);

    return [
        'success' => true,
        'remaining_balance' => intval($new_balance),
    ];
}

function db_create_prediction(WP_REST_Request $req) {
    $body = $req->get_json_params();
    if (empty($body['title']) || empty($body['content'])) {
        return new WP_Error('missing_data', 'Missing title or content.', ['status' => 400]);
    }

    $post_id = wp_insert_post([
        'post_type' => 'prediction',
        'post_status' => 'publish',
        'post_title' => sanitize_text_field($body['title']),
        'post_content' => sanitize_textarea_field($body['content']),
    ]);

    if (is_wp_error($post_id)) return $post_id;

    if (!empty($body['category'])) {
        wp_set_object_terms($post_id, sanitize_text_field($body['category']), 'prediction_category');
    }
    if (!empty($body['closing_date'])) {
        update_post_meta($post_id, '_prediction_ending_date', sanitize_text_field($body['closing_date']));
    }

    return ['success' => true, 'post_id' => $post_id];
}

function db_get_predictions(WP_REST_Request $req) {
    $query = new WP_Query([
        'post_type' => 'prediction',
        'post_status' => 'publish',
        'posts_per_page' => 30,
        'orderby' => 'date',
        'order' => 'DESC'
    ]);

    $data = [];
    foreach ($query->posts as $post) {
        // Calculate REAL votes from actual user bets
        $real_votes_yes = 0;
        $real_votes_no = 0;
        
        // Debug: Track bet details
        $bet_details = [];
        
        // Get all users who have betting history
        $users = get_users(['meta_key' => 'db_bet_history']);
        
        foreach ($users as $user) {
            $history = get_user_meta($user->ID, 'db_bet_history', true);
            if (!is_array($history)) continue;
            
            foreach ($history as $bet) {
                if ($bet['prediction_id'] == $post->ID) {
                    $bet_details[] = [
                        'user' => $user->ID,
                        'choice' => $bet['choice'],
                        'amount' => $bet['amount']
                    ];
                    
                    if ($bet['choice'] == 'yes') {
                        $real_votes_yes += $bet['amount'];
                    } else {
                        $real_votes_no += $bet['amount'];
                    }
                }
            }
        }
        
        // Get category information
        $categories = wp_get_object_terms($post->ID, 'prediction_category');
        $category_name = !empty($categories) && !is_wp_error($categories) ? $categories[0]->name : 'General';
        
        // Get closing date
        $closing_date = get_post_meta($post->ID, '_prediction_ending_date', true);
        
        // Debug: Also get the old meta field values for comparison
        $meta_votes_yes = intval(get_post_meta($post->ID, '_votes_yes', true));
        $meta_votes_no = intval(get_post_meta($post->ID, '_votes_no', true));
        
        $data[] = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'category' => $category_name,
            'closing_date' => $closing_date ?: '',
            'votes_yes' => $real_votes_yes,  // Real votes from actual bets
            'votes_no'  => $real_votes_no,   // Real votes from actual bets
            
            // Debug info (remove this later)
            'debug' => [
                'real_yes' => $real_votes_yes,
                'real_no' => $real_votes_no,
                'meta_yes' => $meta_votes_yes,
                'meta_no' => $meta_votes_no,
                'bet_count' => count($bet_details),
                'bets' => $bet_details
            ]
        ];
    }

    return rest_ensure_response($data);
}
function db_award_points_manual($user_id, $amount, $point_type = 'betcoins', $reason = '') {
    if (!db_gamipress_available()) return false;

    // Use GamiPress native function if available, otherwise fallback to manual
    if (function_exists('gamipress_add_points')) {
        gamipress_add_points($user_id, $amount, $point_type, ['reason' => $reason]);
    } else {
        $current_balance = gamipress_get_user_points($user_id, $point_type);
        $new_balance = $current_balance + $amount;
        update_user_meta($user_id, '_gamipress_' . $point_type . '_points', $new_balance);

        db_log_transaction($user_id, ($reason === 'DollarBets winnings' ? 'win' : 'award'), $amount, $reason ?: 'Points awarded', ['status' => 'completed']);
    }
    return gamipress_get_user_points($user_id, $point_type);
}

function db_resolve_prediction($prediction_id, $winning_choice) {
    if (!in_array($winning_choice, ['yes', 'no'], true)) {
        return new WP_Error('invalid_choice', 'Invalid winning choice');
    }

    $users = get_users(['meta_key' => 'db_bet_history']);

    foreach ($users as $user) {
        $history = get_user_meta($user->ID, 'db_bet_history', true);
        if (!is_array($history)) continue;

        foreach ($history as $bet) {
            if ($bet['prediction_id'] == $prediction_id && $bet['choice'] == $winning_choice) {
                $winnings = $bet['amount'] * 2;
                db_award_points_manual($user->ID, $winnings, 'betcoins', 'DollarBets winnings');
            }
        }
    }

    update_post_meta($prediction_id, '_prediction_resolved', $winning_choice);

    return ['success' => true, 'resolved' => $winning_choice];
}