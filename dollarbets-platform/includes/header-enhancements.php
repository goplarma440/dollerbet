<?php
if (!defined('ABSPATH')) exit;

/**
 * Enhanced Header with User Info, Betcoins, and Dark/Light Mode Toggle
 * This file contains all header-related functionality
 */

/**
 * Enhanced Header Shortcode
 * Usage: [dollarbets_header]
 * Shows username, avatar, betcoins, and dark/light mode toggle with text labels
 */
function dollarbets_enhanced_header_shortcode($atts) {
    $atts = shortcode_atts([
        'show_avatar' => 'true',
        'show_username' => 'true', 
        'show_balance' => 'true',
        'show_toggle' => 'true',
        'align' => 'right', // left, center, right
        'style' => 'modern' // modern, classic, minimal
    ], $atts);
    
    $show_avatar = ($atts['show_avatar'] === 'true');
    $show_username = ($atts['show_username'] === 'true');
    $show_balance = ($atts['show_balance'] === 'true');
    $show_toggle = ($atts['show_toggle'] === 'true');
    
    ob_start();
    ?>
    <div id="dollarbets-enhanced-header" class="dollarbets-header-container <?php echo esc_attr($atts['style']); ?> <?php echo esc_attr($atts['align']); ?>">
        <?php if (is_user_logged_in()): ?>
            <div class="dollarbets-user-section">
                <?php if ($show_avatar && function_exists('um_get_user_avatar_url')): ?>
                    <div class="dollarbets-avatar">
                        <?php 
                        $avatar_url = um_get_user_avatar_url(get_current_user_id(), 'original');
                        if ($avatar_url): ?>
                            <img src="<?php echo esc_url($avatar_url); ?>" alt="User Avatar" class="avatar-img">
                        <?php else: ?>
                            <div class="avatar-placeholder">
                                <span class="avatar-initial"><?php echo strtoupper(substr(wp_get_current_user()->display_name, 0, 1)); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($show_username): ?>
                    <div class="dollarbets-username">
                        <span class="username-text">Hello, <?php echo esc_html(wp_get_current_user()->display_name); ?></span>
                    </div>
                <?php endif; ?>
                
                <?php if ($show_balance && function_exists('gamipress_get_user_points')): ?>
                    <div class="dollarbets-balance">
                        <span class="balance-icon">💰</span>
                        <span class="balance-amount" id="header-balance-amount"><?php echo number_format(gamipress_get_user_points(get_current_user_id(), 'betcoins')); ?></span>
                        <span class="balance-label">BetCoins</span>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="dollarbets-guest-section">
                <span class="guest-text">Welcome, Guest</span>
                <a href="<?php echo wp_login_url(); ?>" class="login-link">Login</a>
            </div>
        <?php endif; ?>
        
        <?php if ($show_toggle): ?>
            <div class="dollarbets-mode-toggle">
                <div class="mode-toggle-wrapper">
                    <span class="mode-label mode-light" id="light-label">Light</span>
                    <label class="toggle-switch">
                        <input type="checkbox" id="dollarbets-enhanced-dark-toggle" class="toggle-input">
                        <span class="toggle-slider"></span>
                    </label>
                    <span class="mode-label mode-dark" id="dark-label">Dark</span>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <style>
    /* Enhanced Header Styles */
    .dollarbets-header-container {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1rem 2rem;
        background: #ffffff;
        border-bottom: 1px solid #e5e7eb;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        transition: all 0.3s ease;
    }
    
    .dollarbets-header-container.left {
        justify-content: flex-start;
        gap: 2rem;
    }
    
    .dollarbets-header-container.center {
        justify-content: center;
        gap: 2rem;
    }
    
    .dollarbets-header-container.right {
        justify-content: space-between;
    }
    
    /* User Section */
    .dollarbets-user-section {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    /* Avatar Styles */
    .dollarbets-avatar {
        position: relative;
    }
    
    .avatar-img {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #e5e7eb;
        transition: border-color 0.3s ease;
    }
    
    .avatar-img:hover {
        border-color: #3b82f6;
    }
    
    .avatar-placeholder {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        border: 2px solid #e5e7eb;
    }
    
    .avatar-initial {
        color: white;
        font-weight: bold;
        font-size: 1.1rem;
    }
    
    /* Username Styles */
    .dollarbets-username {
        display: flex;
        flex-direction: column;
    }
    
    .username-text {
        font-weight: 600;
        color: #374151;
        font-size: 0.95rem;
    }
    
    /* Balance Styles */
    .dollarbets-balance {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 25px;
        font-weight: 600;
        box-shadow: 0 2px 4px rgba(245, 158, 11, 0.3);
        transition: transform 0.2s ease;
    }
    
    .dollarbets-balance:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(245, 158, 11, 0.4);
    }
    
    .balance-icon {
        font-size: 1.1rem;
    }
    
    .balance-amount {
        font-size: 1rem;
        font-weight: bold;
    }
    
    .balance-label {
        font-size: 0.85rem;
        opacity: 0.9;
    }
    
    /* Guest Section */
    .dollarbets-guest-section {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .guest-text {
        color: #6b7280;
        font-weight: 500;
    }
    
    .login-link {
        background: #3b82f6;
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 500;
        transition: background-color 0.2s ease;
    }
    
    .login-link:hover {
        background: #2563eb;
        text-decoration: none;
    }
    
    /* Mode Toggle Styles */
    .dollarbets-mode-toggle {
        display: flex;
        align-items: center;
    }
    
    .mode-toggle-wrapper {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        background: #f3f4f6;
        padding: 0.5rem;
        border-radius: 25px;
        border: 1px solid #e5e7eb;
    }
    
    .mode-label {
        font-size: 0.85rem;
        font-weight: 500;
        transition: color 0.3s ease;
        user-select: none;
    }
    
    .mode-label.mode-light {
        color: #f59e0b;
    }
    
    .mode-label.mode-dark {
        color: #6b7280;
    }
    
    /* Toggle Switch */
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 24px;
        cursor: pointer;
    }
    
    .toggle-input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #cbd5e1;
        transition: 0.3s;
        border-radius: 24px;
    }
    
    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: 0.3s;
        border-radius: 50%;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
    }
    
    .toggle-input:checked + .toggle-slider {
        background-color: #1f2937;
    }
    
    .toggle-input:checked + .toggle-slider:before {
        transform: translateX(26px);
    }
    
    /* Dark Mode Styles */
    body.dollarbets-dark-mode .dollarbets-header-container {
        background: #1f2937;
        border-bottom-color: #374151;
        color: #e5e7eb;
    }
    
    body.dollarbets-dark-mode .username-text {
        color: #e5e7eb;
    }
    
    body.dollarbets-dark-mode .guest-text {
        color: #9ca3af;
    }
    
    body.dollarbets-dark-mode .mode-toggle-wrapper {
        background: #374151;
        border-color: #4b5563;
    }
    
    body.dollarbets-dark-mode .mode-label.mode-light {
        color: #6b7280;
    }
    
    body.dollarbets-dark-mode .mode-label.mode-dark {
        color: #fbbf24;
    }
    
    body.dollarbets-dark-mode .avatar-img {
        border-color: #4b5563;
    }
    
    body.dollarbets-dark-mode .avatar-img:hover {
        border-color: #60a5fa;
    }
    
    body.dollarbets-dark-mode .avatar-placeholder {
        border-color: #4b5563;
    }
    
    /* Responsive Design */
    @media (max-width: 768px) {
        .dollarbets-header-container {
            flex-direction: column;
            gap: 1rem;
            padding: 1rem;
        }
        
        .dollarbets-header-container.left,
        .dollarbets-header-container.center,
        .dollarbets-header-container.right {
            justify-content: center;
        }
        
        .dollarbets-user-section {
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .mode-toggle-wrapper {
            gap: 0.5rem;
        }
        
        .mode-label {
            font-size: 0.8rem;
        }
    }
    
    @media (max-width: 480px) {
        .dollarbets-balance {
            padding: 0.4rem 0.8rem;
        }
        
        .balance-label {
            display: none;
        }
        
        .avatar-img,
        .avatar-placeholder {
            width: 35px;
            height: 35px;
        }
        
        .avatar-initial {
            font-size: 1rem;
        }
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('dollarbets-enhanced-dark-toggle');
        const lightLabel = document.getElementById('light-label');
        const darkLabel = document.getElementById('dark-label');
        
        if (!toggle) return;
        
        // Load saved preference
        const savedDarkMode = localStorage.getItem('dollarbets-dark-mode');
        let isDarkMode = savedDarkMode ? JSON.parse(savedDarkMode) : false;
        
        // Set initial state
        toggle.checked = isDarkMode;
        updateLabels();
        applyDarkMode();
        
        // Handle toggle change
        toggle.addEventListener('change', function() {
            isDarkMode = this.checked;
            localStorage.setItem('dollarbets-dark-mode', JSON.stringify(isDarkMode));
            updateLabels();
            applyDarkMode();
            
            // Dispatch custom event for other components
            window.dispatchEvent(new CustomEvent('dollarbets-dark-mode-changed', {
                detail: { isDarkMode: isDarkMode }
            }));
            
            // Update balance if needed
            updateBalance();
        });
        
        function updateLabels() {
            if (lightLabel && darkLabel) {
                if (isDarkMode) {
                    lightLabel.style.color = '#6b7280';
                    darkLabel.style.color = '#fbbf24';
                } else {
                    lightLabel.style.color = '#f59e0b';
                    darkLabel.style.color = '#6b7280';
                }
            }
        }
        
        function applyDarkMode() {
            if (isDarkMode) {
                document.body.classList.add('dollarbets-dark-mode');
            } else {
                document.body.classList.remove('dollarbets-dark-mode');
            }
        }
        
        function updateBalance() {
            const balanceElement = document.getElementById('header-balance-amount');
            if (!balanceElement) return;
            
            // Make AJAX call to update balance
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=dollarbets_update_balance&_ajax_nonce=<?php echo wp_create_nonce('dollarbets_balance_nonce'); ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    balanceElement.textContent = data.data.balance;
                }
            })
            .catch(error => {
                console.log('Balance update failed:', error);
            });
        }
        
        // Listen for balance updates from other components
        window.addEventListener('dollarbets-balance-updated', function(e) {
            const balanceElement = document.getElementById('header-balance-amount');
            if (balanceElement && e.detail.balance !== undefined) {
                balanceElement.textContent = e.detail.balance;
            }
        });
        
        // Auto-update balance every 30 seconds
        setInterval(updateBalance, 30000);
    });
    </script>
    <?php
    
    return ob_get_clean();
}

