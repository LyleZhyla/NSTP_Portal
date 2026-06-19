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

// Keep Bootstrap modals usable even when they are rendered inside stacked
// AdminLTE containers, cards, or tables.
(function() {
    if (window.__nstpModalStackFixLoaded) {
        return;
    }
    window.__nstpModalStackFixLoaded = true;

    function moveModalToBody(modal) {
        if (modal && modal.classList && modal.classList.contains('modal') && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    }

    function normalizeOpenModals() {
        document.querySelectorAll('.modal').forEach(moveModalToBody);
        document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
            backdrop.style.setProperty('z-index', '20040', 'important');
        });
        document.querySelectorAll('.modal.show').forEach(function(modal) {
            modal.style.setProperty('z-index', '20060', 'important');
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        normalizeOpenModals();

        if (window.jQuery) {
            window.jQuery(document).on('show.bs.modal', '.modal', function() {
                moveModalToBody(this);
                this.style.setProperty('z-index', '20060', 'important');
                setTimeout(normalizeOpenModals, 0);
            });

            window.jQuery(document).on('shown.bs.modal', '.modal', normalizeOpenModals);
        }

        new MutationObserver(normalizeOpenModals).observe(document.body, {
            childList: true,
            subtree: true
        });
    });
})();
</script>
