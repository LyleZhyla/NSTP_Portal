<?php
// theme-toggle.php - Theme toggle button component
?>
<!-- Theme Toggle Button -->
<li class="nav-item dropdown" id="theme-toggle-container">
    <a class="nav-link" data-toggle="dropdown" href="#" role="button" id="theme-dropdown">
        <i class="fas fa-palette"></i>
        <span class="d-lg-none ml-2">Theme</span>
    </a>
    <div class="dropdown-menu dropdown-menu-right p-2" style="min-width: 200px;">
        <h6 class="dropdown-header">Theme Options</h6>
        <div class="dropdown-item">
            <div class="custom-control custom-radio">
                <input type="radio" id="theme-light" name="theme-radio" class="custom-control-input theme-option" value="light">
                <label class="custom-control-label d-flex align-items-center" for="theme-light">
                    <i class="fas fa-sun mr-2 text-warning"></i> Light Mode
                </label>
            </div>
        </div>
        <div class="dropdown-item">
            <div class="custom-control custom-radio">
                <input type="radio" id="theme-dark" name="theme-radio" class="custom-control-input theme-option" value="dark">
                <label class="custom-control-label d-flex align-items-center" for="theme-dark">
                    <i class="fas fa-moon mr-2 text-primary"></i> Dark Mode
                </label>
            </div>
        </div>
        <div class="dropdown-item">
            <div class="custom-control custom-radio">
                <input type="radio" id="theme-auto" name="theme-radio" class="custom-control-input theme-option" value="auto">
                <label class="custom-control-label d-flex align-items-center" for="theme-auto">
                    <i class="fas fa-clock mr-2 text-info"></i> Auto (System)
                </label>
            </div>
        </div>
        <div class="dropdown-divider"></div>
        <div class="text-center small text-muted" id="theme-status">
            <i class="fas fa-circle-notch fa-spin mr-1" style="display: none;"></i>
            <span></span>
        </div>
    </div>
</li>

<!-- Theme Toggle JavaScript -->
<script>
(function() {
    // Theme management
    const ThemeManager = {
        // Get current theme from localStorage
        getCurrentTheme: function() {
            return localStorage.getItem('theme') || 'light';
        },
        
        // Set theme and save to localStorage
        setTheme: function(theme) {
            localStorage.setItem('theme', theme);
            this.applyTheme(theme);
            this.updateRadioButtons(theme);
            this.showStatus('Theme updated to ' + theme + ' mode');
            
            // Dispatch event for other components
            document.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme: theme } }));
        },
        
        // Apply theme to document
        applyTheme: function(theme) {
            const body = document.body;
            
            if (theme === 'dark') {
                body.classList.add('dark-mode');
            } else if (theme === 'light') {
                body.classList.remove('dark-mode');
            } else if (theme === 'auto') {
                // Check system preference
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    body.classList.add('dark-mode');
                } else {
                    body.classList.remove('dark-mode');
                }
            }
        },
        
        // Update radio button states
        updateRadioButtons: function(theme) {
            const radios = document.querySelectorAll('.theme-option');
            radios.forEach(radio => {
                if (radio.value === theme) {
                    radio.checked = true;
                }
            });
        },
        
        // Show status message in dropdown
        showStatus: function(message) {
            const statusSpan = document.querySelector('#theme-status span');
            const spinner = document.querySelector('#theme-status i');
            
            if (statusSpan) {
                statusSpan.textContent = message;
                if (spinner) spinner.style.display = 'none';
                
                // Clear after 2 seconds
                setTimeout(() => {
                    statusSpan.textContent = '';
                }, 2000);
            }
        },
        
        // Initialize theme
        init: function() {
            const savedTheme = this.getCurrentTheme();
            this.updateRadioButtons(savedTheme);
            
            // Listen for system theme changes if auto is selected
            if (savedTheme === 'auto') {
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
                    this.applyTheme('auto');
                });
            }
            
            // Add event listeners to radio buttons
            const radios = document.querySelectorAll('.theme-option');
            radios.forEach(radio => {
                radio.addEventListener('change', (e) => {
                    if (e.target.checked) {
                        const spinner = document.querySelector('#theme-status i');
                        if (spinner) spinner.style.display = 'inline-block';
                        this.setTheme(e.target.value);
                    }
                });
            });
        }
    };
    
    // Initialize theme manager when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => ThemeManager.init());
    } else {
        ThemeManager.init();
    }
    
    // Make ThemeManager available globally
    window.ThemeManager = ThemeManager;
})();
</script>