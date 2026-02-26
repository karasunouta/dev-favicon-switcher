<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 設定削除
delete_option('dev_favicon_switcher_settings');
delete_option('dev_favicon_switcher_setup_completed');