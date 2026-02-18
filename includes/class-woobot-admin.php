<?php
/**
 * WooBot Admin - WordPress admin dashboard.
 *
 * @package WooBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooBot_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_woobot_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_woobot_regenerate_key', array( $this, 'ajax_regenerate_key' ) );
        add_action( 'wp_ajax_woobot_clear_logs', array( $this, 'ajax_clear_logs' ) );
        add_action( 'wp_ajax_woobot_export_logs', array( $this, 'ajax_export_logs' ) );
        add_action( 'wp_ajax_woobot_sync_credits', array( $this, 'ajax_sync_credits' ) );
        add_action( 'wp_ajax_woobot_register_store', array( $this, 'ajax_register_store' ) );
    }

    public function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'WooBot Dashboard', 'woobot-whatsapp-uploader' ),
            __( '🤖 WooBot', 'woobot-whatsapp-uploader' ),
            'manage_woocommerce',
            'woobot-dashboard',
            array( $this, 'render_dashboard' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'woocommerce_page_woobot-dashboard' !== $hook ) {
            return;
        }

        wp_enqueue_style( 'woobot-admin', WOOBOT_PLUGIN_URL . 'admin/css/woobot-admin.css', array(), WOOBOT_VERSION );
        wp_enqueue_script( 'woobot-admin', WOOBOT_PLUGIN_URL . 'admin/js/woobot-admin.js', array( 'jquery' ), WOOBOT_VERSION, true );

        wp_localize_script( 'woobot-admin', 'woobotAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'woobot_admin_nonce' ),
            'strings' => array(
                'confirmRegenerate' => __( 'Are you sure? This will invalidate the current API key.', 'woobot-whatsapp-uploader' ),
                'confirmClearLogs'  => __( 'Are you sure you want to delete all logs?', 'woobot-whatsapp-uploader' ),
                'saved'             => __( 'Settings saved successfully.', 'woobot-whatsapp-uploader' ),
                'error'             => __( 'An error occurred. Please try again.', 'woobot-whatsapp-uploader' ),
                'copied'            => __( 'Copied!', 'woobot-whatsapp-uploader' ),
                'syncing'           => __( 'Syncing...', 'woobot-whatsapp-uploader' ),
                'syncSuccess'       => __( 'Credits synced!', 'woobot-whatsapp-uploader' ),
                'syncFailed'        => __( 'Sync failed. Check server URL and API key.', 'woobot-whatsapp-uploader' ),
                'registering'       => __( 'Registering...', 'woobot-whatsapp-uploader' ),
                'registerSuccess'   => __( 'Store registered!', 'woobot-whatsapp-uploader' ),
                'registerFailed'    => __( 'Registration failed.', 'woobot-whatsapp-uploader' ),
            ),
        ) );

        // Auto-sync credits on dashboard page load
        WooBot_Credits::sync();
    }

    public function render_dashboard() {
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'dashboard';
        $settings = WooBot_Settings::get_all();
        $connection = WooBot_Settings::check_connection();
        $is_connected = ! empty( $settings['api_key'] ) && ! empty( $settings['store_key'] );
        ?>
        <div class="wrap woobot-wrap" dir="rtl">
            <div class="woobot-header">
                <h1 class="woobot-logo">
                    <span class="woobot-logo-icon">🤖</span>
                    WooBot
                    <span class="woobot-subtitle"><?php esc_html_e( 'מווטסאפ למדף', 'woobot-whatsapp-uploader' ); ?></span>
                </h1>
            </div>

            <nav class="nav-tab-wrapper woobot-tabs">
                <a href="?page=woobot-dashboard&tab=dashboard" class="nav-tab <?php echo 'dashboard' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Dashboard', 'woobot-whatsapp-uploader' ); ?>
                </a>
                <a href="?page=woobot-dashboard&tab=settings" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Settings', 'woobot-whatsapp-uploader' ); ?>
                </a>
                <a href="?page=woobot-dashboard&tab=logs" class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Logs', 'woobot-whatsapp-uploader' ); ?>
                </a>
                <a href="?page=woobot-dashboard&tab=help" class="nav-tab <?php echo 'help' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Help', 'woobot-whatsapp-uploader' ); ?>
                </a>
            </nav>

            <div class="woobot-content">
                <?php
                switch ( $active_tab ) {
                    case 'settings': $this->render_settings_tab( $settings ); break;
                    case 'logs': $this->render_logs_tab(); break;
                    case 'help': $this->render_help_tab( $settings ); break;
                    default: $this->render_dashboard_tab( $settings, $connection ); break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_dashboard_tab( $settings, $connection ) {
        $balance = WooBot_Credits::get_balance();
        ?>
        <!-- Connection Status -->
        <div class="woobot-card">
            <h2><?php esc_html_e( 'Connection Status', 'woobot-whatsapp-uploader' ); ?></h2>
            <div class="woobot-status-grid">
                <div class="woobot-status-item <?php echo $connection['woocommerce'] ? 'status-ok' : 'status-error'; ?>">
                    <span class="status-icon"><?php echo $connection['woocommerce'] ? '✅' : '❌'; ?></span>
                    <span>WooCommerce</span>
                </div>
                <div class="woobot-status-item <?php echo $connection['server_url'] ? 'status-ok' : 'status-error'; ?>">
                    <span class="status-icon"><?php echo $connection['server_url'] ? '✅' : '❌'; ?></span>
                    <span><?php esc_html_e( 'Server URL', 'woobot-whatsapp-uploader' ); ?></span>
                </div>
                <div class="woobot-status-item <?php echo $connection['api_key'] ? 'status-ok' : 'status-error'; ?>">
                    <span class="status-icon"><?php echo $connection['api_key'] ? '✅' : '❌'; ?></span>
                    <span><?php esc_html_e( 'API Key', 'woobot-whatsapp-uploader' ); ?></span>
                </div>
                <div class="woobot-status-item <?php echo $connection['server_connected'] ? 'status-ok' : 'status-warning'; ?>">
                    <span class="status-icon"><?php echo $connection['server_connected'] ? '✅' : '⚠️'; ?></span>
                    <span><?php esc_html_e( 'WooBot Server', 'woobot-whatsapp-uploader' ); ?></span>
                </div>
            </div>
        </div>

        <!-- Store Key & WhatsApp Link -->
        <div class="woobot-card">
            <h2><?php esc_html_e( 'Store Key & WhatsApp Link', 'woobot-whatsapp-uploader' ); ?></h2>
            <?php if ( ! empty( $settings['store_name'] ) ) : ?>
                <p class="woobot-store-name">
                    <?php printf( esc_html__( 'Store: %s', 'woobot-whatsapp-uploader' ), '<strong>' . esc_html( $settings['store_name'] ) . '</strong>' ); ?>
                    <?php if ( ! empty( $settings['subscription_tier'] ) ) : ?>
                        — <span class="woobot-badge badge-action"><?php echo esc_html( $settings['subscription_tier'] ); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <div class="woobot-field-group">
                <label><?php esc_html_e( 'Store Key', 'woobot-whatsapp-uploader' ); ?></label>
                <div class="woobot-copy-field">
                    <input type="text" value="<?php echo esc_attr( $settings['store_key'] ); ?>" readonly id="woobot-store-key" />
                    <button type="button" class="button woobot-copy-btn" data-target="woobot-store-key"><?php esc_html_e( 'Copy', 'woobot-whatsapp-uploader' ); ?></button>
                </div>
            </div>
            <div class="woobot-field-group">
                <label><?php esc_html_e( 'WhatsApp Link', 'woobot-whatsapp-uploader' ); ?></label>
                <div class="woobot-copy-field">
                    <input type="text" value="<?php echo esc_attr( WooBot_Settings::get_whatsapp_link() ); ?>" readonly id="woobot-wa-link" />
                    <button type="button" class="button woobot-copy-btn" data-target="woobot-wa-link"><?php esc_html_e( 'Copy', 'woobot-whatsapp-uploader' ); ?></button>
                </div>
                <p class="description"><?php esc_html_e( 'Share this link with the store owner to connect them to the WooBot WhatsApp bot.', 'woobot-whatsapp-uploader' ); ?></p>
            </div>
        </div>

        <!-- Credit Balance -->
        <div class="woobot-card">
            <h2>
                <?php esc_html_e( 'Credit Balance', 'woobot-whatsapp-uploader' ); ?>
                <button type="button" class="button button-small woobot-sync-btn" id="woobot-sync-credits" style="margin-right:10px;">
                    🔄 <?php esc_html_e( 'Sync', 'woobot-whatsapp-uploader' ); ?>
                </button>
                <span id="woobot-sync-status" class="woobot-inline-status"></span>
            </h2>
            <div class="woobot-credits-grid">
                <div class="woobot-credit-box">
                    <span class="credit-number" id="woobot-listing-credits"><?php echo esc_html( $balance['listing'] ); ?></span>
                    <span class="credit-label"><?php esc_html_e( 'Listing Credits', 'woobot-whatsapp-uploader' ); ?></span>
                </div>
                <div class="woobot-credit-box">
                    <span class="credit-number" id="woobot-enhancement-credits"><?php echo esc_html( $balance['enhancement'] ); ?></span>
                    <span class="credit-label"><?php esc_html_e( 'Enhancement Credits', 'woobot-whatsapp-uploader' ); ?></span>
                </div>
            </div>
            <?php if ( ! empty( $balance['last_sync'] ) ) : ?>
                <p class="woobot-credits-updated">
                    <?php printf( esc_html__( 'Last synced: %s', 'woobot-whatsapp-uploader' ), esc_html( $balance['last_sync'] ) ); ?>
                </p>
            <?php endif; ?>
            <a href="<?php echo esc_url( WooBot_Credits::get_purchase_url() ); ?>" class="button button-primary woobot-buy-credits" target="_blank">
                <?php esc_html_e( 'Buy Credits', 'woobot-whatsapp-uploader' ); ?>
            </a>
        </div>

        <!-- Quick Stats -->
        <div class="woobot-card">
            <h2><?php esc_html_e( 'Quick Stats', 'woobot-whatsapp-uploader' ); ?></h2>
            <div class="woobot-stats-grid">
                <div class="woobot-stat-box">
                    <span class="stat-number"><?php echo esc_html( $settings['total_uploads'] ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Products Uploaded', 'woobot-whatsapp-uploader' ); ?></span>
                </div>
                <div class="woobot-stat-box">
                    <span class="stat-number"><?php echo esc_html( $settings['total_enhanced'] ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Images Enhanced', 'woobot-whatsapp-uploader' ); ?></span>
                </div>
                <div class="woobot-stat-box">
                    <span class="stat-value"><?php echo ! empty( $settings['last_upload'] ) ? esc_html( $settings['last_upload'] ) : esc_html__( 'Never', 'woobot-whatsapp-uploader' ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Last Upload', 'woobot-whatsapp-uploader' ); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_settings_tab( $settings ) {
        ?>
        <div class="woobot-card">
            <h2><?php esc_html_e( 'Server Connection', 'woobot-whatsapp-uploader' ); ?></h2>

            <!-- Server URL -->
            <div class="woobot-field-group">
                <label for="woobot-server-url"><?php esc_html_e( 'Server URL (API Base URL)', 'woobot-whatsapp-uploader' ); ?></label>
                <input type="url" id="woobot-server-url" name="server_url" value="<?php echo esc_attr( $settings['server_url'] ); ?>" placeholder="https://your-server.replit.dev" style="max-width:600px;" />
                <p class="description"><?php esc_html_e( 'The WooBot server URL provided by your administrator.', 'woobot-whatsapp-uploader' ); ?></p>
            </div>

            <!-- API Key -->
            <div class="woobot-field-group">
                <label><?php esc_html_e( 'API Key', 'woobot-whatsapp-uploader' ); ?></label>
                <div class="woobot-copy-field">
                    <input type="password" value="<?php echo esc_attr( $settings['api_key'] ); ?>" readonly id="woobot-api-key" />
                    <button type="button" class="button woobot-toggle-visibility" data-target="woobot-api-key"><?php esc_html_e( 'Show', 'woobot-whatsapp-uploader' ); ?></button>
                    <button type="button" class="button woobot-copy-btn" data-target="woobot-api-key"><?php esc_html_e( 'Copy', 'woobot-whatsapp-uploader' ); ?></button>
                </div>
                <p class="description"><?php esc_html_e( 'This key authenticates your store with the WooBot server. Do not change unless instructed.', 'woobot-whatsapp-uploader' ); ?></p>
            </div>

            <!-- Store Key (read-only) -->
            <div class="woobot-field-group">
                <label><?php esc_html_e( 'Store Key', 'woobot-whatsapp-uploader' ); ?></label>
                <div class="woobot-copy-field">
                    <input type="text" value="<?php echo esc_attr( $settings['store_key'] ); ?>" readonly id="woobot-store-key-settings" />
                    <button type="button" class="button woobot-copy-btn" data-target="woobot-store-key-settings"><?php esc_html_e( 'Copy', 'woobot-whatsapp-uploader' ); ?></button>
                </div>
            </div>

            <!-- Register New Store -->
            <?php if ( empty( $settings['api_key'] ) || empty( $settings['store_key'] ) ) : ?>
                <div class="woobot-register-section">
                    <h3><?php esc_html_e( 'Register New Store', 'woobot-whatsapp-uploader' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'No API Key yet? Enter your Partner ID and click Register to connect this store.', 'woobot-whatsapp-uploader' ); ?></p>
                    <div class="woobot-field-group">
                        <label for="woobot-register-partner-id"><?php esc_html_e( 'Partner ID', 'woobot-whatsapp-uploader' ); ?></label>
                        <input type="text" id="woobot-register-partner-id" placeholder="WOOBOT-YK-001" value="<?php echo esc_attr( $settings['partner_id'] ); ?>" />
                    </div>
                    <button type="button" class="button button-primary" id="woobot-register-btn">
                        <?php esc_html_e( '🔗 Register Store', 'woobot-whatsapp-uploader' ); ?>
                    </button>
                    <span id="woobot-register-status" class="woobot-inline-status"></span>
                </div>
            <?php endif; ?>

            <!-- Test Connection -->
            <div style="margin-top:16px;">
                <button type="button" class="button" id="woobot-sync-credits-settings">
                    🔄 <?php esc_html_e( 'Test Connection & Sync Credits', 'woobot-whatsapp-uploader' ); ?>
                </button>
                <span id="woobot-sync-status-settings" class="woobot-inline-status"></span>
            </div>
        </div>

        <div class="woobot-card">
            <h2><?php esc_html_e( 'Product Settings', 'woobot-whatsapp-uploader' ); ?></h2>

            <div class="woobot-field-group">
                <label for="woobot-partner-id"><?php esc_html_e( 'Partner ID', 'woobot-whatsapp-uploader' ); ?></label>
                <input type="text" id="woobot-partner-id" name="partner_id" value="<?php echo esc_attr( $settings['partner_id'] ); ?>" />
                <p class="description"><?php esc_html_e( 'Your partner ID for commission tracking.', 'woobot-whatsapp-uploader' ); ?></p>
            </div>

            <div class="woobot-field-group">
                <label for="woobot-default-status"><?php esc_html_e( 'Default Product Status', 'woobot-whatsapp-uploader' ); ?></label>
                <select id="woobot-default-status" name="default_status">
                    <option value="draft" <?php selected( $settings['default_status'], 'draft' ); ?>><?php esc_html_e( 'Draft', 'woobot-whatsapp-uploader' ); ?></option>
                    <option value="publish" <?php selected( $settings['default_status'], 'publish' ); ?>><?php esc_html_e( 'Published', 'woobot-whatsapp-uploader' ); ?></option>
                    <option value="pending" <?php selected( $settings['default_status'], 'pending' ); ?>><?php esc_html_e( 'Pending Review', 'woobot-whatsapp-uploader' ); ?></option>
                </select>
            </div>

            <div class="woobot-field-group">
                <label for="woobot-max-file-size"><?php esc_html_e( 'Maximum File Size (MB)', 'woobot-whatsapp-uploader' ); ?></label>
                <input type="number" id="woobot-max-file-size" name="max_file_size" value="<?php echo esc_attr( $settings['max_file_size'] / 1048576 ); ?>" min="1" max="50" />
            </div>

            <div class="woobot-field-group">
                <label for="woobot-rate-limit"><?php esc_html_e( 'Rate Limit (requests/minute)', 'woobot-whatsapp-uploader' ); ?></label>
                <input type="number" id="woobot-rate-limit" name="rate_limit" value="<?php echo esc_attr( $settings['rate_limit'] ); ?>" min="0" max="300" />
                <p class="description"><?php esc_html_e( 'Set to 0 to disable.', 'woobot-whatsapp-uploader' ); ?></p>
            </div>

            <div class="woobot-field-group">
                <label for="woobot-log-retention"><?php esc_html_e( 'Log Retention (days)', 'woobot-whatsapp-uploader' ); ?></label>
                <input type="number" id="woobot-log-retention" name="log_retention" value="<?php echo esc_attr( $settings['log_retention'] ); ?>" min="1" max="365" />
            </div>

            <button type="button" class="button button-primary" id="woobot-save-settings"><?php esc_html_e( 'Save Settings', 'woobot-whatsapp-uploader' ); ?></button>
            <span id="woobot-save-status" class="woobot-inline-status"></span>
        </div>
        <?php
    }

    private function render_logs_tab() {
        $logger = new WooBot_Logger();
        $args = array(
            'per_page'    => 20,
            'page'        => isset( $_GET['log_page'] ) ? max( 1, intval( $_GET['log_page'] ) ) : 1,
            'action_type' => isset( $_GET['action_type'] ) ? sanitize_text_field( wp_unslash( $_GET['action_type'] ) ) : '',
            'status'      => isset( $_GET['log_status'] ) ? sanitize_text_field( wp_unslash( $_GET['log_status'] ) ) : '',
            'date_from'   => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
            'date_to'     => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
        );
        $result = $logger->get_logs( $args );
        $total_pages = ceil( $result['total'] / $args['per_page'] );
        ?>
        <div class="woobot-card">
            <h2><?php esc_html_e( 'API Activity Logs', 'woobot-whatsapp-uploader' ); ?></h2>
            <div class="woobot-log-filters">
                <select id="woobot-filter-action">
                    <option value=""><?php esc_html_e( 'All Actions', 'woobot-whatsapp-uploader' ); ?></option>
                    <option value="upload_image" <?php selected( $args['action_type'], 'upload_image' ); ?>><?php esc_html_e( 'Upload Image', 'woobot-whatsapp-uploader' ); ?></option>
                    <option value="create_product" <?php selected( $args['action_type'], 'create_product' ); ?>><?php esc_html_e( 'Create Product', 'woobot-whatsapp-uploader' ); ?></option>
                    <option value="full_upload" <?php selected( $args['action_type'], 'full_upload' ); ?>><?php esc_html_e( 'Full Upload', 'woobot-whatsapp-uploader' ); ?></option>
                </select>
                <select id="woobot-filter-status">
                    <option value=""><?php esc_html_e( 'All Statuses', 'woobot-whatsapp-uploader' ); ?></option>
                    <option value="success" <?php selected( $args['status'], 'success' ); ?>><?php esc_html_e( 'Success', 'woobot-whatsapp-uploader' ); ?></option>
                    <option value="error" <?php selected( $args['status'], 'error' ); ?>><?php esc_html_e( 'Error', 'woobot-whatsapp-uploader' ); ?></option>
                </select>
                <input type="date" id="woobot-filter-from" value="<?php echo esc_attr( $args['date_from'] ); ?>" />
                <input type="date" id="woobot-filter-to" value="<?php echo esc_attr( $args['date_to'] ); ?>" />
                <button type="button" class="button" id="woobot-apply-filters"><?php esc_html_e( 'Filter', 'woobot-whatsapp-uploader' ); ?></button>
                <button type="button" class="button" id="woobot-export-logs"><?php esc_html_e( 'Export CSV', 'woobot-whatsapp-uploader' ); ?></button>
                <button type="button" class="button woobot-danger-btn" id="woobot-clear-logs"><?php esc_html_e( 'Clear Logs', 'woobot-whatsapp-uploader' ); ?></button>
            </div>
            <table class="wp-list-table widefat fixed striped woobot-logs-table">
                <thead><tr>
                    <th class="column-id">ID</th>
                    <th><?php esc_html_e( 'Time', 'woobot-whatsapp-uploader' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'woobot-whatsapp-uploader' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'woobot-whatsapp-uploader' ); ?></th>
                    <th><?php esc_html_e( 'Response Time', 'woobot-whatsapp-uploader' ); ?></th>
                    <th><?php esc_html_e( 'Product/Image', 'woobot-whatsapp-uploader' ); ?></th>
                    <th><?php esc_html_e( 'Error', 'woobot-whatsapp-uploader' ); ?></th>
                </tr></thead>
                <tbody>
                    <?php if ( empty( $result['logs'] ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No logs found.', 'woobot-whatsapp-uploader' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $result['logs'] as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( $log->id ); ?></td>
                                <td><?php echo esc_html( $log->created_at ); ?></td>
                                <td><span class="woobot-badge badge-action"><?php echo esc_html( $log->action_type ); ?></span></td>
                                <td><span class="woobot-badge badge-<?php echo esc_attr( $log->status ); ?>"><?php echo esc_html( $log->status ); ?></span></td>
                                <td><?php echo esc_html( number_format( $log->response_time, 3 ) ); ?>s</td>
                                <td>
                                    <?php if ( $log->product_id ) : ?>
                                        <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $log->product_id . '&action=edit' ) ); ?>">#<?php echo esc_html( $log->product_id ); ?></a>
                                    <?php endif; ?>
                                    <?php if ( $log->image_id ) : ?>📷 #<?php echo esc_html( $log->image_id ); ?><?php endif; ?>
                                </td>
                                <td class="woobot-error-cell"><?php echo esc_html( $log->error_message ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ( $total_pages > 1 ) : ?>
                <div class="woobot-pagination">
                    <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                        <a href="<?php echo esc_url( add_query_arg( 'log_page', $i, admin_url( 'admin.php?page=woobot-dashboard&tab=logs' ) ) ); ?>" class="button <?php echo $i === $args['page'] ? 'current' : ''; ?>"><?php echo esc_html( $i ); ?></a>
                    <?php endfor; ?>
                    <span class="woobot-total-logs"><?php printf( esc_html__( 'Total: %d', 'woobot-whatsapp-uploader' ), $result['total'] ); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_help_tab( $settings ) {
        $site_url = home_url();
        $api_key = $settings['api_key'];
        ?>
        <div class="woobot-card">
            <h2><?php esc_html_e( 'Integration Guide', 'woobot-whatsapp-uploader' ); ?></h2>

            <h3><?php esc_html_e( 'Quick Start', 'woobot-whatsapp-uploader' ); ?></h3>
            <ol class="woobot-steps">
                <li><?php esc_html_e( 'Go to Settings and enter the Server URL.', 'woobot-whatsapp-uploader' ); ?></li>
                <li><?php esc_html_e( 'If you have an API Key, it will be pre-filled. Otherwise, enter your Partner ID and click Register.', 'woobot-whatsapp-uploader' ); ?></li>
                <li><?php esc_html_e( 'Click "Test Connection & Sync Credits" to verify everything works.', 'woobot-whatsapp-uploader' ); ?></li>
                <li><?php esc_html_e( 'Copy the WhatsApp Link from the Dashboard and share it with the store owner.', 'woobot-whatsapp-uploader' ); ?></li>
            </ol>

            <h3><?php esc_html_e( 'API Endpoints', 'woobot-whatsapp-uploader' ); ?></h3>

            <h4>1. <?php esc_html_e( 'Full Upload (Image + Product)', 'woobot-whatsapp-uploader' ); ?></h4>
            <pre class="woobot-code"><code>POST <?php echo esc_html( $site_url ); ?>/wp-json/woobot/v1/full-upload
Content-Type: application/json
X-WooBot-Key: <?php echo esc_html( $api_key ); ?>

{
    "image_url": "https://example.com/photo.jpg",
    "name": "Product Name",
    "price": 99.90,
    "description": "Description",
    "stock_quantity": 10
}</code></pre>

            <h4>2. <?php esc_html_e( 'Check Status', 'woobot-whatsapp-uploader' ); ?></h4>
            <pre class="woobot-code"><code>GET <?php echo esc_html( $site_url ); ?>/wp-json/woobot/v1/status
X-WooBot-Key: <?php echo esc_html( $api_key ); ?></code></pre>

            <h3><?php esc_html_e( 'Troubleshooting', 'woobot-whatsapp-uploader' ); ?></h3>
            <div class="woobot-faq">
                <details>
                    <summary><?php esc_html_e( 'Credits show 0?', 'woobot-whatsapp-uploader' ); ?></summary>
                    <p><?php esc_html_e( 'Click the Sync button on the Dashboard or check that Server URL and API Key are correct in Settings.', 'woobot-whatsapp-uploader' ); ?></p>
                </details>
                <details>
                    <summary><?php esc_html_e( 'Server connection shows warning?', 'woobot-whatsapp-uploader' ); ?></summary>
                    <p><?php esc_html_e( 'Verify the Server URL is correct and accessible. Go to Settings and use "Test Connection".', 'woobot-whatsapp-uploader' ); ?></p>
                </details>
                <details>
                    <summary><?php esc_html_e( 'REST API returns 404?', 'woobot-whatsapp-uploader' ); ?></summary>
                    <p><?php esc_html_e( 'Make sure pretty permalinks are enabled (Settings > Permalinks) and WooCommerce is active.', 'woobot-whatsapp-uploader' ); ?></p>
                </details>
            </div>
        </div>
        <?php
    }

    // ========== AJAX Handlers ==========

    public function ajax_save_settings() {
        check_ajax_referer( 'woobot_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Permission denied.' ); }

        $settings = array();
        if ( isset( $_POST['server_url'] ) )     $settings['server_url']     = sanitize_url( wp_unslash( $_POST['server_url'] ) );
        if ( isset( $_POST['partner_id'] ) )      $settings['partner_id']     = sanitize_text_field( wp_unslash( $_POST['partner_id'] ) );
        if ( isset( $_POST['default_status'] ) )  $settings['default_status'] = sanitize_text_field( wp_unslash( $_POST['default_status'] ) );
        if ( isset( $_POST['max_file_size'] ) )   $settings['max_file_size']  = intval( $_POST['max_file_size'] ) * 1048576;
        if ( isset( $_POST['rate_limit'] ) )      $settings['rate_limit']     = intval( $_POST['rate_limit'] );
        if ( isset( $_POST['log_retention'] ) )   $settings['log_retention']  = intval( $_POST['log_retention'] );

        WooBot_Settings::update( $settings );
        wp_send_json_success( __( 'Settings saved.', 'woobot-whatsapp-uploader' ) );
    }

    public function ajax_regenerate_key() {
        check_ajax_referer( 'woobot_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Permission denied.' ); }
        $new_key = WooBot_Settings::regenerate_api_key();
        wp_send_json_success( array( 'key' => $new_key ) );
    }

    public function ajax_clear_logs() {
        check_ajax_referer( 'woobot_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Permission denied.' ); }
        ( new WooBot_Logger() )->clear_logs();
        wp_send_json_success();
    }

    public function ajax_export_logs() {
        check_ajax_referer( 'woobot_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Permission denied.' ); }
        wp_send_json_success( array( 'csv' => ( new WooBot_Logger() )->export_csv() ) );
    }

    public function ajax_sync_credits() {
        check_ajax_referer( 'woobot_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Permission denied.' ); }

        $result = WooBot_Credits::sync();

        if ( false === $result ) {
            wp_send_json_error( __( 'Sync failed. Check Server URL and API Key.', 'woobot-whatsapp-uploader' ) );
        }

        wp_send_json_success( array(
            'listing'     => intval( get_option( 'woobot_listing_credits', 0 ) ),
            'enhancement' => intval( get_option( 'woobot_enhancement_credits', 0 ) ),
            'storeName'   => get_option( 'woobot_store_name', '' ),
        ) );
    }

    public function ajax_register_store() {
        check_ajax_referer( 'woobot_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_send_json_error( 'Permission denied.' ); }

        $partner_id = isset( $_POST['partner_id'] ) ? sanitize_text_field( wp_unslash( $_POST['partner_id'] ) ) : '';

        if ( empty( $partner_id ) ) {
            wp_send_json_error( __( 'Partner ID is required.', 'woobot-whatsapp-uploader' ) );
        }

        // Save partner ID
        update_option( 'woobot_partner_id', $partner_id );

        $result = WooBot_Credits::register_store( $partner_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'storeKey'     => get_option( 'woobot_store_key', '' ),
            'apiKey'       => get_option( 'woobot_api_key', '' ),
            'whatsappLink' => get_option( 'woobot_whatsapp_link', '' ),
        ) );
    }
}
