<?php
/**
 * Plugin Name: Dev Favicon Switcher
 * Plugin URI: https://www.karasunouta.com/
 * Description: Automatically switches favicon between production and development environments
 * Version: 1.3.0
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * Author: karasunouta
 * Author URI: https://www.karasunouta.com/
 * Text Domain: dev-favicon-switcher
 * Domain Path: /languages
 * License: Commercial
 * License URI: https://www.karasunouta.com/
 * 
 * Copyright (c) 2026 karasunouta
 * Licensed for two sites use.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Dev_Favicon_Switcher
{

    /**
     * プラグインバージョン
     */
    const VERSION = '1.3.0';

    private $option_name = 'dev_favicon_switcher_settings';
    private $required_sizes = array(32, 180, 192, 270);
    private $slug = 'dev-favicon-switcher';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // 設定を取得してenabledチェック
        $settings = get_option($this->option_name, array('enabled' => '1'));
        $is_enabled = !empty($settings['enabled']) && $settings['enabled'] === '1';

        // Frontend filters（enabledの場合のみ）
        if ($is_enabled) {
            add_filter('get_site_icon_url', array($this, 'replace_favicon_url'), 10, 1);
            add_filter('site_icon_meta_tags', array($this, 'replace_favicon_meta_tags'), 10, 1);
        }

        // 画像切り抜きAjax handler
        add_action('wp_ajax_dev_favicon_crop_image', array($this, 'ajax_crop_image'));

        // 開発アイコン削除Ajax handler
        add_action('wp_ajax_dev_favicon_remove_icon', array($this, 'ajax_remove_icon'));

        // インストール済みプラグイン一覧から設定ページにリンク
        add_filter(
            'plugin_action_links_' . plugin_basename(__FILE__),
        [$this, 'add_settings_link']
        );

        // プラグイン有効化時のリダイレクト
        add_action('activated_plugin', array($this, 'redirect_after_activation'));
    }

    public function add_settings_page()
    {
        add_options_page(
            __('Dev Favicon Switcher', 'dev-favicon-switcher'),
            __('Dev Favicon', 'dev-favicon-switcher'),
            'manage_options',
            $this->slug,
            array($this, 'render_settings_page')
        );
    }

    public function register_settings()
    {
        register_setting(
            'dev_favicon_settings_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );
    }

    public function sanitize_settings($input)
    {
        $sanitized = array();

        // Enabled/Disabled
        $sanitized['enabled'] = !empty($input['enabled']) ? '1' : '0';

        // Dev icon ID
        $sanitized['dev_icon_id'] = !empty($input['dev_icon_id']) ? absint($input['dev_icon_id']) : '';

        // Auto-detect
        $sanitized['auto_detect'] = !empty($input['auto_detect']) ? '1' : '0';

        // Dev URLs (textarea, one per line)
        $sanitized['dev_urls'] = !empty($input['dev_urls']) ? sanitize_textarea_field($input['dev_urls']) : '';

        // Custom sizes (textarea, numbers only)
        if (!empty($input['custom_sizes'])) {
            $lines = explode("\n", $input['custom_sizes']);
            $valid_sizes = array();
            foreach ($lines as $line) {
                $size = trim($line);
                if (is_numeric($size) && $size > 0) {
                    $valid_sizes[] = intval($size);
                }
            }
            $sanitized['custom_sizes'] = implode("\n", $valid_sizes);
        }
        else {
            $sanitized['custom_sizes'] = '';
        }

        // Development iconが設定されている場合、必要なサイズを自動生成
        if (!empty($sanitized['dev_icon_id'])) {
            $this->generate_icon_sizes($sanitized['dev_icon_id'], $sanitized['custom_sizes']);
        }

        return $sanitized;
    }

    /**
     * プラグイン一覧ページに設定メニューへのリンクを追加
     */
    public function add_settings_link(array $links): array
    {
        $settings_url = admin_url("admin.php?page={$this->slug}");

        $settings_link = '<a href="' . esc_url($settings_url) . '">' . __('Settings') . '</a>';

        // 行の先頭に追加
        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * 画像切り抜きAjaxハンドラー
     */
    public function ajax_crop_image()
    {
        check_ajax_referer('dev-favicon-crop', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }

        $attachment_id = absint($_POST['id']);

        // cropDetailsが存在しない場合はエラー
        if (empty($_POST['cropDetails'])) {
            error_log('Dev Favicon: cropDetails is missing from request');
            wp_send_json_error(array('message' => 'Crop details missing'));
        }

        $crop_details = json_decode(stripslashes($_POST['cropDetails']), true);

        if (!$crop_details || !isset($crop_details['x1'])) {
            error_log('Dev Favicon: Failed to parse cropDetails');
            wp_send_json_error(array('message' => 'Invalid crop details'));
        }

        error_log('Dev Favicon: Cropping with details - ' . print_r($crop_details, true));

        // 元画像の情報を取得
        $original_file = get_attached_file($attachment_id);
        $original_basename = basename($original_file);
        $original_name = pathinfo($original_basename, PATHINFO_FILENAME);
        $extension = pathinfo($original_basename, PATHINFO_EXTENSION);

        // クロップ実行（一時ファイルとして生成）
        $cropped = wp_crop_image(
            $attachment_id,
            (int)$crop_details['x1'],
            (int)$crop_details['y1'],
            (int)$crop_details['width'],
            (int)$crop_details['height'],
            512,
            512
        );

        if (is_wp_error($cropped)) {
            error_log('Dev Favicon: Crop failed - ' . $cropped->get_error_message());
            wp_send_json_error(array('message' => $cropped->get_error_message()));
        }

        // アップロードディレクトリの情報を取得
        $upload_dir = wp_upload_dir();

        // 希望するファイル名を生成（croppedプレフィックス付き、番号なし）
        $desired_filename = 'cropped-' . $original_name . '.' . $extension;
        $target_path = $upload_dir['path'] . '/' . $desired_filename;

        // 既存ファイルがあれば削除（同じ画像で何度もクロップする場合を想定）
        if (file_exists($target_path)) {
            // 古いメディアライブラリエントリーも削除
            global $wpdb;
            $old_attachment = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE guid LIKE %s",
                '%' . $desired_filename
            ));
            if ($old_attachment) {
                wp_delete_attachment($old_attachment, true);
            }
        }

        // 一時ファイルを目的の場所に移動（リネーム）
        if (!@rename($cropped, $target_path)) {
            @unlink($cropped);
            wp_send_json_error(array('message' => 'Failed to move cropped file'));
        }

        // 添付ファイル情報の構成
        $attachment = array(
            'post_title' => 'dev-favicon',
            'post_mime_type' => 'image/' . $extension,
            'guid' => $upload_dir['url'] . '/' . $desired_filename
        );

        // メディアライブラリに登録（ファイルは既に正しい場所にある）
        $new_attachment_id = wp_insert_attachment($attachment, $target_path);

        if (is_wp_error($new_attachment_id)) {
            @unlink($target_path);
            wp_send_json_error(array('message' => $new_attachment_id->get_error_message()));
        }

        // メタデータの生成・更新
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        wp_update_attachment_metadata(
            $new_attachment_id,
            wp_generate_attachment_metadata($new_attachment_id, $target_path)
        );

        // ファビコン専用サイズを生成（これが抜けていた！）
        $settings = get_option($this->option_name);
        $custom_sizes = !empty($settings['custom_sizes']) ? $settings['custom_sizes'] : '';
        $result = $this->generate_icon_sizes($new_attachment_id, $custom_sizes);

        if (is_wp_error($result)) {
            error_log('Dev Favicon: Failed to generate sizes - ' . $result->get_error_message());
        }
        else {
            error_log('Dev Favicon: Generated sizes - ' . print_r($result, true));
        }

        error_log('Dev Favicon: Crop successful - ID: ' . $new_attachment_id . ', File: ' . $desired_filename);

        // レスポンス生成
        $response = wp_prepare_attachment_for_js($new_attachment_id);
        wp_send_json_success($response);
    }

    /**
     * 開発アイコン設定削除Ajaxハンドラー
     */
    public function ajax_remove_icon()
    {
        check_ajax_referer('dev_favicon_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        // 現在の設定を取得
        $settings = get_option($this->option_name, array());

        // dev_icon_idのみをクリア（他の設定は保持）
        $settings['dev_icon_id'] = '';

        // 設定を更新
        update_option($this->option_name, $settings);

        error_log('Dev Favicon: Icon setting removed (image file preserved)');

        wp_send_json_success(array('message' => 'Icon setting removed'));
    }

    public function enqueue_admin_scripts($hook)
    {
        if ($hook !== "settings_page_{$this->slug}") {
            return;
        }

        // WordPress標準のメディアライブラリとクロッパー
        wp_enqueue_media();
        wp_enqueue_script('media-views');
        wp_enqueue_script('customize-controls');

        // ビルドされたJSファイルを読み込み
        $asset_file = include plugin_dir_path(__FILE__) . 'build/index.asset.php';

        wp_enqueue_script(
            'dev-favicon-admin',
            plugins_url('build/index.js', __FILE__),
            array_merge($asset_file['dependencies'], array('media-views', 'customize-controls')),
            $asset_file['version'],
            true
        );

        wp_localize_script('dev-favicon-admin', 'devFaviconAjax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dev_favicon_nonce'),
            'crop_nonce' => wp_create_nonce('dev-favicon-crop') // この行を追加
        ));

        // Customizer用のスタイル
        wp_enqueue_style('customize-controls');

        wp_enqueue_style(
            'dev-favicon-admin',
            plugins_url('assets/css/admin.css', __FILE__),
            array(),
            '1.0.0'
        );
    }

    public function render_settings_page()
    {
        $settings = get_option($this->option_name, array(
            'enabled' => '1',
            'dev_icon_id' => '',
            'dev_urls' => '',
            'auto_detect' => '1',
            'custom_sizes' => ''
        ));

        $current_icon_id = get_option('site_icon');
        $current_icon_url = $current_icon_id ? wp_get_attachment_image_url($current_icon_id, 'full') : '';

        $dev_icon_url = !empty($settings['dev_icon_id']) ? 
            wp_get_attachment_image_url($settings['dev_icon_id'], 'full') : '';

?>
        <div class="wrap">
            <h1><?php _e('Dev Favicon Switcher Settings', 'dev-favicon-switcher'); ?></h1>
            
            <?php if ($this->is_dev_environment($settings)): ?>
                <div class="notice notice-info">
                    <p><?php _e('Development environment detected!', 'dev-favicon-switcher'); ?></p>
                </div>
            <?php
        endif; ?>
            
            <form method="post" action="options.php" id="dev-favicon-form">
                <?php settings_fields('dev_favicon_settings_group'); ?>
                
                <table class="form-table">
                    <!-- Enable/Disable Switch (一番上に配置) -->
                    <tr>
                        <th scope="row">
                            <?php _e('Plugin Status', 'dev-favicon-switcher'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                    name="<?php echo $this->option_name; ?>[enabled]" 
                                    value="1" 
                                    <?php checked($settings['enabled'], '1'); ?>>
                                <strong><?php _e('Enable development favicon switching', 'dev-favicon-switcher'); ?></strong>
                            </label>
                            <p class="description">
                                <?php _e('Uncheck this to temporarily disable favicon switching without losing your settings. Useful for testing or presentations.', 'dev-favicon-switcher'); ?>
                            </p>
                        </td>
                    </tr>

                    <!-- Production Icon (Read-only) -->
                    <tr>
                        <th scope="row">
                            <?php _e('Production Favicon', 'dev-favicon-switcher'); ?>
                        </th>
                        <td>
                            <?php if ($current_icon_url): ?>
                                <img src="<?php echo esc_url($current_icon_url); ?>" 
                                     style="max-width: 64px; height: auto; border: 1px solid #ddd; padding: 5px;">
                                <p class="description">
                                    <?php printf(__('Current site icon (set in <a href="%s">Settings > General</a>)', 'dev-favicon-switcher'), admin_url('options-general.php')); ?>
                                </p>
                            <?php
        else: ?>
                                <p class="description">
                                    <?php printf(__('No site icon set. Please set one in <a href="%s">Settings > General</a>', 'dev-favicon-switcher'), admin_url('options-general.php')); ?>
                                </p>
                            <?php
        endif; ?>
                        </td>
                    </tr>
                    
                    <!-- Development Icon -->
                    <tr>
                        <th scope="row">
                            <label for="dev_icon_id"><?php _e('Development Favicon', 'dev-favicon-switcher'); ?></label>
                        </th>
                        <td>
                            <div id="dev-icon-preview">
                                <?php if ($dev_icon_url): ?>
                                    <img src="<?php echo esc_url($dev_icon_url); ?>" 
                                         style="max-width: 64px; height: auto; border: 1px solid #ddd; padding: 5px;">
                                <?php
        endif; ?>
                            </div>
                            <input type="hidden" 
                                   name="<?php echo $this->option_name; ?>[dev_icon_id]" 
                                   id="dev_icon_id" 
                                   value="<?php echo esc_attr($settings['dev_icon_id']); ?>">
                            <button type="button" class="button" id="select-dev-icon">
                                <?php _e('Select Development Icon', 'dev-favicon-switcher'); ?>
                            </button>
                            <button type="button" class="button" id="remove-dev-icon" 
                                    <?php echo empty($settings['dev_icon_id']) ? 'style="display:none;"' : ''; ?>>
                                <?php _e('Remove', 'dev-favicon-switcher'); ?>
                            </button>
                            <p class="description">
                                <?php _e('Choose an icon that will be displayed in development environments', 'dev-favicon-switcher'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Auto-detect Development Environment -->
                    <tr>
                        <th scope="row">
                            <?php _e('Auto-detect Development', 'dev-favicon-switcher'); ?>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="<?php echo $this->option_name; ?>[auto_detect]" 
                                       value="1" 
                                       <?php checked($settings['auto_detect'], '1'); ?>>
                                <?php _e('Automatically detect .local, .test, .dev domains', 'dev-favicon-switcher'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Recommended: Enable this to automatically apply development favicon on common development domains', 'dev-favicon-switcher'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Development URLs -->
                    <tr>
                        <th scope="row">
                            <label for="dev_urls"><?php _e('Development URLs', 'dev-favicon-switcher'); ?></label>
                        </th>
                        <td>
                            <textarea name="<?php echo $this->option_name; ?>[dev_urls]" 
                                      id="dev_urls" 
                                      rows="4" 
                                      class="large-text"
                                      placeholder="https://mysite.local/&#10;https://staging.mysite.com/"><?php echo esc_textarea($settings['dev_urls']); ?></textarea>
                            <p class="description">
                                <?php _e('Enter development URLs (one per line). The plugin will switch to development favicon when the current URL starts with any of these.', 'dev-favicon-switcher'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Custom Sizes (Advanced) -->
                    <tr>
                        <th scope="row">
                            <label for="custom_sizes"><?php _e('Custom Icon Sizes', 'dev-favicon-switcher'); ?></label>
                        </th>
                        <td>
                            <textarea name="<?php echo $this->option_name; ?>[custom_sizes]" 
                                      id="custom_sizes" 
                                      rows="3" 
                                      class="regular-text"
                                      placeholder="64&#10;128&#10;256"><?php echo esc_textarea($settings['custom_sizes']); ?></textarea>
                            <p class="description">
                                <?php _e('Optional: Add custom icon sizes in pixels (one per line). Standard sizes (32, 180, 192, 270) are always included.', 'dev-favicon-switcher'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(__('Save Settings', 'dev-favicon-switcher')); ?>
            </form>
        </div>
        <?php
    }

    public function ajax_check_sizes()
    {
        check_ajax_referer('dev_favicon_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $settings = get_option($this->option_name);
        if (empty($settings['dev_icon_id'])) {
            wp_send_json_error('No development icon selected');
        }

        $file_path = get_attached_file($settings['dev_icon_id']);
        if (!$file_path || !file_exists($file_path)) {
            wp_send_json_error('Development icon file not found');
        }

        $missing_sizes = array();
        $existing_sizes = array();

        $path_parts = pathinfo($file_path);

        foreach ($this->required_sizes as $size) {
            $sized_file = $path_parts['dirname'] . '/' . $path_parts['filename'] . '-' . $size . 'x' . $size . '.' . $path_parts['extension'];

            if (file_exists($sized_file)) {
                $existing_sizes[] = $size;
            }
            else {
                $missing_sizes[] = $size;
            }
        }

        wp_send_json_success(array(
            'existing' => $existing_sizes,
            'missing' => $missing_sizes
        ));
    }

    public function ajax_generate_sizes()
    {
        check_ajax_referer('dev_favicon_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $settings = get_option($this->option_name);
        if (empty($settings['dev_icon_id'])) {
            wp_send_json_error('No development icon selected');
        }

        $result = $this->generate_icon_sizes($settings['dev_icon_id']);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    private function generate_icon_sizes($attachment_id, $custom_sizes_str = '')
    {
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return new WP_Error('file_not_found', 'Icon file not found');
        }

        // 基本サイズ + カスタムサイズ
        $sizes = $this->required_sizes;

        if (!empty($custom_sizes_str)) {
            $custom_lines = explode("\n", $custom_sizes_str);
            foreach ($custom_lines as $line) {
                $size = intval(trim($line));
                if ($size > 0 && !in_array($size, $sizes)) {
                    $sizes[] = $size;
                }
            }
        }

        $generated = array();
        $skipped = array();
        $errors = array();

        $prod_extension = pathinfo($file_path, PATHINFO_EXTENSION);

        foreach ($sizes as $size) {
            // ファイル名の最後の拡張子のみを置換（より安全）
            $path_parts = pathinfo($file_path);
            $sized_file = $path_parts['dirname'] . '/' . $path_parts['filename'] . '-' . $size . 'x' . $size . '.' . $path_parts['extension'];

            if (file_exists($sized_file)) {
                $skipped[] = $size;
                continue;
            }

            // 毎回新しいエディターインスタンスを作成（重要！）
            $image = wp_get_image_editor($file_path);

            if (is_wp_error($image)) {
                $errors[] = sprintf('Size %dx%d: %s', $size, $size, $image->get_error_message());
                continue;
            }

            // リサイズ
            $resize_result = $image->resize($size, $size, true);

            if (is_wp_error($resize_result)) {
                $errors[] = sprintf('Size %dx%d: %s', $size, $size, $resize_result->get_error_message());
                continue;
            }

            // 保存
            $saved = $image->save($sized_file);

            if (is_wp_error($saved)) {
                $errors[] = sprintf('Size %dx%d: %s', $size, $size, $saved->get_error_message());
            }
            else {
                $generated[] = $size;
            }

            // メモリ解放（念のため）
            unset($image);
        }

        return array(
            'generated' => $generated,
            'skipped' => $skipped,
            'errors' => $errors
        );
    }

    private function is_dev_environment($settings = null)
    {
        if (!$settings) {
            $settings = get_option($this->option_name);
        }

        // プラグインが無効化されている場合は常にfalse
        if (empty($settings['enabled']) || $settings['enabled'] !== '1') {
            return false;
        }

        $current_url = home_url();

        // 自動検出が有効な場合
        if (!empty($settings['auto_detect']) && $settings['auto_detect'] === '1') {
            $hostname = parse_url($current_url, PHP_URL_HOST);
            $dev_extensions = array('.local', '.test', '.dev');
            foreach ($dev_extensions as $ext) {
                if (strpos($hostname, $ext) !== false) {
                    return true;
                }
            }
        }

        // 手動で設定されたURLをチェック
        if (!empty($settings['dev_urls'])) {
            $dev_urls = explode("\n", $settings['dev_urls']);
            foreach ($dev_urls as $dev_url) {
                $dev_url = trim($dev_url);
                if (!empty($dev_url) && strpos($current_url, rtrim($dev_url, '/')) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    public function replace_favicon_url($url)
    {
        // 開発環境以外では独自ファビコンの適用処理回避
        if (!$this->is_dev_environment()) {
            return $url;
        }

        // 「設定 > 一般」ページでは適用処理回避（サイトアイコン欄に通常のサイトアイコンを表示）  
        global $pagenow;
        if (is_admin() && $pagenow === 'options-general.php' && !isset($_GET['page'])) {
            // ※pageパラメーターがある場合は各プラグイン設定ページなど
            return $url;
        }

        // 独自ファビコン未設定なら適用処理回避
        $settings = get_option($this->option_name);
        if (empty($settings['dev_icon_id'])) {
            return $url;
        }

        // 開発アイコンのURLとファイル名を取得
        $dev_icon_url = wp_get_attachment_image_url($settings['dev_icon_id'], 'full');
        if (!$dev_icon_url) {
            return $url;
        }

        // 本番アイコンのファイル名と拡張子を取得
        $prod_filename = basename($url);
        $prod_extension = pathinfo($prod_filename, PATHINFO_EXTENSION);
        $prod_filename_base = preg_replace('/(-\d+x\d+)?\.' . preg_quote($prod_extension, '/') . '$/', '', $prod_filename);

        // 開発アイコンのファイル名と拡張子を取得
        $dev_filename = basename($dev_icon_url);
        $dev_extension = pathinfo($dev_filename, PATHINFO_EXTENSION);
        $dev_filename_base = preg_replace('/\.' . preg_quote($dev_extension, '/') . '$/', '', $dev_filename);

        // URLのファイル名部分を置換（サイズsuffixは保持、拡張子も動的に対応）
        $url = preg_replace(
            '#/' . preg_quote($prod_filename_base, '#') . '(-\d+x\d+)?\.' . preg_quote($prod_extension, '#') . '#',
            '/' . $dev_filename_base . '$1.' . $dev_extension,
            $url
        );

        // パスも置換（年月フォルダが異なる場合に対応）
        $prod_icon_id = get_option('site_icon');
        if ($prod_icon_id) {
            $prod_icon_url = wp_get_attachment_url($prod_icon_id);
            $prod_path = dirname(parse_url($prod_icon_url, PHP_URL_PATH));
            $dev_path = dirname(parse_url($dev_icon_url, PHP_URL_PATH));

            if ($prod_path !== $dev_path) {
                $url = str_replace($prod_path, $dev_path, $url);
            }
        }

        return $url;
    }

    public function replace_favicon_meta_tags($meta_tags)
    {
        // 開発環境以外では独自ファビコンの適用処理回避
        if (!$this->is_dev_environment()) {
            return $meta_tags;
        }

        // 独自ファビコン未設定なら適用処理回避
        $settings = get_option($this->option_name);
        if (empty($settings['dev_icon_id'])) {
            return $meta_tags;
        }

        $dev_icon_url = wp_get_attachment_image_url($settings['dev_icon_id'], 'full');
        if (!$dev_icon_url) {
            return $meta_tags;
        }

        // 本番アイコンの情報を取得
        $prod_icon_id = get_option('site_icon');
        if (!$prod_icon_id) {
            return $meta_tags;
        }

        $prod_icon_url = wp_get_attachment_url($prod_icon_id);
        $prod_filename = basename($prod_icon_url);
        $prod_extension = pathinfo($prod_filename, PATHINFO_EXTENSION);
        $prod_filename_base = preg_replace('/\.' . preg_quote($prod_extension, '/') . '$/', '', $prod_filename);
        $prod_path = dirname(parse_url($prod_icon_url, PHP_URL_PATH));

        // 開発アイコンの情報
        $dev_filename = basename($dev_icon_url);
        $dev_extension = pathinfo($dev_filename, PATHINFO_EXTENSION);
        $dev_filename_base = preg_replace('/\.' . preg_quote($dev_extension, '/') . '$/', '', $dev_filename);
        $dev_path = dirname(parse_url($dev_icon_url, PHP_URL_PATH));

        foreach ($meta_tags as &$tag) {
            // ファイル名を置換（拡張子も動的に対応）
            $tag = preg_replace(
                '#/' . preg_quote($prod_filename_base, '#') . '(-\d+x\d+)?\.' . preg_quote($prod_extension, '#') . '#',
                '/' . $dev_filename_base . '$1.' . $dev_extension,
                $tag
            );

            // パスを置換
            if ($prod_path !== $dev_path) {
                $tag = str_replace($prod_path, $dev_path, $tag);
            }
        }

        return $meta_tags;
    }

    /**
     * プラグイン有効化後に設定ページへリダイレクト（初回のみ）
     */
    public function redirect_after_activation($plugin)
    {
        if ($plugin === plugin_basename(__FILE__)) {
            // 複数プラグイン一括有効化の場合はリダイレクトしない
            if (isset($_GET['activate-multi'])) {
                return;
            }

            // 初回有効化フラグをチェック
            $is_first_activation = get_transient('dev-favicon-switcher_first_activation');

            if ($is_first_activation) {
                // 転送フラグトランジェントを削除（直後に無効化→有効化処理が行われても再度リダイレクトはしない）
                delete_transient('dev-favicon-switcher_first_activation');

                // 初回セットアップ完了フラグをセット（次回トランジェントの生成抑止）
                update_option('dev-favicon-switcher_setup_completed', true);

                // 設定ページのURLを構成
                $redirect_url = admin_url("options-general.php?page={$this->slug}");

                // リダイレクト実行
                wp_safe_redirect($redirect_url);
                exit;
            }
        }
    }
}

// プラグイン有効化フック
register_activation_hook(__FILE__, function () {
    // 初回セットアップ完了フラグをチェック
    $setup_completed = get_option('dev-favicon-switcher_setup_completed');

    if (!$setup_completed) {
        // 初回のみ転送フラグトランジェントをセット（60秒間有効）
        set_transient('dev-favicon-switcher_first_activation', true, 60);
    }
});

// Initialize plugin
new Dev_Favicon_Switcher();