/**
 * Dev Favicon Switcher - Admin JavaScript
 */

import 'customize-controls';
import 'media-views';
import { __, sprintf } from '@wordpress/i18n';
import './admin.css';

(function() {
    'use strict';
    
    // DOM Ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    function init() {
        // Site Icon Cropper (WordPress標準機能を使用)
        setupSiteIconCropper();
        
        // Dev URL auto-suggestion
        suggestDevUrl();
        
        // Form validation
        setupFormValidation();
    }
    
    // ============================================
    // Site Icon Cropper (WordPress標準)
    // ============================================
    function setupSiteIconCropper() {
        const selectButton = document.getElementById('select-dev-favicon');
        const removeButton = document.getElementById('remove-dev-favicon');
        
        if (!selectButton) return;
        
        // Check if WordPress Media and Cropper are available
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            console.error('WordPress media library not loaded');
            setupSimpleMediaUploader();
            return;
        }
        
        let faviconCropperFrame;
        
        selectButton.addEventListener('click', function(e) {
            e.preventDefault();
            
            // WP Coreの `site-icon.js` の実装に倣い、毎回フレームを再構築することで
            // その後のキャンセルや状態遷移時のバグを完全に回避（クロップ段階で中止して再試行した際の混乱を回避）
            if (faviconCropperFrame) {
                faviconCropperFrame.remove();
            }
            
            // カスタムCropperコントローラーを作成（WordPress標準のアクションを上書き）
            const DevFaviconCropper = wp.media.controller.Cropper.extend({
                doCrop: function(attachment) {
                    console.log('=== CUSTOM doCrop CALLED ===');
                    
                    const cropDetails = attachment.get('cropDetails');
                    const cropNonce = devFaviconAjax.crop_nonce;
                    
                    console.log('Crop details:', cropDetails);
                    
                    // カスタムAjaxリクエスト
                    wp.ajax.post('dev_favicon_crop_image', {
                        nonce: cropNonce,
                        id: attachment.get('id'),
                        cropDetails: JSON.stringify(cropDetails)
                    })
                    .done(function(attachmentData) {
                        console.log('Crop successful:', attachmentData);
                        setDevFavicon(attachmentData.id, attachmentData.url);
                        faviconCropperFrame.close();
                    })
                    .fail(function(error) {
                        console.error('Crop failed:', error);
                        alert('Crop error: ' + (error.message || error || 'Unknown error'));
                    });
                }
            });
            
            // Create media frame with custom cropper
            faviconCropperFrame = wp.media({
                button: {
                    text: 'Crop Image',
                    close: false
                },
                states: [
                    new wp.media.controller.Library({
                        title: 'Choose Dev Favicon',
                        library: wp.media.query({ type: 'image' }),
                        multiple: false,
                        date: false,
                        priority: 20,
                        suggestedWidth: 512,
                        suggestedHeight: 512
                    }),
                    new DevFaviconCropper({
                        imgSelectOptions: calculateImageSelectOptions,
                        control: {
                            id: 'dev-favicon-control',
                            params: {
                                flex_width: false,
                                flex_height: false,
                                width: 512,
                                height: 512
                            }
                        }
                    })
                ]
            });
            
            // When user selects an image from library
            faviconCropperFrame.on('select', function() {
                const selection = faviconCropperFrame.state().get('selection');
                const attachment = selection.first().toJSON();
                
                console.log('Image selected:', attachment);
                
                // Proceed to crop state
                faviconCropperFrame.setState('cropper');
            });
            
            // When user skips cropping
            faviconCropperFrame.on('skippedcrop', function() {
                console.log('Crop skipped');
                const selection = faviconCropperFrame.state().get('selection');
                const attachment = selection.first().toJSON();
                setDevFavicon(attachment.id, attachment.url);
                faviconCropperFrame.close();
            });
            
            faviconCropperFrame.open();
        });
        
        // Helper function to set dev icon
        function setDevFavicon(attachmentId, attachmentUrl) {
            console.log('Setting dev icon:', attachmentId, attachmentUrl);
            
            document.getElementById('dev_favicon_id').value = attachmentId;
            
            const preview = document.getElementById('dev-favicon-preview');
            preview.innerHTML = `<img src="${attachmentUrl}" style="max-width: 64px; height: auto; border: 1px solid #ddd; padding: 5px;">`;
            
            if (removeButton) {
                removeButton.style.display = 'inline-block';
            }
        }
        
        // Calculate crop area for square 1:1 ratio
        function calculateImageSelectOptions(attachment, controller) {
            const realWidth = attachment.get('width');
            const realHeight = attachment.get('height');
            const xInit = 512;
            const yInit = 512;
            
            // Can skip crop if image is already small enough
            const canSkipCrop = (realWidth <= xInit && realHeight <= yInit);
            controller.set('canSkipCrop', canSkipCrop);
            
            // Calculate initial crop area (centered square)
            const ratio = xInit / yInit; // 1:1 for square
            const imgSelectOptions = {
                handles: true,
                keys: true,
                instance: true,
                persistent: true,
                imageWidth: realWidth,
                imageHeight: realHeight,
                minWidth: xInit > realWidth ? realWidth : xInit,
                minHeight: yInit > realHeight ? realHeight : yInit,
                aspectRatio: xInit + ':' + yInit
            };
            
            // Center the crop area
            if (realWidth > realHeight) {
                // Landscape - center horizontally
                const cropHeight = realHeight;
                const cropWidth = cropHeight * ratio;
                imgSelectOptions.x1 = Math.max(0, (realWidth - cropWidth) / 2);
                imgSelectOptions.x2 = Math.min(realWidth, imgSelectOptions.x1 + cropWidth);
                imgSelectOptions.y1 = 0;
                imgSelectOptions.y2 = realHeight;
            } else {
                // Portrait or square - center vertically
                const cropWidth = realWidth;
                const cropHeight = cropWidth / ratio;
                imgSelectOptions.y1 = Math.max(0, (realHeight - cropHeight) / 2);
                imgSelectOptions.y2 = Math.min(realHeight, imgSelectOptions.y1 + cropHeight);
                imgSelectOptions.x1 = 0;
                imgSelectOptions.x2 = realWidth;
            }
            
            return imgSelectOptions;
        }
        
        // Remove dev icon
        if (removeButton) {
            removeButton.addEventListener('click', function(e) {
                e.preventDefault();
                
                if (!confirm(__('Are you sure you want to remove the dev favicon setting?\n\n(The image file will remain in your media library)', 'dev-favicon-switcher'))) {
                    return;
                }
                
                // クリア処理を実行
                const formData = new FormData();
                formData.append('action', 'dev_favicon_remove_icon');
                formData.append('nonce', devFaviconAjax.nonce);
                
                fetch(devFaviconAjax.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // UI更新
                        document.getElementById('dev_favicon_id').value = '';
                        document.getElementById('dev-favicon-preview').innerHTML = '';
                        removeButton.style.display = 'none';
                        
                        console.log('Dev favicon setting removed');
                    } else {
                        alert(__('Failed to remove icon setting: ', 'dev-favicon-switcher') + (data.data || __('Unknown error', 'dev-favicon-switcher')));
                    }
                })
                .catch(error => {
                    console.error('Remove error:', error);
                    alert(__('Failed to remove icon setting', 'dev-favicon-switcher'));
                });
            });
        }

        // Restore Default Icon
        const restoreButton = document.getElementById('restore-default-favicon');
        if (restoreButton) {
            restoreButton.addEventListener('click', function(e) {
                e.preventDefault();
                
                if (!confirm(__('Are you sure you want to restore the default dev favicon?', 'dev-favicon-switcher'))) {
                    return;
                }
                
                // Add loading state
                const originalText = restoreButton.innerHTML;
                restoreButton.innerHTML = __('Restoring...', 'dev-favicon-switcher');
                restoreButton.disabled = true;

                const formData = new FormData();
                formData.append('action', 'dev_favicon_restore_default');
                formData.append('nonce', devFaviconAjax.nonce);
                
                fetch(devFaviconAjax.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    restoreButton.innerHTML = originalText;
                    restoreButton.disabled = false;

                    if (data.success && data.data && data.data.id && data.data.url) {
                        // UI更新
                        setDevFavicon(data.data.id, data.data.url);
                        
                        console.log('Default dev favicon restored');
                        
                        // 保存を促す（実装プラン通り）
                        alert(__('Default icon restored successfully. Please click "Save Settings" to apply changes.', 'dev-favicon-switcher'));
                    } else {
                        alert(__('Failed to restore default icon: ', 'dev-favicon-switcher') + (data.data || __('Unknown error', 'dev-favicon-switcher')));
                    }
                })
                .catch(error => {
                    restoreButton.innerHTML = originalText;
                    restoreButton.disabled = false;
                    console.error('Restore error:', error);
                    alert(__('Failed to restore default icon.', 'dev-favicon-switcher'));
                });
            });
        }
    }
    
    // ============================================
    // Simple Media Uploader (Fallback)
    // ============================================
    function setupSimpleMediaUploader() {
        const selectButton = document.getElementById('select-dev-favicon');
        const removeButton = document.getElementById('remove-dev-favicon');
        
        if (!selectButton) return;
        
        let devFaviconFrame;
        
        selectButton.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (devFaviconFrame) {
                devFaviconFrame.open();
                return;
            }
            
            devFaviconFrame = wp.media({
                title: 'Select Development Favicon',
                button: {
                    text: 'Use this icon'
                },
                multiple: false,
                library: {
                    type: 'image'
                }
            });
            
            devFaviconFrame.on('select', function() {
                const attachment = devFaviconFrame.state().get('selection').first().toJSON();
                
                document.getElementById('dev_favicon_id').value = attachment.id;
                
                const preview = document.getElementById('dev-favicon-preview');
                preview.innerHTML = `<img src="${attachment.url}" style="max-width: 64px; height: auto; border: 1px solid #ddd; padding: 5px;">`;
                
                if (removeButton) {
                    removeButton.style.display = 'inline-block';
                }
            });
            
            devFaviconFrame.open();
        });
        
        if (removeButton) {
            removeButton.addEventListener('click', function(e) {
                e.preventDefault();
                
                if (!confirm(__('Are you sure you want to remove the dev favicon?', 'dev-favicon-switcher'))) {
                    return;
                }
                
                document.getElementById('dev_favicon_id').value = '';
                document.getElementById('dev-favicon-preview').innerHTML = '';
                this.style.display = 'none';
            });
        }
    }
    
    // ============================================
    // Auto-suggest Dev URL
    // ============================================
    function suggestDevUrl() {
        const devUrlTextarea = document.getElementById('dev_urls');
        if (!devUrlTextarea || devUrlTextarea.value) return;
        
        const hostname = window.location.hostname;
        if (hostname.includes('.local') || hostname.includes('.test') || hostname.includes('.dev')) {
            const suggestedUrl = window.location.protocol + '//' + hostname + '/';

            /* translators: %s: URL like "user-domain.local". */
            const placeholderTemplate = __('e.g., %s', 'dev-favicon-switcher');
            const placeholder = sprintf(placeholderTemplate, suggestedUrl);

            devUrlTextarea.setAttribute('placeholder', placeholder);
        }
    }
    
    // ============================================
    // Form Validation
    // ============================================
    function setupFormValidation() {
        const form = document.getElementById('dev-favicon-form');
        if (!form) return;
        
        form.addEventListener('submit', function(e) {
            const devIconId = document.getElementById('dev_favicon_id').value;
            const autoDetect = document.querySelector('input[name*="[auto_detect]"]');
            const devUrls = document.getElementById('dev_urls').value;
            
            // 開発アイコンが未設定の場合は警告
            if (!devIconId) {
                alert('Please select a dev favicon before saving.');
                e.preventDefault();
                return false;
            }
            
            // 自動検出もURLも設定されていない場合は警告
            if ((!autoDetect || !autoDetect.checked) && !devUrls.trim()) {
                if (!confirm('Neither auto-detect nor development URLs are set. The plugin will not activate. Continue anyway?')) {
                    e.preventDefault();
                    return false;
                }
            }
            
            return true;
        });
    }
    
})();