<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://the-restart.co
 * @since      1.0.0
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/admin
 */

class Restart_Registry_Admin {

    private $plugin_name;
    private $version;

    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function enqueue_styles() {
        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/restart-registry-admin.css', array(), $this->version, 'all');
    }

    public function enqueue_scripts() {
        wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/restart-registry-admin.js', array('jquery'), $this->version, false);
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Gift Registry', 'restart-registry'),
            __('Gift Registry', 'restart-registry'),
            'manage_options',
            'restart-registry',
            array($this, 'display_dashboard_page'),
            'dashicons-heart',
            30
        );

        add_submenu_page(
            'restart-registry',
            __('Dashboard', 'restart-registry'),
            __('Dashboard', 'restart-registry'),
            'manage_options',
            'restart-registry',
            array($this, 'display_dashboard_page')
        );

        add_submenu_page(
            'restart-registry',
            __('All Registries', 'restart-registry'),
            __('All Registries', 'restart-registry'),
            'manage_options',
            'restart-registry-list',
            array($this, 'display_registries_page')
        );

        add_submenu_page(
            'restart-registry',
            __('Affiliate Settings', 'restart-registry'),
            __('Affiliate Settings', 'restart-registry'),
            'manage_options',
            'restart-registry-affiliates',
            array($this, 'display_affiliates_page')
        );

