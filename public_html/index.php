<?php
/**
 * public_html/index.php
 *
 * SIPB-GPI Dashboard
 * Shows KPI cards, charts, top FG items ranking, based on user role
 *
 * Features:
 * - Role-based content (Superadmin, Approver, User) - approver level via Posisi Approval
 * - KPI cards (total SIPB, pending, approved, rejected) - scoped per user untuk role 'user'
 * - Filter: Kategori & Periode (All-time / Bulan Ini / Tahun Ini / Custom range)
 * - Monthly chart
 * - Top FG Items ranking (frekuensi & total quantity, khusus SIPB Approved)
 * - Quick links to pending approvals
 */

date_default_timezone_set('Asia/Jakarta');

require __DIR__ . '/config/config.php';
require_login();

$current_user = get_logged_in_user();

$user_id = $current_user['id'];
$user_name = $current_user['name'];
$user_role = $current_user['role'];
$user_section = $current_user['section'];
$user_approval_level = $current_user['approval_level']; // 'SPV', 'PM', atau null

$db = new Database($conn);

// ============================================
// FILTER: Kategori & Periode
// ============================================
$categories_list = ['R&D Sample', 'Prototipe', 'Alat', 'QC Sample', 'Lain-lain'];
$filter_category = trim($_GET['category'] ?? '');
$filter_period = trim($_GET['period'] ?? 'all'); // all | month | year | custom
$filter_date_from = trim($_GET['date_from'] ?? '');
$filter_date_to = trim($_GET['date_to'] ?? '');

$date_from = null;
$date_to = null;
if ($filter_period === 'month') {
    $date_from = date('Y-m-01');
    $date_to = date('Y-m-t');
} elseif ($filter_period === 'year') {
    $date_from = date('Y-01-01');
    $date_to = date('Y-12-31');
} elseif ($filter_period === 'custom' && !empty($filter_date_from) && !empty($filter_date_to)) {
    $date_from = $filter_date_from;
    $date_to = $filter_date_to;
}

$filter_year = intval($_GET['year'] ?? date('Y')); // dipakai khusus untuk chart bulanan

/**
 * Bangun WHERE clause + params gabungan: scope role + filter kategori + filter periode.
 * $extra_status_sql: kondisi status tambahan (misal "sd.status = 'Approved'"), atau null.
 */
function build_dashboard_where($user_role, $user_id, $filter_category, $date_from, $date_to, $extra_status_sql = null) {
    $conditions = [];
    $params = [];

    if ($user_role === 'user') {
        $conditions[] = "sd.created_by = ?";
        $params[] = $user_id;
    }
    if (!empty($filter_category)) {
        $conditions[] = "EXISTS (SELECT 1 FROM sipb_items si WHERE si.sipb_id = sd.id AND si.category = ?)";
        $params[] = $filter_category;
    }
    if ($date_from && $date_to) {
        $conditions[] = "sd.doc_date BETWEEN ? AND ?";
        $params[] = $date_from;
        $params[] = $date_to;
    }
    if ($extra_status_sql) {
        $conditions[] = $extra_status_sql;
    }

    $where = count($conditions) > 0 ? "WHERE " . implode(" AND ", $conditions) : "";
    return [$where, $params];
}

// ============================================
// KPI CARDS
// ============================================
[$w, $p] = build_dashboard_where($user_role, $user_id, $filter_category, $date_from, $date_to);
$total_sipb = $db->getScalar("SELECT COUNT(*) FROM sipb_documents sd $w", $p) ?? 0;

[$w, $p] = build_dashboard_where($user_role, $user_id, $filter_category, $date_from, $date_to, "sd.status = 'Approved'");
$total_approved = $db->getScalar("SELECT COUNT(*) FROM sipb_documents sd $w", $p) ?? 0;

[$w, $p] = build_dashboard_where($user_role, $user_id, $filter_category, $date_from, $date_to, "sd.status = 'Rejected'");
$total_rejected = $db->getScalar("SELECT COUNT(*) FROM sipb_documents sd $w", $p) ?? 0;

[$w, $p] = build_dashboard_where($user_role, $user_id, $filter_category, $date_from, $date_to, "sd.status IN ('Draft', 'Submitted')");
$total_pending = $db->getScalar("SELECT COUNT(*) FROM sipb_documents sd $w", $p) ?? 0;

