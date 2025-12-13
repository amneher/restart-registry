<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://the-restart.co
 * @since      1.0.0
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/public
 */

class Restart_Registry_Public {

    private $plugin_name;
    private $version;
    private $controller;

    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-restart-registry-controller.php';
        $this->controller = new Restart_Registry_Controller();

        add_shortcode('restart_registry', array($this, 'registry_shortcode'));
        add_shortcode('restart_registry_view', array($this, 'registry_view_shortcode'));
        add_shortcode('restart_registry_create', array($this, 'registry_create_shortcode'));

        add_action('wp_ajax_restart_registry_add_item', array($this, 'ajax_add_item'));
        add_action('wp_ajax_restart_registry_delete_item', array($this, 'ajax_delete_item'));
        add_action('wp_ajax_restart_registry_update_item', array($this, 'ajax_update_item'));
        add_action('wp_ajax_restart_registry_mark_purchased', array($this, 'ajax_mark_purchased'));
        add_action('wp_ajax_nopriv_restart_registry_mark_purchased', array($this, 'ajax_mark_purchased'));
        add_action('wp_ajax_restart_registry_send_invite', array($this, 'ajax_send_invite'));
        add_action('wp_ajax_restart_registry_create', array($this, 'ajax_create_registry'));
        add_action('wp_ajax_restart_registry_update', array($this, 'ajax_update_registry'));
        add_action('wp_ajax_restart_registry_fetch_url', array($this, 'ajax_fetch_url'));
    }

    public function enqueue_styles() {
        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/restart-registry-public.css', array(), $this->version, 'all');
    }

    public function enqueue_scripts() {
        wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/restart-registry-public.js', array('jquery'), $this->version, true);
        
        wp_localize_script($this->plugin_name, 'restartRegistry', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('restart_registry_nonce'),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to remove this item?', 'restart-registry'),
                'confirmPurchase' => __('Mark this item as purchased?', 'restart-registry'),
                'loading' => __('Loading...', 'restart-registry'),
                'error' => __('An error occurred. Please try again.', 'restart-registry'),
            ),
        ));
    }

    public function registry_shortcode($atts) {
        $atts = shortcode_atts(array(), $atts, 'restart_registry');

        if (isset($_GET['registry'])) {
            return $this->render_registry_view(sanitize_text_field($_GET['registry']));
        }

        if (!is_user_logged_in()) {
            return $this->render_login_prompt();
        }

        $user_id = get_current_user_id();
        $registry = $this->controller->get_user_registry($user_id);

        if (!$registry) {
            return $this->render_create_form();
        }

        return $this->render_manage_registry($registry);
    }

    public function registry_view_shortcode($atts) {
        $atts = shortcode_atts(array(
            'registry' => '',
        ), $atts, 'restart_registry_view');

        $share_key = !empty($atts['registry']) ? $atts['registry'] : (isset($_GET['registry']) ? sanitize_text_field($_GET['registry']) : '');

        if (empty($share_key)) {
            return '<p class="rr-error">' . __('No registry specified.', 'restart-registry') . '</p>';
        }

        return $this->render_registry_view($share_key);
    }

    public function registry_create_shortcode($atts) {
        if (!is_user_logged_in()) {
            return $this->render_login_prompt();
        }

        $user_id = get_current_user_id();
        $registry = $this->controller->get_user_registry($user_id);

        if ($registry) {
            return '<p class="rr-notice">' . __('You already have a registry.', 'restart-registry') . ' <a href="' . esc_url(add_query_arg('registry', $registry['share_key'], get_permalink())) . '">' . __('View your registry', 'restart-registry') . '</a></p>';
        }

        return $this->render_create_form();
    }

    private function render_login_prompt() {
        ob_start();
        ?>
        <div class="rr-login-prompt">
            <h3><?php _e('Login Required', 'restart-registry'); ?></h3>
            <p><?php _e('You need to be logged in to create or manage a gift registry.', 'restart-registry'); ?></p>
            <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="rr-button"><?php _e('Log In', 'restart-registry'); ?></a>
            <?php if (get_option('users_can_register')): ?>
                <a href="<?php echo esc_url(wp_registration_url()); ?>" class="rr-button rr-button-secondary"><?php _e('Register', 'restart-registry'); ?></a>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_create_form() {
        ob_start();
        ?>
        <div class="rr-create-form">
            <h3><?php _e('Create Your Gift Registry', 'restart-registry'); ?></h3>
            <form id="rr-create-registry-form" class="rr-form">
                <div class="rr-form-group">
                    <label for="rr-registry-title"><?php _e('Registry Title', 'restart-registry'); ?></label>
                    <input type="text" id="rr-registry-title" name="title" required placeholder="<?php esc_attr_e('e.g., Wedding Registry, Baby Shower', 'restart-registry'); ?>">
                </div>
                <div class="rr-form-group">
                    <label for="rr-registry-description"><?php _e('Description (optional)', 'restart-registry'); ?></label>
                    <textarea id="rr-registry-description" name="description" rows="3" placeholder="<?php esc_attr_e('Tell your guests about this registry...', 'restart-registry'); ?>"></textarea>
                </div>
                <div class="rr-form-group">
                    <label>
                        <input type="checkbox" name="is_public" value="1">
                        <?php _e('Make this registry public (anyone with the link can view it)', 'restart-registry'); ?>
                    </label>
                </div>
                <button type="submit" class="rr-button"><?php _e('Create Registry', 'restart-registry'); ?></button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_manage_registry($registry) {
        $disclosure = get_option('restart_registry_affiliate_disclosure', __('Some links on this registry are affiliate links.', 'restart-registry'));
        
        ob_start();
        ?>
        <div class="rr-manage-registry" data-registry-id="<?php echo esc_attr($registry['id']); ?>">
            <div class="rr-registry-header">
                <h2><?php echo esc_html($registry['title']); ?></h2>
                <?php if (!empty($registry['description'])): ?>
                    <p class="rr-description"><?php echo esc_html($registry['description']); ?></p>
                <?php endif; ?>
                
                <div class="rr-registry-meta">
                    <span class="rr-visibility <?php echo $registry['is_public'] ? 'public' : 'private'; ?>">
                        <?php echo $registry['is_public'] ? __('Public', 'restart-registry') : __('Private', 'restart-registry'); ?>
                    </span>
                    <button type="button" class="rr-button rr-button-small" id="rr-edit-registry"><?php _e('Edit Settings', 'restart-registry'); ?></button>
                </div>
            </div>

            <div class="rr-share-section">
                <h3><?php _e('Share Your Registry', 'restart-registry'); ?></h3>
                <div class="rr-share-link">
                    <input type="text" readonly value="<?php echo esc_url(add_query_arg('registry', $registry['share_key'], get_permalink())); ?>" id="rr-share-url">
                    <button type="button" class="rr-button rr-button-small" onclick="navigator.clipboard.writeText(document.getElementById('rr-share-url').value); alert('<?php esc_attr_e('Link copied!', 'restart-registry'); ?>');"><?php _e('Copy', 'restart-registry'); ?></button>
                </div>
                
                <div class="rr-invite-form">
                    <h4><?php _e('Invite by Email', 'restart-registry'); ?></h4>
                    <form id="rr-send-invite-form">
                        <input type="email" name="email" placeholder="<?php esc_attr_e('Enter email address', 'restart-registry'); ?>" required>
                        <button type="submit" class="rr-button rr-button-small"><?php _e('Send Invite', 'restart-registry'); ?></button>
                    </form>
                </div>
            </div>

            <div class="rr-add-item-section">
                <h3><?php _e('Add an Item', 'restart-registry'); ?></h3>
                <form id="rr-add-item-form" class="rr-form">
                    <div class="rr-form-row">
                        <div class="rr-form-group rr-form-group-large">
                            <label for="rr-item-url"><?php _e('Product URL', 'restart-registry'); ?></label>
                            <input type="url" id="rr-item-url" name="url" required placeholder="<?php esc_attr_e('Paste product link here...', 'restart-registry'); ?>">
                            <button type="button" id="rr-fetch-url" class="rr-button rr-button-small"><?php _e('Fetch Details', 'restart-registry'); ?></button>
                        </div>
                    </div>
                    <div class="rr-form-row">
                        <div class="rr-form-group">
                            <label for="rr-item-name"><?php _e('Product Name', 'restart-registry'); ?></label>
                            <input type="text" id="rr-item-name" name="name" required>
                        </div>
                        <div class="rr-form-group rr-form-group-small">
                            <label for="rr-item-quantity"><?php _e('Quantity', 'restart-registry'); ?></label>
                            <input type="number" id="rr-item-quantity" name="quantity" min="1" value="1">
                        </div>
                    </div>
                    <div class="rr-form-row">
                        <div class="rr-form-group">
                            <label for="rr-item-price"><?php _e('Price (optional)', 'restart-registry'); ?></label>
                            <input type="number" id="rr-item-price" name="price" step="0.01" min="0">
                        </div>
                        <div class="rr-form-group">
                            <label for="rr-item-priority"><?php _e('Priority', 'restart-registry'); ?></label>
                            <select id="rr-item-priority" name="priority">
                                <option value="low"><?php _e('Low', 'restart-registry'); ?></option>
                                <option value="medium" selected><?php _e('Medium', 'restart-registry'); ?></option>
                                <option value="high"><?php _e('High', 'restart-registry'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="rr-form-group">
                        <label for="rr-item-description"><?php _e('Notes (optional)', 'restart-registry'); ?></label>
                        <textarea id="rr-item-description" name="description" rows="2"></textarea>
                    </div>
                    <button type="submit" class="rr-button"><?php _e('Add to Registry', 'restart-registry'); ?></button>
                </form>
            </div>

            <?php if (!empty($disclosure)): ?>
                <div class="rr-affiliate-disclosure">
                    <small><?php echo esc_html($disclosure); ?></small>
                </div>
            <?php endif; ?>

            <div class="rr-items-section">
                <h3><?php _e('Your Items', 'restart-registry'); ?> <span class="rr-item-count">(<?php echo count($registry['items']); ?>)</span></h3>
                
                <div class="rr-items-grid" id="rr-items-container">
                    <?php if (empty($registry['items'])): ?>
                        <p class="rr-no-items"><?php _e('No items yet. Add your first item above!', 'restart-registry'); ?></p>
                    <?php else: ?>
                        <?php foreach ($registry['items'] as $item): ?>
                            <?php echo $this->render_item_card($item, true); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_registry_view($share_key) {
        $registry = $this->controller->get_registry_by_share_key($share_key);

        if (is_wp_error($registry)) {
            return '<p class="rr-error">' . __('Registry not found.', 'restart-registry') . '</p>';
        }

        $user = get_userdata($registry['user_id']);
        $owner_name = $user ? $user->display_name : __('Someone', 'restart-registry');
        $disclosure = get_option('restart_registry_affiliate_disclosure', __('Some links on this registry are affiliate links.', 'restart-registry'));
        $allow_guests = get_option('restart_registry_allow_guests', 1);

        ob_start();
        ?>
        <div class="rr-view-registry" data-registry-id="<?php echo esc_attr($registry['id']); ?>">
            <div class="rr-registry-header">
                <h2><?php echo esc_html($registry['title']); ?></h2>
                <p class="rr-owner"><?php printf(__('A gift registry by %s', 'restart-registry'), esc_html($owner_name)); ?></p>
                <?php if (!empty($registry['description'])): ?>
                    <p class="rr-description"><?php echo esc_html($registry['description']); ?></p>
                <?php endif; ?>
            </div>

            <?php if (!empty($disclosure)): ?>
                <div class="rr-affiliate-disclosure">
                    <small><?php echo esc_html($disclosure); ?></small>
                </div>
            <?php endif; ?>

            <div class="rr-items-section">
                <h3><?php _e('Gift Ideas', 'restart-registry'); ?> <span class="rr-item-count">(<?php echo count($registry['items']); ?>)</span></h3>
                
                <div class="rr-items-grid" id="rr-items-container">
                    <?php if (empty($registry['items'])): ?>
                        <p class="rr-no-items"><?php _e('No items in this registry yet.', 'restart-registry'); ?></p>
                    <?php else: ?>
                        <?php foreach ($registry['items'] as $item): ?>
                            <?php echo $this->render_item_card($item, false, $allow_guests); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_item_card($item, $is_owner = false, $can_purchase = true) {
        $remaining = $item['quantity_needed'] - $item['quantity_purchased'];
        $is_fulfilled = $remaining <= 0;
        
        ob_start();
        ?>
        <div class="rr-item-card <?php echo $is_fulfilled ? 'rr-item-fulfilled' : ''; ?>" data-item-id="<?php echo esc_attr($item['id']); ?>">
            <?php if (!empty($item['image_url'])): ?>
                <div class="rr-item-image">
                    <img src="<?php echo esc_url($item['image_url']); ?>" alt="<?php echo esc_attr($item['name']); ?>">
                </div>
            <?php endif; ?>
            
            <div class="rr-item-content">
                <h4 class="rr-item-name"><?php echo esc_html($item['name']); ?></h4>
                
                <?php if (!empty($item['retailer'])): ?>
                    <span class="rr-item-retailer"><?php echo esc_html($item['retailer']); ?></span>
                <?php endif; ?>
                
                <?php if (!empty($item['description'])): ?>
                    <p class="rr-item-description"><?php echo esc_html($item['description']); ?></p>
                <?php endif; ?>
                
                <div class="rr-item-meta">
                    <?php if (!empty($item['price'])): ?>
                        <span class="rr-item-price">$<?php echo number_format($item['price'], 2); ?></span>
                    <?php endif; ?>
                    
                    <span class="rr-item-quantity">
                        <?php if ($is_fulfilled): ?>
                            <?php _e('Fully purchased!', 'restart-registry'); ?>
                        <?php else: ?>
                            <?php printf(__('%d of %d needed', 'restart-registry'), $remaining, $item['quantity_needed']); ?>
                        <?php endif; ?>
                    </span>
                    
                    <?php if ($item['priority'] === 'high'): ?>
                        <span class="rr-item-priority rr-priority-high"><?php _e('High Priority', 'restart-registry'); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="rr-item-actions">
                    <?php if (!$is_fulfilled): ?>
                        <a href="<?php echo esc_url($item['affiliate_url'] ?: $item['original_url']); ?>" target="_blank" rel="noopener" class="rr-button rr-button-primary"><?php _e('Buy This Gift', 'restart-registry'); ?></a>
                        
                        <?php if (!$is_owner && $can_purchase): ?>
                            <button type="button" class="rr-button rr-button-secondary rr-mark-purchased"><?php _e('Mark as Purchased', 'restart-registry'); ?></button>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ($is_owner): ?>
                        <button type="button" class="rr-button rr-button-small rr-edit-item"><?php _e('Edit', 'restart-registry'); ?></button>
                        <button type="button" class="rr-button rr-button-small rr-button-danger rr-delete-item"><?php _e('Remove', 'restart-registry'); ?></button>
                    <?php endif; ?>
                </div>
                
                <?php if ($item['affiliate_url'] !== $item['original_url']): ?>
                    <div class="rr-link-transparency">
                        <small><?php _e('Original link:', 'restart-registry'); ?> <a href="<?php echo esc_url($item['original_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html(parse_url($item['original_url'], PHP_URL_HOST)); ?></a></small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_create_registry() {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in.', 'restart-registry')));
        }

        $user_id = get_current_user_id();
        $title = sanitize_text_field($_POST['title']);
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
        $is_public = isset($_POST['is_public']) && $_POST['is_public'] === '1';

        $result = $this->controller->create_registry($user_id, $title, $description, $is_public);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => __('Registry created successfully!', 'restart-registry'),
            'registry_id' => $result['id'],
            'share_key' => $result['share_key'],
        ));
    }

    public function ajax_add_item() {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in.', 'restart-registry')));
        }

        $registry_id = intval($_POST['registry_id']);
        
        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(array('message' => __('You cannot edit this registry.', 'restart-registry')));
        }

        $data = array(
            'name' => sanitize_text_field($_POST['name']),
            'url' => esc_url_raw($_POST['url']),
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '',
            'price' => isset($_POST['price']) ? floatval($_POST['price']) : null,
            'quantity' => isset($_POST['quantity']) ? intval($_POST['quantity']) : 1,
            'priority' => isset($_POST['priority']) ? sanitize_text_field($_POST['priority']) : 'medium',
            'image_url' => isset($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : '',
        );

        $result = $this->controller->add_item($registry_id, $data);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $item = $this->controller->get_item($result['id']);
        
        wp_send_json_success(array(
            'message' => __('Item added successfully!', 'restart-registry'),
            'item_id' => $result['id'],
            'is_affiliate' => $result['is_affiliate'],
            'retailer' => $result['retailer'],
            'html' => $this->render_item_card($item, true),
        ));
    }

    public function ajax_delete_item() {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in.', 'restart-registry')));
        }

        $item_id = intval($_POST['item_id']);
        $item = $this->controller->get_item($item_id);

        if (!$item) {
            wp_send_json_error(array('message' => __('Item not found.', 'restart-registry')));
        }

        if (!$this->controller->can_edit_registry($item['registry_id'], get_current_user_id())) {
            wp_send_json_error(array('message' => __('You cannot edit this registry.', 'restart-registry')));
        }

        $this->controller->delete_item($item_id);

        wp_send_json_success(array('message' => __('Item removed.', 'restart-registry')));
    }

    public function ajax_update_item() {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in.', 'restart-registry')));
        }

        $item_id = intval($_POST['item_id']);
        $item = $this->controller->get_item($item_id);

        if (!$item) {
            wp_send_json_error(array('message' => __('Item not found.', 'restart-registry')));
        }

        if (!$this->controller->can_edit_registry($item['registry_id'], get_current_user_id())) {
            wp_send_json_error(array('message' => __('You cannot edit this registry.', 'restart-registry')));
        }

        $data = array();
        if (isset($_POST['name'])) $data['name'] = sanitize_text_field($_POST['name']);
        if (isset($_POST['description'])) $data['description'] = sanitize_textarea_field($_POST['description']);
        if (isset($_POST['quantity'])) $data['quantity'] = intval($_POST['quantity']);
        if (isset($_POST['priority'])) $data['priority'] = sanitize_text_field($_POST['priority']);
        if (isset($_POST['price'])) $data['price'] = floatval($_POST['price']);

        $this->controller->update_item($item_id, $data);

        wp_send_json_success(array('message' => __('Item updated.', 'restart-registry')));
    }

    public function ajax_mark_purchased() {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        $item_id = intval($_POST['item_id']);
        $quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;
        $name = isset($_POST['purchaser_name']) ? sanitize_text_field($_POST['purchaser_name']) : '';
        $email = isset($_POST['purchaser_email']) ? sanitize_email($_POST['purchaser_email']) : '';
        $anonymous = isset($_POST['is_anonymous']) && $_POST['is_anonymous'] === '1';

        $result = $this->controller->mark_item_purchased($item_id, $quantity, $name, $email, $anonymous);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => __('Thank you for purchasing this gift!', 'restart-registry')));
    }

    public function ajax_send_invite() {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in.', 'restart-registry')));
        }

        $registry_id = intval($_POST['registry_id']);
        $email = sanitize_email($_POST['email']);

        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(array('message' => __('You cannot manage this registry.', 'restart-registry')));
        }

        if (!is_email($email)) {
            wp_send_json_error(array('message' => __('Please enter a valid email address.', 'restart-registry')));
        }

        $result = $this->controller->send_invite($registry_id, $email);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => __('Invitation sent!', 'restart-registry')));
    }

    public function ajax_update_registry() {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('You must be logged in.', 'restart-registry')));
        }

        $registry_id = intval($_POST['registry_id']);

        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(array('message' => __('You cannot edit this registry.', 'restart-registry')));
        }

        $data = array();
        if (isset($_POST['title'])) $data['title'] = sanitize_text_field($_POST['title']);
        if (isset($_POST['description'])) $data['description'] = sanitize_textarea_field($_POST['description']);
        if (isset($_POST['is_public'])) $data['is_public'] = $_POST['is_public'] === '1';

        $this->controller->update_registry($registry_id, $data);

        wp_send_json_success(array('message' => __('Registry updated.', 'restart-registry')));
    }

    public function ajax_fetch_url() {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        $url = esc_url_raw($_POST['url']);

        if (empty($url)) {
            wp_send_json_error(array('message' => __('Please enter a URL.', 'restart-registry')));
        }

        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'user-agent' => 'Mozilla/5.0 (compatible; GiftRegistry/1.0)',
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => __('Could not fetch URL. Please enter details manually.', 'restart-registry')));
        }

        $body = wp_remote_retrieve_body($response);
        $data = array(
            'name' => '',
            'price' => '',
            'image_url' => '',
        );

        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $body, $matches)) {
            $data['name'] = html_entity_decode(trim($matches[1]), ENT_QUOTES, 'UTF-8');
            $data['name'] = preg_replace('/\s*[-|:].*(Amazon|Target|Walmart|eBay|Etsy).*$/i', '', $data['name']);
        }

        if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $matches)) {
            $data['image_url'] = $matches[1];
        }

        if (preg_match('/\$([0-9,]+\.?\d{0,2})/', $body, $matches)) {
            $data['price'] = floatval(str_replace(',', '', $matches[1]));
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-affiliate-converter.php';
        $converter = new Restart_Registry_Affiliate_Converter();
        $affiliate_result = $converter->convert_url($url);
        
        $data['retailer'] = $affiliate_result['retailer'];
        $data['is_affiliate'] = $affiliate_result['is_affiliate'];

        wp_send_json_success($data);
    }
}