        add_submenu_page(
            'restart-registry',
            __('Settings', 'restart-registry'),
            __('Settings', 'restart-registry'),
            'manage_options',
            'restart-registry-settings',
            array($this, 'display_settings_page')
        );
    }

    public function register_settings() {
        register_setting('restart_registry_affiliates', 'restart_registry_amazon_tag');
        register_setting('restart_registry_affiliates', 'restart_registry_target_id');
        register_setting('restart_registry_affiliates', 'restart_registry_walmart_id');
        register_setting('restart_registry_affiliates', 'restart_registry_etsy_id');
        register_setting('restart_registry_affiliates', 'restart_registry_ebay_id');
        register_setting('restart_registry_affiliates', 'restart_registry_bestbuy_id');
        register_setting('restart_registry_affiliates', 'restart_registry_homedepot_id');
        register_setting('restart_registry_affiliates', 'restart_registry_wayfair_id');
        register_setting('restart_registry_affiliates', 'restart_registry_shareasale_id');
        register_setting('restart_registry_affiliates', 'restart_registry_shareasale_merchant');
        register_setting('restart_registry_affiliates', 'restart_registry_cj_id');
        register_setting('restart_registry_affiliates', 'restart_registry_affiliate_disclosure');

        register_setting('restart_registry_settings', 'restart_registry_page_id');
        register_setting('restart_registry_settings', 'restart_registry_email_from');
        register_setting('restart_registry_settings', 'restart_registry_email_name');
        register_setting('restart_registry_settings', 'restart_registry_allow_guests');

        add_settings_section(
            'restart_registry_affiliate_section',
            __('Affiliate Program IDs', 'restart-registry'),
            array($this, 'affiliate_section_callback'),
            'restart_registry_affiliates'
        );

        $affiliate_fields = array(
            'amazon_tag' => array('label' => 'Amazon Associates Tag', 'description' => 'Your Amazon Associates tag (e.g., yourtag-20)'),
            'target_id' => array('label' => 'Target Affiliate ID', 'description' => 'Your Target affiliate ID'),
            'walmart_id' => array('label' => 'Walmart Affiliate ID', 'description' => 'Your Walmart affiliate ID'),
            'etsy_id' => array('label' => 'Etsy Affiliate ID', 'description' => 'Your Etsy affiliate ID'),
            'ebay_id' => array('label' => 'eBay Campaign ID', 'description' => 'Your eBay Partner Network campaign ID'),
            'bestbuy_id' => array('label' => 'Best Buy Affiliate ID', 'description' => 'Your Best Buy affiliate ID'),
            'homedepot_id' => array('label' => 'Home Depot Affiliate ID', 'description' => 'Your Home Depot affiliate ID'),
            'wayfair_id' => array('label' => 'Wayfair Affiliate ID', 'description' => 'Your Wayfair affiliate ID'),
        );

        foreach ($affiliate_fields as $key => $field) {
            add_settings_field(
                'restart_registry_' . $key,
                $field['label'],
                array($this, 'text_field_callback'),
                'restart_registry_affiliates',
                'restart_registry_affiliate_section',
                array(
                    'label_for' => 'restart_registry_' . $key,
                    'description' => $field['description'],
                )
            );
        }

        add_settings_field(
            'restart_registry_affiliate_disclosure',
            __('Affiliate Disclosure', 'restart-registry'),
            array($this, 'textarea_field_callback'),
            'restart_registry_affiliates',
            'restart_registry_affiliate_section',
            array(
                'label_for' => 'restart_registry_affiliate_disclosure',
                'description' => __('This disclosure will be shown on registry pages to comply with FTC guidelines.', 'restart-registry'),
            )
        );
    }

    public function affiliate_section_callback() {
        echo '<p>' . __('Enter your affiliate program IDs below. When users add products from these retailers, the links will automatically be converted to affiliate links. This is done transparently - users can see the original link and affiliate link.', 'restart-registry') . '</p>';
    }

    public function text_field_callback($args) {
        $option = get_option($args['label_for']);
        ?>
        <input type="text" 
               id="<?php echo esc_attr($args['label_for']); ?>" 
               name="<?php echo esc_attr($args['label_for']); ?>" 
               value="<?php echo esc_attr($option); ?>" 
               class="regular-text">
        <?php if (isset($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    public function textarea_field_callback($args) {
        $option = get_option($args['label_for']);
        $default = __('Some links on this registry are affiliate links. When you purchase through these links, the registry owner may earn a small commission at no additional cost to you.', 'restart-registry');
        ?>
        <textarea id="<?php echo esc_attr($args['label_for']); ?>" 
                  name="<?php echo esc_attr($args['label_for']); ?>" 
                  rows="4" 
                  class="large-text"><?php echo esc_textarea($option ?: $default); ?></textarea>
        <?php if (isset($args['description'])): ?>
            <p class="description"><?php echo esc_html($args['description']); ?></p>
        <?php endif;
    }

    public function display_dashboard_page() {
        global $wpdb;
        
        $registries_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}restart_registries");
        $items_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}restart_registry_items");
        $purchases_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}restart_registry_purchases");
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="restart-registry-dashboard">
                <div class="dashboard-widgets">
                    <div class="dashboard-widget">
                        <h3><?php _e('Total Registries', 'restart-registry'); ?></h3>
                        <span class="count"><?php echo intval($registries_count); ?></span>
                    </div>
                    <div class="dashboard-widget">
                        <h3><?php _e('Total Items', 'restart-registry'); ?></h3>
                        <span class="count"><?php echo intval($items_count); ?></span>
                    </div>
                    <div class="dashboard-widget">
                        <h3><?php _e('Items Purchased', 'restart-registry'); ?></h3>
                        <span class="count"><?php echo intval($purchases_count); ?></span>
                    </div>
                </div>

                <div class="dashboard-recent">
                    <h2><?php _e('Recent Registries', 'restart-registry'); ?></h2>
                    <?php
                    $recent = $wpdb->get_results("
                        SELECT r.*, u.display_name 
                        FROM {$wpdb->prefix}restart_registries r
                        LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
                        ORDER BY r.created_at DESC 
                        LIMIT 5
                    ");
                    
                    if ($recent): ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php _e('Title', 'restart-registry'); ?></th>
                                    <th><?php _e('Owner', 'restart-registry'); ?></th>
                                    <th><?php _e('Items', 'restart-registry'); ?></th>
                                    <th><?php _e('Created', 'restart-registry'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent as $registry): 
                                    $item_count = $wpdb->get_var($wpdb->prepare(
                                        "SELECT COUNT(*) FROM {$wpdb->prefix}restart_registry_items WHERE registry_id = %d",
                                        $registry->id
                                    ));
                                ?>
                                    <tr>
                                        <td><?php echo esc_html($registry->title); ?></td>
                                        <td><?php echo esc_html($registry->display_name); ?></td>
                                        <td><?php echo intval($item_count); ?></td>
                                        <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($registry->created_at))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p><?php _e('No registries yet.', 'restart-registry'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function display_registries_page() {
        global $wpdb;
        
        $registries = $wpdb->get_results("
            SELECT r.*, u.display_name, u.user_email,
                   (SELECT COUNT(*) FROM {$wpdb->prefix}restart_registry_items WHERE registry_id = r.id) as item_count
            FROM {$wpdb->prefix}restart_registries r
            LEFT JOIN {$wpdb->users} u ON r.user_id = u.ID
            ORDER BY r.created_at DESC
        ");
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Title', 'restart-registry'); ?></th>
                        <th><?php _e('Owner', 'restart-registry'); ?></th>
                        <th><?php _e('Email', 'restart-registry'); ?></th>
                        <th><?php _e('Items', 'restart-registry'); ?></th>
                        <th><?php _e('Visibility', 'restart-registry'); ?></th>
                        <th><?php _e('Created', 'restart-registry'); ?></th>
                        <th><?php _e('Actions', 'restart-registry'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($registries): ?>
                        <?php foreach ($registries as $registry): ?>
                            <tr>
                                <td><strong><?php echo esc_html($registry->title); ?></strong></td>
                                <td><?php echo esc_html($registry->display_name); ?></td>
                                <td><?php echo esc_html($registry->user_email); ?></td>
                                <td><?php echo intval($registry->item_count); ?></td>
                                <td><?php echo $registry->is_public ? __('Public', 'restart-registry') : __('Private', 'restart-registry'); ?></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($registry->created_at))); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(add_query_arg('registry', $registry->share_key, home_url('/registry/'))); ?>" target="_blank"><?php _e('View', 'restart-registry'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7"><?php _e('No registries found.', 'restart-registry'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function display_affiliates_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="notice notice-info">
                <p><strong><?php _e('How Affiliate Links Work:', 'restart-registry'); ?></strong></p>
                <p><?php _e('When users add products from supported retailers, the plugin automatically converts the links to affiliate links using your IDs. The original link is preserved and users can see both - this ensures transparency and builds trust.', 'restart-registry'); ?></p>
            </div>

            <form action="options.php" method="post">
                <?php
                settings_fields('restart_registry_affiliates');
                do_settings_sections('restart_registry_affiliates');
                submit_button(__('Save Affiliate Settings', 'restart-registry'));
                ?>
            </form>

            <div class="supported-retailers">
                <h2><?php _e('Supported Retailers', 'restart-registry'); ?></h2>
                <ul>
                    <li><strong>Amazon</strong> - amazon.com, amazon.co.uk, amazon.ca, amazon.de, amazon.fr</li>
                    <li><strong>Target</strong> - target.com</li>
                    <li><strong>Walmart</strong> - walmart.com</li>
                    <li><strong>Etsy</strong> - etsy.com</li>
                    <li><strong>eBay</strong> - ebay.com, ebay.co.uk</li>
                    <li><strong>Best Buy</strong> - bestbuy.com</li>
                    <li><strong>Home Depot</strong> - homedepot.com</li>
                    <li><strong>Wayfair</strong> - wayfair.com</li>
                </ul>
                <p><?php _e('More retailers can be added through custom filters in your theme or plugin.', 'restart-registry'); ?></p>
            </div>
        </div>
        <?php
    }

    public function display_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('restart_registry_settings');
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="restart_registry_page_id"><?php _e('Registry Page', 'restart-registry'); ?></label>
                        </th>
                        <td>
                            <?php
                            wp_dropdown_pages(array(
                                'name' => 'restart_registry_page_id',
                                'selected' => get_option('restart_registry_page_id'),
                                'show_option_none' => __('— Select a page —', 'restart-registry'),
                            ));
                            ?>
                            <p class="description"><?php _e('Select the page where the [restart_registry] shortcode is placed.', 'restart-registry'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="restart_registry_email_from"><?php _e('Email From Address', 'restart-registry'); ?></label>
                        </th>
                        <td>
                            <input type="email" 
                                   id="restart_registry_email_from" 
                                   name="restart_registry_email_from" 
                                   value="<?php echo esc_attr(get_option('restart_registry_email_from', get_option('admin_email'))); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="restart_registry_email_name"><?php _e('Email From Name', 'restart-registry'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="restart_registry_email_name" 
                                   name="restart_registry_email_name" 
                                   value="<?php echo esc_attr(get_option('restart_registry_email_name', get_bloginfo('name'))); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Guest Purchases', 'restart-registry'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="restart_registry_allow_guests" 
                                       value="1" 
                                       <?php checked(get_option('restart_registry_allow_guests'), 1); ?>>
                                <?php _e('Allow guests to mark items as purchased without logging in', 'restart-registry'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>

            <hr>
            
            <h2><?php _e('Shortcodes', 'restart-registry'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><code>[restart_registry]</code></th>
                    <td><?php _e('Display the main registry interface (create/view/manage)', 'restart-registry'); ?></td>
                </tr>
                <tr>
                    <th><code>[restart_registry_view]</code></th>
                    <td><?php _e('Display a registry for viewing only (use with registry="share_key")', 'restart-registry'); ?></td>
                </tr>
                <tr>
                    <th><code>[restart_registry_create]</code></th>
                    <td><?php _e('Display the registry creation form', 'restart-registry'); ?></td>
                </tr>
            </table>
        </div>
        <?php
    }
}
