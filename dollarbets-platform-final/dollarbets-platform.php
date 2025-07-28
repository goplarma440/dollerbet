<?php
/*
Plugin Name: DollarBets Platform
Description:  Prediction market with BetCoins (GamiPress), Ultimate Member, NewsAPI integration, and frontend prediction tiles.
Version: 1.0
Author: DolleBets
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define reusable paths
define('DOLLARBETS_PATH', plugin_dir_path(__FILE__));
define('DOLLARBETS_URL', plugin_dir_url(__FILE__));

// Activation hook
register_activation_hook(__FILE__, 'dollarbets_activate_plugin');
function dollarbets_activate_plugin() {
    // Create database tables if needed
    global $wpdb;
    
    // Create custom tables for predictions and bets if they don't exist
    $predictions_table = $wpdb->prefix . 'dollarbets_predictions';
    $bets_table = $wpdb->prefix . 'dollarbets_bets';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql_predictions = "CREATE TABLE IF NOT EXISTS $predictions_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        status varchar(20) DEFAULT 'active',
        total_bets_yes int(11) DEFAULT 0,
        total_bets_no int(11) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    $sql_bets = "CREATE TABLE IF NOT EXISTS $bets_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        prediction_id mediumint(9) NOT NULL,
        bet_type varchar(10) NOT NULL,
        amount int(11) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_predictions);
    dbDelta($sql_bets);
    
    // Flush rewrite rules
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules();
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'dollarbets_deactivate_plugin');
function dollarbets_deactivate_plugin() {
    // Flush rewrite rules
    if (function_exists('flush_rewrite_rules')) {
        flush_rewrite_rules();
    }
}

// Load core components
require_once DOLLARBETS_PATH . 'includes/custom-post-types.php';
require_once DOLLARBETS_PATH . 'includes/enqueue-scripts.php';
require_once DOLLARBETS_PATH . 'includes/admin-settings.php';
require_once DOLLARBETS_PATH . 'includes/toggle-mode.php';
require_once DOLLARBETS_PATH . 'includes/header-enhancements.php';
require_once DOLLARBETS_PATH . 'includes/payment-gateway.php';
require_once DOLLARBETS_PATH . 'includes/transaction-history.php';
require_once DOLLARBETS_PATH . 'includes/prediction-resolution.php';
require_once DOLLARBETS_PATH . 'includes/ultimate-member-transactions.php';
require_once DOLLARBETS_PATH . 'includes/payout-system.php';
require_once DOLLARBETS_PATH . 'includes/leaderboard.php';
require_once DOLLARBETS_PATH . 'includes/ultimate-member-hooks.php';
require_once DOLLARBETS_PATH . 'includes/news-api-integration.php';
require_once DOLLARBETS_PATH . 'includes/payment-settings.php';
require_once DOLLARBETS_PATH . 'shortcodes/predictions.php';
require_once DOLLARBETS_PATH . 'shortcodes/top-gamipress.php';


require_once DOLLARBETS_PATH . 'includes/elementor-compatibility.php';


require_once DOLLARBETS_PATH . 'shortcodes/user-info.php';

