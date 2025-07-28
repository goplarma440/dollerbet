<?php
if (!defined('ABSPATH')) exit;

/**
 * Dark Mode Toggle Shortcode
 * Usage: [dollarbets_dark_toggle]
 * Or with balance: [dollarbets_dark_toggle show_balance="true"]
 */
function dollarbets_dark_toggle_shortcode($atts) {
    $atts = shortcode_atts([
        'show_balance' => 'false',
        'align' => 'right', // left, center, right
        'size' => 'normal' // small, normal, large
    ], $atts);
    
    $show_balance = ($atts['show_balance'] === 'true');
    $align_class = 'justify-' . ($atts['align'] === 'center' ? 'center' : ($atts['align'] === 'left' ? 'start' : 'end'));
    
    // Size classes
    $size_classes = [
        'small' => 'w-8 h-4',
        'normal' => 'w-12 h-6', 
        'large' => 'w-16 h-8'
    ];
    $toggle_size = $size_classes[$atts['size']] ?? $size_classes['normal'];
    
    $dot_size = [
        'small' => 'w-3 h-3 top-0.5 left-0.5',
        'normal' => 'w-5 h-5 top-0.5 left-0.5',
        'large' => 'w-7 h-7 top-0.5 left-0.5'
    ];
    $dot_class = $dot_size[$atts['size']] ?? $dot_size['normal'];
    
    $translate_distance = [
        'small' => 'translate-x-4',
        'normal' => 'translate-x-6',
        'large' => 'translate-x-8'
    ];
    $translate_class = $translate_distance[$atts['size']] ?? $translate_distance['normal'];
    
    ob_start();
    ?>
    <div id="dollarbets-header-toggle" class="dollarbets-toggle-container">
        <div class="flex items-center space-x-4 <?php echo $align_class; ?>">
            <?php if ($show_balance && is_user_logged_in() && function_exists('gamipress_get_user_points')): ?>
                <span id="dollarbets-balance" class="text-sm font-medium">
                    💰 <span id="balance-amount"><?php echo number_format(gamipress_get_user_points(get_current_user_id(), 'betcoins')); ?></span> BetCoins
                </span>
            <?php endif; ?>
            
            <label class="relative inline-block <?php echo $toggle_size; ?> cursor-pointer">
                <input type="checkbox" id="dollarbets-dark-toggle" class="opacity-0 w-0 h-0">
                <span class="absolute inset-0 rounded-full transition-colors duration-300 bg-gray-600" id="toggle-bg"></span>
                <span class="absolute <?php echo $dot_class; ?> bg-white rounded-full transition-transform duration-300 z-10" id="toggle-dot"></span>
            </label>
        </div>
    </div>

    <style>
    .dollarbets-toggle-container {
        display: flex;
        align-items: center;
        padding: 0.5rem;
    }
    
    .dollarbets-toggle-container .flex {
        display: flex;
        align-items: center;
    }
    
    .dollarbets-toggle-container .space-x-4 > * + * {
        margin-left: 1rem;
    }
    
    .dollarbets-toggle-container .justify-start {
        justify-content: flex-start;
    }
    
    .dollarbets-toggle-container .justify-center {
        justify-content: center;
    }
    
    .dollarbets-toggle-container .justify-end {
        justify-content: flex-end;
    }
    
    .dollarbets-toggle-container .relative {
        position: relative;
    }
    
    .dollarbets-toggle-container .inline-block {
        display: inline-block;
    }
    
    .dollarbets-toggle-container .cursor-pointer {
        cursor: pointer;
    }
    
    .dollarbets-toggle-container .opacity-0 {
        opacity: 0;
    }
    
    .dollarbets-toggle-container .w-0 {
        width: 0;
    }
    
    .dollarbets-toggle-container .h-0 {
        height: 0;
    }
    
    .dollarbets-toggle-container .absolute {
        position: absolute;
    }
    
    .dollarbets-toggle-container .inset-0 {
        top: 0;
        right: 0;
        bottom: 0;
        left: 0;
    }
    
    .dollarbets-toggle-container .rounded-full {
        border-radius: 9999px;
    }
    
    .dollarbets-toggle-container .transition-colors {
        transition-property: background-color, border-color, color, fill, stroke;
        transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
        transition-duration: 300ms;
    }
    
    .dollarbets-toggle-container .transition-transform {
        transition-property: transform;
        transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
        transition-duration: 300ms;
    }
    
    .dollarbets-toggle-container .duration-300 {
        transition-duration: 300ms;
    }
    
    .dollarbets-toggle-container .bg-gray-600 {
        background-color: #4b5563;
    }
    
    .dollarbets-toggle-container .bg-blue-500 {
        background-color: #3b82f6;
    }
    
    .dollarbets-toggle-container .bg-white {
        background-color: #ffffff;
    }
    
    .dollarbets-toggle-container .z-10 {
        z-index: 10;
    }
    
    .dollarbets-toggle-container .text-sm {
        font-size: 0.875rem;
        line-height: 1.25rem;
    }
    
    .dollarbets-toggle-container .font-medium {
        font-weight: 500;
    }
    
    /* Size classes */
    .dollarbets-toggle-container .w-8 { width: 2rem; }
    .dollarbets-toggle-container .h-4 { height: 1rem; }
    .dollarbets-toggle-container .w-12 { width: 3rem; }
    .dollarbets-toggle-container .h-6 { height: 1.5rem; }
    .dollarbets-toggle-container .w-16 { width: 4rem; }
    .dollarbets-toggle-container .h-8 { height: 2rem; }
    
    .dollarbets-toggle-container .w-3 { width: 0.75rem; }
    .dollarbets-toggle-container .h-3 { height: 0.75rem; }
    .dollarbets-toggle-container .w-5 { width: 1.25rem; }
    .dollarbets-toggle-container .h-5 { height: 1.25rem; }
    .dollarbets-toggle-container .w-7 { width: 1.75rem; }
    .dollarbets-toggle-container .h-7 { height: 1.75rem; }
    
    .dollarbets-toggle-container .top-0\.5 { top: 0.125rem; }
    .dollarbets-toggle-container .left-0\.5 { left: 0.125rem; }
    
    .dollarbets-toggle-container .translate-x-4 { transform: translateX(1rem); }
    .dollarbets-toggle-container .translate-x-6 { transform: translateX(1.5rem); }
    .dollarbets-toggle-container .translate-x-8 { transform: translateX(2rem); }
    
    /* Dark mode styles */
    body.dollarbets-dark-mode {
        background-color: #0D0E1B !important;
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .dollarbets-toggle-container {
        color: #E0E0E0;
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('dollarbets-dark-toggle');
        const toggleBg = document.getElementById('toggle-bg');
        const toggleDot = document.getElementById('toggle-dot');
        
        if (!toggle || !toggleBg || !toggleDot) return;
        
        // Load saved preference
        const savedDarkMode = localStorage.getItem('dollarbets-dark-mode');
        let isDarkMode = savedDarkMode ? JSON.parse(savedDarkMode) : false;
        
        // Set initial state
        toggle.checked = !isDarkMode; // Inverted because checked = light mode
        updateToggleAppearance();
        applyDarkMode();
        
        // Handle toggle change
        toggle.addEventListener('change', function() {
            isDarkMode = !this.checked;
            localStorage.setItem('dollarbets-dark-mode', JSON.stringify(isDarkMode));
            updateToggleAppearance();
            applyDarkMode();
            
            // Dispatch custom event for main app
            window.dispatchEvent(new CustomEvent('dollarbets-dark-mode-changed', {
                detail: { isDarkMode: isDarkMode }
            }));
        });
        
        function updateToggleAppearance() {
            if (isDarkMode) {
                toggleBg.style.backgroundColor = '#4b5563'; // gray-600
                toggleDot.classList.remove('<?php echo $translate_class; ?>');
            } else {
                toggleBg.style.backgroundColor = '#3b82f6'; // blue-500
                toggleDot.classList.add('<?php echo $translate_class; ?>');
            }
        }
        
        function applyDarkMode() {
            if (isDarkMode) {
                document.body.classList.add('dollarbets-dark-mode');
            } else {
                document.body.classList.remove('dollarbets-dark-mode');
            }
        }
    });
    </script>
    <?php
    
    return ob_get_clean();
}

