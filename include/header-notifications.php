<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$headerNotifications = [];
$headerNotificationCount = 0;

if (isset($_SESSION['user_id'])) {
    if (!isset($conn)) {
        require_once __DIR__ . '/../conn/conn.php';
    }
    require_once __DIR__ . '/notifications.php';

    try {
        $headerNotifications = getUserNotifications($conn, (int) $_SESSION['user_id'], 12);
        $headerNotificationCount = countUnreadNotifications($conn, (int) $_SESSION['user_id']);
    } catch (Throwable $error) {
        $headerNotifications = [];
        $headerNotificationCount = 0;
    }
}
?>

<li class="nav-item dropdown">
    <a class="nav-link notification-nav-link" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Open notifications">
        <i class="far fa-bell"></i>
        <?php if ($headerNotificationCount > 0): ?>
            <span class="badge badge-warning navbar-badge" id="headerNotificationBadge"><?php echo $headerNotificationCount; ?></span>
        <?php endif; ?>
    </a>
    <div class="dropdown-menu dropdown-menu-right notification-dropdown" id="headerNotificationDropdown">
        <div class="notification-dropdown-header">
            <i class="far fa-bell mr-2"></i>Notifications
        </div>
        <div id="headerNotificationList">
            <?php if (count($headerNotifications) > 0): ?>
                <?php foreach ($headerNotifications as $notification): ?>
                    <?php
                        $notificationType = (string) ($notification['type'] ?? '');
                        $iconClass = $notificationType === 'late_attendance' ? 'fa-clock text-warning' : 'fa-bullhorn text-info';
                        $notificationTitle = $notification['title'] ?? 'Notification';
                        $notificationMessage = $notification['message'] ?? '';
                        $notificationTime = date('M d, Y h:i A', strtotime($notification['created_at'] ?? 'now'));
                        $isRead = (int) ($notification['is_read'] ?? 0) === 1;
                    ?>
                    <div
                        class="header-notification-item <?php echo $isRead ? 'is-read' : 'is-unread'; ?>"
                        id="header-notification-<?php echo (int) $notification['notification_id']; ?>"
                        role="button"
                        tabindex="0"
                        data-title="<?php echo htmlspecialchars($notificationTitle, ENT_QUOTES, 'UTF-8'); ?>"
                        data-message="<?php echo htmlspecialchars($notificationMessage, ENT_QUOTES, 'UTF-8'); ?>"
                        data-time="<?php echo htmlspecialchars($notificationTime, ENT_QUOTES, 'UTF-8'); ?>"
                        data-icon="<?php echo htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8'); ?>"
                        data-is-read="<?php echo $isRead ? '1' : '0'; ?>"
                    >
                        <div class="notification-icon">
                            <i class="fas <?php echo $iconClass; ?>"></i>
                        </div>
                        <div class="notification-copy">
                            <div class="notification-title">
                                <?php echo htmlspecialchars($notificationTitle, ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!$isRead): ?>
                                    <span class="notification-unread-dot" aria-label="Unread"></span>
                                <?php endif; ?>
                            </div>
                            <div class="notification-message"><?php echo htmlspecialchars($notificationMessage, ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="notification-time"><?php echo htmlspecialchars($notificationTime, ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="notification-actions">
                                <?php if ($isRead): ?>
                                    <span class="notification-read-label"><i class="fas fa-check mr-1"></i>Read</span>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary header-mark-notification-read" data-id="<?php echo (int) $notification['notification_id']; ?>">
                                        <i class="fas fa-check mr-1"></i>Mark as read
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-danger header-delete-notification" data-id="<?php echo (int) $notification['notification_id']; ?>">
                                    <i class="fas fa-trash mr-1"></i>Delete
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="notification-empty-state" id="headerNotificationEmpty">
                    <i class="far fa-bell-slash"></i>
                    <p>No new notifications</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</li>

<div class="modal fade" id="headerNotificationDetailModal" tabindex="-1" role="dialog" aria-labelledby="headerNotificationDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered notification-detail-dialog" role="document">
        <div class="modal-content notification-modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center">
                    <div class="notification-detail-icon-wrap mr-3">
                        <i class="far fa-bell" id="headerNotificationDetailIcon"></i>
                    </div>
                    <div>
                        <h5 class="modal-title mb-1" id="headerNotificationDetailModalLabel">
                            <span id="headerNotificationDetailTitle">Notification</span>
                        </h5>
                        <div class="notification-detail-time mb-0" id="headerNotificationDetailTime"></div>
                    </div>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="notification-detail-message" id="headerNotificationDetailMessage"></div>
            </div>
        </div>
    </div>
</div>

<style>
    .notification-nav-link {
        position: relative;
    }

    .notification-dropdown {
        width: min(380px, calc(100vw - 24px));
        max-height: 430px;
        overflow-y: auto;
        padding: 0;
        border: 0;
        border-radius: 8px;
        box-shadow: 0 16px 40px rgba(15, 23, 42, 0.18);
    }

    .notification-dropdown-header {
        padding: 12px 16px;
        font-weight: 700;
        color: #1f2937;
        border-bottom: 1px solid rgba(0, 0, 0, 0.08);
        background: #fff;
    }

    .notification-detail-dialog {
        max-width: 520px;
    }

    #headerNotificationDetailModal {
        z-index: 2055;
    }

    .modal-backdrop.notification-detail-backdrop,
    .modal-backdrop.show {
        z-index: 2050;
    }

    .notification-modal-content {
        border-radius: 8px;
        overflow: hidden;
        border: 0;
        box-shadow: 0 16px 40px rgba(15, 23, 42, 0.22);
    }

    .header-notification-item {
        display: flex;
        gap: 12px;
        padding: 16px 18px;
        border-bottom: 1px solid rgba(0, 0, 0, 0.08);
        background: #fff;
        cursor: pointer;
    }

    .header-notification-item.is-read {
        background: #fbfdff;
    }

    .header-notification-item:last-child {
        border-bottom: 0;
    }

    .header-notification-item:hover,
    .header-notification-item:focus {
        background: #f8fafc;
        outline: 0;
    }

    .notification-icon {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #f4f6f9;
        display: flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 36px;
    }

    .notification-copy {
        min-width: 0;
        flex: 1;
    }

    .notification-title {
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 4px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .notification-unread-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #f59e0b;
        flex: 0 0 8px;
    }

    .notification-message {
        color: #4b5563;
        font-size: 0.9rem;
        line-height: 1.45;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
    }

    .notification-time {
        color: #6b7280;
        font-size: 0.78rem;
        margin: 8px 0 10px;
    }

    .notification-actions {
        min-height: 31px;
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }

    .notification-read-label {
        color: #6b7280;
        font-size: 0.82rem;
        font-weight: 700;
    }

    .notification-detail-icon-wrap {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: #f4f6f9;
        display: flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 42px;
    }

    .notification-detail-time {
        color: #6b7280;
        font-size: 0.82rem;
        margin-bottom: 12px;
    }

    .notification-detail-message {
        color: #1f2937;
        line-height: 1.6;
        white-space: pre-wrap;
        overflow-wrap: anywhere;
        padding: 14px 16px;
        border: 1px solid rgba(0, 0, 0, 0.08);
        border-radius: 8px;
        background: #f8fafc;
    }

    .notification-empty-state {
        padding: 38px 20px;
        text-align: center;
        color: #6b7280;
    }

    .notification-empty-state i {
        font-size: 2rem;
        margin-bottom: 10px;
    }

    .notification-empty-state p {
        margin: 0;
        font-weight: 600;
    }

    body.dark-mode .notification-dropdown,
    body.dark-mode .notification-dropdown-header,
    body.dark-mode .header-notification-item {
        background: #343a40;
        border-bottom-color: rgba(255, 255, 255, 0.1);
    }

    body.dark-mode .header-notification-item:hover,
    body.dark-mode .header-notification-item:focus {
        background: #3d444b;
    }

    body.dark-mode .header-notification-item.is-read {
        background: #30363b;
    }

    body.dark-mode .notification-icon,
    body.dark-mode .notification-detail-icon-wrap {
        background: #2f353a;
    }

    body.dark-mode .notification-title {
        color: #f8f9fa;
    }

    body.dark-mode .notification-message,
    body.dark-mode .notification-time,
    body.dark-mode .notification-detail-time,
    body.dark-mode .notification-detail-message,
    body.dark-mode .notification-empty-state {
        color: #ced4da;
    }

    body.dark-mode .notification-detail-message {
        background: #30363b;
        border-color: rgba(255, 255, 255, 0.12);
    }

    @media (max-width: 575.98px) {
        .notification-dropdown {
            width: calc(100vw - 18px);
        }

        .notification-detail-dialog {
            margin: 0.75rem;
        }
    }
</style>

<script>
(function() {
    const modalId = 'headerNotificationDetailModal';

    function getNotificationModal() {
        return document.getElementById(modalId);
    }

    function moveModalToBody() {
        const modal = getNotificationModal();
        if (modal && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    }

    function updateNotificationBadge() {
        const badge = document.getElementById('headerNotificationBadge');
        if (!badge) {
            return;
        }

        const currentCount = parseInt(badge.textContent, 10) || 0;
        const nextCount = Math.max(currentCount - 1, 0);
        if (nextCount === 0) {
            badge.remove();
        } else {
            badge.textContent = nextCount;
        }
    }

    function markNotificationItemRead(item, button) {
        if (!item) {
            return;
        }

        item.classList.remove('is-unread');
        item.classList.add('is-read');
        item.dataset.isRead = '1';

        const dot = item.querySelector('.notification-unread-dot');
        if (dot) {
            dot.remove();
        }

        const actions = item.querySelector('.notification-actions');
        if (actions) {
            const deleteButton = actions.querySelector('.header-delete-notification');
            actions.innerHTML = '<span class="notification-read-label"><i class="fas fa-check mr-1"></i>Read</span>';
            if (deleteButton) {
                actions.appendChild(deleteButton);
            }
        } else if (button) {
            button.outerHTML = '<span class="notification-read-label"><i class="fas fa-check mr-1"></i>Read</span>';
        }
    }

    function showEmptyNotificationStateIfNeeded() {
        const list = document.getElementById('headerNotificationList');
        if (!list || list.querySelector('.header-notification-item')) {
            return;
        }

        list.innerHTML = '<div class="notification-empty-state" id="headerNotificationEmpty"><i class="far fa-bell-slash"></i><p>No new notifications</p></div>';
    }

    function deleteNotificationItem(item) {
        if (!item) {
            return;
        }

        if (item.dataset.isRead !== '1') {
            updateNotificationBadge();
        }

        item.remove();
        showEmptyNotificationStateIfNeeded();
    }

    function openNotificationDetail(item) {
        if (!item) {
            return;
        }

        const icon = document.getElementById('headerNotificationDetailIcon');
        const title = document.getElementById('headerNotificationDetailTitle');
        const time = document.getElementById('headerNotificationDetailTime');
        const message = document.getElementById('headerNotificationDetailMessage');

        if (icon) {
            icon.className = 'fas ' + (item.dataset.icon || 'fa-bell text-info');
        }
        if (title) {
            title.textContent = item.dataset.title || 'Notification';
        }
        if (time) {
            time.textContent = item.dataset.time || '';
        }
        if (message) {
            message.textContent = item.dataset.message || '';
        }

        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
            moveModalToBody();
            if (window.jQuery.fn.dropdown) {
                window.jQuery('.notification-nav-link').dropdown('hide');
                window.jQuery('.notification-nav-link').attr('aria-expanded', 'false');
            }
            window.jQuery('#' + modalId).modal({
                backdrop: true,
                keyboard: true,
                show: true
            });
            setTimeout(function() {
                const backdrop = document.querySelector('.modal-backdrop');
                if (backdrop) {
                    backdrop.classList.add('notification-detail-backdrop');
                }
            }, 0);
        }
    }

    document.addEventListener('keydown', function(event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        const item = event.target.closest('.header-notification-item');
        if (!item || event.target.closest('.header-mark-notification-read, .header-delete-notification')) {
            return;
        }

        event.preventDefault();
        openNotificationDetail(item);
    });

    document.addEventListener('click', function(event) {
        const button = event.target.closest('.header-mark-notification-read');
        if (!button) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const notificationId = button.getAttribute('data-id');
        if (!notificationId) {
            return;
        }

        const item = button.closest('.header-notification-item');
        if (item && item.dataset.isRead === '1') {
            button.disabled = false;
            return;
        }

        button.disabled = true;
        const formData = new FormData();
        formData.append('notification_id', notificationId);

        fetch('endpoint/mark-notification-read.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function(response) { return response.json(); })
            .then(function(response) {
                if (!response.success) {
                    button.disabled = false;
                    return;
                }

                markNotificationItemRead(item || document.getElementById('header-notification-' + notificationId), button);
                updateNotificationBadge();
            })
            .catch(function() {
                button.disabled = false;
            });
    });

    document.addEventListener('click', function(event) {
        const button = event.target.closest('.header-delete-notification');
        if (!button) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        const notificationId = button.getAttribute('data-id');
        if (!notificationId) {
            return;
        }

        const item = button.closest('.header-notification-item');
        button.disabled = true;

        const formData = new FormData();
        formData.append('notification_id', notificationId);

        fetch('endpoint/delete-notification.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function(response) { return response.json(); })
            .then(function(response) {
                if (!response.success) {
                    button.disabled = false;
                    return;
                }

                deleteNotificationItem(item || document.getElementById('header-notification-' + notificationId));
            })
            .catch(function() {
                button.disabled = false;
            });
    });

    document.addEventListener('click', function(event) {
        const item = event.target.closest('.header-notification-item');
        if (!item || event.target.closest('.header-mark-notification-read, .header-delete-notification')) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        openNotificationDetail(item);
    });

    document.addEventListener('click', function(event) {
        const closeButton = event.target.closest('#headerNotificationDetailModal [data-dismiss="modal"]');
        if (!closeButton) {
            return;
        }

        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
            window.jQuery('#' + modalId).modal('hide');
            return;
        }

        const modal = getNotificationModal();
        if (modal) {
            modal.classList.remove('show');
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            modal.removeAttribute('aria-modal');
        }
        document.body.classList.remove('modal-open');
        document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
            backdrop.remove();
        });
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', moveModalToBody);
    } else {
        moveModalToBody();
    }
})();
</script>
