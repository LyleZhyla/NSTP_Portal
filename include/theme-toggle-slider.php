<?php
// theme-toggle-slider.php - SLIDING toggle button (only for index.php)
?>
<!-- Sliding Theme Toggle Button -->
<style>
/* Sliding Toggle Switch - Modern Design */
.theme-toggle-wrapper {
    display: flex;
    align-items: center;
    margin-left: 15px;
    position: relative;
}

.theme-toggle-label {
    margin-right: 10px;
    font-size: 0.9rem;
    color: #2c3e50;
    font-weight: 500;
}

body.dark-mode .theme-toggle-label {
    color: #e0e0e0;
}

.theme-switch {
    position: relative;
    display: inline-block;
    width: 70px;
    height: 34px;
}

.theme-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.theme-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, #667eea, #764ba2);
    transition: .4s;
    border-radius: 34px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.theme-slider:before {
    position: absolute;
    content: "";
    height: 26px;
    width: 26px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
    z-index: 2;
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}

/* Sun and Moon icons */
.theme-slider:after {
    position: absolute;
    content: "☀️";
    right: 8px;
    top: 5px;
    font-size: 16px;
    z-index: 1;
}

.theme-switch input:checked + .theme-slider:after {
    content: "🌙";
    left: 8px;
    right: auto;
}

.theme-switch input:checked + .theme-slider:before {
    transform: translateX(36px);
}

/* Auto mode indicator */
.theme-auto-indicator {
    margin-left: 10px;
    font-size: 0.8rem;
    padding: 4px 10px;
    border-radius: 20px;
    background: #e9ecef;
    color: #495057;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 1px solid #dee2e6;
    display: flex;
    align-items: center;
    gap: 5px;
}

.theme-auto-indicator i {
    font-size: 0.8rem;
    color: #17a2b8;
}

body.dark-mode .theme-auto-indicator {
    background: #404040;
    color: #e0e0e0;
    border-color: #4a4a4a;
}

.theme-auto-indicator:hover {
    background: #17a2b8;
    color: white;
    border-color: #17a2b8;
}

.theme-auto-indicator:hover i {
    color: white;
}

.theme-auto-indicator.active {
    background: #17a2b8;
    color: white;
    border-color: #17a2b8;
}

.theme-auto-indicator.active i {
    color: white;
}

/* Tooltip */
.theme-tooltip {
    position: absolute;
    bottom: -40px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,0,0,0.8);
    color: white;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
    pointer-events: none;
    z-index: 1000;
}

.theme-toggle-wrapper:hover .theme-tooltip {
    opacity: 1;
    visibility: visible;
    bottom: -45px;
}

/* Responsive */
@media (max-width: 768px) {
    .theme-toggle-wrapper {
        margin-left: 5px;
    }
    
    .theme-toggle-label {
        display: none;
    }
    
    .theme-auto-indicator span {
        display: none;
    }
    
    .theme-auto-indicator {
        padding: 4px 8px;
    }
}
</style>

<div class="theme-toggle-wrapper">
    <span class="theme-toggle-label">Theme:</span>
    
    <label class="theme-switch">
        <input type="checkbox" id="themeToggle" <?php echo (isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark') ? 'checked' : ''; ?>>
        <span class="theme-slider"></span>
    </label>
    
    <div class="theme-auto-indicator" id="autoThemeBtn" title="Follow system preference">
        <i class="fas fa-clock"></i>
        <span>Auto</span>
    </div>
    
    <div class="theme-tooltip">Toggle dark/light mode</div>
</div>

<script>
(function() {
    // Theme Manager with Sliding Toggle
    const ThemeManager = {
        init: function() {
            // Get elements
            const toggle = document.getElementById('themeToggle');
            const autoBtn = document.getElementById('autoThemeBtn');
            
            // Get saved theme
            const savedTheme = localStorage.getItem('theme') || 'light';
            
            // Set initial state
            if (savedTheme === 'dark') {
                toggle.checked = true;
            } else if (savedTheme === 'auto') {
                autoBtn.classList.add('active');
                // For auto, set toggle based on system preference
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    toggle.checked = true;
                } else {
                    toggle.checked = false;
                }
            }
            
            // Toggle change handler
            toggle.addEventListener('change', function() {
                if (this.checked) {
                    // Switch to dark mode
                    localStorage.setItem('theme', 'dark');
                    document.body.classList.add('dark-mode');
                    autoBtn.classList.remove('active');
                    this.checked = true;
                } else {
                    // Switch to light mode
                    localStorage.setItem('theme', 'light');
                    document.body.classList.remove('dark-mode');
                    autoBtn.classList.remove('active');
                    this.checked = false;
                }
                
                // Dispatch event for other components
                document.dispatchEvent(new CustomEvent('themeChanged', { 
                    detail: { theme: localStorage.getItem('theme') } 
                }));
            });
            
            // Auto mode button
            autoBtn.addEventListener('click', function() {
                localStorage.setItem('theme', 'auto');
                
                // Check system preference
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.body.classList.add('dark-mode');
                    toggle.checked = true;
                } else {
                    document.body.classList.remove('dark-mode');
                    toggle.checked = false;
                }
                
                // Update UI
                autoBtn.classList.add('active');
                
                // Dispatch event
                document.dispatchEvent(new CustomEvent('themeChanged', { 
                    detail: { theme: 'auto' } 
                }));
            });
            
            // Listen for system theme changes
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                if (localStorage.getItem('theme') === 'auto') {
                    if (e.matches) {
                        document.body.classList.add('dark-mode');
                        toggle.checked = true;
                    } else {
                        document.body.classList.remove('dark-mode');
                        toggle.checked = false;
                    }
                }
            });
        }
    };
    
    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => ThemeManager.init());
    } else {
        ThemeManager.init();
    }
})();
</script>