// Register the shortcode
add_shortcode('dollarbets_dark_toggle', 'dollarbets_dark_toggle_shortcode');

/**
 * Update balance shortcode (for AJAX updates)
 */
function dollarbets_update_balance_ajax() {
    if (!is_user_logged_in() || !function_exists('gamipress_get_user_points')) {
        if (function_exists('wp_die')) {
            wp_die('Unauthorized', 'Error', ['response' => 401]);
        } else {
            http_response_code(401);
            die('Unauthorized');
        }
    }
    
    $balance = gamipress_get_user_points(get_current_user_id(), 'betcoins');
    wp_send_json_success(['balance' => number_format($balance)]);
}
add_action('wp_ajax_dollarbets_update_balance', 'dollarbets_update_balance_ajax');

/**
 * Helper function to sync dark mode with main app
 */
function dollarbets_sync_dark_mode_script() {
    if (is_page() && has_shortcode(get_post()->post_content, 'dollarbets_dark_toggle')) {
        ?>
        <script>
        // Sync dark mode between header toggle and main app
        window.addEventListener('dollarbets-dark-mode-changed', function(e) {
            // Update main app if it exists
            if (window.dollarbetsApp && window.dollarbetsApp.setDarkMode) {
                window.dollarbetsApp.setDarkMode(e.detail.isDarkMode);
            }
        });
        </script>
        <?php
    }
}
add_action('wp_footer', 'dollarbets_sync_dark_mode_script');