// Pending approvals khusus buat approver, berdasarkan posisi (approval_level) - tidak terpengaruh filter
$my_pending_approvals = 0;
if ($user_role === 'approver' && $user_approval_level === 'SPV') {
    $my_pending_approvals = $db->getScalar(
        "SELECT COUNT(*) FROM sipb_documents WHERE approval_spv_status = 'Pending' AND status = 'Submitted'", []
    ) ?? 0;
} elseif ($user_role === 'approver' && $user_approval_level === 'PM') {
    $my_pending_approvals = $db->getScalar(
        "SELECT COUNT(*) FROM sipb_documents WHERE approval_pm_status = 'Pending' AND approval_spv_status = 'Approved'", []
    ) ?? 0;
} elseif ($user_role === 'superadmin') {
    $my_pending_approvals = $db->getScalar("SELECT COUNT(*) FROM sipb_documents WHERE status = 'Submitted'", []) ?? 0;
}

// ============================================
// MONTHLY CHART DATA (ikut filter kategori, tapi selalu per-bulan sepanjang $filter_year)
// ============================================
$monthly_data = [];
for ($m = 1; $m <= 12; $m++) {
    $month_start = sprintf('%04d-%02d-01', $filter_year, $m);
    $month_end = date('Y-m-t', strtotime($month_start));
    [$w, $p] = build_dashboard_where($user_role, $user_id, $filter_category, $month_start, $month_end);
    $count = $db->getScalar("SELECT COUNT(*) FROM sipb_documents sd $w", $p) ?? 0;
    $monthly_data[] = ['month' => date('M', mktime(0, 0, 0, $m, 1)), 'count' => $count];
}

// ============================================
// TOP FG ITEMS - ranking frekuensi & total quantity (khusus SIPB status Approved)
// ============================================
$top_items_conditions = ["sd.status = 'Approved'"];
$top_items_params = [];

if ($user_role === 'user') {
    $top_items_conditions[] = "sd.created_by = ?";
    $top_items_params[] = $user_id;
}
if (!empty($filter_category)) {
    $top_items_conditions[] = "si.category = ?";
    $top_items_params[] = $filter_category;
}
if ($date_from && $date_to) {
    $top_items_conditions[] = "sd.doc_date BETWEEN ? AND ?";
    $top_items_params[] = $date_from;
    $top_items_params[] = $date_to;
}
$top_items_where = "WHERE " . implode(" AND ", $top_items_conditions);

$top_items_raw = $db->getRows(
    "SELECT si.item_code, si.item_name, si.unit, COUNT(*) as freq, SUM(si.quantity) as total_qty
     FROM sipb_items si
     JOIN sipb_documents sd ON si.sipb_id = sd.id
     $top_items_where
     GROUP BY si.item_code, si.item_name, si.unit
     ORDER BY freq DESC
     LIMIT 100",
    $top_items_params
) ?? [];

// Agregasi per item_code (gabungkan lintas unit kalau ada) di PHP
$top_items_agg = [];
foreach ($top_items_raw as $row) {
    $key = $row['item_code'] . '|' . $row['item_name'];
    if (!isset($top_items_agg[$key])) {
        $top_items_agg[$key] = [
            'item_code' => $row['item_code'],
            'item_name' => $row['item_name'],
            'freq' => 0,
            'qty_parts' => [],
        ];
    }
    $top_items_agg[$key]['freq'] += intval($row['freq']);
    $qty_display = rtrim(rtrim(number_format($row['total_qty'], 2, '.', ''), '0'), '.') . ' ' . $row['unit'];
    $top_items_agg[$key]['qty_parts'][] = $qty_display;
}

// Sort by frekuensi desc, ambil top 10
usort($top_items_agg, fn($a, $b) => $b['freq'] <=> $a['freq']);
$top_items = array_slice($top_items_agg, 0, 10);
$max_freq = count($top_items) > 0 ? $top_items[0]['freq'] : 1;

// ============================================
// RECENT ACTIVITY
// ============================================
$recent_activity = [];
if ($user_role === 'approver' && $user_approval_level === 'SPV') {
    $result = $db->query(
        "SELECT d.id, d.doc_number, d.category, d.doc_date, u.name as created_by
         FROM sipb_documents d
         JOIN users u ON d.created_by = u.id
         WHERE d.approval_spv_status = 'Pending' AND d.status = 'Submitted'
         ORDER BY d.doc_date DESC LIMIT 5", []
    );
    while ($row = $result->fetch_assoc()) { $recent_activity[] = $row; }
} elseif ($user_role === 'approver' && $user_approval_level === 'PM') {
    $result = $db->query(
        "SELECT d.id, d.doc_number, d.category, d.doc_date, u.name as created_by
         FROM sipb_documents d
         JOIN users u ON d.created_by = u.id
         WHERE d.approval_pm_status = 'Pending' AND d.approval_spv_status = 'Approved'
         ORDER BY d.approval_spv_at DESC LIMIT 5", []
    );
    while ($row = $result->fetch_assoc()) { $recent_activity[] = $row; }
} else {
    $recent_where = ($user_role === 'user') ? "WHERE created_by = ?" : "";
    $recent_params = ($user_role === 'user') ? [$user_id] : [];
    $result = $db->query(
        "SELECT id, doc_number, category, doc_date, status
         FROM sipb_documents $recent_where
         ORDER BY doc_date DESC, id DESC LIMIT 5",
        $recent_params
    );
    while ($row = $result->fetch_assoc()) { $recent_activity[] = $row; }
}

