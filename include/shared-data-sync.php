<?php
$sharedDataRevision = getSharedDataRevision($conn);
?>
<script>
(function () {
    let currentRevision = <?php echo (int) $sharedDataRevision; ?>;
    let checking = false;
    let reloadPending = false;

    function pageIsBusy() {
        return document.querySelector('.modal.show') !== null
            || document.querySelector('[aria-busy="true"]') !== null;
    }

    function reloadWhenSafe() {
        if (document.hidden || pageIsBusy()) {
            reloadPending = true;
            return;
        }

        window.location.reload();
    }

    function checkForSharedChanges() {
        if (reloadPending && !document.hidden && !pageIsBusy()) {
            window.location.reload();
            return;
        }

        if (checking) {
            return;
        }

        checking = true;
        fetch('./endpoint/get-shared-data-revision.php', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Unable to check shared data revision.');
                }
                return response.json();
            })
            .then(function (response) {
                const nextRevision = Number(response.revision || 0);
                if (response.success && nextRevision > currentRevision) {
                    currentRevision = nextRevision;
                    reloadWhenSafe();
                }
            })
            .catch(function () {
                // A temporary connection issue should not disrupt the current page.
            })
            .finally(function () {
                checking = false;
            });
    }

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            if (reloadPending && !pageIsBusy()) {
                window.location.reload();
                return;
            }
            checkForSharedChanges();
        }
    });

    if (window.jQuery) {
        window.jQuery(document).on('hidden.bs.modal', function () {
            if (reloadPending && !pageIsBusy()) {
                window.location.reload();
            }
        });
    }

    window.setInterval(checkForSharedChanges, 3000);
})();
</script>
