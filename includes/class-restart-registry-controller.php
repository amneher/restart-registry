<?php

/**
 * Registry Controller
 *
 * Manages registry CRUD via the WordPress CPT (restart-registry) for
 * registry metadata and the Lambda FastAPI service for item data.
 *
 * Registry identity  → WP post ID  (replaces the old share_key)
 * Item identity      → Lambda SQLite row ID (stored in restart_item_ids meta)
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/includes
 */

class Restart_Registry_Controller {

    /** @var Restart_Registry_Lambda_Client */
    private $lambda;

    /** @var Restart_Registry_Affiliate_Converter */
    private $affiliate_converter;

    public function __construct() {
        require_once plugin_dir_path(__FILE__) . 'class-lambda-api-client.php';
        require_once plugin_dir_path(__FILE__) . 'class-affiliate-converter.php';
        $this->lambda              = new Restart_Registry_Lambda_Client();
        $this->affiliate_converter = new Restart_Registry_Affiliate_Converter();
    }

    // =========================================================================
    // Registry read
    // =========================================================================

    /**
     * Build a registry array from a WP post object (without items).
     */
    private function post_to_registry(\WP_Post $post): array {
        $invitees = json_decode(get_post_meta($post->ID, 'restart_invitees', true) ?: '[]', true) ?: [];
        $item_ids = json_decode(get_post_meta($post->ID, 'restart_item_ids', true) ?: '[]', true) ?: [];

        return [
            'id'          => $post->ID,
            'user_id'     => (int) $post->post_author,
            'title'       => $post->post_title,
            'description' => $post->post_content,
            'is_public'   => $post->post_status === 'publish',
            'permalink'   => get_permalink($post->ID),
            // backward-compat alias used in the public shortcode class
            'share_key'   => $post->ID,
            'meta'        => [
                'invitees'   => $invitees,
                'item_ids'   => $item_ids,
                'event_type' => get_post_meta($post->ID, 'restart_event_type', true) ?: '',
                'event_date' => get_post_meta($post->ID, 'restart_event_date', true) ?: '',
            ],
            // items populated separately to avoid eager-loading Lambda on every call
            'items' => [],
        ];
    }

    /**
     * Get a registry by WP post ID (with items loaded from Lambda).
     */
    public function get_registry(int $registry_id) {
        $post = get_post($registry_id);
        if (!$post || $post->post_type !== 'restart-registry') {
            return new WP_Error('not_found', __('Registry not found.', 'restart-registry'));
        }
        $registry           = $this->post_to_registry($post);
        $registry['items']  = $this->get_registry_items($registry_id);
        return $registry;
    }

    /**
     * Look up a registry by its WP post ID (numeric string) or post slug.
     * Used by the ?registry=<key> share-link flow.
     */
    public function get_registry_by_share_key(string $key) {
        if (is_numeric($key)) {
            return $this->get_registry((int) $key);
        }

        $posts = get_posts([
            'post_type'      => 'restart-registry',
            'name'           => $key,
            'posts_per_page' => 1,
            'post_status'    => ['publish', 'private'],
        ]);

        if (empty($posts)) {
            return new WP_Error('not_found', __('Registry not found.', 'restart-registry'));
        }

        $registry          = $this->post_to_registry($posts[0]);
        $registry['items'] = $this->get_registry_items($posts[0]->ID);
        return $registry;
    }

    /**
     * Get the first registry belonging to a WP user (with items).
     */
    public function get_user_registry(int $user_id): ?array {
        $posts = get_posts([
            'post_type'      => 'restart-registry',
            'author'         => $user_id,
            'posts_per_page' => 1,
            'post_status'    => ['publish', 'private', 'draft'],
        ]);

        if (empty($posts)) {
            return null;
        }

        $registry          = $this->post_to_registry($posts[0]);
        $registry['items'] = $this->get_registry_items($posts[0]->ID);
        return $registry;
    }

    // =========================================================================
    // Registry write
    // =========================================================================

    /**
     * Create a new registry WP post for a user.
     * Returns ['id' => post_id, 'share_key' => post_id] or WP_Error.
     */
    public function create_registry(int $user_id, string $title, string $description = '', bool $is_public = false) {
        $existing = get_posts([
            'post_type'      => 'restart-registry',
            'author'         => $user_id,
            'posts_per_page' => 1,
            'post_status'    => ['publish', 'private', 'draft'],
        ]);

        if (!empty($existing)) {
            return new WP_Error('registry_exists', __('You already have a registry.', 'restart-registry'));
        }

        $post_id = wp_insert_post([
            'post_type'    => 'restart-registry',
            'post_title'   => sanitize_text_field($title),
            'post_content' => sanitize_textarea_field($description),
            'post_status'  => $is_public ? 'publish' : 'private',
            'post_author'  => $user_id,
        ], true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, 'restart_invitees', '[]');
        update_post_meta($post_id, 'restart_item_ids', '[]');
        update_post_meta($post_id, 'restart_event_type', '');
        update_post_meta($post_id, 'restart_event_date', '');

        return [
            'id'        => $post_id,
            'share_key' => $post_id,
        ];
    }