$status_badge_class = [
    'Draft' => 'badge-draft',
    'Submitted' => 'badge-submitted',
    'Approved' => 'badge-approved',
    'Rejected' => 'badge-rejected',
];

$active_page = 'dashboard';
$BASE = getenv('APP_URL') ?: 'http://localhost/SIPB-GPI';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - SIPB-GPI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo $BASE; ?>/assets/css/theme.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .quick-actions {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .welcome-text {
            color: var(--text-muted);
            margin-bottom: 20px;
            font-size: 15px;
        }
        .chart-row {
            display: grid;
            grid-template-columns: 1.6fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        @media (max-width: 1000px) {
            .chart-row { grid-template-columns: 1fr; }
        }
        .filter-bar {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .filter-bar .filter-group label {
            display: block;
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }
        .filter-bar select, .filter-bar input[type="date"] {
            padding: 9px 12px;
            border: 1px solid var(--border-light);
            border-radius: 8px;
            font-size: 13.5px;
            background: #fff;
        }
        .top-item-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-light);
        }
        .top-item-rank {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            background: #eef0fa;
            color: var(--accent-blue);
            font-weight: 700;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .top-item-info { flex: 1; min-width: 0; }
        .top-item-name { font-weight: 600; font-size: 13.5px; color: var(--text-dark); }
        .top-item-code { font-size: 11.5px; color: var(--text-muted); }
        .top-item-bar-track {
            background: #eef0fa;
            border-radius: 4px;
            height: 6px;
            margin-top: 5px;
            overflow: hidden;
        }
        .top-item-bar-fill {
            background: linear-gradient(90deg, #4a5bd6, #7c8aeb);
            height: 100%;
            border-radius: 4px;
        }
        .top-item-stats {
            text-align: right;
            flex-shrink: 0;
            font-size: 12px;
        }
        .top-item-freq { font-weight: 700; color: var(--text-dark); font-size: 14px; }
        .top-item-qty { color: var(--text-muted); }
    </style>
</head>
<body>

<div class="app-shell">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-chart-line"></i> Dashboard</h1>
        </div>
        <p class="welcome-text">Selamat datang, <strong><?php echo htmlspecialchars($user_name); ?></strong>!</p>

        <?php if ($user_role !== 'approver'): ?>
        <div class="quick-actions">
            <a href="<?php echo $BASE; ?>/sipb/create.php" class="btn-primary-pill"><i class="fas fa-plus"></i> Buat SIPB Baru</a>
            <a href="<?php echo $BASE; ?>/sipb/list.php" class="btn-secondary-pill"><i class="fas fa-list"></i> Lihat SIPB Saya</a>
        </div>
        <?php else: ?>
        <div class="quick-actions">
            <a href="<?php echo $BASE; ?>/approval/pending.php" class="btn-primary-pill"><i class="fas fa-clipboard-check"></i> Review Pending Approval</a>
        </div>
        <?php endif; ?>

        <!-- Filter Bar -->
        <div class="content-card">
            <form method="GET" class="filter-bar" id="filterForm">
                <div class="filter-group">
                    <label>Kategori</label>
                    <select name="category" onchange="document.getElementById('filterForm').submit()">
                        <option value="">Semua Kategori</option>
                        <?php foreach ($categories_list as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filter_category === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Periode</label>
                    <select name="period" id="periodSelect" onchange="togglePeriodInputs(); document.getElementById('filterForm').submit()">
                        <option value="all" <?php echo $filter_period === 'all' ? 'selected' : ''; ?>>All-time</option>
                        <option value="month" <?php echo $filter_period === 'month' ? 'selected' : ''; ?>>Bulan Ini</option>
                        <option value="year" <?php echo $filter_period === 'year' ? 'selected' : ''; ?>>Tahun Ini</option>
                        <option value="custom" <?php echo $filter_period === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                    </select>
                </div>
                <div class="filter-group" id="dateFromGroup" style="<?php echo $filter_period === 'custom' ? '' : 'display:none;'; ?>">
                    <label>Dari Tanggal</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>
                <div class="filter-group" id="dateToGroup" style="<?php echo $filter_period === 'custom' ? '' : 'display:none;'; ?>">
                    <label>Sampai Tanggal</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>
                <?php if ($filter_period === 'custom'): ?>
                    <button type="submit" class="btn-primary-pill" style="padding: 9px 18px;"><i class="fas fa-filter"></i> Terapkan</button>
                <?php endif; ?>
                <?php if (!empty($filter_category) || $filter_period !== 'all'): ?>
                    <a href="<?php echo $BASE; ?>/index.php" class="btn-secondary-pill" style="padding: 9px 18px;"><i class="fas fa-times"></i> Reset Filter</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="stat-grid">
            <div class="stat-card blue">
                <div class="stat-label"><i class="fas fa-file-lines"></i> Total SIPB</div>
                <div class="stat-number"><?php echo $total_sipb; ?></div>
            </div>
            <div class="stat-card green">
                <div class="stat-label"><i class="fas fa-circle-check"></i> Approved</div>
                <div class="stat-number"><?php echo $total_approved; ?></div>
            </div>
            <div class="stat-card orange">
                <div class="stat-label"><i class="fas fa-clock"></i> Pending</div>
                <div class="stat-number"><?php echo $total_pending; ?></div>
            </div>
            <div class="stat-card red">
                <div class="stat-label"><i class="fas fa-circle-xmark"></i> Rejected</div>
                <div class="stat-number"><?php echo $total_rejected; ?></div>
            </div>
        </div>

        <div class="chart-row">
            <div class="content-card">
                <h5 style="margin-bottom: 16px;"><i class="fas fa-chart-column"></i> SIPB per Bulan (<?php echo $filter_year; ?>)</h5>
                <canvas id="monthlyChart" height="110"></canvas>
            </div>
            <div class="content-card">
                <h5 style="margin-bottom: 16px;">
                    <i class="fas fa-clock-rotate-left"></i>
                    <?php echo ($user_role === 'approver') ? 'Pending Approval' : 'SIPB Terbaru'; ?>
                </h5>
                <?php if (count($recent_activity) > 0): ?>
                    <?php foreach ($recent_activity as $activity): ?>
                        <div style="padding: 12px 0; border-bottom: 1px solid var(--border-light);">
                            <div style="font-weight: 600; font-size: 14px;"><?php echo htmlspecialchars($activity['doc_number']); ?></div>
                            <div style="font-size: 12px; color: var(--text-muted); margin: 4px 0;">
                                <?php echo htmlspecialchars($activity['category'] ?? '-'); ?> &middot;
                                <?php echo date('d-m-Y', strtotime($activity['doc_date'])); ?>
                            </div>
                            <a href="<?php echo $BASE; ?>/sipb/view.php?id=<?php echo $activity['id']; ?>" class="btn-secondary-pill" style="padding: 5px 14px; font-size: 12px;">View Details</a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-muted" style="text-align: center; padding: 30px 0;">No recent activity</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top FG Items Ranking -->
        <div class="content-card">
            <h5 style="margin-bottom: 4px;"><i class="fas fa-ranking-star"></i> Top 10 Barang FG Paling Sering Keluar</h5>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px;">
                Berdasarkan SIPB berstatus <strong>Approved</strong><?php echo !empty($filter_category) ? ' &middot; kategori: ' . htmlspecialchars($filter_category) : ''; ?><?php echo $filter_period !== 'all' ? ' &middot; periode: ' . ($filter_period === 'month' ? 'Bulan ini' : ($filter_period === 'year' ? 'Tahun ini' : 'Custom')) : ''; ?>
            </p>
            <?php if (count($top_items) > 0): ?>
                <?php foreach ($top_items as $i => $item): ?>
                    <div class="top-item-row">
                        <div class="top-item-rank">#<?php echo $i + 1; ?></div>
                        <div class="top-item-info">
                            <div class="top-item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                            <div class="top-item-code"><?php echo htmlspecialchars($item['item_code']); ?></div>
                            <div class="top-item-bar-track">
                                <div class="top-item-bar-fill" style="width: <?php echo round(($item['freq'] / $max_freq) * 100); ?>%;"></div>
                            </div>
                        </div>
                        <div class="top-item-stats">
                            <div class="top-item-freq"><?php echo $item['freq']; ?>x</div>
                            <div class="top-item-qty"><?php echo htmlspecialchars(implode(', ', $item['qty_parts'])); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 30px 0; color: var(--text-muted);">
                    <i class="fas fa-box-open" style="font-size: 32px; margin-bottom: 10px; display: block;"></i>
                    <p>Belum ada data barang FG yang approved sesuai filter ini.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function togglePeriodInputs() {
        const period = document.getElementById('periodSelect').value;
        document.getElementById('dateFromGroup').style.display = (period === 'custom') ? '' : 'none';
        document.getElementById('dateToGroup').style.display = (period === 'custom') ? '' : 'none';
    }

    const ctx = document.getElementById('monthlyChart');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($monthly_data, 'month')); ?>,
            datasets: [{
                label: 'SIPB Documents',
                data: <?php echo json_encode(array_column($monthly_data, 'count')); ?>,
                backgroundColor: '#4a5bd6',
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });
</script>

</body>
</html>

