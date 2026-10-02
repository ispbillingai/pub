<?php
/**
 * Admin — Menu cassa: products only the till sees (buttons, with their
 * photo, in Ordini Cassa). Categories here are marked till_only, so the guests' menus, the
 * PDF, online ordering, the waiters and the normal Menu admin never show them.
 * Products and categories are switched off, not deleted (sold ones stay in
 * the orders' history).
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/till.php';
require_once __DIR__ . '/../includes/menu_images.php';
requireRole(['admin']);

$pdo     = getDBConnection();
$freeCat = tillFreeCategoryId();      // the keypad's hidden "Varie": not managed here

/** A price typed as "3,50" or "3.50"; null when it isn't one. */
function tillPrice(string $v): ?float
{
    $v = str_replace(',', '.', trim($v));
    return is_numeric($v) && (float) $v >= 0 && (float) $v <= TILL_MAX_AMOUNT ? round((float) $v, 2) : null;
}
$color = fn($v) => preg_match('/^#[0-9a-f]{6}$/i', (string) $v) ? $v : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a   = $_POST['action'] ?? '';
    $id  = (int) ($_POST['id'] ?? 0);
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
    $ok  = true;
    if ($a === 'add_category' && $name !== '') {
        $sort = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu_categories WHERE till_only = 1")->fetchColumn();
        $pdo->prepare("INSERT INTO menu_categories (name, sort_order, allow_composition, color, active, till_only) VALUES (?, ?, 0, ?, 1, 1)")
            ->execute([mb_substr($name, 0, 100), $sort, $color($_POST['color'] ?? '')]);
    } elseif ($a === 'update_category' && $name !== '' && $id !== $freeCat) {
        $pdo->prepare("UPDATE menu_categories SET name = ?, color = ?, sort_order = ? WHERE id = ? AND till_only = 1")
            ->execute([mb_substr($name, 0, 100), $color($_POST['color'] ?? ''), (int) ($_POST['sort_order'] ?? 0), $id]);
    } elseif ($a === 'toggle_category' && $id !== $freeCat) {
        $pdo->prepare("UPDATE menu_categories SET active = 1 - active WHERE id = ? AND till_only = 1")->execute([$id]);
    } elseif ($a === 'add_item' && $name !== '' && ($price = tillPrice((string) ($_POST['price'] ?? ''))) !== null) {
        $cat = (int) ($_POST['category_id'] ?? 0);
        $st  = $pdo->prepare("SELECT 1 FROM menu_categories WHERE id = ? AND till_only = 1 AND id <> ?");
        $st->execute([$cat, $freeCat]);
        if ($ok = (bool) $st->fetchColumn()) {
            $sort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM menu_items WHERE category_id = ?");
            $sort->execute([$cat]);
            $pdo->prepare("INSERT INTO menu_items (category_id, name, base_price, sort_order, image_url, active) VALUES (?, ?, ?, ?, ?, 1)")
                ->execute([$cat, $name, $price, (int) $sort->fetchColumn(), saveMenuImage('image')]);
        }
    } elseif ($a === 'update_item' && $name !== '' && ($price = tillPrice((string) ($_POST['price'] ?? ''))) !== null) {
        $pdo->prepare("UPDATE menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
                       SET mi.name = ?, mi.base_price = ?, mi.sort_order = ? WHERE mi.id = ? AND mc.till_only = 1 AND mc.id <> ?")
            ->execute([$name, $price, (int) ($_POST['sort_order'] ?? 0), $id, $freeCat]);
        // A new photo replaces the old one (none chosen = keep it).
        if ($img = saveMenuImage('image')) {
            $pdo->prepare("UPDATE menu_items SET image_url = ? WHERE id = ?")->execute([$img, $id]);
        }
    } elseif ($a === 'remove_image') {
        $pdo->prepare("UPDATE menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
                       SET mi.image_url = NULL WHERE mi.id = ? AND mc.till_only = 1 AND mc.id <> ?")->execute([$id, $freeCat]);
    } elseif ($a === 'toggle_item') {
        $pdo->prepare("UPDATE menu_items mi JOIN menu_categories mc ON mc.id = mi.category_id
                       SET mi.active = 1 - mi.active WHERE mi.id = ? AND mc.till_only = 1 AND mc.id <> ?")->execute([$id, $freeCat]);
    } else {
        $ok = false;
    }
    if ($ok) logActivity('till_menu_' . $a, 'menu', $id ?: null);
    header('Location: /admin/till-menu.php?' . ($ok ? 'saved=1' : 'error=1') . (!empty($_POST['cat']) ? '#cat-' . (int) $_POST['cat'] : ''));
    exit;
}

$cats = $pdo->prepare("SELECT * FROM menu_categories WHERE till_only = 1 AND id <> ? ORDER BY active DESC, sort_order, name");
$cats->execute([$freeCat]);
$cats  = $cats->fetchAll();
$items = $pdo->prepare("SELECT * FROM menu_items WHERE category_id = ? ORDER BY active DESC, sort_order, name");

$pageTitle = t('till_menu_title');
include __DIR__ . '/../includes/header.php';
?>
<style>
.tm-row { display: grid; grid-template-columns: 56px minmax(160px, 1fr) 110px 80px minmax(150px, 220px) auto; gap: 8px; align-items: center; padding: 6px 0; border-bottom: 1px dashed var(--border-color, #e5e7eb); }
.tm-thumb { width: 52px; height: 52px; border-radius: 8px; object-fit: cover; background: #f3f4f6; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); }
.tm-file { font-size: .8rem; }
.tm-row.off { opacity: .5; }
.tm-row input { width: 100%; }
.tm-cat-head { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.tm-cat-head input[type=text] { flex: 1; min-width: 160px; }
.tm-swatch { width: 44px; height: 38px; padding: 2px; }
@media (max-width: 900px) { .tm-row { grid-template-columns: 56px 1fr 90px; } }
</style>

<div class="page-header">
    <h1><i class="fas fa-cash-register"></i> <?= te('till_menu_title') ?></h1>
    <a class="btn btn-outline" href="/cashier/online.php"><i class="fas fa-globe"></i> <?= te('cash_online_title') ?></a>
</div>
<p class="text-muted"><?= te('till_menu_intro') ?></p>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success mb-lg" style="background: rgba(39,174,96,0.1); color: var(--success); padding: 12px 16px; border-radius: 8px;"><i class="fas fa-check-circle"></i> <?= te('msg_settings_saved') ?></div>
<?php elseif (isset($_GET['error'])): ?>
    <div class="alert alert-danger mb-lg" style="background: rgba(220,38,38,.08); color: var(--danger); padding: 12px 16px; border-radius: 8px;"><i class="fas fa-triangle-exclamation"></i> <?= te('till_menu_error') ?></div>
<?php endif; ?>

<!-- New category -->
<form method="POST" class="card mb-lg" style="padding:14px 18px;">
    <input type="hidden" name="action" value="add_category">
    <div class="tm-cat-head">
        <strong><i class="fas fa-folder-plus"></i> <?= te('till_menu_new_cat') ?></strong>
        <input type="text" name="name" class="form-control" maxlength="100" placeholder="<?= te('till_menu_cat_ph') ?>" required>
        <input type="color" name="color" class="form-control tm-swatch" value="#e8590c" title="<?= te('till_menu_color') ?>">
        <button class="btn btn-primary"><i class="fas fa-plus"></i> <?= te('add') ?></button>
    </div>
</form>

<?php foreach ($cats as $c):
    $items->execute([(int) $c['id']]);
    $list = $items->fetchAll(); ?>
<div class="card mb-lg" id="cat-<?= (int) $c['id'] ?>" style="<?= $c['active'] ? '' : 'opacity:.6;' ?>">
    <div class="card-header">
        <form method="POST" class="tm-cat-head" style="flex:1;">
            <input type="hidden" name="action" value="update_category">
            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
            <input type="hidden" name="cat" value="<?= (int) $c['id'] ?>">
            <input type="color" name="color" class="form-control tm-swatch" value="<?= htmlspecialchars($c['color'] ?: '#e8590c') ?>" title="<?= te('till_menu_color') ?>">
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($c['name']) ?>" maxlength="100" required style="font-weight:700;">
            <input type="number" name="sort_order" class="form-control" value="<?= (int) $c['sort_order'] ?>" style="width:80px;" title="<?= te('till_menu_order') ?>">
            <button class="btn btn-sm btn-outline"><i class="fas fa-save"></i></button>
        </form>
        <form method="POST" style="margin-left:8px;">
            <input type="hidden" name="action" value="toggle_category"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="cat" value="<?= (int) $c['id'] ?>">
            <button class="btn btn-sm <?= $c['active'] ? 'btn-outline' : 'btn-success' ?>"><?= te($c['active'] ? 'till_menu_hide' : 'till_menu_show') ?></button>
        </form>
    </div>
    <div class="card-body">
        <?php foreach ($list as $it): ?>
            <?php $fid = 'item-' . (int) $it['id']; ?>
            <div class="tm-row <?= $it['active'] ? '' : 'off' ?>">
                <?php if (!empty($it['image_url'])): ?>
                    <img class="tm-thumb" src="<?= htmlspecialchars($it['image_url']) ?>" alt="">
                <?php else: ?>
                    <span class="tm-thumb"><i class="fas fa-image"></i></span>
                <?php endif; ?>
                <input type="text" name="name" form="<?= $fid ?>" class="form-control" value="<?= htmlspecialchars($it['name']) ?>" maxlength="150" required>
                <input type="text" name="price" form="<?= $fid ?>" class="form-control" value="<?= number_format((float) $it['base_price'], 2, ',', '') ?>" inputmode="decimal" required title="<?= te('price') ?>">
                <input type="number" name="sort_order" form="<?= $fid ?>" class="form-control" value="<?= (int) $it['sort_order'] ?>" title="<?= te('till_menu_order') ?>">
                <input type="file" name="image" form="<?= $fid ?>" class="tm-file" accept="image/jpeg,image/png,image/webp,image/gif" title="<?= te(empty($it['image_url']) ? 'photo' : 'till_menu_photo_change') ?>">
                <span class="d-flex gap-sm">
                    <form method="POST" id="<?= $fid ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_item"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="cat" value="<?= (int) $c['id'] ?>">
                        <button class="btn btn-sm btn-outline" title="<?= te('save_settings') ?>"><i class="fas fa-save"></i></button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="action" value="toggle_item"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="cat" value="<?= (int) $c['id'] ?>">
                        <button class="btn btn-sm <?= $it['active'] ? 'btn-outline' : 'btn-success' ?>" title="<?= te($it['active'] ? 'till_menu_hide' : 'till_menu_show') ?>"><i class="fas <?= $it['active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button>
                    </form>
                    <?php if (!empty($it['image_url'])): ?>
                    <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('photo_remove') . '?')) ?>)">
                        <input type="hidden" name="action" value="remove_image"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><input type="hidden" name="cat" value="<?= (int) $c['id'] ?>">
                        <button class="btn btn-sm btn-outline" title="<?= te('photo_remove') ?>"><i class="fas fa-image"></i><i class="fas fa-xmark" style="font-size:.7em;"></i></button>
                    </form>
                    <?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
        <?php if (!$list): ?><p class="text-muted"><?= te('till_menu_no_items') ?></p><?php endif; ?>

        <form method="POST" class="tm-row" style="border-bottom:0;margin-top:6px;" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_item"><input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="cat" value="<?= (int) $c['id'] ?>">
            <span class="tm-thumb"><i class="fas fa-plus"></i></span>
            <input type="text" name="name" class="form-control" maxlength="150" placeholder="<?= te('till_menu_item_ph') ?>" required>
            <input type="text" name="price" class="form-control" placeholder="0,00" inputmode="decimal" required>
            <span></span>
            <input type="file" name="image" class="tm-file" accept="image/jpeg,image/png,image/webp,image/gif" title="<?= te('photo_optional') ?>">
            <button class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> <?= te('add') ?></button>
        </form>
    </div>
</div>
<?php endforeach; ?>

<?php if (!$cats): ?>
    <div class="card" style="padding:40px;text-align:center;"><p class="text-muted"><?= te('till_menu_none') ?></p></div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
