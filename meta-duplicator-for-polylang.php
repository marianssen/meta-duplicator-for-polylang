<?php

/**
 * Plugin Name: Meta Duplicator for Polylang
 * Description: Copy custom fields and ACF data between Polylang translations. Adds a meta box to sync post meta across languages.
 * Version: 0.3
 * Author: Marián Rehák
 * Text Domain: meta-duplicator-for-polylang
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MDUFOPL_VERSION', '1');
define('MDUFOPL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MDUFOPL_PLUGIN_URL', plugin_dir_url(__FILE__));

class MDUFOPL_ContentSync
{
    /**
     * Plugin instance
     *
     * @var MDUFOPL_ContentSync
     */
    private static $instance = null;

    /**
     * Get plugin instance
     *
     * @return MDUFOPL_ContentSync
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        add_action('admin_init', array($this, 'check_dependencies'));
        add_action('add_meta_boxes', array($this, 'add_sync_meta_box'));
        add_action('wp_ajax_mdufopl_sync_content', array($this, 'ajax_sync_content'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    /**
     * Check if required dependencies are active
     */
    public function check_dependencies()
    {
        if (!function_exists('pll_get_post_translations')) {
            add_action('admin_notices', array($this, 'dependency_notice'));
            return false;
        }
        return true;
    }

    /**
     * Show dependency notice
     */
    public function dependency_notice()
    {
        $plugin_name = esc_html__('Meta Duplicator for Polylang', 'meta-duplicator-for-polylang');
        $message = sprintf(
            /* translators: %s: Plugin name */
            esc_html__('%s requires the Polylang plugin to be installed and activated.', 'meta-duplicator-for-polylang'),
            '<strong>' . $plugin_name . '</strong>'
        );

        echo '<div class="notice notice-error"><p>' . wp_kses_post($message) . '</p></div>';
    }

    /**
     * Add the synchronization meta box
     */
    public function add_sync_meta_box()
    {
        // Check if dependencies are met
        if (!$this->check_dependencies()) {
            return;
        }

        // Only add to public post types
        $post_types = get_post_types(array('public' => true), 'names');

        // Allow filtering of post types
        $post_types = apply_filters('mdufopl_post_types', $post_types);

        foreach ($post_types as $post_type) {
            add_meta_box(
                'mdufopl-sync-meta-box',
                __('Sync Content from Language', 'meta-duplicator-for-polylang'),
                array($this, 'render_sync_meta_box'),
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Render the synchronization meta box
     *
     * @param WP_Post $post Current post object
     */
    public function render_sync_meta_box($post)
    {
        // Verify post object
        if (!$post || !is_object($post)) {
            return;
        }

        // Check if Polylang is active
        if (!function_exists('pll_get_post_translations')) {
            printf(
                '<p>%s</p>',
                esc_html__('Polylang plugin is not active.', 'meta-duplicator-for-polylang')
            );
            return;
        }

        $current_post_id = (int) $post->ID;
        $current_lang = pll_get_post_language($current_post_id);

        // Get all translations of this post
        $translations = pll_get_post_translations($current_post_id);

        if (empty($translations) || count($translations) <= 1) {
            printf(
                '<p>%s</p>',
                esc_html__('No other language variations found.', 'meta-duplicator-for-polylang')
            );
            return;
        }

        // Add nonce for security
        wp_nonce_field('mdufopl_sync_content_' . $current_post_id, 'mdufopl_sync_nonce');

        echo '<div id="mdufopl-sync-buttons">';
        printf(
            '<p>%s</p>',
            esc_html__('Click the button to copy content from the selected language version:', 'meta-duplicator-for-polylang')
        );

        foreach ($translations as $lang_code => $translation_id) {
            // Skip current language
            if ($lang_code === $current_lang || (int) $translation_id === $current_post_id) {
                continue;
            }

            // Verify translation exists and user can edit it
            $translation_post = get_post($translation_id);
            if (!$translation_post || !current_user_can('edit_post', $translation_id)) {
                continue;
            }

            // Get language name
            $lang_name = $this->get_language_name($lang_code);

            printf(
                '<div style="margin-bottom: 10px;">
                    <button type="button" class="button button-secondary mdufopl-sync-btn" style="width: 100%%;" 
                            data-source-id="%d" 
                            data-target-id="%d" 
                            data-lang-name="%s">
                        %s
                    </button>
                </div>',
                (int) $translation_id,
                esc_html($current_post_id),
                esc_attr($lang_name),
                esc_html($lang_name)
            );
        }

        echo '</div>';
        echo '<div id="mdufopl-sync-message" style="margin-top: 10px;"></div>';
    }

    /**
     * Handle AJAX sync request
     */
    public function ajax_sync_content()
    {
        // Verify request method
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die(
                wp_json_encode(array(
                    'success' => false,
                    'message' => __('Invalid request method.', 'meta-duplicator-for-polylang')
                )),
                '',
                array(
                    'response' => 400,
                    'content_type' => 'application/json'
                )
            );
        }

        // Sanitize and validate input
        $source_post_id = isset($_POST['source_id']) ? (int) $_POST['source_id'] : 0;
        $target_post_id = isset($_POST['target_id']) ? (int) $_POST['target_id'] : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        // Verify nonce with specific action
        if (!wp_verify_nonce($nonce, 'mdufopl_sync_content_' . $target_post_id)) {
            wp_die(
                wp_json_encode(array(
                    'success' => false,
                    'message' => __('Security check failed.', 'meta-duplicator-for-polylang')
                )),
                '',
                array(
                    'response' => 403,
                    'content_type' => 'application/json'
                )
            );
        }

        // Validate post IDs
        if ($source_post_id <= 0 || $target_post_id <= 0) {
            wp_die(
                wp_json_encode(array(
                    'success' => false,
                    'message' => __('Invalid post IDs.', 'meta-duplicator-for-polylang')
                )),
                '',
                array(
                    'response' => 400,
                    'content_type' => 'application/json'
                )
            );
        }

        // Check user permissions for both posts
        if (!current_user_can('edit_post', $target_post_id) || !current_user_can('edit_post', $source_post_id)) {
            wp_die(
                wp_json_encode(array(
                    'success' => false,
                    'message' => __('Permission denied.', 'meta-duplicator-for-polylang')
                )),
                '',
                array(
                    'response' => 403,
                    'content_type' => 'application/json'
                )
            );
        }

        // Verify posts exist
        $source_post = get_post($source_post_id);
        $target_post = get_post($target_post_id);

        if (!$source_post || !$target_post) {
            wp_die(
                wp_json_encode(array(
                    'success' => false,
                    'message' => __('One or both posts do not exist.', 'meta-duplicator-for-polylang')
                )),
                '',
                array(
                    'response' => 404,
                    'content_type' => 'application/json'
                )
            );
        }

        // Verify posts are translations of each other
        if (!$this->are_posts_translations($source_post_id, $target_post_id)) {
            wp_die(
                wp_json_encode(array(
                    'success' => false,
                    'message' => __('Posts are not translations of each other.', 'meta-duplicator-for-polylang')
                )),
                '',
                array(
                    'response' => 400,
                    'content_type' => 'application/json'
                )
            );
        }

        // Perform the synchronization
        $result = $this->sync_post_meta($source_post_id, $target_post_id);

        if ($result['success']) {
            $message = sprintf(
                /* translators: %d: number of synced fields */
                esc_html__('Content was successfully copied (%d fields).', 'meta-duplicator-for-polylang'),
                $result['count']
            );
            wp_die(
                wp_json_encode(array(
                    'success' => true,
                    'message' => $message
                ))
            );
        } else {
            wp_die(
                wp_json_encode(array(
                    'success' => false,
                    'message' => $result['message']
                )),
                '',
                array(
                    'response' => 500,
                    'content_type' => 'application/json'
                )
            );
        }
    }

    /**
     * Verify that two posts are translations of each other
     *
     * @param int $source_id Source post ID
     * @param int $target_id Target post ID
     * @return bool
     */
    private function are_posts_translations($source_id, $target_id)
    {
        $translations = pll_get_post_translations($target_id);
        return in_array($source_id, $translations, true);
    }

    /**
     * Synchronize post meta and ACF fields
     *
     * @param int $source_post_id Source post ID
     * @param int $target_post_id Target post ID
     * @return array Result array with success status, count, and message
     */
    private function sync_post_meta($source_post_id, $target_post_id)
    {
        // Get all meta from source post
        $source_meta = get_post_meta($source_post_id);

        if (empty($source_meta)) {
            return array(
                'success' => false,
                'count' => 0,
                'message' => __('No metadata found to copy.', 'meta-duplicator-for-polylang')
            );
        }

        // Get excluded fields (filterable)
        $excluded_fields = apply_filters('mdufopl_excluded_fields', array(
            '_edit_lock',
            '_edit_last',
            '_wp_old_slug',
            '_wp_old_date',
            '_pingme',
            '_encloseme',
            '_wp_trash_meta_status',
            '_wp_trash_meta_time',
            '_wp_desired_post_slug',
            '_wp_attachment_metadata',
            '_thumbnail_id' // Usually language-specific
        ));

        $synced_count = 0;
        $errors = array();

        // Copy meta from source to target
        foreach ($source_meta as $key => $values) {
            // Skip excluded fields and Polylang-specific meta
            if (in_array($key, $excluded_fields) || $this->is_polylang_meta($key)) {
                continue;
            }

            // Allow filtering of individual meta keys
            if (!apply_filters('mdufopl_sync_meta_key', true, $key, $source_post_id, $target_post_id)) {
                continue;
            }

            try {
                // Delete existing meta first
                delete_post_meta($target_post_id, $key);

                // Add each value (handles multiple values for same key)
                foreach ($values as $value) {
                    $unserialized_value = maybe_unserialize($value);
                    if (add_post_meta($target_post_id, $key, $unserialized_value)) {
                        $synced_count++;
                    }
                }
            } catch (Exception $e) {
                $error_message = sprintf(
                    /* translators: 1: meta key, 2: error message */
                    esc_html__('Error syncing meta key %1$s: %2$s', 'meta-duplicator-for-polylang'),
                    $key,
                    $e->getMessage()
                );
                $errors[] = $error_message;
            }
        }

        // Special handling for ACF fields
        if (function_exists('get_fields')) {
            try {
                $acf_result = $this->sync_acf_fields($source_post_id, $target_post_id);
                $synced_count += $acf_result['count'];
                $errors = array_merge($errors, $acf_result['errors']);
            } catch (Exception $e) {
                $error_message = sprintf(
                    /* translators: %s: error message */
                    esc_html__('Error syncing ACF fields: %s', 'meta-duplicator-for-polylang'),
                    $e->getMessage()
                );
                $errors[] = $error_message;
            }
        }

        // Fire action after sync
        do_action('mdufopl_after_sync', $source_post_id, $target_post_id, $synced_count);

        if ($synced_count > 0) {
            return array(
                'success' => true,
                'count' => $synced_count,
                'message' => '',
                'errors' => $errors
            );
        } else {
            return array(
                'success' => false,
                'count' => 0,
                'message' => __('No fields were synchronized.', 'meta-duplicator-for-polylang'),
                'errors' => $errors
            );
        }
    }

    /**
     * Check if meta key is Polylang-specific
     *
     * @param string $key Meta key to check
     * @return bool
     */
    private function is_polylang_meta($key)
    {
        $polylang_keys = array(
            '_pll_sync_delete',
            '_pll_sync_post',
            '_translations_',
            '_polylang_'
        );

        foreach ($polylang_keys as $pll_key) {
            if (strpos($key, $pll_key) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Synchronize ACF fields specifically
     *
     * @param int $source_post_id Source post ID
     * @param int $target_post_id Target post ID
     * @return array Result with count and errors
     */
    private function sync_acf_fields($source_post_id, $target_post_id)
    {
        $source_fields = get_fields($source_post_id);
        $errors = array();

        if (!$source_fields) {
            return array(
                'count' => 0,
                'errors' => array()
            );
        }

        $acf_count = 0;
        foreach ($source_fields as $field_name => $field_value) {
            try {
                // Get field object to check field type
                $field_object = get_field_object($field_name, $source_post_id);

                if ($field_object && isset($field_object['type'])) {
                    $field_value = $this->process_field_value($field_value, $field_object, $source_post_id);
                }

                if (update_field($field_name, $field_value, $target_post_id)) {
                    $acf_count++;
                }
            } catch (Exception $e) {
                $error_message = sprintf(
                    /* translators: 1: field name, 2: error message */
                    esc_html__('Error syncing ACF field %1$s: %2$s', 'meta-duplicator-for-polylang'),
                    $field_name,
                    $e->getMessage()
                );
                $errors[] = $error_message;
            }
        }

        return array(
            'count' => $acf_count,
            'errors' => $errors
        );
    }

    /**
     * Process field value based on field type
     *
     * @param mixed $value Field value
     * @param array $field_object Field object with type information
     * @param int $source_post_id Source post ID
     * @return mixed Processed field value
     */
    private function process_field_value($value, $field_object, $source_post_id)
    {
        $field_type = $field_object['type'];

        switch ($field_type) {
            case 'oembed':
                // For oEmbed fields, get the raw URL instead of cached HTML
                $raw_value = get_post_meta($source_post_id, $field_object['name'], true);
                return $raw_value;

            case 'image':
            case 'file':
                // For media fields, ensure we're copying the attachment ID, not the array
                if (is_array($value) && isset($value['ID'])) {
                    return $value['ID'];
                }
                return $value;

            case 'gallery':
                // For gallery fields, ensure we have attachment IDs
                if (is_array($value)) {
                    $ids = array();
                    foreach ($value as $item) {
                        if (is_array($item) && isset($item['ID'])) {
                            $ids[] = $item['ID'];
                        } elseif (is_numeric($item)) {
                            $ids[] = $item;
                        }
                    }
                    return $ids;
                }
                return $value;

            case 'post_object':
            case 'page_link':
            case 'relationship':
                // For post relationship fields, ensure we have post IDs
                if (is_array($value)) {
                    $ids = array();
                    foreach ($value as $item) {
                        if (is_object($item) && isset($item->ID)) {
                            $ids[] = $item->ID;
                        } elseif (is_array($item) && isset($item['ID'])) {
                            $ids[] = $item['ID'];
                        } elseif (is_numeric($item)) {
                            $ids[] = $item;
                        }
                    }
                    return $ids;
                } elseif (is_object($value) && isset($value->ID)) {
                    return $value->ID;
                }
                return $value;

            case 'user':
                // For user fields, ensure we have user IDs
                if (is_array($value)) {
                    $ids = array();
                    foreach ($value as $item) {
                        if (is_object($item) && isset($item->ID)) {
                            $ids[] = $item->ID;
                        } elseif (is_array($item) && isset($item['ID'])) {
                            $ids[] = $item['ID'];
                        } elseif (is_numeric($item)) {
                            $ids[] = $item;
                        }
                    }
                    return $ids;
                } elseif (is_object($value) && isset($value->ID)) {
                    return $value->ID;
                }
                return $value;

            case 'taxonomy':
                // For taxonomy fields, ensure we have term IDs
                if (is_array($value)) {
                    $ids = array();
                    foreach ($value as $item) {
                        if (is_object($item) && isset($item->term_id)) {
                            $ids[] = $item->term_id;
                        } elseif (is_array($item) && isset($item['term_id'])) {
                            $ids[] = $item['term_id'];
                        } elseif (is_numeric($item)) {
                            $ids[] = $item;
                        }
                    }
                    return $ids;
                } elseif (is_object($value) && isset($value->term_id)) {
                    return $value->term_id;
                }
                return $value;

            case 'repeater':
            case 'flexible_content':
                // For repeater and flexible content, recursively process sub-fields
                if (is_array($value)) {
                    foreach ($value as $row_key => $row_value) {
                        if (is_array($row_value)) {
                            foreach ($row_value as $sub_field_key => $sub_field_value) {
                                // Get sub-field object
                                $sub_field_name = $field_object['name'] . '_' . $row_key . '_' . $sub_field_key;
                                $sub_field_object = get_field_object($sub_field_name, $source_post_id);

                                if ($sub_field_object && isset($sub_field_object['type'])) {
                                    $value[$row_key][$sub_field_key] = $this->process_field_value(
                                        $sub_field_value,
                                        $sub_field_object,
                                        $source_post_id
                                    );
                                }
                            }
                        }
                    }
                }
                return $value;

            case 'group':
                // For group fields, recursively process sub-fields
                if (is_array($value)) {
                    foreach ($value as $sub_field_key => $sub_field_value) {
                        $sub_field_name = $field_object['name'] . '_' . $sub_field_key;
                        $sub_field_object = get_field_object($sub_field_name, $source_post_id);

                        if ($sub_field_object && isset($sub_field_object['type'])) {
                            $value[$sub_field_key] = $this->process_field_value(
                                $sub_field_value,
                                $sub_field_object,
                                $source_post_id
                            );
                        }
                    }
                }
                return $value;

            default:
                // For all other field types, return as-is
                return $value;
        }
    }

    /**
     * Get language name with fallback
     *
     * @param string $lang_code Language code
     * @return string Language name
     */
    private function get_language_name($lang_code)
    {
        // Sanitize input
        $lang_code = sanitize_text_field($lang_code);

        // Try the standard Polylang function first
        if (function_exists('pll_get_language_name')) {
            $name = pll_get_language_name($lang_code);
            if ($name) {
                return $name;
            }
        }

        // Fallback: get from languages array
        if (function_exists('pll_languages_list')) {
            $languages = pll_languages_list(array('fields' => 'name'));
            $lang_slugs = pll_languages_list(array('fields' => 'slug'));

            if (is_array($languages) && is_array($lang_slugs)) {
                $index = array_search($lang_code, $lang_slugs, true);
                if ($index !== false && isset($languages[$index])) {
                    return $languages[$index];
                }
            }
        }

        // Final fallback: use language code
        return strtoupper($lang_code);
    }

    /**
     * Enqueue scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_scripts($hook)
    {
        // Only load on post edit screens
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }

        // Check if we're on a post type that supports our meta box
        global $post;
        if (!$post || !$this->check_dependencies()) {
            return;
        }

        wp_enqueue_script(
            'mdufopl-sync-content',
            MDUFOPL_PLUGIN_URL . 'assets/js/sync-content.js',
            array('jquery'),
            MDUFOPL_VERSION,
            true
        );

        // Localize script for translations and AJAX
        $script_data = array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mdufopl_sync_content_' . $post->ID),
            'messages' => array(
                /* translators: 1: polylang language */
                'confirm' => esc_html__('Are you sure you want to copy all content from the %s language version? This action will overwrite all current metadata and ACF fields.', 'meta-duplicator-for-polylang'),
                'copying' => esc_html__('Copying...', 'meta-duplicator-for-polylang'),
                'copyingContent' => esc_html__('Copying content...', 'meta-duplicator-for-polylang'),
                'error' => esc_html__('Error:', 'meta-duplicator-for-polylang'),
                'serverError' => esc_html__('Error communicating with server.', 'meta-duplicator-for-polylang')
            )
        );

        wp_localize_script('jquery', 'mdufoplDuplicateContent', $script_data);
    }
}

// Initialize the plugin
function mdufopl_init()
{
    return MDUFOPL_ContentSync::get_instance();
}

// Hook into plugins_loaded to ensure all plugins are loaded
add_action('plugins_loaded', 'mdufopl_init');

// Activation hook
register_activation_hook(__FILE__, 'mdufopl_activate');

function mdufopl_activate()
{
    // Check if Polylang is active
    if (!function_exists('pll_get_post_translations')) {
        deactivate_plugins(plugin_basename(__FILE__));

        $plugin_name = esc_html__('Meta Duplicator for Polylang', 'meta-duplicator-for-polylang');
        $message = sprintf(
            /* translators: %s: Plugin name */
            esc_html__('%s requires the Polylang plugin to be installed and activated.', 'meta-duplicator-for-polylang'),
            '<strong>' . $plugin_name . '</strong>'
        );

        wp_die(
            wp_kses_post($message),
            esc_html__('Plugin Activation Error', 'meta-duplicator-for-polylang'),
            array('back_link' => true)
        );
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'mdufopl_deactivate');

function mdufopl_deactivate()
{
    // Clean up if needed
    // Currently no cleanup required
}
