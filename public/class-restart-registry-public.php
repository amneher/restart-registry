<?php

/**
 * The public-facing functionality of the plugin.
 *
 * Renders registry shortcodes and handles AJAX actions.
 * Registry data lives in the restart-registry CPT; item data lives in Lambda.
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/public
 */

class Restart_Registry_Public {

    /** @var string */
    private $plugin_name;

    /** @var string */
    private $version;

    /** @var Restart_Registry_Controller */
    private $controller;

    public function __construct(string $plugin_name, string $version) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-restart-registry-controller.php';
        $this->controller = new Restart_Registry_Controller();

        add_shortcode('restart_registry',        [$this, 'registry_shortcode']);
        add_shortcode('restart_registry_view',   [$this, 'registry_view_shortcode']);
        add_shortcode('restart_registry_create', [$this, 'registry_create_shortcode']);

        add_action('wp_ajax_restart_registry_add_item',              [$this, 'ajax_add_item']);
        add_action('wp_ajax_restart_registry_delete_item',           [$this, 'ajax_delete_item']);
        add_action('wp_ajax_restart_registry_update_item',           [$this, 'ajax_update_item']);
        add_action('wp_ajax_restart_registry_mark_purchased',        [$this, 'ajax_mark_purchased']);
        add_action('wp_ajax_nopriv_restart_registry_mark_purchased', [$this, 'ajax_mark_purchased']);
        add_action('wp_ajax_restart_registry_send_invite',           [$this, 'ajax_send_invite']);
        add_action('wp_ajax_restart_registry_create',                [$this, 'ajax_create_registry']);
        add_action('wp_ajax_restart_registry_update',                [$this, 'ajax_update_registry']);
        add_action('wp_ajax_restart_registry_fetch_url',             [$this, 'ajax_fetch_url']);
    }

    // =========================================================================
    // Asset enqueuing
    // =========================================================================

    public function enqueue_styles(): void {
        wp_enqueue_style(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'css/restart-registry-public.css',
            [],
            $this->version,
            'all'
        );
    }

    public function enqueue_scripts(): void {
        wp_enqueue_script(
            $this->plugin_name,
            plugin_dir_url(__FILE__) . 'js/restart-registry-public.js',
            ['jquery'],
            $this->version,
            true
        );

        wp_localize_script($this->plugin_name, 'restartRegistry', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('restart_registry_nonce'),
            'strings' => [
                'confirmDelete'   => __('Are you sure you want to remove this item?', 'restart-registry'),
                'confirmPurchase' => __('Mark this item as purchased?', 'restart-registry'),
                'loading'         => __('Loading…', 'restart-registry'),
                'error'           => __('An error occurred. Please try again.', 'restart-registry'),
            ],
        ]);
    }

    // =========================================================================
    // Shortcodes
    // =========================================================================

    /**
     * [restart_registry]
     *
     * • On a single restart-registry CPT page: owner sees manage view; guests see read view.
     * • With ?registry=<post_id|slug> query param: public/invitee read view.
     * • Otherwise: logged-in user sees their own manage view (or create form).
     */
    public function registry_shortcode($atts): string {
        // --- Single CPT page (e.g. /registry/johns-registry/) ---
        if (is_singular('restart-registry')) {
            $post_id = get_the_ID();
            $user_id = get_current_user_id();

            if ($this->controller->can_edit_registry($post_id, $user_id)) {
                $registry = $this->controller->get_registry($post_id);
                if (is_wp_error($registry)) {
                    return '<p class="rr-error">' . esc_html($registry->get_error_message()) . '</p>';
                }
                return $this->render_manage_registry($registry);
            }

            if ($this->controller->can_view_registry($post_id, $user_id)) {
                $registry = $this->controller->get_registry($post_id);
                if (is_wp_error($registry)) {
                    return '<p class="rr-error">' . esc_html($registry->get_error_message()) . '</p>';
                }
                return $this->render_registry_view_html($registry);
            }

            if (!is_user_logged_in()) {
                return $this->render_login_prompt();
            }

            return '<p class="rr-error">' . __('You do not have permission to view this registry.', 'restart-registry') . '</p>';
        }

        // --- ?registry=<key> share link ---
        if (isset($_GET['registry'])) {
            return $this->render_registry_view(sanitize_text_field($_GET['registry']));
        }

        // --- Logged-in user's own registry page ---
        if (!is_user_logged_in()) {
            return $this->render_login_prompt();
        }

        $user_id  = get_current_user_id();
        $registry = $this->controller->get_user_registry($user_id);

        if (!$registry) {
            return $this->render_create_form();
        }

        return $this->render_manage_registry($registry);
    }

    /**
     * [restart_registry_view registry="<post_id|slug>"]
     */
    public function registry_view_shortcode($atts): string {
        $atts = shortcode_atts(['registry' => ''], $atts, 'restart_registry_view');
        $key  = !empty($atts['registry'])
            ? $atts['registry']
            : (isset($_GET['registry']) ? sanitize_text_field($_GET['registry']) : '');

        if (empty($key)) {
            return '<p class="rr-error">' . __('No registry specified.', 'restart-registry') . '</p>';
        }

        return $this->render_registry_view($key);
    }

    /**
     * [restart_registry_create]
     */
    public function registry_create_shortcode($atts): string {
        if (!is_user_logged_in()) {
            return $this->render_login_prompt();
        }

        $user_id  = get_current_user_id();
        $registry = $this->controller->get_user_registry($user_id);

        if ($registry) {
            return '<p class="rr-notice">' .
                __('You already have a registry.', 'restart-registry') . ' ' .
                '<a href="' . esc_url($registry['permalink']) . '">' . __('View your registry', 'restart-registry') . '</a>' .
                '</p>';
        }

        return $this->render_create_form();
    }

    // =========================================================================
    // Private rendering helpers
    // =========================================================================

    private function render_login_prompt(): string {
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

    private function render_create_form(): string {
        ob_start();
        ?>
        <div class="rr-create-form">
            <h3><?php _e('Create Your Gift Registry', 'restart-registry'); ?></h3>
            <form id="rr-create-registry-form" class="rr-form">
                <div class="rr-form-group">
                    <label for="rr-registry-title"><?php _e('Registry Title', 'restart-registry'); ?></label>
                    <input type="text" id="rr-registry-title" name="title" required
                           placeholder="<?php esc_attr_e('e.g., Wedding Registry, Baby Shower', 'restart-registry'); ?>">
                </div>
                <div class="rr-form-group">
                    <label for="rr-registry-description"><?php _e('Description (optional)', 'restart-registry'); ?></label>
                    <textarea id="rr-registry-description" name="description" rows="3"
                              placeholder="<?php esc_attr_e('Tell your guests about this registry…', 'restart-registry'); ?>"></textarea>
                </div>
                <div class="rr-form-row">
                    <div class="rr-form-group">
                        <label for="rr-registry-event-type"><?php _e('Event Type (optional)', 'restart-registry'); ?></label>
                        <input type="text" id="rr-registry-event-type" name="event_type"
                               placeholder="<?php esc_attr_e('e.g., Wedding, Baby Shower, Birthday', 'restart-registry'); ?>">
                    </div>
                    <div class="rr-form-group">
                        <label for="rr-registry-event-date"><?php _e('Event Date (optional)', 'restart-registry'); ?></label>
                        <input type="date" id="rr-registry-event-date" name="event_date">
                    </div>
                </div>
                <div class="rr-form-group">
                    <label>
                        <input type="checkbox" name="is_public" value="1">
                        <?php _e('Make this registry public', 'restart-registry'); ?>
                    </label>
                </div>
                <button type="submit" class="rr-button"><?php _e('Create Registry', 'restart-registry'); ?></button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_manage_registry(array $registry): string {
        $disclosure = get_option('restart_registry_affiliate_disclosure', __('Some links on this registry are affiliate links.', 'restart-registry'));
        $event_type = $registry['meta']['event_type'] ?? '';
        $event_date = $registry['meta']['event_date'] ?? '';

        ob_start();
        ?>
        <div class="rr-manage-registry" data-registry-id="<?php echo esc_attr($registry['id']); ?>">

            <div class="rr-registry-header">
                <h2><?php echo esc_html($registry['title']); ?></h2>
                <?php if (!empty($registry['description'])): ?>
                    <p class="rr-description"><?php echo esc_html($registry['description']); ?></p>
                <?php endif; ?>
                <?php if ($event_type || $event_date): ?>
                    <p class="rr-event-meta">
                        <?php if ($event_type): ?><span class="rr-event-type"><?php echo esc_html($event_type); ?></span><?php endif; ?>
                        <?php if ($event_date): ?><span class="rr-event-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($event_date))); ?></span><?php endif; ?>
                    </p>
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
                    <input type="text" readonly value="<?php echo esc_url($registry['permalink']); ?>" id="rr-share-url">
                    <button type="button" class="rr-button rr-button-small"
                            onclick="navigator.clipboard.writeText(document.getElementById('rr-share-url').value);alert('<?php esc_attr_e('Link copied!', 'restart-registry'); ?>')">
                        <?php _e('Copy', 'restart-registry'); ?>
                    </button>
                </div>
                <div class="rr-invite-form">
                    <h4><?php _e('Invite by Email or Username', 'restart-registry'); ?></h4>
                    <form id="rr-send-invite-form">
                        <input type="text" name="invitee"
                               placeholder="<?php esc_attr_e('Email address or WP username', 'restart-registry'); ?>" required>
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
                            <input type="url" id="rr-item-url" name="url" required
                                   placeholder="<?php esc_attr_e('Paste product link here…', 'restart-registry'); ?>">
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
                            <label for="rr-item-price"><?php _e('Price ($)', 'restart-registry'); ?></label>
                            <input type="number" id="rr-item-price" name="price" step="0.01" min="0.01" required>
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

    /**
     * Look up a registry by share key (post ID or slug) and render the guest view.
     */
    private function render_registry_view(string $key): string {
        $registry = $this->controller->get_registry_by_share_key($key);
        if (is_wp_error($registry)) {
            return '<p class="rr-error">' . esc_html($registry->get_error_message()) . '</p>';
        }

        $user_id = get_current_user_id();
        if (!$this->controller->can_view_registry($registry['id'], $user_id ?: null)) {
            return is_user_logged_in()
                ? '<p class="rr-error">' . __('You do not have permission to view this registry.', 'restart-registry') . '</p>'
                : $this->render_login_prompt();
        }

        return $this->render_registry_view_html($registry);
    }

    private function render_registry_view_html(array $registry): string {
        $user         = get_userdata($registry['user_id']);
        $owner_name   = $user ? $user->display_name : __('Someone', 'restart-registry');
        $disclosure   = get_option('restart_registry_affiliate_disclosure', __('Some links on this registry are affiliate links.', 'restart-registry'));
        $allow_guests = get_option('restart_registry_allow_guests', 1);
        $event_type   = $registry['meta']['event_type'] ?? '';
        $event_date   = $registry['meta']['event_date'] ?? '';

        ob_start();
        ?>
        <div class="rr-view-registry" data-registry-id="<?php echo esc_attr($registry['id']); ?>">

            <div class="rr-registry-header">
                <h2><?php echo esc_html($registry['title']); ?></h2>
                <p class="rr-owner"><?php printf(__('A gift registry by %s', 'restart-registry'), esc_html($owner_name)); ?></p>
                <?php if ($event_type || $event_date): ?>
                    <p class="rr-event-meta">
                        <?php if ($event_type): ?><span class="rr-event-type"><?php echo esc_html($event_type); ?></span><?php endif; ?>
                        <?php if ($event_date): ?><span class="rr-event-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($event_date))); ?></span><?php endif; ?>
                    </p>
                <?php endif; ?>
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
                            <?php echo $this->render_item_card($item, false, (bool) $allow_guests); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a single item card.
     *
     * Item fields (from Lambda): id, name, url, description, price,
     *   retailer, affiliate_status, quantity_needed, quantity_purchased, is_active.
     */
    private function render_item_card(array $item, bool $is_owner = false, bool $can_purchase = true): string {
        $qty_needed    = (int) ($item['quantity_needed']    ?? 1);
        $qty_purchased = (int) ($item['quantity_purchased'] ?? 0);
        $remaining     = $qty_needed - $qty_purchased;
        $is_fulfilled  = $remaining <= 0;
        $is_affiliate  = !empty($item['affiliate_status']);

        ob_start();
        ?>
        <div class="rr-item-card <?php echo $is_fulfilled ? 'rr-item-fulfilled' : ''; ?>"
             data-item-id="<?php echo esc_attr($item['id']); ?>">
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
                        <span class="rr-item-price">$<?php echo number_format((float) $item['price'], 2); ?></span>
                    <?php endif; ?>
                    <span class="rr-item-quantity">
                        <?php if ($is_fulfilled): ?>
                            <?php _e('Fully purchased!', 'restart-registry'); ?>
                        <?php else: ?>
                            <?php printf(__('%d of %d needed', 'restart-registry'), $remaining, $qty_needed); ?>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="rr-item-actions">
                    <?php if (!$is_fulfilled): ?>
                        <a href="<?php echo esc_url($item['url']); ?>" target="_blank" rel="noopener sponsored"
                           class="rr-button rr-button-primary"><?php _e('Buy This Gift', 'restart-registry'); ?></a>
                        <?php if (!$is_owner && $can_purchase): ?>
                            <button type="button" class="rr-button rr-button-secondary rr-mark-purchased">
                                <?php _e('Mark as Purchased', 'restart-registry'); ?>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($is_owner): ?>
                        <button type="button" class="rr-button rr-button-small rr-edit-item"><?php _e('Edit', 'restart-registry'); ?></button>
                        <button type="button" class="rr-button rr-button-small rr-button-danger rr-delete-item"><?php _e('Remove', 'restart-registry'); ?></button>
                    <?php endif; ?>
                </div>

                <?php if ($is_affiliate): ?>
                    <div class="rr-affiliate-badge"><small><?php _e('Affiliate link', 'restart-registry'); ?></small></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // AJAX handlers
    // =========================================================================

    public function ajax_create_registry(): void {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $title = sanitize_text_field($_POST['title'] ?? '');
        if (empty($title)) {
            wp_send_json_error(['message' => __('Please enter a registry title.', 'restart-registry')]);
        }

        $result = $this->controller->create_registry(
            get_current_user_id(),
            $title,
            sanitize_textarea_field($_POST['description'] ?? ''),
            isset($_POST['is_public']) && $_POST['is_public'] === '1'
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $meta = [];
        if (!empty($_POST['event_type'])) $meta['event_type'] = $_POST['event_type'];
        if (!empty($_POST['event_date']))  $meta['event_date'] = $_POST['event_date'];
        if ($meta) $this->controller->update_registry($result['id'], $meta);

        wp_send_json_success([
            'message'     => __('Registry created successfully!', 'restart-registry'),
            'registry_id' => $result['id'],
            'redirect'    => get_permalink($result['id']),
        ]);
    }

    public function ajax_add_item(): void {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot edit this registry.', 'restart-registry')]);
        }

        $data = [
            'name'        => sanitize_text_field($_POST['name'] ?? ''),
            'url'         => esc_url_raw($_POST['url'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'price'       => isset($_POST['price']) ? (float) $_POST['price'] : 0.01,
            'quantity'    => isset($_POST['quantity']) ? (int) $_POST['quantity'] : 1,
        ];

        if (empty($data['name']) || empty($data['url'])) {
            wp_send_json_error(['message' => __('Name and URL are required.', 'restart-registry')]);
        }

        $result = $this->controller->add_item($registry_id, $data);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message'      => __('Item added successfully!', 'restart-registry'),
            'item_id'      => $result['id'],
            'is_affiliate' => $result['is_affiliate'],
            'retailer'     => $result['retailer'],
            'html'         => $this->render_item_card($result['html_item'], true),
        ]);
    }

    public function ajax_delete_item(): void {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $item_id     = (int) ($_POST['item_id']     ?? 0);
        $registry_id = (int) ($_POST['registry_id'] ?? 0);

        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot edit this registry.', 'restart-registry')]);
        }

        $this->controller->delete_item($item_id, $registry_id);
        wp_send_json_success(['message' => __('Item removed.', 'restart-registry')]);
    }

    public function ajax_update_item(): void {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $item_id     = (int) ($_POST['item_id']     ?? 0);
        $registry_id = (int) ($_POST['registry_id'] ?? 0);

        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot edit this registry.', 'restart-registry')]);
        }

        $data = [];
        if (isset($_POST['name']))        $data['name']        = $_POST['name'];
        if (isset($_POST['description'])) $data['description'] = $_POST['description'];
        if (isset($_POST['quantity']))    $data['quantity']    = (int) $_POST['quantity'];
        if (isset($_POST['price']))       $data['price']       = (float) $_POST['price'];

        $result = $this->controller->update_item($item_id, $data);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Item updated.', 'restart-registry')]);
    }

    public function ajax_mark_purchased(): void {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        $item_id  = (int) ($_POST['item_id']  ?? 0);
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

        $result = $this->controller->mark_item_purchased(
            $item_id,
            $quantity,
            sanitize_text_field($_POST['purchaser_name']  ?? ''),
            sanitize_email($_POST['purchaser_email']       ?? ''),
            isset($_POST['is_anonymous']) && $_POST['is_anonymous'] === '1'
        );

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Thank you for purchasing this gift!', 'restart-registry')]);
    }

    public function ajax_send_invite(): void {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        // Accept both 'invitee' (new) and 'email' (legacy)
        $invitee = sanitize_text_field($_POST['invitee'] ?? $_POST['email'] ?? '');

        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot manage this registry.', 'restart-registry')]);
        }
        if (empty($invitee)) {
            wp_send_json_error(['message' => __('Please enter an email or username.', 'restart-registry')]);
        }

        $result = $this->controller->send_invite($registry_id, $invitee);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Invitation sent!', 'restart-registry')]);
    }

    public function ajax_update_registry(): void {
        check_ajax_referer('restart_registry_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You must be logged in.', 'restart-registry')]);
        }

        $registry_id = (int) ($_POST['registry_id'] ?? 0);
        if (!$this->controller->can_edit_registry($registry_id, get_current_user_id())) {
            wp_send_json_error(['message' => __('You cannot edit this registry.', 'restart-registry')]);
        }

        $data = [];
        if (isset($_POST['title']))       $data['title']       = $_POST['title'];
        if (isset($_POST['description'])) $data['description'] = $_POST['description'];
        if (isset($_POST['is_public']))   $data['is_public']   = $_POST['is_public'] === '1';
        if (isset($_POST['event_type'])) $data['event_type']  = $_POST['event_type'];
        if (isset($_POST['event_date']))  $data['event_date']  = $_POST['event_date'];

        $this->controller->update_registry($registry_id, $data);
        wp_send_json_success(['message' => __('Registry updated.', 'restart-registry')]);
    }

    public function ajax_fetch_url(): void {
        check_ajax_referer('restart_registry_nonce', 'nonce');

        $url = esc_url_raw($_POST['url'] ?? '');
        if (empty($url)) {
            wp_send_json_error(['message' => __('Please enter a URL.', 'restart-registry')]);
        }

        $response = wp_remote_get($url, [
            'timeout'    => 10,
            'user-agent' => 'Mozilla/5.0 (compatible; GiftRegistry/1.0)',
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => __('Could not fetch URL. Please enter details manually.', 'restart-registry')]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = ['name' => '', 'price' => ''];

        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $body, $m)) {
            $data['name'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
            $data['name'] = preg_replace('/\s*[-|:].*(?:Amazon|Target|Walmart|eBay|Etsy).*$/i', '', $data['name']);
        }
        if (preg_match('/\$([0-9,]+\.?\d{0,2})/', $body, $m)) {
            $data['price'] = (float) str_replace(',', '', $m[1]);
        }

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-affiliate-converter.php';
        $affiliate_result = (new Restart_Registry_Affiliate_Converter())->convert_url($url);
        $data['retailer']     = $affiliate_result['retailer'];
        $data['is_affiliate'] = $affiliate_result['is_affiliate'];

        wp_send_json_success($data);
    }
}
