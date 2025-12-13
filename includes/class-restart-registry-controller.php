<?php

/**
 * Registry Controller
 *
 * Handles all registry CRUD operations
 *
 * @package    Restart_Registry
 * @subpackage Restart_Registry/includes
 */

class Restart_Registry_Controller {

    private $affiliate_converter;

    public function __construct() {
        require_once plugin_dir_path(__FILE__) . 'class-affiliate-converter.php';
        $this->affiliate_converter = new Restart_Registry_Affiliate_Converter();
    }

    public function create_registry($user_id, $title, $description = '', $is_public = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'restart_registries';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d LIMIT 1",
            $user_id
        ));

        if ($existing) {
            return new WP_Error('registry_exists', __('You already have a registry.', 'restart-registry'));
        }

        $share_key = $this->generate_share_key();

        $result = $wpdb->insert(
            $table,
            array(
                'user_id' => $user_id,
                'title' => sanitize_text_field($title),
                'description' => sanitize_textarea_field($description),
                'share_key' => $share_key,
                'is_public' => $is_public ? 1 : 0,
            ),
            array('%d', '%s', '%s', '%s', '%d')
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to create registry.', 'restart-registry'));
        }

        return array(
            'id' => $wpdb->insert_id,
            'share_key' => $share_key,
        );
    }

    public function get_registry($registry_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'restart_registries';

        $registry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $registry_id
        ), ARRAY_A);

        if (!$registry) {
            return new WP_Error('not_found', __('Registry not found.', 'restart-registry'));
        }

        $registry['items'] = $this->get_registry_items($registry_id);
        return $registry;
    }

    public function get_registry_by_share_key($share_key) {
        global $wpdb;
        $table = $wpdb->prefix . 'restart_registries';

        $registry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE share_key = %s",
            $share_key
        ), ARRAY_A);

        if (!$registry) {
            return new WP_Error('not_found', __('Registry not found.', 'restart-registry'));
        }

        $registry['items'] = $this->get_registry_items($registry['id']);
        return $registry;
    }

    public function get_user_registry($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'restart_registries';

        $registry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d",
            $user_id
        ), ARRAY_A);

        if (!$registry) {
            return null;
        }

        $registry['items'] = $this->get_registry_items($registry['id']);
        return $registry;
    }

    public function update_registry($registry_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'restart_registries';

        $update_data = array();
        $formats = array();

        if (isset($data['title'])) {
            $update_data['title'] = sanitize_text_field($data['title']);
            $formats[] = '%s';
        }

        if (isset($data['description'])) {
            $update_data['description'] = sanitize_textarea_field($data['description']);
            $formats[] = '%s';
        }

        if (isset($data['is_public'])) {
            $update_data['is_public'] = $data['is_public'] ? 1 : 0;
            $formats[] = '%d';
        }

        if (empty($update_data)) {
            return new WP_Error('no_data', __('No data to update.', 'restart-registry'));
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            array('id' => $registry_id),
            $formats,
            array('%d')
        );

        return $result !== false;
    }

    public function delete_registry($registry_id) {
        global $wpdb;
        
        $wpdb->delete($wpdb->prefix . 'restart_registry_items', array('registry_id' => $registry_id), array('%d'));
        $wpdb->delete($wpdb->prefix . 'restart_registry_invites', array('registry_id' => $registry_id), array('%d'));
        $wpdb->delete($wpdb->prefix . 'restart_registry_purchases', array('registry_id' => $registry_id), array('%d'));
        
        return $wpdb->delete($wpdb->prefix . 'restart_registries', array('id' => $registry_id), array('%d'));
    }

    public function add_item($registry_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'restart_registry_items';

        $url = esc_url_raw($data['url']);
        $affiliate_result = $this->affiliate_converter->convert_url($url);

        $result = $wpdb->insert(
            $table,
            array(
                'registry_id' => $registry_id,
                'name' => sanitize_text_field($data['name']),
                'description' => isset($data['description']) ? sanitize_textarea_field($data['description']) : '',
                'original_url' => $url,
                'affiliate_url' => $affiliate_result['affiliate_url'],
                'image_url' => isset($data['image_url']) ? esc_url_raw($data['image_url']) : '',
                'price' => isset($data['price']) ? floatval($data['price']) : null,
                'retailer' => $affiliate_result['retailer'],
                'quantity_needed' => isset($data['quantity']) ? intval($data['quantity']) : 1,
                'priority' => isset($data['priority']) ? sanitize_text_field($data['priority']) : 'medium',
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%s')
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to add item.', 'restart-registry'));
        }

        return array(
            'id' => $wpdb->insert_id,
            'is_affiliate' => $affiliate_result['is_affiliate'],
            'retailer' => $affiliate_result['retailer'],
        );
    }

    public function get_registry_items($registry_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'restart_registry_items';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE registry_id = %d ORDER BY priority DESC, created_at ASC",
            $registry_id
        ), ARRAY_A);
    }

    public function get_item($item_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'restart_registry_items';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $item_id
        ), ARRAY_A);
    }

    public function update_item($item_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'restart_registry_items';

        $update_data = array();
        $formats = array();

        if (isset($data['name'])) {
            $update_data['name'] = sanitize_text_field($data['name']);
            $formats[] = '%s';
        }

        if (isset($data['description'])) {
            $update_data['description'] = sanitize_textarea_field($data['description']);
            $formats[] = '%s';
        }

        if (isset($data['quantity'])) {
            $update_data['quantity_needed'] = intval($data['quantity']);
            $formats[] = '%d';
        }

        if (isset($data['priority'])) {
            $update_data['priority'] = sanitize_text_field($data['priority']);
            $formats[] = '%s';
        }

        if (isset($data['price'])) {
            $update_data['price'] = floatval($data['price']);
            $formats[] = '%f';
        }

        if (isset($data['image_url'])) {
            $update_data['image_url'] = esc_url_raw($data['image_url']);
            $formats[] = '%s';
        }

        if (empty($update_data)) {
            return new WP_Error('no_data', __('No data to update.', 'restart-registry'));
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            array('id' => $item_id),
            $formats,
            array('%d')
        );

        return $result !== false;
    }

    public function delete_item($item_id) {
        global $wpdb;
        
        $wpdb->delete($wpdb->prefix . 'restart_registry_purchases', array('item_id' => $item_id), array('%d'));
        
        return $wpdb->delete($wpdb->prefix . 'restart_registry_items', array('id' => $item_id), array('%d'));
    }

    public function mark_item_purchased($item_id, $quantity = 1, $purchaser_name = '', $purchaser_email = '', $is_anonymous = false) {
        global $wpdb;
        
        $item = $this->get_item($item_id);
        if (!$item) {
            return new WP_Error('not_found', __('Item not found.', 'restart-registry'));
        }

        $remaining = $item['quantity_needed'] - $item['quantity_purchased'];
        if ($quantity > $remaining) {
            return new WP_Error('quantity_exceeded', __('Cannot purchase more than needed.', 'restart-registry'));
        }

        $purchases_table = $wpdb->prefix . 'restart_registry_purchases';
        $wpdb->insert(
            $purchases_table,
            array(
                'item_id' => $item_id,
                'registry_id' => $item['registry_id'],
                'purchaser_name' => sanitize_text_field($purchaser_name),
                'purchaser_email' => sanitize_email($purchaser_email),
                'quantity' => $quantity,
                'is_anonymous' => $is_anonymous ? 1 : 0,
            ),
            array('%d', '%d', '%s', '%s', '%d', '%d')
        );

        $items_table = $wpdb->prefix . 'restart_registry_items';
        $wpdb->query($wpdb->prepare(
            "UPDATE $items_table SET quantity_purchased = quantity_purchased + %d WHERE id = %d",
            $quantity,
            $item_id
        ));

        return true;
    }

    public function send_invite($registry_id, $email) {
        global $wpdb;
        $table = $wpdb->prefix . 'restart_registry_invites';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE registry_id = %d AND email = %s",
            $registry_id,
            $email
        ));

        if ($existing) {
            return new WP_Error('already_invited', __('This email has already been invited.', 'restart-registry'));
        }

        $invite_token = wp_generate_password(32, false);

        $result = $wpdb->insert(
            $table,
            array(
                'registry_id' => $registry_id,
                'email' => sanitize_email($email),
                'invite_token' => $invite_token,
            ),
            array('%d', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to create invite.', 'restart-registry'));
        }

        $registry = $this->get_registry($registry_id);
        $registry_url = add_query_arg('registry', $registry['share_key'], home_url('/registry/'));
        
        $this->send_invite_email($email, $registry, $registry_url);

        return array(
            'invite_id' => $wpdb->insert_id,
            'token' => $invite_token,
        );
    }

    private function send_invite_email($email, $registry, $registry_url) {
        $user = get_userdata($registry['user_id']);
        $user_name = $user ? $user->display_name : 'Someone';

        $subject = sprintf(__('%s invited you to view their gift registry!', 'restart-registry'), $user_name);
        
        $message = sprintf(
            __("Hello!\n\n%s has invited you to view their gift registry: %s\n\nClick the link below to see their wishlist:\n%s\n\nBest regards,\nThe Registry Team", 'restart-registry'),
            $user_name,
            $registry['title'],
            $registry_url
        );

        $headers = array('Content-Type: text/plain; charset=UTF-8');

        wp_mail($email, $subject, $message, $headers);
    }

    public function get_registry_invites($registry_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'restart_registry_invites';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE registry_id = %d ORDER BY sent_at DESC",
            $registry_id
        ), ARRAY_A);
    }

    public function resend_invite($invite_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'restart_registry_invites';

        $invite = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $invite_id
        ), ARRAY_A);

        if (!$invite) {
            return new WP_Error('not_found', __('Invite not found.', 'restart-registry'));
        }

        $registry = $this->get_registry($invite['registry_id']);
        $registry_url = add_query_arg('registry', $registry['share_key'], home_url('/registry/'));
        
        $this->send_invite_email($invite['email'], $registry, $registry_url);

        $wpdb->update(
            $table,
            array('sent_at' => current_time('mysql')),
            array('id' => $invite_id),
            array('%s'),
            array('%d')
        );

        return true;
    }

    public function delete_invite($invite_id) {
        global $wpdb;
        return $wpdb->delete($wpdb->prefix . 'restart_registry_invites', array('id' => $invite_id), array('%d'));
    }

    public function can_view_registry($registry_id, $user_id = null) {
        $registry = $this->get_registry($registry_id);
        
        if (is_wp_error($registry)) {
            return false;
        }

        if ($registry['is_public']) {
            return true;
        }

        if ($user_id && $registry['user_id'] == $user_id) {
            return true;
        }

        return false;
    }

    public function can_edit_registry($registry_id, $user_id) {
        $registry = $this->get_registry($registry_id);
        
        if (is_wp_error($registry)) {
            return false;
        }

        return $registry['user_id'] == $user_id || current_user_can('manage_restart_registry');
    }

    private function generate_share_key() {
        return wp_generate_password(16, false);
    }

    public function get_item_purchases($item_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'restart_registry_purchases';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE item_id = %d ORDER BY purchased_at DESC",
            $item_id
        ), ARRAY_A);
    }
}
