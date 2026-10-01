<?php
/**
 * Admin Orders List
 * Restaurant POS System
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

// Filters
$status = $_GET['status'] ?? '';
$date = $_GET['date'] ?? date('Y-m-d');

// Build query
$sql = "
    SELECT o.*, COALESCE(o.table_label, t.table_number) AS table_number, r.name as room_name, u.full_name as waiter_name
    FROM orders o
    JOIN tables_restaurant t ON o.table_id = t.id
    JOIN rooms r ON o.room_id = r.id
    JOIN users u ON o.waiter_id = u.id
    WHERE 1=1
";
$params = [];

if ($status) {
    $sql .= " AND o.status = ?";
    $params[] = $status;
}

if ($date) {
    $sql .= " AND DATE(o.opened_at) = ?";
    $params[] = $date;
}

$sql .= " ORDER BY o.opened_at DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$pageTitle = t('all_orders');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-list-alt"></i> <?= te('all_orders') ?></h1>
</div>

<!-- Filters -->
<div class="card mb-lg">
    <div class="card-body">
        <form method="GET" class="d-flex gap-md align-center" style="flex-wrap: wrap;">
            <div class="form-group" style="margin: 0;">
                <label class="form-label"><?= te('date') ?></label>
                <input type="date" name="date" class="form-control" value="<?= $date ?>">
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label"><?= te('status') ?></label>
                <select name="status" class="form-control">
                    <option value=""><?= te('all_statuses') ?></option>
                    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>><?= te('status_open') ?></option>
                    <option value="sent_to_kitchen" <?= $status === 'sent_to_kitchen' ? 'selected' : '' ?>><?= te('status_sent_to_kitchen') ?></option>
                    <option value="bill_requested" <?= $status === 'bill_requested' ? 'selected' : '' ?>><?= te('status_bill_requested') ?></option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>><?= te('status_paid') ?></option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>><?= te('status_cancelled') ?></option>
                </select>
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i> <?= te('filter') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <table class="data-table">
        <thead>
            <tr>
                <th><?= te('order_no') ?></th>
                <th><?= te('table') ?></th>
                <th><?= te('waiter') ?></th>
                <th><?= te('guests') ?></th>
                <th><?= te('subtotal') ?></th>
                <th><?= te('discount') ?></th>
                <th><?= te('total') ?></th>
                <th><?= te('status') ?></th>
                <th><?= te('opened') ?></th>
                <th><?= te('actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($order['order_number']) ?></strong></td>
                    <td>
                        <?= htmlspecialchars($order['table_number']) ?>
                        <small class="text-muted">(<?= htmlspecialchars($order['room_name']) ?>)</small>
                    </td>
                    <td><?= htmlspecialchars($order['waiter_name']) ?></td>
                    <td><?= $order['number_of_people'] ?></td>
                    <td><?= formatCurrency($order['subtotal']) ?></td>
                    <td>
                        <?php if ($order['discount_amount'] > 0): ?>
                            <span class="text-danger">-<?= formatCurrency($order['discount_amount']) ?></span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><strong class="text-primary"><?= formatCurrency($order['total']) ?></strong></td>
                    <td>
                        <span class="badge badge-<?= 
                            $order['status'] === 'paid' ? 'success' : 
                            ($order['status'] === 'cancelled' ? 'danger' : 
                            ($order['status'] === 'bill_requested' ? 'warning' : 'info')) 
                        ?>">
                            <?= htmlspecialchars(statusLabel($order['status'])) ?>
                        </span>
                    </td>
                    <td><?= date('H:i', strtotime($order['opened_at'])) ?></td>
                    <td>
                        <a href="/waiter/order.php?order=<?= $order['id'] ?>" class="btn btn-sm btn-outline">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php if ($order['status'] === 'bill_requested'): ?>
                            <a href="/cashier/payment.php?order=<?= $order['id'] ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-money-bill"></i>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($orders)): ?>
                <tr>
                    <td colspan="10" class="text-center text-muted" style="padding: 40px;">
                        <?= te('no_orders_found') ?>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
