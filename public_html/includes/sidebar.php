<?php
/**
 * includes/sidebar.php
 *
 * Sidebar navigasi terpusat - dipakai di semua halaman.
 * Requires: $current_user (dari get_logged_in_user()) dan $active_page (string) sudah di-set oleh caller.
 *
 * $active_page values: 'dashboard', 'create', 'list', 'pending', 'users'
 */

$BASE = getenv('APP_URL') ?: 'http://localhost/SIPB-GPI';
$role_labels = [
    'superadmin' => 'SUPERADMIN',
    'approver' => 'APPROVER',
    'user' => 'USER',
];
?>
<div class="sidebar">
    <div class="sidebar-brand">
        <img src="<?php echo $BASE; ?>/assets/img/logo-indogum-white.png" alt="IndoGum" class="brand-logo">
        <span class="label">SIPB-GPI</span>
    </div>
    <nav class="sidebar-nav">
        <a href="<?php echo $BASE; ?>/index.php" class="<?php echo $active_page === 'dashboard' ? 'active' : ''; ?>">
            <i class="fas fa-chart-line"></i> <span class="label">Dashboard</span>
        </a>
        <a href="<?php echo $BASE; ?>/sipb/create.php" class="<?php echo $active_page === 'create' ? 'active' : ''; ?>">
            <i class="fas fa-file-circle-plus"></i> <span class="label">Submit SIPB</span>
        </a>
        <a href="<?php echo $BASE; ?>/sipb/list.php" class="<?php echo $active_page === 'list' ? 'active' : ''; ?>">
            <i class="fas fa-clock-rotate-left"></i> <span class="label">History SIPB</span>
        </a>
        <?php if (in_array($current_user['role'], ['approver', 'superadmin'])): ?>
        <a href="<?php echo $BASE; ?>/approval/pending.php" class="<?php echo $active_page === 'pending' ? 'active' : ''; ?>">
            <i class="fas fa-clipboard-check"></i> <span class="label">Approval SIPB</span>
        </a>
        <?php endif; ?>
        <?php if ($current_user['role'] === 'superadmin'): ?>
        <a href="<?php echo $BASE; ?>/admin/users/list.php" class="<?php echo $active_page === 'users' ? 'active' : ''; ?>">
            <i class="fas fa-users-gear"></i> <span class="label">User Management</span>
        </a>
        <a href="<?php echo $BASE; ?>/admin/fg-products/list.php" class="<?php echo $active_page === 'fg-products' ? 'active' : ''; ?>">
            <i class="fas fa-database"></i> <span class="label">Manage Database FG</span>
        </a>
        <a href="<?php echo $BASE; ?>/admin/cleanup-rejected.php" class="<?php echo $active_page === 'cleanup-rejected' ? 'active' : ''; ?>">
            <i class="fas fa-trash"></i> <span class="label">Cleanup Rejected SIPB</span>
        </a>
        <?php endif; ?>
        <a href="<?php echo $BASE; ?>/logout.php">
            <i class="fas fa-right-from-bracket"></i> <span class="label">Logout</span>
        </a>
    </nav>
    <div class="sidebar-user">
        <div class="user-name"><?php echo htmlspecialchars($current_user['name'] ?? ''); ?></div>
        <div><?php echo htmlspecialchars($current_user['position_name'] ?? $current_user['section'] ?? ''); ?></div>
        <span class="user-role"><?php echo htmlspecialchars($role_labels[$current_user['role']] ?? strtoupper($current_user['role'] ?? '')); ?></span>
    </div>
</div>