/**
 * Add comprehensive dark mode CSS for Ultimate Member and other components
 */
function dollarbets_comprehensive_dark_mode_css() {
    ?>
    <style>
    /* Comprehensive Dark Mode Styles */
    body.dollarbets-dark-mode {
        background-color: #0D0E1B !important;
        color: #E0E0E0 !important;
    }
    
    /* Ultimate Member Dark Mode Styles */
    body.dollarbets-dark-mode .um {
        background-color: #1a1a2e !important;
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .um-profile {
        background-color: #1a1a2e !important;
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .um-profile-body {
        background-color: #1a1a2e !important;
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .um-profile-nav {
        background-color: #16213e !important;
        border-color: #2d3748 !important;
    }
    
    body.dollarbets-dark-mode .um-profile-nav a {
        color: #E0E0E0 !important;
        border-color: #2d3748 !important;
    }
    
    body.dollarbets-dark-mode .um-profile-nav a:hover,
    body.dollarbets-dark-mode .um-profile-nav a.current {
        background-color: #2d3748 !important;
        color: #ffffff !important;
    }
    
    body.dollarbets-dark-mode .um-header {
        background-color: #16213e !important;
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .um-header h1,
    body.dollarbets-dark-mode .um-header h2,
    body.dollarbets-dark-mode .um-header h3,
    body.dollarbets-dark-mode .um-header h4,
    body.dollarbets-dark-mode .um-header h5,
    body.dollarbets-dark-mode .um-header h6 {
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .um-form {
        background-color: #1a1a2e !important;
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .um-form input,
    body.dollarbets-dark-mode .um-form textarea,
    body.dollarbets-dark-mode .um-form select {
        background-color: #2d3748 !important;
        color: #E0E0E0 !important;
        border-color: #4a5568 !important;
    }
    
    body.dollarbets-dark-mode .um-form input:focus,
    body.dollarbets-dark-mode .um-form textarea:focus,
    body.dollarbets-dark-mode .um-form select:focus {
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 1px #3b82f6 !important;
    }
    
    body.dollarbets-dark-mode .um-form label {
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .um-button {
        background-color: #3b82f6 !important;
        color: #ffffff !important;
        border-color: #3b82f6 !important;
    }
    
    body.dollarbets-dark-mode .um-button:hover {
        background-color: #2563eb !important;
        border-color: #2563eb !important;
    }
    
    /* Modal and Popup Dark Mode */
    body.dollarbets-dark-mode .um-modal {
        background-color: rgba(13, 14, 27, 0.95) !important;
    }
    
    body.dollarbets-dark-mode .um-modal-content {
        background-color: #1a1a2e !important;
        color: #E0E0E0 !important;
        border-color: #2d3748 !important;
    }
    
    body.dollarbets-dark-mode .um-modal-header {
        background-color: #16213e !important;
        color: #E0E0E0 !important;
        border-color: #2d3748 !important;
    }
    
    body.dollarbets-dark-mode .um-modal-header h1,
    body.dollarbets-dark-mode .um-modal-header h2,
    body.dollarbets-dark-mode .um-modal-header h3,
    body.dollarbets-dark-mode .um-modal-header h4,
    body.dollarbets-dark-mode .um-modal-header h5,
    body.dollarbets-dark-mode .um-modal-header h6 {
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .um-modal-body {
        background-color: #1a1a2e !important;
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .um-modal-footer {
        background-color: #16213e !important;
        border-color: #2d3748 !important;
    }
    
    /* WordPress Admin Bar Dark Mode */
    body.dollarbets-dark-mode #wpadminbar {
        background-color: #16213e !important;
    }
    
    body.dollarbets-dark-mode #wpadminbar .ab-item {
        color: #E0E0E0 !important;
    }
    
    /* General WordPress Dark Mode */
    body.dollarbets-dark-mode .wp-block {
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .entry-content {
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .site-header {
        background-color: #16213e !important;
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .site-footer {
        background-color: #16213e !important;
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .widget {
        background-color: #1a1a2e !important;
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .widget-title {
        color: #E0E0E0 !important;
    }
    
    /* Navigation Dark Mode */
    body.dollarbets-dark-mode .main-navigation {
        background-color: #16213e !important;
    }
    
    body.dollarbets-dark-mode .main-navigation a {
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .main-navigation a:hover {
        color: #3b82f6 !important;
    }
    
    /* Form Elements Dark Mode */
    body.dollarbets-dark-mode input[type="text"],
    body.dollarbets-dark-mode input[type="email"],
    body.dollarbets-dark-mode input[type="password"],
    body.dollarbets-dark-mode input[type="number"],
    body.dollarbets-dark-mode input[type="date"],
    body.dollarbets-dark-mode textarea,
    body.dollarbets-dark-mode select {
        background-color: #2d3748 !important;
        color: #E0E0E0 !important;
        border-color: #4a5568 !important;
    }
    
    body.dollarbets-dark-mode input[type="text"]:focus,
    body.dollarbets-dark-mode input[type="email"]:focus,
    body.dollarbets-dark-mode input[type="password"]:focus,
    body.dollarbets-dark-mode input[type="number"]:focus,
    body.dollarbets-dark-mode input[type="date"]:focus,
    body.dollarbets-dark-mode textarea:focus,
    body.dollarbets-dark-mode select:focus {
        border-color: #3b82f6 !important;
        box-shadow: 0 0 0 1px #3b82f6 !important;
    }
    
    /* Table Dark Mode */
    body.dollarbets-dark-mode table {
        background-color: #1a1a2e !important;
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode table th {
        background-color: #16213e !important;
        color: #E0E0E0 !important;
        border-color: #2d3748 !important;
    }
    
    body.dollarbets-dark-mode table td {
        border-color: #2d3748 !important;
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode table tr:nth-child(even) {
        background-color: #2d3748 !important;
    }
    
    /* Card/Box Dark Mode */
    body.dollarbets-dark-mode .card,
    body.dollarbets-dark-mode .box,
    body.dollarbets-dark-mode .panel {
        background-color: #1a1a2e !important;
        color: #E0E0E0 !important;
        border-color: #2d3748 !important;
    }
    
    /* Heading Dark Mode */
    body.dollarbets-dark-mode h1,
    body.dollarbets-dark-mode h2,
    body.dollarbets-dark-mode h3,
    body.dollarbets-dark-mode h4,
    body.dollarbets-dark-mode h5,
    body.dollarbets-dark-mode h6 {
        color: #E0E0E0 !important;
    }
    
    /* Link Dark Mode */
    body.dollarbets-dark-mode a {
        color: #3b82f6 !important;
    }
    
    body.dollarbets-dark-mode a:hover {
        color: #2563eb !important;
    }
    
    /* Button Dark Mode */
    body.dollarbets-dark-mode .button,
    body.dollarbets-dark-mode .btn {
        background-color: #3b82f6 !important;
        color: #ffffff !important;
        border-color: #3b82f6 !important;
    }
    
    body.dollarbets-dark-mode .button:hover,
    body.dollarbets-dark-mode .btn:hover {
        background-color: #2563eb !important;
        border-color: #2563eb !important;
    }
    
    body.dollarbets-dark-mode .button.secondary,
    body.dollarbets-dark-mode .btn.secondary {
        background-color: #2d3748 !important;
        color: #E0E0E0 !important;
        border-color: #4a5568 !important;
    }
    
    body.dollarbets-dark-mode .button.secondary:hover,
    body.dollarbets-dark-mode .btn.secondary:hover {
        background-color: #4a5568 !important;
        border-color: #718096 !important;
    }
    
    /* Specific DollarBets Components Dark Mode */
    body.dollarbets-dark-mode .dollarbets-purchase-form {
        background-color: #1a1a2e !important;
        color: #E0E0E0 !important;
        border-color: #2d3748 !important;
    }
    
    body.dollarbets-dark-mode .package-option {
        background-color: #2d3748 !important;
        color: #E0E0E0 !important;
        border-color: #4a5568 !important;
    }
    
    body.dollarbets-dark-mode .package-option:hover {
        border-color: #3b82f6 !important;
    }
    
    body.dollarbets-dark-mode .modal-content {
        background-color: #1a1a2e !important;
        color: #E0E0E0 !important;
    }
    
    body.dollarbets-dark-mode .payment-modal {
        background-color: rgba(13, 14, 27, 0.95) !important;
    }
    
    /* Scrollbar Dark Mode */
    body.dollarbets-dark-mode ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    
    body.dollarbets-dark-mode ::-webkit-scrollbar-track {
        background: #1a1a2e;
    }
    
    body.dollarbets-dark-mode ::-webkit-scrollbar-thumb {
        background: #4a5568;
        border-radius: 4px;
    }
    
    body.dollarbets-dark-mode ::-webkit-scrollbar-thumb:hover {
        background: #718096;
    }
    
    /* Ensure text remains readable */
    body.dollarbets-dark-mode * {
        color: inherit !important;
    }
    
    body.dollarbets-dark-mode p,
    body.dollarbets-dark-mode span,
    body.dollarbets-dark-mode div {
        color: #E0E0E0 !important;
    }
    
    /* Override any white backgrounds */
    body.dollarbets-dark-mode [style*="background-color: white"],
    body.dollarbets-dark-mode [style*="background-color: #fff"],
    body.dollarbets-dark-mode [style*="background-color: #ffffff"] {
        background-color: #1a1a2e !important;
    }
    
    /* Override any black text */
    body.dollarbets-dark-mode [style*="color: black"],
    body.dollarbets-dark-mode [style*="color: #000"],
    body.dollarbets-dark-mode [style*="color: #000000"] {
        color: #E0E0E0 !important;
    }    </style>
    <?php
}
add_action('wp_head', 'dollarbets_comprehensive_dark_mode_css');
?>
