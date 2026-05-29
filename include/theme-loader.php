<?php
// theme-loader.php - Only contains theme initialization, NO OUTPUT
?>
<script>
// Theme initialization - Run immediately to prevent flash
(function() {
    try {
        const savedTheme = localStorage.getItem('theme') || 'light';
        
        // Apply theme immediately
        if (savedTheme === 'dark') {
            document.documentElement.classList.add('dark-mode');
            if (document.body) document.body.classList.add('dark-mode');
        } else if (savedTheme === 'auto') {
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark-mode');
                if (document.body) document.body.classList.add('dark-mode');
            }
        }
    } catch (e) {
        console.log('Theme initialization error:', e);
    }
})();
</script>