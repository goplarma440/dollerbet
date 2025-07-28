<?php
// File: includes/ultimate-member-hooks.php

if (!defined('ABSPATH')) exit;

/**
 * Award 1000 BetCoins when user registers via Ultimate Member
 */
add_action('um_after_new_user_register', 'dollarbets_award_initial_betcoins', 10, 2);
function dollarbets_award_initial_betcoins($user_id, $args) {
    // Check if GamiPress is available
    if (!function_exists("gamipress_get_user_points")) {
        return;
    }

    $points = gamipress_get_user_points($user_id, "betcoins");
    if ($points <= 0) {
        // Use db_award_points_manual for consistency and logging
        db_award_points_manual($user_id, 1000, "betcoins", "Initial BetCoins on registration");
    }
}

/**
 * Allow REST API login/register without nonce for Ultimate Member
 */
add_filter('um_rest_api_disable_nonce_check', '__return_true');

/**
 * Pass logged-in user ID to frontend JavaScript
 */
add_action('wp_enqueue_scripts', 'dollarbets_enqueue_logged_in_user_data');
function dollarbets_enqueue_logged_in_user_data() {
    if (is_user_logged_in()) {
        wp_localize_script('dollarbets-frontend', 'dollarbetsUser', [
            'userId' => get_current_user_id(),
        ]);
    }
}

/**
 * Add "My Bets" tab to Ultimate Member profile
 */
add_action('um_profile_tabs', 'dollarbets_add_my_bets_tab', 100);
function dollarbets_add_my_bets_tab($tabs) {
    // Only add tab if user is logged in
    if (!is_user_logged_in()) {
        return $tabs;
    }

    // Get current user and profile user
    $current_user_id = get_current_user_id();
    $profile_user_id = um_profile_id();
    
    // Only show "My Bets" tab on current user's own profile
    if ($current_user_id === $profile_user_id) {
        $tabs['my_bets'] = array(
            'name' => 'My Bets',
            'icon' => 'um-faicon-bar-chart',
            'custom' => true,
            'show_button' => false,
            'priority' => 90
        );
    }

    return $tabs;
}

/**
 * Helper function to get prediction title
 */
function dollarbets_get_prediction_title($prediction_id) {
    $post = get_post($prediction_id);
    return $post ? $post->post_title : 'Unknown Prediction';
}

/**
 * Helper function to get prediction status
 */
function dollarbets_get_prediction_status($prediction_id) {
    $resolved = get_post_meta($prediction_id, '_prediction_resolved', true);
    $ending_date = get_post_meta($prediction_id, '_prediction_ending_date', true);
    
    if ($resolved) {
        return ['status' => 'resolved', 'winner' => $resolved];
    }
    
    if ($ending_date && strtotime($ending_date) < time()) {
        return ['status' => 'expired', 'winner' => null];
    }
    
    return ['status' => 'active', 'winner' => null];
}

/**
 * Helper function to calculate bet result
 */
function dollarbets_calculate_bet_result($bet, $prediction_status) {
    if ($prediction_status['status'] !== 'resolved') {
        return ['result' => 'pending', 'payout' => 0];
    }
    
    $winner = $prediction_status['winner'];
    if ($bet['choice'] === $winner) {
        return ['result' => 'won', 'payout' => $bet['amount'] * 2];
    } else {
        return ['result' => 'lost', 'payout' => 0];
    }
}

/**
 * Render content for "My Bets" tab
 */