    /**
     * Update registry post fields.
     * Accepted keys: title, description, is_public, event_type, event_date.
     */
    public function update_registry(int $registry_id, array $data): bool {
        $update = ['ID' => $registry_id];

        if (isset($data['title'])) {
            $update['post_title'] = sanitize_text_field($data['title']);
        }
        if (isset($data['description'])) {
            $update['post_content'] = sanitize_textarea_field($data['description']);
        }
        if (isset($data['is_public'])) {
            $update['post_status'] = $data['is_public'] ? 'publish' : 'private';
        }

        if (count($update) > 1) {
            wp_update_post($update);
        }

        if (isset($data['event_type'])) {
            update_post_meta($registry_id, 'restart_event_type', sanitize_text_field($data['event_type']));
        }
        if (isset($data['event_date'])) {
            update_post_meta($registry_id, 'restart_event_date', sanitize_text_field($data['event_date']));
        }

        return true;
    }

    /**
     * Delete a registry post and all its Lambda items.
     */
    public function delete_registry(int $registry_id): bool {
        $item_ids = json_decode(get_post_meta($registry_id, 'restart_item_ids', true) ?: '[]', true) ?: [];
        foreach ($item_ids as $item_id) {
            $this->lambda->delete_item((int) $item_id);
        }
        return (bool) wp_delete_post($registry_id, true);
    }

    // =========================================================================
    // Items
    // =========================================================================

    /**
     * Fetch all Lambda items for a registry (order preserved from meta).
     */
    public function get_registry_items(int $registry_id): array {
        $item_ids = json_decode(get_post_meta($registry_id, 'restart_item_ids', true) ?: '[]', true) ?: [];
        if (empty($item_ids)) {
            return [];
        }
        return $this->lambda->get_items($item_ids);
    }

    /**
     * Fetch a single Lambda item.
     */
    public function get_item(int $item_id) {
        return $this->lambda->get_item($item_id);
    }

    /**
     * Create a Lambda item and link its ID to the registry meta.
     * Returns ['id' => lambda_id, 'is_affiliate' => bool, 'retailer' => string] or WP_Error.
     *
     * Required: name, url.  Optional: description, price, quantity.
     */
    public function add_item(int $registry_id, array $data) {
        $url              = esc_url_raw($data['url']);
        $affiliate_result = $this->affiliate_converter->convert_url($url);

        $lambda_data = [
            'name'  => sanitize_text_field($data['name']),
            'url'   => $affiliate_result['affiliate_url'] ?: $url,
            'price' => !empty($data['price']) ? (float) $data['price'] : 0.01,
        ];

        if (!empty($data['description'])) {
            $lambda_data['description'] = sanitize_textarea_field($data['description']);
        }
        if ($affiliate_result['retailer']) {
            $lambda_data['retailer'] = $affiliate_result['retailer'];
        }
        if ($affiliate_result['is_affiliate']) {
            $lambda_data['affiliate_status'] = 'converted';
        }
        if (!empty($data['quantity'])) {
            $lambda_data['quantity_needed'] = (int) $data['quantity'];
        }

        $item = $this->lambda->create_item($lambda_data);
        if (is_wp_error($item)) {
            return $item;
        }

        // Link item ID in post meta
        $item_ids   = json_decode(get_post_meta($registry_id, 'restart_item_ids', true) ?: '[]', true) ?: [];
        $item_ids[] = (int) $item['id'];
        update_post_meta($registry_id, 'restart_item_ids', json_encode($item_ids));

        return [
            'id'           => $item['id'],
            'is_affiliate' => $affiliate_result['is_affiliate'],
            'retailer'     => $affiliate_result['retailer'],
            'html_item'    => $item,
        ];
    }

    /**
     * Update a Lambda item's editable fields.
     * Accepted keys: name, description, price, quantity (→ quantity_needed).
     */
    public function update_item(int $item_id, array $data) {
        $update = [];
        if (isset($data['name']))        $update['name']           = sanitize_text_field($data['name']);
        if (isset($data['description'])) $update['description']    = sanitize_textarea_field($data['description']);
        if (isset($data['price']))       $update['price']          = max(0.01, (float) $data['price']);
        if (isset($data['quantity']))    $update['quantity_needed'] = max(1, (int) $data['quantity']);

        if (empty($update)) {
            return new WP_Error('no_data', __('No data to update.', 'restart-registry'));
        }

        return $this->lambda->update_item($item_id, $update);
    }

