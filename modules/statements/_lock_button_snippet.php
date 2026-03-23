<!-- ── Nút chốt kỳ AJAX (thay thế toàn bộ block cũ) ── -->
<?php if (!empty($statements) && $canEdit): ?>
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap" id="lockBar">

    <?php if ($lockedPeriod && $lockedPeriod['status'] === 'locked'): ?>
    <span class="badge bg-success fs-6" id="lockStatus">
        🔒 Đã chốt lúc <?= date('d/m H:i', strtotime($lockedPeriod['locked_at'])) ?>
    </span>
    <button class="btn btn-sm btn-outline-warning" onclick="doUnlock()">
        <i class="fas fa-lock-open me-1"></i>Mở lại kỳ
    </button>
    <!-- Nút đi đến Báo cáo -->
    <a href="/transport/modules/reports/index.php?tab=revenue&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
       class="btn btn-sm btn-info">
        <i class="fas fa-chart-bar me-1"></i>Xem Báo cáo tổng hợp
    </a>

    <?php else: ?>
    <?php if ($lockedPeriod): ?>
    <span class="badge bg-warning fs-6">📝 Bản nháp</span>
    <?php endif; ?>

    <!-- Form lưu nháp (POST thường) -->
    <form method="POST" class="d-inline">
        <input type="hidden" name="action"    value="save_draft">
        <input type="hidden" name="date_from" value="<?= $dateFrom ?>">
        <input type="hidden" name="date_to"   value="<?= $dateTo ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-save me-1"></i>Lưu nháp
        </button>
    </form>

    <!-- Nút chốt kỳ — AJAX -->
    <button class="btn btn-success fw-bold" id="btnLock" onclick="doLock()">
        <i class="fas fa-lock me-1"></i>Chốt kỳ
        <span class="badge bg-white text-success ms-1" id="lockTripCount"><?= $grandTrips ?> chuyến</span>
    </button>
    <?php endif; ?>

</div>

<script>
const LOCK_DATE_FROM = '<?= $dateFrom ?>';
const LOCK_DATE_TO   = '<?= $dateTo ?>';
const LOCK_GRAND_TOTAL  = <?= $grandTotal ?>;
const LOCK_GRAND_TRIPS  = <?= $grandTrips ?>;
const LOCK_GRAND_KM     = <?= $grandKm ?>;

function doLock() {
    if (!confirm(
        'Xác nhận CHỐT KỲ\n' +
        'Từ: <?= date('d/m/Y', strtotime($dateFrom)) ?> → <?= date('d/m/Y', strtotime($dateTo)) ?>\n' +
        'Tổng: ' + LOCK_GRAND_TRIPS + ' chuyến — ' + LOCK_GRAND_KM.toLocaleString() + ' km\n\n' +
        'Sau khi chốt sẽ không thể thay đổi!'
    )) return;

    const btn = document.getElementById('btnLock');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang chốt...';

    fetch('/transport/modules/statements/lock_period.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({
            action       : 'lock',
            date_from    : LOCK_DATE_FROM,
            date_to      : LOCK_DATE_TO,
            total_amount : LOCK_GRAND_TOTAL,
            total_trips  : LOCK_GRAND_TRIPS,
            total_km     : LOCK_GRAND_KM,
            period_label : '<?= date('d/m/Y', strtotime($dateFrom)) ?> – <?= date('d/m/Y', strtotime($dateTo)) ?>',
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            // Flash message rồi redirect sang Báo cáo tổng hợp
            alert(data.msg);
            window.location.href = data.redirect ||
                '/transport/modules/reports/index.php?tab=revenue&date_from=' +
                LOCK_DATE_FROM + '&date_to=' + LOCK_DATE_TO;
        } else {
            alert('❌ ' + data.msg);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-lock me-1"></i>Chốt kỳ <span class="badge bg-white text-success ms-1">' + LOCK_GRAND_TRIPS + ' chuyến</span>';
        }
    })
    .catch(err => {
        alert('Lỗi kết nối: ' + err);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-lock me-1"></i>Chốt kỳ';
    });
}

function doUnlock() {
    if (!confirm('Mở lại kỳ này để chỉnh sửa?')) return;
    fetch('/transport/modules/statements/lock_period.php', {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({ action: 'unlock', date_from: LOCK_DATE_FROM, date_to: LOCK_DATE_TO })
    })
    .then(r => r.json())
    .then(data => {
        alert(data.msg);
        location.reload();
    });
}
</script>
<?php endif; ?>