// Register the enhanced header shortcode
add_shortcode('dollarbets_header', 'dollarbets_enhanced_header_shortcode');



/**
 * Simple header shortcode for minimal display
 * Usage: [dollarbets_simple_header]
 */
function dollarbets_simple_header_shortcode($atts) {
    $atts = shortcode_atts([
        'show_balance' => 'true',
        'show_toggle' => 'true'
    ], $atts);
    
    if (!is_user_logged_in()) {
        return '<div class="dollarbets-simple-header">Please log in to view your information.</div>';
    }
    
    ob_start();
    ?>
    <div class="dollarbets-simple-header">
        <?php if ($atts['show_balance'] === 'true' && function_exists('gamipress_get_user_points')): ?>
            <span class="simple-balance">
                💰 <?php echo number_format(gamipress_get_user_points(get_current_user_id(), 'betcoins')); ?> BetCoins
            </span>
        <?php endif; ?>
        
        <?php if ($atts['show_toggle'] === 'true'): ?>
            <label class="simple-toggle">
                <input type="checkbox" id="dollarbets-simple-dark-toggle">
                <span>Dark Mode</span>
            </label>
        <?php endif; ?>
    </div>
    
    <style>
    .dollarbets-simple-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.5rem 1rem;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        margin-bottom: 1rem;
    }
    
    .simple-balance {
        font-weight: 600;
        color: #374151;
    }
    
    .simple-toggle {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        font-size: 0.9rem;
    }
    
    body.dollarbets-dark-mode .dollarbets-simple-header {
        background: #374151;
        border-color: #4b5563;
        color: #e5e7eb;
    }
    
    body.dollarbets-dark-mode .simple-balance {
        color: #e5e7eb;
    }
    </style>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const simpleToggle = document.getElementById('dollarbets-simple-dark-toggle');
        if (!simpleToggle) return;
        
        // Sync with main dark mode
        const savedDarkMode = localStorage.getItem('dollarbets-dark-mode');
        let isDarkMode = savedDarkMode ? JSON.parse(savedDarkMode) : false;
        
        simpleToggle.checked = isDarkMode;
        
        simpleToggle.addEventListener('change', function() {
            isDarkMode = this.checked;
            localStorage.setItem('dollarbets-dark-mode', JSON.stringify(isDarkMode));
            
            if (isDarkMode) {
                document.body.classList.add('dollarbets-dark-mode');
            } else {
                document.body.classList.remove('dollarbets-dark-mode');
            }
            
            // Dispatch event for synchronization
            window.dispatchEvent(new CustomEvent('dollarbets-dark-mode-changed', {
                detail: { isDarkMode: isDarkMode }
            }));
        });
    });
    </script>
    <?php
    
    return ob_get_clean();
}

// Register the simple header shortcode
add_shortcode('dollarbets_simple_header', 'dollarbets_simple_header_shortcode');

/**
 * Add header enhancement styles to frontend
 */
function dollarbets_header_enhancements_styles() {
    ?>
    <style>
    /* Global dark mode styles for the entire site */
    body.dollarbets-dark-mode {
        background-color: #0f172a !important;
        color: #e2e8f0 !important;
    }
    
    body.dollarbets-dark-mode * {
        border-color: #334155 !important;
    }
    
    body.dollarbets-dark-mode .prediction-tile {
        background-color: #1e293b !important;
        border-color: #334155 !important;
        color: #e2e8f0 !important;
    }
    
    body.dollarbets-dark-mode .prediction-tile h3 {
        color: #f1f5f9 !important;
    }
    
    body.dollarbets-dark-mode .category-filter button {
        background-color: #334155 !important;
        color: #e2e8f0 !important;
    }
    
    body.dollarbets-dark-mode .category-filter button.active,
    body.dollarbets-dark-mode .category-filter button:hover {
        background-color: #3b82f6 !important;
        color: white !important;
    }
    </style>
    <?php
}
add_action('wp_head', 'dollarbets_header_enhancements_styles');
?>