    /**
     * Delete a Lambda item and remove it from the registry meta.
     */
    public function delete_item(int $item_id, int $registry_id): bool {
        $this->lambda->delete_item($item_id);

        $item_ids = json_decode(get_post_meta($registry_id, 'restart_item_ids', true) ?: '[]', true) ?: [];
        $item_ids = array_values(array_filter($item_ids, fn($id) => (int) $id !== $item_id));
        update_post_meta($registry_id, 'restart_item_ids', json_encode($item_ids));

        return true;
    }

    /**
     * Increment quantity_purchased for an item.
     * Returns the updated item or WP_Error.
     */
    public function mark_item_purchased(int $item_id, int $quantity = 1, string $purchaser_name = '', string $purchaser_email = '', bool $is_anonymous = false) {
        $item = $this->lambda->get_item($item_id);
        if (!$item || is_wp_error($item)) {
            return new WP_Error('not_found', __('Item not found.', 'restart-registry'));
        }

        $current   = (int) ($item['quantity_purchased'] ?? 0);
        $needed    = (int) ($item['quantity_needed'] ?? 1);
        $remaining = $needed - $current;

        if ($quantity > $remaining) {
            return new WP_Error('quantity_exceeded', __('Cannot purchase more than needed.', 'restart-registry'));
        }

        return $this->lambda->update_item($item_id, ['quantity_purchased' => $current + $quantity]);
    }

    // =========================================================================
    // Invites
    // =========================================================================

    /**
     * Add an email or WP username to the registry invitee list and send an email.
     * Returns ['invite_id' => index] or WP_Error.
     */
    public function send_invite(int $registry_id, string $invitee) {
        $invitees = json_decode(get_post_meta($registry_id, 'restart_invitees', true) ?: '[]', true) ?: [];

        if (in_array($invitee, $invitees, true)) {
            return new WP_Error('already_invited', __('This contact has already been invited.', 'restart-registry'));
        }

        $invitees[] = $invitee;
        update_post_meta($registry_id, 'restart_invitees', json_encode($invitees));

        if (is_email($invitee)) {
            $this->send_invite_email($invitee, $registry_id);
        }

        return ['invite_id' => count($invitees) - 1];
    }

    /**
     * Return the invitee list as an array of row-like arrays.
     */
    public function get_registry_invites(int $registry_id): array {
        $invitees = json_decode(get_post_meta($registry_id, 'restart_invitees', true) ?: '[]', true) ?: [];
        return array_map(
            fn($invitee, $i) => ['id' => $i, 'email' => $invitee],
            $invitees,
            array_keys($invitees)
        );
    }

    private function send_invite_email(string $email, int $registry_id): void {
        $post      = get_post($registry_id);
        $author    = $post ? get_userdata((int) $post->post_author) : null;
        $name      = $author ? $author->display_name : __('Someone', 'restart-registry');
        $title     = $post ? $post->post_title : __('a gift registry', 'restart-registry');
        $link      = get_permalink($registry_id) ?: home_url('/');

        $subject = sprintf(__('%s invited you to view their gift registry!', 'restart-registry'), $name);
        $message = sprintf(
            __("Hello!\n\n%s has invited you to view their gift registry: %s\n\nClick below to see their wishlist:\n%s\n\nBest,\nThe Restart Team", 'restart-registry'),
            $name,
            $title,
            $link
        );

        wp_mail($email, $subject, $message);
    }

    // =========================================================================
    // Access control
    // =========================================================================

    /**
     * True if the user may view the registry (public, owner, admin, or invitee).
     */
    public function can_view_registry(int $registry_id, ?int $user_id = null): bool {
        $post = get_post($registry_id);
        if (!$post || $post->post_type !== 'restart-registry') {
            return false;
        }

        if ($post->post_status === 'publish') {
            return true;
        }
        if ($user_id && (int) $post->post_author === $user_id) {
            return true;
        }
        if ($user_id && user_can($user_id, 'manage_restart_registry')) {
            return true;
        }

        // Check invitee list (email or username match)
        if ($user_id) {
            $invitees = json_decode(get_post_meta($registry_id, 'restart_invitees', true) ?: '[]', true) ?: [];
            $user     = get_userdata($user_id);
            if ($user && (
                in_array($user->user_email, $invitees, true) ||
                in_array($user->user_login, $invitees, true)
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if the user may edit the registry (author or admin).
     */
    public function can_edit_registry(int $registry_id, int $user_id): bool {
        $post = get_post($registry_id);
        if (!$post || $post->post_type !== 'restart-registry') {
            return false;
        }
        return (int) $post->post_author === $user_id || user_can($user_id, 'manage_restart_registry');
    }
}