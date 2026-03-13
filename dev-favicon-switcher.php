<?php
/**
 * Plugin Name: Dev Favicon Switcher
 * Plugin URI: https://www.karasunouta.com/
 * Description: Automatically switches favicon (site icon) between production and development environments.
 * Version: 1.3.9
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dev_Favicon_Switcher {


	/**
	 * プラグインバージョン
	 */
	const VERSION = '1.3.9';

	private $option_name = 'dev_favicon_switcher_settings';
	private $page_slug   = 'dev-favicon-switcher';

	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		// 設定を取得してenabledチェック
		$settings   = get_option( $this->option_name, array( 'enabled' => '1' ) );
		$is_enabled = ! empty( $settings['enabled'] ) && $settings['enabled'] === '1';

		// Frontend filters（enabledの場合のみ）
		if ( $is_enabled ) {
			add_filter( 'get_site_icon_url', array( $this, 'replace_favicon_url' ), 10, 1 );
			add_filter( 'site_icon_meta_tags', array( $this, 'replace_favicon_meta_tags' ), 10, 1 );
		}

		// 画像切り抜きAjax handler
		add_action( 'wp_ajax_dev_favicon_crop_image', array( $this, 'ajax_crop_image' ) );

		// 開発アイコン削除Ajax handler
		add_action( 'wp_ajax_dev_favicon_remove_icon', array( $this, 'ajax_remove_icon' ) );

		// 開発アイコン復元Ajax handler
		add_action( 'wp_ajax_dev_favicon_restore_default', array( $this, 'ajax_restore_default' ) );

		// インストール済みプラグイン一覧から設定ページにリンク
		add_filter(
			'plugin_action_links_' . plugin_basename( __FILE__ ),
			array( $this, 'add_settings_link' )
		);

		// プラグイン有効化時の初期設定とリダイレクト
		add_action( 'activated_plugin', array( $this, 'redirect_after_activation' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'dev-favicon-switcher',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);
	}

	public function add_settings_page() {
		add_options_page(
			__( 'Dev Favicon Switcher', 'dev-favicon-switcher' ),
			__( 'Dev Favicon', 'dev-favicon-switcher' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'dev_favicon_settings_group',
			$this->option_name,
			array( $this, 'sanitize_settings' )
		);
	}

	public function sanitize_settings( $input ) {
		$sanitized = array();

		// Enabled/Disabled
		$sanitized['enabled'] = ! empty( $input['enabled'] ) ? '1' : '0';

		// Dev icon ID
		$sanitized['dev_icon_id'] = ! empty( $input['dev_icon_id'] ) ? absint( $input['dev_icon_id'] ) : '';

		// Auto-detect
		$sanitized['auto_detect'] = ! empty( $input['auto_detect'] ) ? '1' : '0';

		// Dev URLs (textarea, one per line)
		$sanitized['dev_urls'] = ! empty( $input['dev_urls'] ) ? sanitize_textarea_field( $input['dev_urls'] ) : '';

		// 古い設定値を取得
		$old_settings = get_option( $this->option_name, array() );
		$old_icon_id  = ! empty( $old_settings['dev_icon_id'] ) ? $old_settings['dev_icon_id'] : '';

		// Development iconが設定されている場合、必要なサイズを自動生成
		if ( ! empty( $sanitized['dev_icon_id'] ) ) {
			// 新しいIDが設定された場合、それが plugin 専用フォルダ内でタイムスタンプ付きのコピーかを確認
			if ( $sanitized['dev_icon_id'] != $old_icon_id ) {
				$cloned_id = $this->ensure_timestamped_clone( $sanitized['dev_icon_id'] );
				if ( $cloned_id ) {
					$sanitized['dev_icon_id'] = $cloned_id;
				}
			}

			$this->generate_icon_sizes( $sanitized['dev_icon_id'] );
		}

		// ガベージコレクションを実行：現在アクティブなアイコン以外の過去ファイルを一掃する
		$active_id = ! empty( $sanitized['dev_icon_id'] ) ? $sanitized['dev_icon_id'] : 0;
		$this->cleanup_unused_dev_icons( $active_id );

		return $sanitized;
	}

	/**
	 * プラグイン一覧ページに設定メニューへのリンクを追加
	 */
	public function add_settings_link( array $links ): array {
		$settings_url = admin_url( "admin.php?page={$this->page_slug}" );

		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings' ) . '</a>';

		// 行の先頭に追加
		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * 画像切り抜きAjaxハンドラー
	 */
	public function ajax_crop_image() {
		check_ajax_referer( 'dev-favicon-crop', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
		}

		$attachment_id = absint( $_POST['id'] );

		// cropDetailsが存在しない場合はエラー
		if ( empty( $_POST['cropDetails'] ) ) {
			error_log( 'Dev Favicon: cropDetails is missing from request' );
			wp_send_json_error( array( 'message' => 'Crop details missing' ) );
		}

		$crop_details = json_decode( stripslashes( $_POST['cropDetails'] ), true );

		if ( ! $crop_details || ! isset( $crop_details['x1'] ) ) {
			error_log( 'Dev Favicon: Failed to parse cropDetails' );
			wp_send_json_error( array( 'message' => 'Invalid crop details' ) );
		}

		error_log( 'Dev Favicon: Cropping with details - ' . print_r( $crop_details, true ) );

		// 元画像の情報を取得
		$original_file     = get_attached_file( $attachment_id );
		$original_basename = basename( $original_file );
		$original_name     = pathinfo( $original_basename, PATHINFO_FILENAME );
		$extension         = pathinfo( $original_basename, PATHINFO_EXTENSION );

		// クロップ実行（一時ファイルとして生成）
		$cropped = wp_crop_image(
			$attachment_id,
			(int) $crop_details['x1'],
			(int) $crop_details['y1'],
			(int) $crop_details['width'],
			(int) $crop_details['height'],
			512,
			512
		);

		if ( is_wp_error( $cropped ) ) {
			error_log( 'Dev Favicon: Crop failed - ' . $cropped->get_error_message() );
			wp_send_json_error( array( 'message' => $cropped->get_error_message() ) );
		}

		// アップロードディレクトリ情報の取得と専用フォルダ確保
		$upload_dir = wp_upload_dir();
		$target_dir = $upload_dir['basedir'] . '/dev-favicon-switcher';
		if ( ! file_exists( $target_dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $target_dir );
			} else {
				@mkdir( $target_dir, 0755, true );
			}
		}

		// 希望するファイル名を生成（croppedプレフィックス + タイムスタンプ付与）
		$timestamp        = time();
		$desired_filename = 'cropped-' . $original_name . '-' . $timestamp . '.' . $extension;
		$target_path      = $target_dir . '/' . $desired_filename;

		// 一時ファイルを目的の場所（専用フォルダ）に移動
		if ( ! @rename( $cropped, $target_path ) ) {
			@unlink( $cropped );
			wp_send_json_error( array( 'message' => 'Failed to move cropped file' ) );
		}

		// 古い同名ファイルがあれば（実質ないはずだが念のため）削除処理を省略

		// 添付ファイル情報の構成
		$attachment = array(
			'post_title'     => 'dev-favicon',
			'post_mime_type' => 'image/' . $extension,
			'guid'           => $upload_dir['baseurl'] . '/dev-favicon-switcher/' . $desired_filename,
		);

		// メディアライブラリに登録（ファイルは既に正しい場所にある）
		$new_attachment_id = wp_insert_attachment( $attachment, $target_path );

		if ( is_wp_error( $new_attachment_id ) ) {
			@unlink( $target_path );
			wp_send_json_error( array( 'message' => $new_attachment_id->get_error_message() ) );
		}

		// メタデータの生成・更新
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata(
			$new_attachment_id,
			wp_generate_attachment_metadata( $new_attachment_id, $target_path )
		);

		// ファビコン専用サイズを生成
		$settings = get_option( $this->option_name );
		$result   = $this->generate_icon_sizes( $new_attachment_id );

		if ( is_wp_error( $result ) ) {
			error_log( 'Dev Favicon: Failed to generate sizes - ' . $result->get_error_message() );
		} else {
			error_log( 'Dev Favicon: Generated sizes - ' . print_r( $result, true ) );
		}

		error_log( 'Dev Favicon: Crop successful - ID: ' . $new_attachment_id . ', File: ' . $desired_filename );

		// レスポンス生成
		$response = wp_prepare_attachment_for_js( $new_attachment_id );
		wp_send_json_success( $response );
	}

	/**
	 * 開発アイコン設定削除Ajaxハンドラー
	 */
	public function ajax_remove_icon() {
		check_ajax_referer( 'dev_favicon_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// 現在の設定を取得
		$settings = get_option( $this->option_name, array() );

		// dev_icon_idのみをクリア（他の設定は保持）
		$settings['dev_icon_id'] = '';

		// 設定を更新
		update_option( $this->option_name, $settings );

		error_log( 'Dev Favicon: Icon setting removed (image file preserved)' );

		wp_send_json_success( array( 'message' => 'Icon setting removed' ) );
	}

	/**
	 * デフォルト開発アイコン復元Ajaxハンドラー
	 */
	public function ajax_restore_default() {
		check_ajax_referer( 'dev_favicon_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		// プラグイン内のデフォルト画像パス
		$default_icon_path = plugin_dir_path( __FILE__ ) . 'assets/dev_favicon.png';
		if ( ! file_exists( $default_icon_path ) ) {
			wp_send_json_error( 'Default icon file not found in plugin.' );
		}

		// アップロードディレクトリの準備
		$upload_dir  = wp_upload_dir();
		$target_dir  = $upload_dir['basedir'] . '/dev-favicon-switcher';
		$timestamp   = time();
		$target_path = $target_dir . '/dev_favicon-' . $timestamp . '.png';

		// 独自のサブディレクトリを作成
		if ( ! file_exists( $target_dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $target_dir );
			} else {
				@mkdir( $target_dir, 0755, true );
			}
		}

		// プラグインから uploads に画像をコピー
		if ( ! copy( $default_icon_path, $target_path ) ) {
			error_log( 'Dev Favicon: Failed to copy default icon to uploads directory.' );
			wp_send_json_error( 'Failed to copy default icon to uploads directory.' );
		}

		// メディアライブラリに登録
		$attachment = array(
			'post_title'     => 'dev-favicon-default',
			'post_mime_type' => 'image/png',
			'guid'           => $upload_dir['baseurl'] . '/dev-favicon-switcher/dev_favicon-' . $timestamp . '.png',
		);

		$attachment_id = wp_insert_attachment( $attachment, $target_path );
		if ( is_wp_error( $attachment_id ) ) {
			error_log( 'Dev Favicon: Failed to insert default icon to media library.' );
			wp_send_json_error( 'Failed to insert default icon to media library.' );
		}

		// メタデータの生成・更新（画像サイズの取得など）
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $target_path )
		);

		// ファビコン専用のリサイズ版画像を生成
		$result = $this->generate_icon_sizes( $attachment_id );
		if ( is_wp_error( $result ) ) {
			error_log( 'Dev Favicon: Failed to generate default icon sizes - ' . $result->get_error_message() );
		}

		// URLを取得してクライアントに返す
		$icon_url = wp_get_attachment_image_url( $attachment_id, 'full' );

		wp_send_json_success(
			array(
				'id'      => $attachment_id,
				'url'     => $icon_url,
				'message' => 'Default development icon restored',
			)
		);
	}

	public function admin_enqueue_scripts( $hook ) {
		if ( $hook !== "settings_page_{$this->page_slug}" ) {
			return;
		}

		// パスの整理
		$entry_point    = 'admin';
		$asset_path     = plugin_dir_path( __FILE__ ) . "build/{$entry_point}.asset.php";
		$script_url     = plugins_url( "/build/{$entry_point}.js", __FILE__ );
		$style_url      = plugins_url( "/build/{$entry_point}.css", __FILE__ );
		$style_path     = plugin_dir_path( __FILE__ ) . "build/{$entry_point}.css";
		$languages_path = plugin_dir_path( __FILE__ ) . 'languages';

		// ビルド済みファイルが存在するかチェック
		if ( ! file_exists( $asset_path ) ) {
			return;
		}
		$assets = include $asset_path;

		// WordPress標準のメディアライブラリとクロッパー
		wp_enqueue_media();

		// 管理画面用JS
		$script_handle = 'dev-favicon-admin';
		wp_enqueue_script(
			$script_handle,
			$script_url,
			$assets['dependencies'],
			$assets['version'],
			true // フッターで読み込み
		);

		// 翻訳の読み込み
		wp_set_script_translations( $script_handle, 'dev-favicon-switcher', $languages_path );

		// JS変数のセット
		wp_localize_script(
			'dev-favicon-admin',
			'devFaviconAjax',
			array(
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'dev_favicon_nonce' ),
				'crop_nonce' => wp_create_nonce( 'dev-favicon-crop' ),
			)
		);

		// 管理画面用CSS
		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'dev-favicon-admin-style',
				$style_url,
				array( 'customize-controls' ), // 依存先CSS（WPコアのCustomizer用スタイル）
				$assets['version'] // スクリプトと同じバージョン管理を適用
			);
		}
	}

	public function render_settings_page() {
		$settings = get_option(
			$this->option_name,
			array(
				'enabled'     => '1',
				'dev_icon_id' => '',
				'dev_urls'    => '',
				'auto_detect' => '1',
			)
		);

		$current_icon_id  = get_option( 'site_icon' );
		$current_icon_url = $current_icon_id ? wp_get_attachment_image_url( $current_icon_id, 'full' ) : '';

		$dev_icon_url = ! empty( $settings['dev_icon_id'] ) ?
			wp_get_attachment_image_url( $settings['dev_icon_id'], 'full' ) : '';

		?>
		<div class="wrap">
			<h1><?php _e( 'Dev Favicon Switcher Settings', 'dev-favicon-switcher' ); ?></h1>
			
			<?php if ( $this->is_dev_environment( $settings ) ) : ?>
				<div class="notice notice-info">
					<p><?php _e( 'Development environment detected.', 'dev-favicon-switcher' ); ?></p>
				</div>
				<?php
		endif;
			?>
			
			<form method="post" action="options.php" id="dev-favicon-form">
				<?php settings_fields( 'dev_favicon_settings_group' ); ?>
				
				<table class="form-table">
					<!-- Enable/Disable Switch (一番上に配置) -->
					<tr>
						<th scope="row">
							<?php _e( 'Plugin Status', 'dev-favicon-switcher' ); ?>
						</th>
						<td>
							<label>
								<input type="checkbox" 
									name="<?php echo $this->option_name; ?>[enabled]" 
									value="1" 
									<?php checked( $settings['enabled'], '1' ); ?>>
								<strong><?php _e( 'Enable development favicon switching', 'dev-favicon-switcher' ); ?></strong>
							</label>
							<p class="description">
								<?php _e( 'Uncheck this to temporarily disable favicon switching without losing your settings. Useful for testing or presentations.', 'dev-favicon-switcher' ); ?>
							</p>
						</td>
					</tr>

					<!-- Production Icon (Read-only) -->
					<tr>
						<th scope="row">
							<?php _e( 'Production Favicon', 'dev-favicon-switcher' ); ?>
						</th>
						<td>
							<?php if ( $current_icon_url ) : ?>
								<img src="<?php echo esc_url( $current_icon_url ); ?>" 
									style="max-width: 64px; height: auto; border: 1px solid #ddd; padding: 5px;">
								<p class="description">
									<?php
									/* translators: %s: URL to the General Settings page. */
									printf( __( 'Current site icon (set in <a href="%s">Settings > General</a>)', 'dev-favicon-switcher' ), admin_url( 'options-general.php' ) );
									?>
								</p>
								<?php
		else :
			?>
								<p class="description">
									<?php
									/* translators: %s: URL to the General Settings page. */
									printf( __( 'No site icon set. Please set one in <a href="%s">Settings > General</a>.', 'dev-favicon-switcher' ), admin_url( 'options-general.php' ) );
									?>
								</p>
							<?php
		endif;
		?>
						</td>
					</tr>
					
					<!-- Development Icon -->
					<tr>
						<th scope="row">
							<label for="dev_icon_id"><?php _e( 'Development Favicon', 'dev-favicon-switcher' ); ?></label>
						</th>
						<td>
							<div id="dev-icon-preview">
								<?php if ( $dev_icon_url ) : ?>
									<img src="<?php echo esc_url( $dev_icon_url ); ?>" 
										style="max-width: 64px; height: auto; border: 1px solid #ddd; padding: 5px; margin-bottom:1em;">
									<?php
		endif;
								?>
							</div>
							<input type="hidden" 
									name="<?php echo $this->option_name; ?>[dev_icon_id]" 
									id="dev_icon_id" 
									value="<?php echo esc_attr( $settings['dev_icon_id'] ); ?>">
							<button type="button" class="button" id="select-dev-icon">
								<?php _e( 'Select Development Icon', 'dev-favicon-switcher' ); ?>
							</button>
							<button type="button" class="button" style="margin-left:0.5em;" id="restore-default-icon">
								<?php _e( 'Restore Default', 'dev-favicon-switcher' ); ?>
							</button>
							<button type="button" class="button" style="margin-left:0.5em;" id="remove-dev-icon" 
									<?php echo empty( $settings['dev_icon_id'] ) ? 'style="display:none;"' : ''; ?>>
								<?php _e( 'Remove', 'dev-favicon-switcher' ); ?>
							</button>
							<p class="description">
								<?php _e( 'Choose an icon that will be displayed in development environments.', 'dev-favicon-switcher' ); ?>
							</p>
						</td>
					</tr>
					
					<!-- Auto-detect Development Environment -->
					<tr>
						<th scope="row">
							<?php _e( 'Auto-detect Development', 'dev-favicon-switcher' ); ?>
						</th>
						<td>
							<label>
								<input type="checkbox" 
										name="<?php echo $this->option_name; ?>[auto_detect]" 
										value="1" 
										<?php checked( $settings['auto_detect'], '1' ); ?>>
								<?php _e( 'Automatically detect .local, .test, .dev domains', 'dev-favicon-switcher' ); ?>
							</label>
							<p class="description">
								<?php _e( 'Recommended: Enable this to automatically apply development favicon on common development domains.', 'dev-favicon-switcher' ); ?>
							</p>
						</td>
					</tr>
					
					<!-- Development URLs -->
					<tr>
						<th scope="row">
							<label for="dev_urls"><?php _e( 'Development URLs', 'dev-favicon-switcher' ); ?></label>
						</th>
						<td>
							<textarea name="<?php echo $this->option_name; ?>[dev_urls]" 
										id="dev_urls" 
										rows="4" 
										class="large-text"
										placeholder="https://mysite.local/&#10;https://staging.mysite.com/"><?php echo esc_textarea( $settings['dev_urls'] ); ?></textarea>
							<p class="description">
								<?php _e( 'Enter development URLs (one per line). The plugin will switch to development favicon when the current URL starts with any of these.', 'dev-favicon-switcher' ); ?>
							</p>
						</td>
					</tr>
					
				</table>
				
				<?php submit_button( __( 'Save Settings', 'dev-favicon-switcher' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * 動的にファビコンの必要サイズ配列を取得
	 */
	private function get_required_sizes() {
		// WordPressコアのデフォルトサイズに対してフィルターを適用し、テーマ等による追加分を取得
		$sizes = apply_filters( 'site_icon_image_sizes', array( 32, 180, 192, 270 ) );
		// 重複を排除して整数化
		return array_unique( array_map( 'absint', $sizes ) );
	}

	public function ajax_check_sizes() {
		check_ajax_referer( 'dev_favicon_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$settings = get_option( $this->option_name );
		if ( empty( $settings['dev_icon_id'] ) ) {
			wp_send_json_error( 'No development icon selected' );
		}

		$file_path = get_attached_file( $settings['dev_icon_id'] );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			wp_send_json_error( 'Development icon file not found' );
		}

		$missing_sizes  = array();
		$existing_sizes = array();

		$path_parts     = pathinfo( $file_path );
		$required_sizes = $this->get_required_sizes();

		foreach ( $required_sizes as $size ) {
			$sized_file = $path_parts['dirname'] . '/' . $path_parts['filename'] . '-' . $size . 'x' . $size . '.' . $path_parts['extension'];

			if ( file_exists( $sized_file ) ) {
				$existing_sizes[] = $size;
			} else {
				$missing_sizes[] = $size;
			}
		}

		wp_send_json_success(
			array(
				'existing' => $existing_sizes,
				'missing'  => $missing_sizes,
			)
		);
	}

	public function ajax_generate_sizes() {
		check_ajax_referer( 'dev_favicon_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}

		$settings = get_option( $this->option_name );
		if ( empty( $settings['dev_icon_id'] ) ) {
			wp_send_json_error( 'No development icon selected' );
		}

		$result = $this->generate_icon_sizes( $settings['dev_icon_id'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	private function generate_icon_sizes( $attachment_id ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', 'Icon file not found' );
		}

		$sizes = $this->get_required_sizes();

		$generated = array();
		$skipped   = array();
		$errors    = array();

		$meta          = wp_get_attachment_metadata( $attachment_id );
		$meta          = is_array( $meta ) ? $meta : array();
		$meta['sizes'] = isset( $meta['sizes'] ) ? $meta['sizes'] : array();
		$meta_updated  = false;

		foreach ( $sizes as $size ) {
			// ファイル名の最後の拡張子のみを置換（より安全）
			$path_parts     = pathinfo( $file_path );
			$sized_file     = $path_parts['dirname'] . '/' . $path_parts['filename'] . '-' . $size . 'x' . $size . '.' . $path_parts['extension'];
			$sized_filename = basename( $sized_file );

			if ( file_exists( $sized_file ) ) {
				$skipped[] = $size;

				if ( ! isset( $meta['sizes'][ "dev-favicon-{$size}" ] ) ) {
					$filetype                               = wp_check_filetype( $sized_file );
					$meta['sizes'][ "dev-favicon-{$size}" ] = array(
						'file'      => $sized_filename,
						'width'     => $size,
						'height'    => $size,
						'mime-type' => $filetype['type'],
					);
					$meta_updated                           = true;
				}
				continue;
			}

			// 毎回新しいエディターインスタンスを作成（重要！）
			$image = wp_get_image_editor( $file_path );

			if ( is_wp_error( $image ) ) {
				$errors[] = sprintf( 'Size %dx%d: %s', $size, $size, $image->get_error_message() );
				continue;
			}

			// リサイズ
			$resize_result = $image->resize( $size, $size, true );

			if ( is_wp_error( $resize_result ) ) {
				$errors[] = sprintf( 'Size %dx%d: %s', $size, $size, $resize_result->get_error_message() );
				continue;
			}

			// 保存
			$saved = $image->save( $sized_file );

			if ( is_wp_error( $saved ) ) {
				$errors[] = sprintf( 'Size %dx%d: %s', $size, $size, $saved->get_error_message() );
			} else {
				$generated[] = $size;

				$meta['sizes'][ "dev-favicon-{$size}" ] = array(
					'file'      => $sized_filename,
					'width'     => $saved['width'],
					'height'    => $saved['height'],
					'mime-type' => $saved['mime-type'],
				);
				$meta_updated                           = true;
			}

			// メモリ解放（念のため）
			unset( $image );
		}

		if ( $meta_updated ) {
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		return array(
			'generated' => $generated,
			'skipped'   => $skipped,
			'errors'    => $errors,
		);
	}

	private function is_dev_environment( $settings = null ) {
		if ( ! $settings ) {
			$settings = get_option( $this->option_name );
		}

		// プラグインが無効化されている場合は常にfalse
		if ( empty( $settings['enabled'] ) || $settings['enabled'] !== '1' ) {
			return false;
		}

		$current_url = home_url();

		// 自動検出が有効な場合
		if ( ! empty( $settings['auto_detect'] ) && $settings['auto_detect'] === '1' ) {
			$hostname       = parse_url( $current_url, PHP_URL_HOST );
			$dev_extensions = array( '.local', '.test', '.dev' );
			foreach ( $dev_extensions as $ext ) {
				if ( strpos( $hostname, $ext ) !== false ) {
					return true;
				}
			}
		}

		// 手動で設定されたURLをチェック
		if ( ! empty( $settings['dev_urls'] ) ) {
			$dev_urls = explode( "\n", $settings['dev_urls'] );
			foreach ( $dev_urls as $dev_url ) {
				$dev_url = trim( $dev_url );
				if ( ! empty( $dev_url ) && strpos( $current_url, rtrim( $dev_url, '/' ) ) === 0 ) {
					return true;
				}
			}
		}

		return false;
	}

	public function replace_favicon_url( $url ) {
		// 開発環境以外では独自ファビコンの適用処理回避
		if ( ! $this->is_dev_environment() ) {
			return $url;
		}

		// 「設定 > 一般」ページでは適用処理回避（サイトアイコン欄に通常のサイトアイコンを表示）
		global $pagenow;
		if ( is_admin() && $pagenow === 'options-general.php' && ! isset( $_GET['page'] ) ) {
			// ※pageパラメーターがある場合は各プラグイン設定ページなど
			return $url;
		}

		// 独自ファビコン未設定なら適用処理回避
		$settings = get_option( $this->option_name );
		if ( empty( $settings['dev_icon_id'] ) ) {
			return $url;
		}

		// 開発アイコンのURLとファイル名を取得
		$dev_icon_url = wp_get_attachment_image_url( $settings['dev_icon_id'], 'full' );
		if ( ! $dev_icon_url ) {
			return $url;
		}

		// 本番アイコンのファイル名と拡張子を取得
		$prod_filename      = basename( $url );
		$prod_extension     = pathinfo( $prod_filename, PATHINFO_EXTENSION );
		$prod_filename_base = preg_replace( '/(-\d+x\d+)?\.' . preg_quote( $prod_extension, '/' ) . '$/', '', $prod_filename );

		// 開発アイコンのファイル名と拡張子を取得
		$dev_filename      = basename( $dev_icon_url );
		$dev_extension     = pathinfo( $dev_filename, PATHINFO_EXTENSION );
		$dev_filename_base = preg_replace( '/\.' . preg_quote( $dev_extension, '/' ) . '$/', '', $dev_filename );

		// URLのファイル名部分を置換（サイズsuffixは保持、拡張子も動的に対応。クエリストリングは使用しない）
		$url = preg_replace(
			'#/' . preg_quote( $prod_filename_base, '#' ) . '(-\d+x\d+)?\.' . preg_quote( $prod_extension, '#' ) . '#',
			'/' . $dev_filename_base . '$1.' . $dev_extension,
			$url
		);

		// パスも置換（年月フォルダが異なる場合に対応）
		$prod_icon_id = get_option( 'site_icon' );
		if ( $prod_icon_id ) {
			$prod_icon_url = wp_get_attachment_url( $prod_icon_id );
			$prod_path     = dirname( parse_url( $prod_icon_url, PHP_URL_PATH ) );
			$dev_path      = dirname( parse_url( $dev_icon_url, PHP_URL_PATH ) );

			if ( $prod_path !== $dev_path ) {
				$url = str_replace( $prod_path, $dev_path, $url );
			}
		}

		return $url;
	}

	public function replace_favicon_meta_tags( $meta_tags ) {
		// 開発環境以外では独自ファビコンの適用処理回避
		if ( ! $this->is_dev_environment() ) {
			return $meta_tags;
		}

		// 独自ファビコン未設定なら適用処理回避
		$settings = get_option( $this->option_name );
		if ( empty( $settings['dev_icon_id'] ) ) {
			return $meta_tags;
		}

		$dev_icon_url = wp_get_attachment_image_url( $settings['dev_icon_id'], 'full' );
		if ( ! $dev_icon_url ) {
			return $meta_tags;
		}

		// 本番アイコンの情報を取得
		$prod_icon_id = get_option( 'site_icon' );
		if ( ! $prod_icon_id ) {
			return $meta_tags;
		}

		$prod_icon_url      = wp_get_attachment_url( $prod_icon_id );
		$prod_filename      = basename( $prod_icon_url );
		$prod_extension     = pathinfo( $prod_filename, PATHINFO_EXTENSION );
		$prod_filename_base = preg_replace( '/\.' . preg_quote( $prod_extension, '/' ) . '$/', '', $prod_filename );
		$prod_path          = dirname( parse_url( $prod_icon_url, PHP_URL_PATH ) );

		// 開発アイコンの情報
		$dev_filename      = basename( $dev_icon_url );
		$dev_extension     = pathinfo( $dev_filename, PATHINFO_EXTENSION );
		$dev_filename_base = preg_replace( '/\.' . preg_quote( $dev_extension, '/' ) . '$/', '', $dev_filename );
		$dev_path          = dirname( parse_url( $dev_icon_url, PHP_URL_PATH ) );

		foreach ( $meta_tags as &$tag ) {
			// ファイル名を置換（拡張子も動的に対応。クエリストリングは使用しない）
			$tag = preg_replace(
				'#/' . preg_quote( $prod_filename_base, '#' ) . '(-\d+x\d+)?\.' . preg_quote( $prod_extension, '#' ) . '#',
				'/' . $dev_filename_base . '$1.' . $dev_extension,
				$tag
			);

			// パスを置換
			if ( $prod_path !== $dev_path ) {
				$tag = str_replace( $prod_path, $dev_path, $tag );
			}
		}

		return $meta_tags;
	}

	/**
	 * プラグイン有効化後に設定ページへリダイレクト（初回のみ）
	 */
	public function redirect_after_activation( $plugin ) {
		// 他プラグインの有効化の場合は処理回避
		if ( $plugin !== plugin_basename( __FILE__ ) ) {
			return;
		}

		// 初回有効化以外の場合は処理回避
		$is_first_activation = get_transient( 'dev_favicon_switcher_first_activation' );
		if ( ! $is_first_activation ) {
			return;
		}

		// 転送フラグトランジェントを削除（続けて無効化→有効化処理が行われても再度リダイレクトはしない）
		delete_transient( 'dev_favicon_switcher_first_activation' );

		// 初回セットアップ完了フラグをセット（次回トランジェントの生成抑止）
		update_option( 'dev_favicon_switcher_setup_completed', true );

		// 初回有効化時にデフォルトアイコンをプロビジョニング
		$this->deploy_default_icon();

		// 複数プラグイン一括有効化の場合はリダイレクトしない
		if ( ! isset( $_GET['activate-multi'] ) ) {
			// 設定ページのURLを構成
			$redirect_url = admin_url( "options-general.php?page={$this->page_slug}" );

			// リダイレクト実行
			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * デフォルトアイコンのデプロイと初期設定
	 */
	private function deploy_default_icon() {
		// プラグイン内のデフォルト画像パス
		$default_icon_path = plugin_dir_path( __FILE__ ) . 'assets/dev_favicon.png';
		if ( ! file_exists( $default_icon_path ) ) {
			return;
		}

		// アップロードディレクトリの準備
		$upload_dir  = wp_upload_dir();
		$target_dir  = $upload_dir['basedir'] . '/dev-favicon-switcher';
		$timestamp   = time();
		$target_path = $target_dir . '/dev_favicon-' . $timestamp . '.png';

		// 独自のサブディレクトリを作成
		if ( ! file_exists( $target_dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $target_dir );
			} else {
				@mkdir( $target_dir, 0755, true );
			}
		}

		// プラグインから uploads に画像をコピー
		if ( ! copy( $default_icon_path, $target_path ) ) {
			error_log( 'Dev Favicon: Failed to copy default icon to uploads directory.' );
			return;
		}

		// メディアライブラリに登録
		$attachment = array(
			'post_title'     => 'dev-favicon-default',
			'post_mime_type' => 'image/png',
			'guid'           => $upload_dir['baseurl'] . '/dev-favicon-switcher/dev_favicon-' . $timestamp . '.png',
		);

		$attachment_id = wp_insert_attachment( $attachment, $target_path );
		if ( is_wp_error( $attachment_id ) ) {
			error_log( 'Dev Favicon: Failed to insert default icon to media library.' );
			return;
		}

		// メタデータの生成・更新（画像サイズの取得など）
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $target_path )
		);

		// ファビコン専用のリサイズ版画像を生成
		$result = $this->generate_icon_sizes( $attachment_id );
		if ( is_wp_error( $result ) ) {
			error_log( 'Dev Favicon: Failed to generate default icon sizes - ' . $result->get_error_message() );
		}

		// 設定にデプロイされた画像をセット
		$settings                = get_option(
			$this->option_name,
			array(
				'enabled'     => '1',
				'auto_detect' => '1',
			)
		);
		$settings['dev_icon_id'] = $attachment_id;
		update_option( $this->option_name, $settings );
	}

	/**
	 * 設定されたIDが dev-favicon-switcher/ 内のファイルでなければクローンしてタイムスタンプ付きの複製を作る
	 *
	 * @param int $attachment_id 元のアタッチメントID
	 * @return int|false 新しいアタッチメントID。失敗または不要な場合はfalse
	 */
	private function ensure_timestamped_clone( $attachment_id ) {
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return false;
		}

		// 既に dev-favicon-switcher ディレクトリ内にあるかチェック
		if ( strpos( $file_path, 'dev-favicon-switcher' ) !== false ) {
			// 一度プラグインによってタイムスタンプ付き等で作られたファイルとみなす
			return false;
		}

		// クローン先の準備
		$upload_dir = wp_upload_dir();
		$target_dir = $upload_dir['basedir'] . '/dev-favicon-switcher';
		if ( ! file_exists( $target_dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $target_dir );
			} else {
				@mkdir( $target_dir, 0755, true );
			}
		}

		// タイムスタンプ付きのファイル名を生成
		$original_basename = basename( $file_path );
		$original_name     = pathinfo( $original_basename, PATHINFO_FILENAME );
		$extension         = pathinfo( $original_basename, PATHINFO_EXTENSION );

		$timestamp        = time();
		$desired_filename = 'cloned-' . $original_name . '-' . $timestamp . '.' . $extension;
		$target_path      = $target_dir . '/' . $desired_filename;

		// ファイルをコピー
		if ( ! copy( $file_path, $target_path ) ) {
			error_log( 'Dev Favicon: Failed to clone icon to uploads directory.' );
			return false;
		}

		// 新しいメディアとして登録
		$attachment = array(
			'post_title'     => 'dev-favicon-cloned',
			'post_mime_type' => 'image/' . $extension,
			'guid'           => $upload_dir['baseurl'] . '/dev-favicon-switcher/' . $desired_filename,
		);

		$new_attachment_id = wp_insert_attachment( $attachment, $target_path );
		if ( is_wp_error( $new_attachment_id ) ) {
			@unlink( $target_path );
			error_log( 'Dev Favicon: Failed to insert cloned icon to media library.' );
			return false;
		}

		// メタデータの生成・更新
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata(
			$new_attachment_id,
			wp_generate_attachment_metadata( $new_attachment_id, $target_path )
		);

		return $new_attachment_id;
	}

	/**
	 * 保持すべきアクティブなアタッチメント以外の、専用ディレクトリ内の画像のDBレコードおよび実体ファイルを一括削除する
	 *
	 * @param int $active_attachment_id 現在使用中として保持すべき画像のアタッチメントID（0の場合はすべて削除）
	 */
	private function cleanup_unused_dev_icons( $active_attachment_id ) {
		global $wpdb;

		// guid に dev-favicon-switcher を含むアタッチメントをすべて検索
		$query   = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE '%/dev-favicon-switcher/%'";
		$results = $wpdb->get_results( $query );

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$id = absint( $row->ID );
				// アクティブなID以外は完全に削除する
				if ( $id !== absint( $active_attachment_id ) ) {
					// dev-favicon専用サイズのメタデータは生成時に登録済みのため、
					// 削除漏れの心配はなく wp_delete_attachment で一掃される

					// wp_delete_attachment(ID, true) を呼ぶと、連携して関連するメタデータ、元画像、リサイズ済み画像がすべてファイルシステムからも削除される
					wp_delete_attachment( $id, true );
					error_log( 'Dev Favicon: Garbage collected unused icon ID ' . $id );
				}
			}
		}
	}
}

// プラグイン有効化フック
register_activation_hook(
	__FILE__,
	function () {
		// 初回セットアップ完了フラグをチェック
		$setup_completed = get_option( 'dev_favicon_switcher_setup_completed' );

		if ( ! $setup_completed ) {
			// 初回のみ転送フラグトランジェントをセット（60秒間有効）
			set_transient( 'dev_favicon_switcher_first_activation', true, 60 );
		}
	}
);

// Initialize plugin
new Dev_Favicon_Switcher();
