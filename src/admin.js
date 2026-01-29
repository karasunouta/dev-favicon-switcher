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
        
        if (!selectButton || typeof wp === 'undefined' || typeof wp.customizer === 'undefined') {
            // Fallback to simple media uploader if site-icon script not loaded
            setupSimpleMediaUploader();
            return;
        }
        
        selectButton.addEventListener('click', function(e) {
            e.preventDefault();
            
            // WordPressのサイトアイコンクロッパーを使用
            const frame = wp.media({
                button: {
                    text: 'Select and Crop',
                    close: false
                },
                states: [
                    new wp.media.controller.Library({
                        title: 'Choose Development Icon',
                        library: wp.media.query({ type: 'image' }),
                        date: false,
                        suggestedWidth: 512,
                        suggestedHeight: 512
                    }),
                    new wp.media.controller.SiteIconCropper({
                        control: {
                            params: {
                                width: 512,
                                height: 512
                            }
                        }
                    })
                ]
            });
            
            frame.on('cropped', function(croppedImage) {
                const attachmentId = croppedImage.id;
                const attachmentUrl = croppedImage.url;
                
                // Set the ID
                document.getElementById('dev_icon_id').value = attachmentId;
                
                // Update preview
                const preview = document.getElementById('dev-icon-preview');
                preview.innerHTML = `<img src="${attachmentUrl}" style="max-width: 64px; height: auto; border: 1px solid #ddd; padding: 5px;">`;
                
                // Show remove button
                if (removeButton) {
                    removeButton.style.display = 'inline-block';
                }
                
                frame.close();
            });
            
            frame.on('skippedcrop', function(selection) {
                const attachment = selection.get('attachment');
                const attachmentId = attachment.get('id');
                const attachmentUrl = attachment.get('url');
                
                // Set the ID
                document.getElementById('dev_icon_id').value = attachmentId;
                
                // Update preview
                const preview = document.getElementById('dev-icon-preview');
                preview.innerHTML = `<img src="${attachmentUrl}" style="max-width: 64px; height: auto; border: 1px solid #ddd; padding: 5px;">`;
                
                // Show remove button
                if (removeButton) {
                    removeButton.style.display = 'inline-block';
                }
                
                frame.close();
            });
            
            frame.open();
        });
        
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