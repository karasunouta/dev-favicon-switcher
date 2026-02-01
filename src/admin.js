/**
 * Dev Favicon Switcher - Admin JavaScript
 * Vanilla JavaScript (no jQuery dependency)
 */

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
        const selectButton = document.getElementById('select-dev-icon');
        const removeButton = document.getElementById('remove-dev-icon');
        
        if (!selectButton) return;
        
        // Check if WordPress Media and Cropper are available
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            console.error('WordPress media library not loaded');
            setupSimpleMediaUploader();
            return;
        }
        
        let iconCropperFrame;
        
        selectButton.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Reopen if already exists
            if (iconCropperFrame) {
                iconCropperFrame.open();
                return;
            }
            
            // Create media frame with cropper
            iconCropperFrame = wp.media({
                button: {
                    text: 'Select and Crop',
                    close: false
                },
                states: [
                    new wp.media.controller.Library({
                        title: 'Choose Development Icon',
                        library: wp.media.query({ type: 'image' }),
                        multiple: false,
                        date: false,
                        priority: 20,
                        suggestedWidth: 512,
                        suggestedHeight: 512
                    }),
                    new wp.media.controller.CustomizeImageCropper({
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
            iconCropperFrame.on('select', function() {
                const selection = iconCropperFrame.state().get('selection');
                const attachment = selection.first().toJSON();
                
                // Proceed to crop state
                iconCropperFrame.setState('cropper');
            });
            
            // When cropping is complete
            iconCropperFrame.on('cropped', function(croppedImage) {
                // Custom crop handler
                handleCrop(croppedImage);
            });
            
            // When user skips cropping
            iconCropperFrame.on('skippedcrop', function() {
                const selection = iconCropperFrame.state().get('selection');
                const attachment = selection.first().toJSON();
                setDevIcon(attachment.id, attachment.url);
                iconCropperFrame.close();
            });
            
            iconCropperFrame.open();
        });
        
        // Handle crop with custom Ajax
        function handleCrop(croppedImage) {
            const cropperState = iconCropperFrame.state('cropper');
            const attachment = cropperState.get('selection').first();
            const cropDetails = cropperState.get('cropDetails');
            const cropNonce = devFaviconAjax.crop_nonce;

            // fetchの代わりに、WordPress標準のwp.ajaxを使うとより安全です
            wp.ajax.send('dev_favicon_crop_image', {
                data: {
                    nonce: cropNonce,
                    id: attachment.get('id'),
                    cropDetails: JSON.stringify(cropDetails)
                },
                success: function(attachmentData) {
                    // attachmentData は PHP側の wp_prepare_attachment_for_js の結果
                    setDevIcon(attachmentData.id, attachmentData.url);
                    
                    // 処理が完了したことを通知してフレームを閉じる
                    iconCropperFrame.setState('library'); // 状態を戻す
                    iconCropperFrame.close();
                },
                error: function(errorMessage) {
                    alert('Crop error: ' + (errorMessage || 'Unknown error'));
                    // エラー時もボタンを戻す必要があるため、一度閉じるか状態をリセット
                    iconCropperFrame.close();
                }
            });
        }
        
        // Helper function to set dev icon
        function setDevIcon(attachmentId, attachmentUrl) {
            document.getElementById('dev_icon_id').value = attachmentId;
            
            const preview = document.getElementById('dev-icon-preview');
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
                
                if (!confirm('Are you sure you want to remove the development icon?')) {
                    return;
                }
                
                document.getElementById('dev_icon_id').value = '';
                document.getElementById('dev-icon-preview').innerHTML = '';
                this.style.display = 'none';
            });
        }
    }
    
    // ============================================
    // Simple Media Uploader (Fallback)
    // ============================================
    function setupSimpleMediaUploader() {
        const selectButton = document.getElementById('select-dev-icon');
        const removeButton = document.getElementById('remove-dev-icon');
        
        if (!selectButton) return;
        
        let devIconFrame;
        
        selectButton.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (devIconFrame) {
                devIconFrame.open();
                return;
            }
            
            devIconFrame = wp.media({
                title: 'Select Development Favicon',
                button: {
                    text: 'Use this icon'
                },
                multiple: false,
                library: {
                    type: 'image'
                }
            });
            
            devIconFrame.on('select', function() {
                const attachment = devIconFrame.state().get('selection').first().toJSON();
                
                document.getElementById('dev_icon_id').value = attachment.id;
                
                const preview = document.getElementById('dev-icon-preview');
                preview.innerHTML = `<img src="${attachment.url}" style="max-width: 64px; height: auto; border: 1px solid #ddd; padding: 5px;">`;
                
                if (removeButton) {
                    removeButton.style.display = 'inline-block';
                }
            });
            
            devIconFrame.open();
        });
        
        if (removeButton) {
            removeButton.addEventListener('click', function(e) {
                e.preventDefault();
                
                if (!confirm('Are you sure you want to remove the development icon?')) {
                    return;
                }
                
                document.getElementById('dev_icon_id').value = '';
                document.getElementById('dev-icon-preview').innerHTML = '';
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
            devUrlTextarea.setAttribute('placeholder', 'e.g., ' + suggestedUrl);
        }
    }
    
    // ============================================
    // Form Validation
    // ============================================
    function setupFormValidation() {
        const form = document.getElementById('dev-favicon-form');
        if (!form) return;
        
        form.addEventListener('submit', function(e) {
            const devIconId = document.getElementById('dev_icon_id').value;
            const autoDetect = document.querySelector('input[name*="[auto_detect]"]');
            const devUrls = document.getElementById('dev_urls').value;
            
            // 開発アイコンが未設定の場合は警告
            if (!devIconId) {
                alert('Please select a development icon before saving.');
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