add_action('um_profile_content_my_bets_default', 'dollarbets_render_my_bets_tab');
function dollarbets_render_my_bets_tab($args = array()) {
    // Security check
    if (!is_user_logged_in()) {
        echo '<p>Please log in to view your bets.</p>';
        return;
    }

    $current_user_id = get_current_user_id();
    $profile_user_id = um_profile_id();
    
    // Only show bets for the current user's own profile
    if ($current_user_id !== $profile_user_id) {
        echo '<p>You can only view your own bets.</p>';
        return;
    }

    // Start output buffering to prevent issues
    ob_start();
    ?>
    
    <div class="dollarbets-my-bets-content">
        <?php
        // Get user balance
        $balance = 0;
        if (function_exists('gamipress_get_user_points')) {
            $balance = gamipress_get_user_points($current_user_id, 'betcoins');
        }
        ?>
        
        <!-- Balance Section -->
        <div class="dollarbets-balance" style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h3 style="margin: 0; color: #2c3e50;">💰 Your Balance: <span style="color: #e74c3c;"><?php echo esc_html($balance); ?> BetCoins</span></h3>
        </div>

        <?php
        // Get bet history
        $bet_history = get_user_meta($current_user_id, 'db_bet_history', true);
        
        if (!is_array($bet_history) || empty($bet_history)) {
            ?>
            <div class="dollarbets-empty" style="text-align: center; padding: 40px; background: #f8f9fa; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <p style="font-size: 16px; color: #6c757d;">🎲 You have not placed any bets yet.</p>
                <p style="color: #6c757d;">Start betting on predictions to see your bet history here!</p>
            </div>
            <?php
        } else {
            // Sort bets by timestamp (newest first)
            usort($bet_history, function($a, $b) {
                return strtotime($b['timestamp']) - strtotime($a['timestamp']);
            });

            echo '<h3 style="margin-bottom: 20px;">📊 Your Bet History</h3>';
            echo '<div class="dollarbets-table-container" style="overflow-x: auto;">';
            echo '<table class="dollarbets-table" style="width: 100%; border-collapse: collapse; background: white; border-radius: 5px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
            echo '<thead>';
            echo '<tr style="background: #3498db; color: white;">';
            echo '<th style="padding: 12px; text-align: left; border-bottom: 1px solid #ddd;">Prediction</th>';
            echo '<th style="padding: 12px; text-align: center; border-bottom: 1px solid #ddd;">Choice</th>';
            echo '<th style="padding: 12px; text-align: center; border-bottom: 1px solid #ddd;">Amount</th>';
            echo '<th style="padding: 12px; text-align: center; border-bottom: 1px solid #ddd;">Status</th>';
            echo '<th style="padding: 12px; text-align: center; border-bottom: 1px solid #ddd;">Result</th>';
            echo '<th style="padding: 12px; text-align: center; border-bottom: 1px solid #ddd;">Date</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            $total_wagered = 0;
            $total_won = 0;
            $wins = 0;
            $losses = 0;

            foreach ($bet_history as $bet) {
                $prediction_id = intval($bet['prediction_id']);
                $choice = sanitize_text_field($bet['choice']);
                $amount = intval($bet['amount']);
                $timestamp = sanitize_text_field($bet['timestamp']);
                
                $total_wagered += $amount;
                
                // Get prediction info
                $prediction_title = dollarbets_get_prediction_title($prediction_id);
                $prediction_status = dollarbets_get_prediction_status($prediction_id);
                $bet_result = dollarbets_calculate_bet_result($bet, $prediction_status);
                
                if ($bet_result['result'] === 'won') {
                    $wins++;
                    $total_won += $bet_result['payout'];
                } elseif ($bet_result['result'] === 'lost') {
                    $losses++;
                }
                
                echo '<tr style="border-bottom: 1px solid #eee;">';
                
                // Prediction title (without ID)
                echo '<td style="padding: 12px; max-width: 200px;">';
                echo '<strong>' . esc_html($prediction_title) . '</strong>';
                echo '</td>';
                
                // Choice
                echo '<td style="padding: 12px; text-align: center;">';
                $choice_color = $choice === 'yes' ? '#27ae60' : '#e74c3c';
                echo '<span style="background: ' . $choice_color . '; color: white; padding: 4px 8px; border-radius: 3px; font-weight: bold;">' . strtoupper(esc_html($choice)) . '</span>';
                echo '</td>';
                
                // Amount
                echo '<td style="padding: 12px; text-align: center; font-weight: bold;">';
                echo esc_html($amount) . ' BC';
                echo '</td>';
                
                // Status
                echo '<td style="padding: 12px; text-align: center;">';
                $status = $prediction_status['status'];
                $status_colors = [
                    'active' => '#3498db',
                    'resolved' => '#27ae60',
                    'expired' => '#95a5a6'
                ];
                echo '<span style="background: ' . $status_colors[$status] . '; color: white; padding: 4px 8px; border-radius: 3px; font-size: 12px;">' . strtoupper($status) . '</span>';
                echo '</td>';
                
                // Result
                echo '<td style="padding: 12px; text-align: center;">';
                $result = $bet_result['result'];
                if ($result === 'won') {
                    echo '<span style="color: #27ae60; font-weight: bold;">✅ WON</span>';
                    echo '<br><small>+' . esc_html($bet_result['payout']) . ' BC</small>';
                } elseif ($result === 'lost') {
                    echo '<span style="color: #e74c3c; font-weight: bold;">❌ LOST</span>';
                    echo '<br><small>-' . esc_html($amount) . ' BC</small>';
                } else {
                    echo '<span style="color: #f39c12; font-weight: bold;">⏳ PENDING</span>';
                }
                echo '</td>';
                
                // Date
                echo '<td style="padding: 12px; text-align: center; font-size: 12px; color: #666;">';
                echo date('M j, Y', strtotime($timestamp));
                echo '<br>' . date('g:i A', strtotime($timestamp));
                echo '</td>';
                
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            echo '</div>';

            // Statistics
            $total_bets = count($bet_history);
            $pending_bets = $total_bets - $wins - $losses;
            $win_rate = $total_bets > 0 ? round(($wins / $total_bets) * 100, 1) : 0;
            $net_profit = $total_won - $total_wagered;
            
            echo '<div class="dollarbets-stats" style="margin-top: 20px; display: flex; flex-wrap: wrap; gap: 15px;">';
            
            // Total Bets
            echo '<div style="flex: 1; min-width: 150px; background: #3498db; color: white; padding: 15px; border-radius: 5px; text-align: center;">';
            echo '<h4 style="margin: 0;">Total Bets</h4>';
            echo '<p style="font-size: 24px; font-weight: bold; margin: 5px 0;">' . $total_bets . '</p>';
            echo '</div>';
            
            // Wins
            echo '<div style="flex: 1; min-width: 150px; background: #27ae60; color: white; padding: 15px; border-radius: 5px; text-align: center;">';
            echo '<h4 style="margin: 0;">Wins</h4>';
            echo '<p style="font-size: 24px; font-weight: bold; margin: 5px 0;">' . $wins . '</p>';
            echo '</div>';
            
            // Losses
            echo '<div style="flex: 1; min-width: 150px; background: #e74c3c; color: white; padding: 15px; border-radius: 5px; text-align: center;">';
            echo '<h4 style="margin: 0;">Losses</h4>';
            echo '<p style="font-size: 24px; font-weight: bold; margin: 5px 0;">' . $losses . '</p>';
            echo '</div>';
            
            // Pending
            echo '<div style="flex: 1; min-width: 150px; background: #f39c12; color: white; padding: 15px; border-radius: 5px; text-align: center;">';
            echo '<h4 style="margin: 0;">Pending</h4>';
            echo '<p style="font-size: 24px; font-weight: bold; margin: 5px 0;">' . $pending_bets . '</p>';
            echo '</div>';
            
            // Win Rate
            echo '<div style="flex: 1; min-width: 150px; background: #9b59b6; color: white; padding: 15px; border-radius: 5px; text-align: center;">';
            echo '<h4 style="margin: 0;">Win Rate</h4>';
            echo '<p style="font-size: 24px; font-weight: bold; margin: 5px 0;">' . $win_rate . '%</p>';
            echo '</div>';
            
            // Net Profit/Loss
            $profit_color = $net_profit >= 0 ? '#27ae60' : '#e74c3c';
            $profit_text = $net_profit >= 0 ? '+' . $net_profit : $net_profit;
            echo '<div style="flex: 1; min-width: 150px; background: ' . $profit_color . '; color: white; padding: 15px; border-radius: 5px; text-align: center;">';
            echo '<h4 style="margin: 0;">Net P/L</h4>';
            echo '<p style="font-size: 24px; font-weight: bold; margin: 5px 0;">' . $profit_text . ' BC</p>';
            echo '</div>';
            
            echo '</div>';
        }
        ?>
    </div>
    
    <?php
    // Output the buffered content
    $content = ob_get_clean();
    echo $content;
}

/**
 * Add custom CSS for better mobile responsiveness
 */
add_action('wp_head', 'dollarbets_profile_css');
function dollarbets_profile_css() {
    // Only load on Ultimate Member profile pages
    if (function_exists('um_is_core_page') && um_is_core_page('user')) {
        echo '<style>
        @media (max-width: 768px) {
            .dollarbets-stats {
                flex-direction: column !important;
            }
            .dollarbets-stats > div {
                min-width: 100% !important;
            }
            .dollarbets-table {
                font-size: 14px !important;
            }
            .dollarbets-table th, 
            .dollarbets-table td {
                padding: 8px !important;
            }
            .dollarbets-table-container {
                overflow-x: auto;
            }
        }
        
        /* Ensure table is responsive */
        .dollarbets-table-container {
            width: 100%;
            overflow-x: auto;
        }
        
        .dollarbets-table {
            min-width: 600px;
        }
        
        /* Balance section styling */
        .dollarbets-balance {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* Empty state styling */
        .dollarbets-empty {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        /* Override Ultimate Member profile body max-width */
        .um-9.um .um-profile-body {
            max-width: fit-content !important;
        }
        
        /* Alternative overrides for different UM versions */
        .um .um-profile-body {
            max-width: fit-content !important;
        }
        
        .um-profile .um-profile-body {
            max-width: fit-content !important;
        }
        </style>';
    }
}

/**
 * Add shortcode to display bets anywhere
 */
add_shortcode('dollarbets_user_bets', 'dollarbets_user_bets_shortcode');
function dollarbets_user_bets_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '<p>Please log in to view your bets.</p>';
    }
    
    ob_start();
    dollarbets_render_my_bets_tab(array());
    return ob_get_clean();
}
?>

/**
 * Add "Add BetCoins" tab to Ultimate Member profile
 */
if (function_exists("um_is_core_page") && um_is_core_page("user")) {
    add_action(
        "um_profile_tabs",
        "dollarbets_add_betcoins_tab",
        100
    );
    function dollarbets_add_betcoins_tab($tabs) {
        $tabs["add_betcoins"] = array(
            "name"      => "Add BetCoins",
            "icon"      => "um-faicon-money",
            "custom"    => true,
            "show_button" => false,
            "priority"  => 95,
        );
        return $tabs;
    }

    /**
     * Render content for "Add BetCoins" tab
     */
    add_action(
        "um_profile_content_add_betcoins_default",
        "dollarbets_render_add_betcoins_tab"
    );
    function dollarbets_render_add_betcoins_tab($args = array()) {
        if (!is_user_logged_in()) {
            echo 

<p>Please log in to purchase BetCoins.</p>

;
            return;
        }
        echo do_shortcode("[dollarbets_purchase]");
    }
}
