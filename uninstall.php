<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// 設定削除
delete_option('ku_df_switcher_settings');
delete_option('ku_df_switcher_setup_completed');