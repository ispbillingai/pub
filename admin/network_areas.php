<?php
/**
 * Admin: Network Areas.
 *
 * A network area is a MikroTik router the panel manages. Each area is reached
 * over WireGuard; the device poller logs into its RouterOS API to ping the
 * devices assigned to it. Admins add/edit/remove routers here so new sites can
 * be onboarded without touching config files.
 */

require_once __DIR__ . '/../includes/functions.php';
requireRole(['admin']);

$pdo = getDBConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_area' || $action === 'edit_area') {
        $name  = trim($_POST['name'] ?? '');
        $host  = trim($_POST['host'] ?? '');
        $port  = (int) ($_POST['api_port'] ?? 8728);
        $user  = trim($_POST['api_user'] ?? 'admin');
        $pass  = (string) ($_POST['api_pass'] ?? '');
        $count = max(1, (int) ($_POST['ping_count'] ?? 2));
        $sort  = (int) ($_POST['sort_order'] ?? 0);
        $active = isset($_POST['active']) ? 1 : 0;

        if ($name === '' || $host === '') {
            header('Location: /admin/network_areas.php?error=required');
            exit;
        }
        if ($port < 1 || $port > 65535) {
            $port = 8728;
        }

        if ($action === 'add_area') {
            $stmt = $pdo->prepare(
                "INSERT INTO network_areas (name, host, api_port, api_user, api_pass, ping_count, active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$name, $host, $port, $user ?: 'admin', $pass, $count, $active, $sort]);
            $newId = (int) $pdo->lastInsertId();
            logActivity('network_area_added', 'network_areas', $newId, ['name' => $name, 'host' => $host]);
            header('Location: /admin/network_areas.php?success=added');
            exit;
        }

        // edit — keep the existing password if the field was left blank.
        $id = (int) ($_POST['area_id'] ?? 0);
        if ($pass === '') {
            $stmt = $pdo->prepare(
                "UPDATE network_areas
                    SET name=?, host=?, api_port=?, api_user=?, ping_count=?, active=?, sort_order=?
                  WHERE id=?"
            );
            $stmt->execute([$name, $host, $port, $user ?: 'admin', $count, $active, $sort, $id]);
        } else {
            $stmt = $pdo->prepare(
                "UPDATE network_areas
                    SET name=?, host=?, api_port=?, api_user=?, api_pass=?, ping_count=?, active=?, sort_order=?
                  WHERE id=?"
            );
            $stmt->execute([$name, $host, $port, $user ?: 'admin', $pass, $count, $active, $sort, $id]);
        }
        logActivity('network_area_updated', 'network_areas', $id, ['name' => $name, 'host' => $host]);
        header('Location: /admin/network_areas.php?success=updated');
        exit;
    }

    if ($action === 'delete_area') {
        $id = (int) ($_POST['area_id'] ?? 0);
        // Detach devices so they fall back to another active area, then delete.
        $pdo->prepare("UPDATE devices SET area_id = NULL WHERE area_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM network_areas WHERE id = ?")->execute([$id]);
        logActivity('network_area_deleted', 'network_areas', $id);
        header('Location: /admin/network_areas.php?success=deleted');
        exit;
    }
}

$areas = $pdo->query("SELECT * FROM network_areas ORDER BY sort_order, id")->fetchAll();

// Device count per area (badge).
$devCounts = [];
try {
    foreach ($pdo->query("SELECT area_id, COUNT(*) c FROM devices WHERE area_id IS NOT NULL GROUP BY area_id") as $r) {
        $devCounts[(int) $r['area_id']] = (int) $r['c'];
    }
} catch (Throwable $e) {
    // ignore
}

$pageTitle = t('network_areas');
include __DIR__ . '/../includes/header.php';

