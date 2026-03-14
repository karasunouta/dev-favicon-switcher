<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// プラグインオプションを削除
delete_option( 'dev_favicon_switcher_settings' );
delete_option( 'dev_favicon_switcher_setup_completed' );

// アップロードディレクトリ内にある開発用ファビコン画像とサブディレクトリごと削除
$dev_favicon_switcher_upload_dir = wp_upload_dir();
$dev_favicon_switcher_target_dir = $dev_favicon_switcher_upload_dir['basedir'] . '/dev-favicon-switcher';

if ( file_exists( $dev_favicon_switcher_target_dir ) ) {
	global $wp_filesystem;

	// WP_Filesystem が初期化されていない場合は初期化する
	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . '/wp-admin/includes/file.php';
		WP_Filesystem();
	}

	// ディレクトリごと削除
	if ( isset( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
		$wp_filesystem->delete( $dev_favicon_switcher_target_dir, true );
	}
}
