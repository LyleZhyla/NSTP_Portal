<?php $isSuperAdminFooter = ($_SESSION['role'] ?? '') === 'super_admin'; ?>

<?php if ($isSuperAdminFooter): ?>
<footer class="main-footer super-admin-footer" role="contentinfo">
    <div class="super-admin-footer__inner">
        <div class="super-admin-footer__brand">
            <span class="super-admin-footer__logo" aria-hidden="true">
                <img src="include/logo.png" alt="">
            </span>
            <span class="super-admin-footer__identity">
                <strong>TAU National Service Training Program</strong>
                <span>QR Code Attendance System</span>
            </span>
        </div>

        <div class="super-admin-footer__meta">
            <span class="super-admin-footer__portal">
                <i class="fas fa-shield-alt" aria-hidden="true"></i>
                Super Admin Portal
            </span>
            <span class="super-admin-footer__copyright">
                &copy; <?php echo date('Y'); ?> TAU-NSTP. All rights reserved.
            </span>
        </div>
    </div>
</footer>
<?php else: ?>
<footer class="main-footer">
    <div class="d-flex justify-content-between align-items-center">
        <strong>National Service Training Program &copy; <?php echo date('Y'); ?></strong>
        <span>
            <img src="include/logo.png" alt="NSTP" style="width: 20px; height: 20px; border-radius: 4px; margin-right: 5px;">
            TAU-NSTP
        </span>
        All rights reserved.
    </div>
</footer>
<?php endif; ?>