$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<style>
.na-mono { font-family:'Space Mono',monospace; font-size:.85rem; }
.test-result { margin-left:6px; font-size:.85rem; }
</style>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <h1><i class="fas fa-diagram-project"></i> <?= te('network_areas') ?></h1>
    <div class="d-flex gap-sm">
        <button class="btn btn-success" onclick="openAddArea()"><i class="fas fa-plus"></i> <?= te('na_add') ?></button>
        <a href="/admin/devices.php" class="btn btn-outline"><i class="fas fa-network-wired"></i> <?= te('devices_title') ?></a>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success mb-lg" style="background:rgba(39,174,96,.1);color:var(--success);padding:14px;border-radius:8px;">
        <i class="fas fa-check-circle"></i>
        <?php
        switch ($_GET['success']) {
            case 'added':   echo te('na_msg_added'); break;
            case 'updated': echo te('na_msg_updated'); break;
            case 'deleted': echo te('na_msg_deleted'); break;
        }
        ?>
    </div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger mb-lg" style="background:rgba(231,76,60,.1);color:var(--danger);padding:14px;border-radius:8px;">
        <i class="fas fa-exclamation-triangle"></i> <?= te('na_err_required') ?>
    </div>
<?php endif; ?>

<p class="text-muted mb-lg"><?= te('network_areas_help') ?></p>

