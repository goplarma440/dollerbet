<?php
if (!defined("ABSPATH")) exit;

/**
 * Transaction History System for DollarBets Platform
 * Handles logging and display of all point transactions
 */

class DollarBets_Transaction_History {

    public function __construct() {
        add_action("init", [$this, "register_transaction_post_type"]);
        add_action("add_meta_boxes", [$this, "add_transaction_meta_box"]);
        add_action("um_profile_tabs", [$this, "add_transaction_history_tab"], 110);
        add_action("um_profile_content_transactions_default", [$this, "render_transaction_history_tab"]);
    }

    /**
     * Register transaction custom post type
     */
    public function register_transaction_post_type() {
        $labels = [
            "name" => "Transactions",
            "singular_name" => "Transaction",
            "menu_name" => "Transactions",
            "name_admin_bar" => "Transaction",
            "archives" => "Transaction Archives",
            "attributes" => "Transaction Attributes",
            "parent_item_colon" => "Parent Transaction:",
            "all_items" => "All Transactions",
            "add_new_item" => "Add New Transaction",
            "add_new" => "Add New",
            "new_item" => "New Transaction",
            "edit_item" => "Edit Transaction",
            "update_item" => "Update Transaction",
            "view_item" => "View Transaction",
            "view_items" => "View Transactions",
            "search_items" => "Search Transaction",
            "not_found" => "Not found",
            "not_found_in_trash" => "Not found in Trash",
            "featured_image" => "Featured Image",
            "set_featured_image" => "Set featured image",
            "remove_featured_image" => "Remove featured image",
            "use_featured_image" => "Use as featured image",
            "insert_into_item" => "Insert into transaction",
            "uploaded_to_this_item" => "Uploaded to this transaction",
            "items_list" => "Transactions list",
            "items_list_navigation" => "Transactions list navigation",
            "filter_items_list" => "Filter transactions list",
        ];

        $args = [
            "label" => "Transaction",
            "description" => "Holds all BetCoin transactions",
            "labels" => $labels,
            "supports" => ["title", "author"],
            "hierarchical" => false,
            "public" => false,
            "show_ui" => true,
            "show_in_menu" => true,
            "menu_position" => 25,
            "menu_icon" => "dashicons-list-view",
            "show_in_admin_bar" => true,
            "show_in_nav_menus" => true,
            "can_export" => true,
            "has_archive" => false,
            "exclude_from_search" => true,
            "publicly_queryable" => false,
            "capability_type" => "post",
            "rewrite" => false,
        ];

        register_post_type("db_transaction", $args);
    }

    /**
     * Add meta box to transaction post type
     */
    public function add_transaction_meta_box() {
        add_meta_box(
            "dollarbets_transaction_details",
            "Transaction Details",
            [$this, "render_transaction_meta_box"],
            "db_transaction",
            "normal",
            "high"
        );
    }

    /**
     * Render transaction meta box
     */
    public function render_transaction_meta_box($post) {
        wp_nonce_field("dollarbets_save_transaction", "dollarbets_transaction_nonce");

        $details = get_post_meta($post->ID, "_transaction_details", true);
        $details = is_array($details) ? $details : [];

        $fields = [
            "user_id" => "User ID",
            "type" => "Type (e.g., purchase, bet, win)",
            "amount" => "Amount (BetCoins)",
            "currency_amount" => "Currency Amount (e.g., USD)",
            "gateway" => "Payment Gateway",
            "transaction_id" => "Gateway Transaction ID",
            "description" => "Description",
            "status" => "Status (e.g., completed, pending, failed)",
        ];

        echo 
            '<table class="form-table"><tbody>';

        foreach ($fields as $key => $label) {
            $value = esc_attr($details[$key] ?? "");
            echo 
                "<tr>
                    <th><label for=\"{$key}\">{$label}</label></th>
                    <td><input type=\"text\" id=\"{$key}\" name=\"transaction_details[{$key}]\" value=\"{$value}\" class=\"regular-text\"></td>
                </tr>";
        }

        echo 
            "</tbody></table>";
    }

    /**
     * Add transaction history tab to Ultimate Member profile
     */
    public function add_transaction_history_tab($tabs) {
        if (is_user_logged_in() && um_profile_id() == get_current_user_id()) {
            $tabs["transactions"] = [
                "name" => "Transactions",
                "icon" => "um-faicon-history",
                "custom" => true,
                "show_button" => false,
                "priority" => 95,
            ];
        }
        return $tabs;
    }

    /**
     * Render transaction history tab content
     */
    public function render_transaction_history_tab($args = []) {
        if (!is_user_logged_in() || um_profile_id() != get_current_user_id()) {
            echo 
                "<p>You can only view your own transaction history.</p>";
            return;
        }

        $user_id = get_current_user_id();
        $paged = get_query_var("paged") ? get_query_var("paged") : 1;

        $query_args = [
            "post_type" => "db_transaction",
            "author" => $user_id,
            "posts_per_page" => 20,
            "paged" => $paged,
            "orderby" => "date",
            "order" => "DESC",
        ];

        $transactions_query = new WP_Query($query_args);

        ob_start();
        ?>
        <div class="dollarbets-transaction-history">
            <h3>Transaction History</h3>

            <?php if ($transactions_query->have_posts()): ?>
                <table class="dollarbets-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($transactions_query->have_posts()): $transactions_query->the_post(); ?>
                            <?php
                            $details = get_post_meta(get_the_ID(), "_transaction_details", true);
                            $details = is_array($details) ? $details : [];
                            $amount = floatval($details["amount"] ?? 0);
                            $status = esc_html($details["status"] ?? "");
                            ?>
                            <tr>
                                <td><?php echo get_the_date("M j, Y g:i A"); ?></td>
                                <td><?php echo esc_html(ucfirst($details["type"] ?? "")); ?></td>
                                <td><?php echo esc_html($details["description"] ?? ""); ?></td>
                                <td class="<?php echo $amount >= 0 ? "positive" : "negative"; ?>">
                                    <?php echo ($amount >= 0 ? "+" : "") . number_format($amount) . " BC"; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($status); ?>">
                                        <?php echo ucfirst($status); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

                <div class="pagination">
                    <?php
                    echo paginate_links([
                        "base" => get_permalink() . "?paged=%#%",
                        "format" => "?paged=%#%",
                        "current" => $paged,
                        "total" => $transactions_query->max_num_pages,
                    ]);
                    ?>
                </div>

            <?php else: ?>
                <p>No transactions found.</p>
            <?php endif; ?>

            <?php wp_reset_postdata(); ?>
        </div>

        <style>
        .dollarbets-transaction-history .dollarbets-table {
            width: 100%;
            border-collapse: collapse;
        }
        .dollarbets-transaction-history th, .dollarbets-transaction-history td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }
        .dollarbets-transaction-history th {
            background: #f8f9fa;
        }
        .dollarbets-transaction-history .positive {
            color: #28a745;
            font-weight: bold;
        }
        .dollarbets-transaction-history .negative {
            color: #dc3545;
            font-weight: bold;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            color: white;
            text-transform: capitalize;
        }
        .status-completed { background: #28a745; }
        .status-pending { background: #ffc107; }
        .status-failed { background: #dc3545; }
        .status-refunded { background: #6c757d; }
        .pagination {
            margin-top: 20px;
        }
        .pagination .page-numbers {
            padding: 5px 10px;
            border: 1px solid #ddd;
            margin: 0 2px;
            text-decoration: none;
        }
        .pagination .page-numbers.current {
            background: #007cba;
            color: white;
            border-color: #007cba;
        }
        body.dark-mode .dollarbets-transaction-history th {
            background: #3c3c3c;
            border-bottom-color: #555;
        }
        body.dark-mode .dollarbets-transaction-history td {
            border-bottom-color: #555;
        }
        </style>
        <?php
        echo ob_get_clean();
    }
}

// Initialize transaction history system
new DollarBets_Transaction_History();

/**
 * Log a transaction
 * @param int $user_id
 * @param string $type
 * @param float $amount
 * @param string $description
 * @param array $extra_data
 * @return int|WP_Error Post ID on success, WP_Error on failure
 */
function db_log_transaction($user_id, $type, $amount, $description, $extra_data = []) {
    $post_data = [
        "post_type" => "db_transaction",
        "post_title" => "{$type} - User {$user_id} - " . date("Y-m-d H:i:s"),
        "post_status" => "publish",
        "post_author" => $user_id,
    ];

    $post_id = wp_insert_post($post_data);

    if (is_wp_error($post_id)) {
        return $post_id;
    }

    $transaction_details = array_merge([
        "user_id" => $user_id,
        "type" => $type,
        "amount" => $amount,
        "description" => $description,
        "status" => "completed",
    ], $extra_data);

    update_post_meta($post_id, "_transaction_details", $transaction_details);

    return $post_id;
}

/**
 * Override the old log_transaction function from payment-gateway.php
 */
if (!function_exists("log_transaction")) {
    function log_transaction($user_id, $gateway, $transaction_id, $betcoins, $amount) {
        return db_log_transaction($user_id, "purchase", $betcoins, "BetCoins purchase via {$gateway}", [
            "gateway" => $gateway,
            "transaction_id" => $transaction_id,
            "currency_amount" => $amount,
        ]);
    }
}