<div class="card">
    <?php if (empty($areas)): ?>
        <div class="card-body text-center" style="padding:60px;">
            <i class="fas fa-diagram-project" style="font-size:3rem;color:var(--text-secondary);margin-bottom:16px;"></i>
            <p class="text-muted"><?= te('na_none') ?></p>
            <button class="btn btn-primary mt-md" onclick="openAddArea()"><i class="fas fa-plus"></i> <?= te('na_add') ?></button>
        </div>
    <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th><?= te('name') ?></th>
                    <th><?= te('na_host') ?></th>
                    <th><?= te('na_api_port') ?></th>
                    <th><?= te('dev_device') ?>s</th>
                    <th><?= te('dev_status') ?></th>
                    <th><?= te('actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($areas as $a):
                    $cnt = $devCounts[(int) $a['id']] ?? 0;
                ?>
                    <tr>
                        <td><strong><?= $h($a['name']) ?></strong></td>
                        <td class="na-mono"><?= $h($a['host']) ?></td>
                        <td><?= (int) $a['api_port'] ?></td>
                        <td><span class="badge badge-info"><?= $cnt ?></span></td>
                        <td>
                            <?php if ((int) $a['active'] === 1): ?>
                                <span class="badge badge-success"><?= te('active') ?></span>
                            <?php else: ?>
                                <span class="badge badge-warning"><?= te('inactive') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-sm align-center">
                                <button type="button" class="btn btn-sm btn-primary"
                                    onclick='openEditArea(<?= json_encode([
                                        "id" => (int) $a["id"],
                                        "name" => $a["name"],
                                        "host" => $a["host"],
                                        "api_port" => (int) $a["api_port"],
                                        "api_user" => $a["api_user"],
                                        "ping_count" => (int) $a["ping_count"],
                                        "active" => (int) $a["active"],
                                        "sort_order" => (int) $a["sort_order"],
                                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                                    <i class="fas fa-edit"></i> <?= te('edit') ?>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline" onclick="testArea(<?= (int) $a['id'] ?>, this)">
                                    <i class="fas fa-plug"></i> <?= te('na_test') ?>
                                </button>
                                <span class="test-result" data-for="<?= (int) $a['id'] ?>"></span>
                                <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('<?= te('na_delete_confirm') ?>');">
                                    <input type="hidden" name="action" value="delete_area">
                                    <input type="hidden" name="area_id" value="<?= (int) $a['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" title="<?= te('delete') ?>"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Add / Edit Area Modal -->
<div class="modal-overlay" id="areaModal">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header">
            <h3 id="areaModalTitle"><?= te('na_add') ?></h3>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" id="na_action" value="add_area">
                <input type="hidden" name="area_id" id="na_id" value="">

                <div class="form-group">
                    <label class="form-label"><?= te('name') ?></label>
                    <input type="text" name="name" id="na_name" class="form-control" required placeholder="<?= te('na_name_ph') ?>">
                </div>

                <div class="form-row">
                    <div class="form-group" style="flex:2;">
                        <label class="form-label"><?= te('na_host') ?></label>
                        <input type="text" name="host" id="na_host" class="form-control" required placeholder="192.168.200.15">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('na_api_port') ?></label>
                        <input type="number" name="api_port" id="na_port" class="form-control" value="8728">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('na_user') ?></label>
                        <input type="text" name="api_user" id="na_user" class="form-control" value="admin">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('na_pass') ?></label>
                        <input type="password" name="api_pass" id="na_pass" class="form-control" placeholder="<?= te('na_pass_ph') ?>" autocomplete="new-password">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><?= te('na_ping_count') ?></label>
                        <input type="number" name="ping_count" id="na_count" class="form-control" value="2" min="1" max="10">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= te('sort_order') ?></label>
                        <input type="number" name="sort_order" id="na_sort" class="form-control" value="0">
                    </div>
                </div>

                <label class="d-block mt-sm">
                    <input type="checkbox" name="active" id="na_active" checked> <?= te('active') ?>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('areaModal')"><?= te('cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= te('save') ?></button>
            </div>
        </form>
    </div>
</div>

<script>
const NA_I18N = {
    add: <?= json_encode(t('na_add')) ?>,
    edit: <?= json_encode(t('na_edit')) ?>,
    ok: <?= json_encode(t('na_test_ok')) ?>,
    failed: <?= json_encode(t('na_test_failed')) ?>,
    editPassHint: <?= json_encode(t('na_pass_keep')) ?>,
    addPassHint: <?= json_encode(t('na_pass_ph')) ?>,
};

function openAddArea() {
    document.getElementById('areaModalTitle').textContent = NA_I18N.add;
    document.getElementById('na_action').value = 'add_area';
    document.getElementById('na_id').value = '';
    document.getElementById('na_name').value = '';
    document.getElementById('na_host').value = '';
    document.getElementById('na_port').value = 8728;
    document.getElementById('na_user').value = 'admin';
    document.getElementById('na_pass').value = '';
    document.getElementById('na_pass').placeholder = NA_I18N.addPassHint;
    document.getElementById('na_count').value = 2;
    document.getElementById('na_sort').value = 0;
    document.getElementById('na_active').checked = true;
    openModal('areaModal');
}

function openEditArea(a) {
    document.getElementById('areaModalTitle').textContent = NA_I18N.edit;
    document.getElementById('na_action').value = 'edit_area';
    document.getElementById('na_id').value = a.id;
    document.getElementById('na_name').value = a.name || '';
    document.getElementById('na_host').value = a.host || '';
    document.getElementById('na_port').value = a.api_port || 8728;
    document.getElementById('na_user').value = a.api_user || 'admin';
    document.getElementById('na_pass').value = '';
    document.getElementById('na_pass').placeholder = NA_I18N.editPassHint;
    document.getElementById('na_count').value = a.ping_count || 2;
    document.getElementById('na_sort').value = a.sort_order || 0;
    document.getElementById('na_active').checked = (a.active == 1);
    openModal('areaModal');
}

async function testArea(areaId, btn) {
    const out = document.querySelector('.test-result[data-for="' + areaId + '"]');
    if (out) { out.textContent = '…'; out.style.color = ''; }
    if (btn) btn.disabled = true;
    try {
        const res = await fetch('/api/network-area-test.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
            body: JSON.stringify({ area_id: areaId })
        });
        const data = await res.json();
        if (out) {
            if (data.ok) { out.style.color = 'var(--success)'; out.textContent = NA_I18N.ok; }
            else { out.style.color = 'var(--danger)'; out.textContent = NA_I18N.failed + ': ' + (data.error || ''); }
        }
    } catch (e) {
        if (out) { out.style.color = 'var(--danger)'; out.textContent = e.message; }
    } finally {
        if (btn) btn.disabled = false;
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
