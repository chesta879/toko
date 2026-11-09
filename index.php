<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
start_app_session();

function h(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_check(): void {
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('Sesi formulir kedaluwarsa. Muat ulang halaman, lalu coba lagi.');
    }
}
function flash(string $message, string $kind = 'success'): void { $_SESSION['flash'] = [$message, $kind]; }
function go(string $view = 'dashboard'): never { header('Location: index.php?view=' . rawurlencode($view)); exit; }
function require_login(): void { if (empty($_SESSION['user_id'])) go('login'); }
function post_value(string $key, string $default = ''): string { return trim((string) ($_POST[$key] ?? $default)); }
function positive_int(mixed $value): int { return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0; }
function money(mixed $value): string { return 'Rp ' . number_format((float) $value, 0, ',', '.'); }
function date_today(): string { return date('Y-m-d'); }
function record_stock(PDO $pdo, int $productId, string $name, string $type, int $quantity, float $cost, ?int $reference, string $note, int $userId): void {
    $stmt = $pdo->prepare('INSERT INTO stock_movements (product_id, product_name, movement_type, quantity, unit_cost, reference_id, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$productId, $name, $type, $quantity, $cost, $reference, $note ?: null, $userId]);
}
function sale_lines_from_post(): array {
    $ids = $_POST['product_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    if (!is_array($ids) || !is_array($quantities)) return [];
    $lines = [];
    foreach ($ids as $index => $rawId) {
        $id = positive_int($rawId);
        $qty = positive_int($quantities[$index] ?? 0);
        if ($id && $qty) $lines[$id] = ($lines[$id] ?? 0) + $qty;
    }
    return $lines;
}

try {
    $pdo = db();
} catch (Throwable $error) {
    http_response_code(503);
    ?><!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Koneksi database</title><style>body{font:16px/1.6 system-ui;max-width:680px;margin:12vh auto;padding:24px;color:#20352c;background:#f6f4eb}section{background:white;border:1px solid #ddd;padding:28px;border-radius:14px}code{background:#eee;padding:2px 6px}</style><section><h1>Database belum siap</h1><p>Aplikasi tidak dapat terhubung ke MySQL. Pastikan MySQL pada XAMPP aktif dan pengaturan database pada <code>config.php</code> sesuai.</p><p>Detail: <?= h($error->getMessage()) ?></p><p>Setelah memperbaiki konfigurasi, muat ulang halaman. Database dan tabel akan dibuat otomatis bila akun MySQL diizinkan membuat database.</p></section></html><?php
    exit;
}

$usersCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$action = (string) ($_GET['action'] ?? '');
if ($action === 'backup' && !empty($_SESSION['user_id'])) {
    $backup = ['format' => 'toko-kita-backup-v1', 'created_at' => date(DATE_ATOM)];
    foreach (['customers', 'products', 'sales', 'sale_items', 'stock_movements'] as $table) {
        $backup[$table] = $pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="backup-toko-kita-' . date('Y-m-d') . '.json"');
    echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

$view = (string) ($_GET['view'] ?? ($usersCount ? 'dashboard' : 'setup'));
$errorMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $task = post_value('task');
    try {
        if ($task === 'setup') {
            if ($usersCount > 0) throw new RuntimeException('Admin sudah dibuat. Silakan masuk.');
            $name = post_value('name');
            $username = post_value('username');
            $password = (string) ($_POST['password'] ?? '');
            if ($name === '' || !preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username) || strlen($password) < 8) {
                throw new RuntimeException('Isi nama, username (3-60 karakter), dan password minimal 8 karakter.');
            }
            $stmt = $pdo->prepare('INSERT INTO users (name, username, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $pdo->lastInsertId();
            $_SESSION['user_name'] = $name;
            flash('Akun admin berhasil dibuat. Selamat datang!');
            go('dashboard');
        }
        if ($task === 'login') {
            $stmt = $pdo->prepare('SELECT id, name, password_hash FROM users WHERE username = ?');
            $stmt->execute([post_value('username')]);
            $user = $stmt->fetch();
            if (!$user || !password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
                throw new RuntimeException('Username atau password tidak sesuai.');
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_name'] = $user['name'];
            flash('Berhasil masuk.');
            go('dashboard');
        }
        if ($task === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            flash('Anda sudah keluar.');
            go('login');
        }

        require_login();
        $userId = (int) $_SESSION['user_id'];
        if ($task === 'save_product') {
            $id = positive_int($_POST['id'] ?? 0);
            $name = post_value('name');
            $category = post_value('category');
            $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
            $cost = filter_var($_POST['cost'] ?? null, FILTER_VALIDATE_FLOAT);
            $stock = filter_var($_POST['stock'] ?? null, FILTER_VALIDATE_INT);
            if ($name === '' || $category === '' || $price === false || $price <= 0 || $cost === false || $cost < 0 || $stock === false || $stock < 0) {
                throw new RuntimeException('Lengkapi nama, kategori, harga, modal, dan stok dengan nilai yang valid.');
            }
            if ($id) {
                $stmt = $pdo->prepare('UPDATE products SET name=?, category=?, price=?, cost=?, stock=? WHERE id=?');
                $stmt->execute([$name, $category, $price, $cost, $stock, $id]);
                if (!$stmt->rowCount()) {
                    $exists = $pdo->prepare('SELECT id FROM products WHERE id=?'); $exists->execute([$id]);
                    if (!$exists->fetch()) throw new RuntimeException('Produk tidak ditemukan.');
                }
                record_stock($pdo, $id, $name, 'adjustment', 0, (float) $cost, null, 'Perubahan data produk/stok', $userId);
                flash('Produk berhasil diperbarui.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO products (name,category,price,cost,stock) VALUES (?,?,?,?,?)');
                $stmt->execute([$name,$category,$price,$cost,$stock]);
                $id = (int) $pdo->lastInsertId();
                if ($stock > 0) record_stock($pdo, $id, $name, 'opening', $stock, (float) $cost, null, 'Stok awal produk', $userId);
                flash('Produk berhasil ditambahkan.');
            }
            go('products');
        }
        if ($task === 'delete_product') {
            $id = positive_int($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM sale_items WHERE product_id=?'); $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) throw new RuntimeException('Produk memiliki riwayat transaksi dan tidak dapat dihapus. Ubah nama atau stoknya saja.');
            $stmt = $pdo->prepare('DELETE FROM products WHERE id=?'); $stmt->execute([$id]);
            flash('Produk berhasil dihapus.');
            go('products');
        }
        if ($task === 'save_customer') {
            $id = positive_int($_POST['id'] ?? 0);
            $name = post_value('name'); $city = post_value('city'); $phone = post_value('phone');
            if ($name === '' || $city === '') throw new RuntimeException('Nama dan kota pelanggan wajib diisi.');
            if ($id) {
                $stmt = $pdo->prepare('UPDATE customers SET name=?,city=?,phone=? WHERE id=?'); $stmt->execute([$name,$city,$phone ?: null,$id]);
                flash('Data pelanggan berhasil diperbarui.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO customers (name,city,phone) VALUES (?,?,?)'); $stmt->execute([$name,$city,$phone ?: null]);
                flash('Pelanggan berhasil ditambahkan.');
            }
            go('customers');
        }
        if ($task === 'delete_customer') {
            $stmt = $pdo->prepare('DELETE FROM customers WHERE id=?'); $stmt->execute([positive_int($_POST['id'] ?? 0)]);
            flash('Pelanggan dihapus. Riwayat transaksi tetap tersimpan.'); go('customers');
        }
        if ($task === 'restock') {
            $id = positive_int($_POST['id'] ?? 0); $qty = positive_int($_POST['quantity'] ?? 0);
            $cost = filter_var($_POST['unit_cost'] ?? null, FILTER_VALIDATE_FLOAT);
            if (!$id || !$qty || $cost === false || $cost < 0) throw new RuntimeException('Jumlah restok dan modal satuan harus valid.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT name FROM products WHERE id=? FOR UPDATE'); $stmt->execute([$id]); $product = $stmt->fetch();
            if (!$product) throw new RuntimeException('Produk tidak ditemukan.');
            $pdo->prepare('UPDATE products SET stock=stock+?,cost=? WHERE id=?')->execute([$qty,$cost,$id]);
            record_stock($pdo,$id,$product['name'],'restock',$qty,(float)$cost,null,post_value('note'),$userId);
            $pdo->commit();
            flash('Restok berhasil dicatat dan stok diperbarui.'); go('products');
        }
        if ($task === 'save_sale') {
            $id = positive_int($_POST['id'] ?? 0); $customerId = positive_int($_POST['customer_id'] ?? 0);
            $date = post_value('sale_date'); $lines = sale_lines_from_post();
            $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
            if (!$customerId || !$dateObject || $dateObject->format('Y-m-d') !== $date || !$lines) throw new RuntimeException('Pilih pelanggan, tanggal yang valid, dan minimal satu barang.');
            $stmt = $pdo->prepare('SELECT name FROM customers WHERE id=?'); $stmt->execute([$customerId]); $customerName = $stmt->fetchColumn();
            if (!$customerName) throw new RuntimeException('Pelanggan tidak ditemukan.');
            $pdo->beginTransaction();
            $oldItems = [];
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM sales WHERE id=? AND status='completed' FOR UPDATE"); $stmt->execute([$id]); $oldSale = $stmt->fetch();
                if (!$oldSale) throw new RuntimeException('Transaksi tidak ditemukan atau sudah dibatalkan/diretur.');
                $stmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id=?'); $stmt->execute([$id]); $oldItems = $stmt->fetchAll();
                foreach ($oldItems as $old) if ($old['product_id']) $pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$old['quantity'],$old['product_id']]);
            }
            $products = [];
            foreach ($lines as $productId => $quantity) {
                $stmt = $pdo->prepare('SELECT * FROM products WHERE id=? FOR UPDATE'); $stmt->execute([$productId]); $product = $stmt->fetch();
                if (!$product || (int)$product['stock'] < $quantity) throw new RuntimeException('Stok produk tidak cukup untuk menyimpan transaksi.');
                $products[$productId] = $product;
            }
            if ($id) {
                $pdo->prepare('UPDATE sales SET customer_id=?,customer_name=?,sale_date=?,note=? WHERE id=?')->execute([$customerId,$customerName,$date,post_value('note') ?: null,$id]);
                $pdo->prepare('DELETE FROM sale_items WHERE sale_id=?')->execute([$id]);
            } else {
                $invoice = 'TK-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                $pdo->prepare('INSERT INTO sales (invoice,customer_id,customer_name,sale_date,note,created_by) VALUES (?,?,?,?,?,?)')->execute([$invoice,$customerId,$customerName,$date,post_value('note') ?: null,$userId]);
                $id = (int)$pdo->lastInsertId();
            }
            $insert = $pdo->prepare('INSERT INTO sale_items (sale_id,product_id,product_name,quantity,unit_price,unit_cost) VALUES (?,?,?,?,?,?)');
            foreach ($lines as $productId => $quantity) {
                $product = $products[$productId];
                $insert->execute([$id,$productId,$product['name'],$quantity,$product['price'],$product['cost']]);
                $pdo->prepare('UPDATE products SET stock=stock-? WHERE id=?')->execute([$quantity,$productId]);
                record_stock($pdo,$productId,$product['name'],'sale',-$quantity,(float)$product['cost'],$id,$id ? ($oldItems ? 'Penyesuaian transaksi' : 'Penjualan') : 'Penjualan',$userId);
            }
            $pdo->commit();
            flash($id && $oldItems ? 'Transaksi berhasil diperbarui.' : 'Transaksi berhasil disimpan.');
            go('sales');
        }
        if ($task === 'cancel_sale') {
            $id = positive_int($_POST['id'] ?? 0);
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM sales WHERE id=? AND status IN ('completed','partial_return') FOR UPDATE"); $stmt->execute([$id]); $sale = $stmt->fetch();
            if (!$sale) throw new RuntimeException('Transaksi tidak ditemukan atau sudah dibatalkan.');
            $stmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id=?'); $stmt->execute([$id]);
            foreach ($stmt->fetchAll() as $item) {
                $remaining = (int)$item['quantity'] - (int)$item['returned_quantity'];
                if ($item['product_id'] && $remaining > 0) {
                    $pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$remaining,$item['product_id']]);
                    record_stock($pdo,(int)$item['product_id'],$item['product_name'],'cancel',$remaining,(float)$item['unit_cost'],$id,'Pembatalan transaksi',$userId);
                }
            }
            $pdo->prepare("UPDATE sales SET status='cancelled' WHERE id=?")->execute([$id]);
            $pdo->commit(); flash('Transaksi dibatalkan; stok barang yang belum diretur sudah dikembalikan.'); go('sales');
        }
        if ($task === 'return_sale') {
            $id = positive_int($_POST['id'] ?? 0); $returns = $_POST['return_quantity'] ?? [];
            if (!is_array($returns)) throw new RuntimeException('Rincian retur tidak valid.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM sales WHERE id=? AND status IN ('completed','partial_return') FOR UPDATE"); $stmt->execute([$id]); $sale = $stmt->fetch();
            if (!$sale) throw new RuntimeException('Transaksi tidak ditemukan atau tidak dapat diretur.');
            $stmt = $pdo->prepare('SELECT * FROM sale_items WHERE sale_id=? FOR UPDATE'); $stmt->execute([$id]); $items = $stmt->fetchAll(); $changed = false; $allReturned = true;
            foreach ($items as $item) {
                $qty = max(0, (int)($returns[$item['id']] ?? 0));
                $remaining = (int)$item['quantity'] - (int)$item['returned_quantity'];
                if ($qty > $remaining) throw new RuntimeException('Jumlah retur melebihi jumlah barang yang belum diretur.');
                if ($qty > 0) {
                    $changed = true;
                    $pdo->prepare('UPDATE sale_items SET returned_quantity=returned_quantity+? WHERE id=?')->execute([$qty,$item['id']]);
                    if ($item['product_id']) {
                        $pdo->prepare('UPDATE products SET stock=stock+? WHERE id=?')->execute([$qty,$item['product_id']]);
                        record_stock($pdo,(int)$item['product_id'],$item['product_name'],'return',$qty,(float)$item['unit_cost'],$id,'Retur penjualan',$userId);
                    }
                }
                if ((int)$item['returned_quantity'] + $qty < (int)$item['quantity']) $allReturned = false;
            }
            if (!$changed) throw new RuntimeException('Masukkan jumlah barang yang akan diretur.');
            $pdo->prepare("UPDATE sales SET status=? WHERE id=?")->execute([$allReturned ? 'cancelled' : 'partial_return',$id]);
            $pdo->commit(); flash('Retur berhasil dicatat dan stok diperbarui.'); go('sales');
        }
        if ($task === 'restore_backup') {
            if (!isset($_FILES['backup']) || $_FILES['backup']['error'] !== UPLOAD_ERR_OK || $_FILES['backup']['size'] > 12000000) throw new RuntimeException('Pilih file backup JSON (maksimal 12 MB).');
            $backup = json_decode((string)file_get_contents($_FILES['backup']['tmp_name']), true, 512, JSON_THROW_ON_ERROR);
            if (($backup['format'] ?? '') !== 'toko-kita-backup-v1') throw new RuntimeException('Format file backup tidak dikenali.');
            foreach (['customers','products','sales','sale_items','stock_movements'] as $table) if (!isset($backup[$table]) || !is_array($backup[$table])) throw new RuntimeException('File backup tidak lengkap.');
            $pdo->beginTransaction();
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach (['stock_movements','sale_items','sales','products','customers'] as $table) $pdo->exec("DELETE FROM {$table}");
            foreach (['customers'=>['id','name','city','phone','created_at'],'products'=>['id','name','category','price','cost','stock','created_at'],'sales'=>['id','invoice','customer_id','customer_name','sale_date','status','note','created_by','created_at','updated_at'],'sale_items'=>['id','sale_id','product_id','product_name','quantity','returned_quantity','unit_price','unit_cost'],'stock_movements'=>['id','product_id','product_name','movement_type','quantity','unit_cost','reference_id','note','created_by','created_at']] as $table=>$columns) {
                $quoted = implode(',',array_map(fn($column)=>"`{$column}`",$columns)); $marks = implode(',',array_fill(0,count($columns),'?')); $insert = $pdo->prepare("INSERT INTO {$table} ({$quoted}) VALUES ({$marks})");
                foreach ($backup[$table] as $row) { if (!is_array($row)) throw new RuntimeException('Baris pada file backup tidak valid.'); $insert->execute(array_map(fn($column)=>$row[$column] ?? null,$columns)); }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); $pdo->commit();
            flash('Backup berhasil dipulihkan.'); go('dashboard');
        }
        throw new RuntimeException('Aksi tidak dikenali.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof PDOException) {
            $errorMessage = 'Perubahan gagal disimpan. Pastikan data valid dan server database tersedia.';
        } else {
            $errorMessage = $error->getMessage();
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
if ($usersCount === 0) $view = 'setup';
$loggedIn = !empty($_SESSION['user_id']);
if (!$loggedIn && !in_array($view, ['setup','login'], true)) $view = $usersCount ? 'login' : 'setup';
if ($loggedIn && in_array($view, ['setup','login'], true)) $view = 'dashboard';
$allowedViews = ['dashboard','products','customers','sales','reports','stock','receipt'];
if (!in_array($view, $allowedViews, true) && $loggedIn) $view = 'dashboard';

$products = $customers = $sales = $movements = [];
$editingProduct = $editingCustomer = $editingSale = null;
if ($loggedIn) {
    $products = $pdo->query('SELECT * FROM products ORDER BY name')->fetchAll();
    $customers = $pdo->query('SELECT * FROM customers ORDER BY name')->fetchAll();
    if (isset($_GET['edit_product'])) { $s=$pdo->prepare('SELECT * FROM products WHERE id=?');$s->execute([positive_int($_GET['edit_product'])]);$editingProduct=$s->fetch() ?: null; }
    if (isset($_GET['edit_customer'])) { $s=$pdo->prepare('SELECT * FROM customers WHERE id=?');$s->execute([positive_int($_GET['edit_customer'])]);$editingCustomer=$s->fetch() ?: null; }
    if (isset($_GET['edit_sale'])) {
        $s=$pdo->prepare("SELECT * FROM sales WHERE id=? AND status='completed'");$s->execute([positive_int($_GET['edit_sale'])]);$editingSale=$s->fetch() ?: null;
        if ($editingSale) {$s=$pdo->prepare('SELECT * FROM sale_items WHERE sale_id=?');$s->execute([$editingSale['id']]);$editingSale['items']=$s->fetchAll();}
    }
    if ($view === 'receipt' && isset($_GET['id'])) {
        $s=$pdo->prepare('SELECT * FROM sales WHERE id=?');$s->execute([positive_int($_GET['id'])]);$receipt=$s->fetch();
        if ($receipt) {$s=$pdo->prepare('SELECT * FROM sale_items WHERE sale_id=?');$s->execute([$receipt['id']]);$receipt['items']=$s->fetchAll();}
    }
    $from = (string)($_GET['from'] ?? date('Y-m-01')); $to = (string)($_GET['to'] ?? date_today());
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) $from=date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) $to=date_today();
    if ($view === 'reports') {
        $s=$pdo->prepare("SELECT COUNT(DISTINCT s.id) AS transactions, COALESCE(SUM((i.quantity-i.returned_quantity)*i.unit_price),0) AS revenue, COALESCE(SUM((i.quantity-i.returned_quantity)*i.unit_cost),0) AS cogs FROM sales s LEFT JOIN sale_items i ON i.sale_id=s.id WHERE s.status<>'cancelled' AND s.sale_date BETWEEN ? AND ?");
        $s->execute([$from,$to]);$report=$s->fetch();$report['profit']=$report['revenue']-$report['cogs'];
        $s=$pdo->prepare("SELECT s.id,s.invoice,s.sale_date,s.customer_name,s.status,COALESCE(SUM((i.quantity-i.returned_quantity)*i.unit_price),0) AS revenue FROM sales s LEFT JOIN sale_items i ON i.sale_id=s.id WHERE s.sale_date BETWEEN ? AND ? GROUP BY s.id ORDER BY s.sale_date DESC,s.id DESC");$s->execute([$from,$to]);$reportRows=$s->fetchAll();
    }
    if ($view === 'stock') {$movements=$pdo->query('SELECT m.*,u.name AS user_name FROM stock_movements m LEFT JOIN users u ON u.id=m.created_by ORDER BY m.id DESC LIMIT 300')->fetchAll();}
    if ($view === 'sales') {
        $filterCustomer=positive_int($_GET['customer'] ?? 0);
        $sql='SELECT * FROM sales'.($filterCustomer?' WHERE customer_id=?':'').' ORDER BY sale_date DESC,id DESC LIMIT 200';
        $s=$pdo->prepare($sql);$s->execute($filterCustomer?[$filterCustomer]:[]);$sales=$s->fetchAll();
        foreach($sales as &$sale){$s=$pdo->prepare('SELECT * FROM sale_items WHERE sale_id=?');$s->execute([$sale['id']]);$sale['items']=$s->fetchAll();}unset($sale);
    }
    $stats=$pdo->query("SELECT (SELECT COALESCE(SUM((i.quantity-i.returned_quantity)*i.unit_price),0) FROM sale_items i JOIN sales s ON s.id=i.sale_id WHERE s.status<>'cancelled') AS revenue,(SELECT COUNT(*) FROM sales WHERE status<>'cancelled') AS sales_count,(SELECT COUNT(*) FROM products WHERE stock<=10) AS low_stock,(SELECT COUNT(*) FROM customers) AS customer_count,(SELECT COUNT(*) FROM products) AS product_count")->fetch();
}
function amount_for_sale(array $sale): float { return array_reduce($sale['items'] ?? [],fn($sum,$item)=>$sum+((int)$item['quantity']-(int)$item['returned_quantity'])*(float)$item['unit_price'],0.0); }
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#183f35">
<title><?= $view === 'login' ? 'Masuk' : ($view === 'setup' ? 'Pengaturan awal' : 'Operasional Toko') ?> · Toko Kita</title>
<style>
:root{--forest:#183f35;--green:#28705b;--cream:#f6f4eb;--paper:#fffefa;--ink:#1d2c27;--muted:#65756e;--line:#e4e6dc;--amber:#e8ad35;--red:#a83c2e;--pale:#fbf0d7}
*{box-sizing:border-box}body{margin:0;background:var(--cream);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}a{color:var(--green)}button,input,select,textarea{font:inherit}button{cursor:pointer}input,select,textarea{min-height:40px;padding:8px 10px;border:1px solid #d5ddd4;border-radius:7px;background:white;color:var(--ink);width:100%}textarea{min-height:76px}label{font-weight:650;font-size:13px}.field{display:grid;gap:5px;margin:0 0 12px}.app{min-height:100vh;display:grid;grid-template-columns:230px 1fr}.side{background:var(--forest);color:#eaf2eb;padding:25px 16px;display:flex;flex-direction:column;gap:20px}.logo{font-size:21px;font-weight:800;color:#fff}.logo b{display:inline-grid;place-items:center;width:34px;height:34px;margin-right:8px;border-radius:10px 10px 10px 3px;background:var(--amber);color:var(--forest)}.nav{display:grid;gap:5px}.nav a{padding:10px 12px;border-radius:8px;color:#dbe8df;text-decoration:none}.nav a.active,.nav a:hover{background:#f1eee1;color:var(--forest);font-weight:700}.user{margin-top:auto;border-top:1px solid #ffffff30;padding-top:14px;color:#c5d8cc;font-size:13px}.main{min-width:0}.top{min-height:64px;background:#faf9f3;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;padding:12px clamp(16px,4vw,40px);gap:14px}.content{max-width:1260px;margin:auto;padding:28px clamp(16px,4vw,40px) 55px}h1,h2,h3{line-height:1.2;margin:0 0 10px}h1{font-size:clamp(25px,3vw,34px)}h2{font-size:20px}p{color:var(--muted)}.intro{margin:0 0 21px}.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid transparent;border-radius:7px;background:var(--forest);color:white;padding:9px 13px;text-decoration:none;font-weight:650}.btn:hover{background:#245849}.btn.alt{background:white;border-color:var(--line);color:var(--forest)}.btn.warn{background:var(--amber);color:#362805}.btn.danger{background:#fce8e3;color:#8e3024;border-color:#efc9c0}.btn.sm{font-size:13px;padding:6px 9px}.btn:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:3px solid var(--amber);outline-offset:2px}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin:18px 0}.card,.panel{background:var(--paper);border:1px solid var(--line);border-radius:11px;padding:18px;box-shadow:0 10px 24px #2444380c}.card small{display:block;color:var(--muted)}.card strong{display:block;font-size:25px;margin:4px 0}.card.primary{background:var(--forest);color:white}.card.primary small{color:#c6d9cd}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.panel{margin:14px 0}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 12px}.span2{grid-column:1/-1}.tools{display:flex;align-items:end;gap:9px;flex-wrap:wrap;margin-bottom:12px}.tools .field{flex:1 1 190px;margin:0}.tablewrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px}th{text-align:left;padding:9px;background:#f5f6ef;color:#627269;font-size:11px;text-transform:uppercase;white-space:nowrap}td{padding:9px;border-bottom:1px solid #eceee7;vertical-align:top}td.num,th.num{text-align:right;white-space:nowrap}.actions{display:flex;gap:5px;flex-wrap:wrap}.notice{padding:12px 15px;border-radius:8px;background:#e5f1e9;color:#245e3d;margin:12px 0}.notice.error{background:#fce8e3;color:#8e3024}.notice.warn{background:var(--pale);color:#684b10}.tag{display:inline-block;border-radius:20px;padding:2px 8px;background:#edf0e8;color:#516057;font-size:11px;font-weight:700}.tag.low,.tag.cancelled{background:#fce8e3;color:#91392e}.tag.partial_return{background:#fff2cf;color:#684b10}.hint{font-size:12px;color:var(--muted)}.inline{display:inline}.toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.login{max-width:510px;margin:9vh auto;padding:26px}.receipt{max-width:650px;margin:auto}.receipt-head{text-align:center;border-bottom:1px dashed #aaa;padding-bottom:15px}.receipt-total{font-weight:800;font-size:20px;text-align:right}.footer{color:var(--muted);font-size:12px;margin-top:30px}@media(max-width:900px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.app{grid-template-columns:190px 1fr}.grid{grid-template-columns:1fr}}@media(max-width:650px){.app{display:block}.side{position:sticky;top:0;z-index:5;padding:8px}.logo{display:none}.nav{grid-template-columns:repeat(6,minmax(0,1fr));gap:3px}.nav a{padding:7px 2px;text-align:center;font-size:11px;white-space:nowrap}.user{display:none}.top{min-height:48px;padding:8px 12px}.content{padding:19px 12px 40px}.cards{gap:8px}.card{padding:13px}.card strong{font-size:20px}.form-grid{grid-template-columns:1fr}.span2{grid-column:auto}.grid{grid-template-columns:1fr}.tools .field{flex-basis:130px}}@media print{.side,.top,.no-print,.notice,.footer{display:none!important}.app{display:block}.content{padding:0;max-width:none}.panel{box-shadow:none;border:0}.receipt{max-width:none}}
</style>
</head>
<body>
<?php if (!$loggedIn): ?>
<main class="panel login">
  <div class="logo" style="color:var(--forest)"><b>T</b>Toko Kita</div>
  <?php if ($errorMessage): ?><div class="notice error"><?= h($errorMessage) ?></div><?php endif ?>
  <?php if ($view === 'setup'): ?>
    <h1>Siapkan toko Anda</h1><p>Buat akun admin pertama. Database dan tabel disiapkan otomatis oleh aplikasi.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="task" value="setup">
      <div class="field"><label for="name">Nama admin</label><input id="name" name="name" required autocomplete="name"></div>
      <div class="field"><label for="username">Username</label><input id="username" name="username" required minlength="3" autocomplete="username"></div>
      <div class="field"><label for="password">Password (minimal 8 karakter)</label><input id="password" name="password" type="password" minlength="8" required autocomplete="new-password"></div>
      <button class="btn warn" type="submit">Buat akun dan mulai</button>
    </form>
  <?php else: ?>
    <h1>Masuk ke Toko Kita</h1><p>Gunakan akun admin Anda untuk membuka data toko.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="task" value="login">
      <div class="field"><label for="username">Username</label><input id="username" name="username" required autocomplete="username"></div>
      <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password"></div>
      <button class="btn" type="submit">Masuk</button>
    </form>
  <?php endif ?>
</main>
<?php else: ?>
<div class="app">
<aside class="side"><div class="logo"><b>T</b>Toko Kita</div><nav class="nav" aria-label="Navigasi utama">
<?php foreach (['dashboard'=>'Ringkasan','products'=>'Produk','customers'=>'Pelanggan','sales'=>'Transaksi','reports'=>'Laporan','stock'=>'Riwayat stok'] as $key=>$label): ?><a class="<?= $view===$key?'active':'' ?>" href="?view=<?= h($key) ?>"><?= h($label) ?><?= $key==='products' && $stats['low_stock'] ? ' · '.(int)$stats['low_stock'] : '' ?></a><?php endforeach ?>
</nav><div class="user"><strong><?= h($_SESSION['user_name'] ?? 'Admin') ?></strong><form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="task" value="logout"><button class="btn alt sm" style="margin-top:8px">Keluar</button></form></div></aside>
<div class="main"><header class="top"><strong><?= h(['dashboard'=>'Toko Kita / Ringkasan','products'=>'Katalog produk','customers'=>'Data pelanggan','sales'=>'Transaksi penjualan','reports'=>'Laporan usaha','stock'=>'Riwayat stok','receipt'=>'Nota penjualan'][$view] ?? 'Toko Kita') ?></strong><div class="toolbar no-print"><a class="btn warn sm" href="?view=sales&new=1">＋ Transaksi baru</a></div></header>
<main class="content">
<?php if ($flash): ?><div class="notice <?= h($flash[1]) ?>"><?= h($flash[0]) ?></div><?php endif ?>
<?php if ($errorMessage): ?><div class="notice error"><?= h($errorMessage) ?></div><?php endif ?>

<?php if ($view==='dashboard'): ?>
  <h1>Ringkasan penjualan</h1><p class="intro">Pantau penjualan, persediaan, dan aktivitas toko Anda.</p>
  <div class="cards"><div class="card primary"><small>Total penjualan bersih</small><strong><?= money($stats['revenue']) ?></strong></div><div class="card"><small>Transaksi aktif</small><strong><?= (int)$stats['sales_count'] ?></strong></div><div class="card"><small>Pelanggan</small><strong><?= (int)$stats['customer_count'] ?></strong></div><div class="card"><small>Produk stok ≤ 10</small><strong><?= (int)$stats['low_stock'] ?></strong></div></div>
  <div class="grid"><section class="panel"><h2>Stok perlu diperiksa</h2><p>Segera catat restok produk yang hampir habis.</p><?php $low=array_filter($products,fn($p)=>(int)$p['stock']<=10); if (!$low): ?><p>Semua stok di atas batas minimum.</p><?php else: foreach($low as $p): ?><p><strong><?= h($p['name']) ?></strong> · <?= (int)$p['stock'] ?> tersisa · <a href="?view=products&restock=<?= (int)$p['id'] ?>">Catat restok</a></p><?php endforeach; endif ?></section><section class="panel"><h2>Akses cepat</h2><div class="toolbar"><a class="btn" href="?view=sales&new=1">Catat transaksi</a><a class="btn alt" href="?view=products">Kelola produk</a><a class="btn alt" href="?view=reports">Lihat laporan</a></div><p class="hint">Aplikasi memakai database MySQL lokal dan akun admin.</p></section></div>
  <section class="panel"><h2>Transaksi terbaru</h2><?php $recent=$pdo->query('SELECT * FROM sales ORDER BY id DESC LIMIT 8')->fetchAll(); if (!$recent): ?><p>Belum ada transaksi. Mulai dengan mencatat penjualan baru.</p><?php else: ?><div class="tablewrap"><table><thead><tr><th>Nota</th><th>Tanggal</th><th>Pelanggan</th><th>Status</th><th class="num">Nilai</th></tr></thead><tbody><?php foreach($recent as $s): $q=$pdo->prepare('SELECT COALESCE(SUM((quantity-returned_quantity)*unit_price),0) FROM sale_items WHERE sale_id=?');$q->execute([$s['id']]); ?><tr><td><a href="?view=receipt&id=<?= (int)$s['id'] ?>"><?= h($s['invoice']) ?></a></td><td><?= h($s['sale_date']) ?></td><td><?= h($s['customer_name']) ?></td><td><span class="tag <?= h($s['status']) ?>"><?= h($s['status']) ?></span></td><td class="num"><?= money($q->fetchColumn()) ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?></section>

<?php elseif ($view==='products'): ?>
  <h1>Produk & persediaan</h1><p class="intro">Kelola harga jual, modal, stok, dan penerimaan barang.</p>
  <?php if (isset($_GET['new']) || $editingProduct): $p=$editingProduct ?: ['id'=>'','name'=>'','category'=>'Sembako','price'=>'','cost'=>'','stock'=>0]; ?>
  <section class="panel"><h2><?= $p['id']?'Ubah produk':'Tambah produk' ?></h2><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="task" value="save_product"><input type="hidden" name="id" value="<?= h($p['id']) ?>"><div class="form-grid">
  <div class="field"><label>Nama produk</label><input name="name" required value="<?= h($p['name']) ?>"></div><div class="field"><label>Kategori</label><input name="category" required list="categories" value="<?= h($p['category']) ?>"><datalist id="categories"><option>Sembako</option><option>Minuman</option><option>Kebersihan</option></datalist></div>
  <div class="field"><label>Harga jual (Rp)</label><input name="price" type="number" min="1" step="1" required value="<?= h($p['price']) ?>"></div><div class="field"><label>Modal per unit (Rp)</label><input name="cost" type="number" min="0" step="1" required value="<?= h($p['cost']) ?>"></div><div class="field"><label>Stok <?= $p['id']?'saat ini':'awal' ?></label><input name="stock" type="number" min="0" step="1" required value="<?= h($p['stock']) ?>"></div></div><button class="btn">Simpan produk</button> <a class="btn alt" href="?view=products">Batal</a></form></section><?php endif ?>
  <?php if (isset($_GET['restock'])): $rid=positive_int($_GET['restock']);$rp=$pdo->prepare('SELECT * FROM products WHERE id=?');$rp->execute([$rid]);$restockProduct=$rp->fetch();if($restockProduct): ?><section class="panel"><h2>Catat restok · <?= h($restockProduct['name']) ?></h2><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="task" value="restock"><input type="hidden" name="id" value="<?= $rid ?>"><div class="form-grid"><div class="field"><label>Jumlah barang masuk</label><input name="quantity" type="number" min="1" required></div><div class="field"><label>Modal satuan terbaru (Rp)</label><input name="unit_cost" type="number" min="0" value="<?= h($restockProduct['cost']) ?>" required></div><div class="field span2"><label>Catatan / pemasok</label><input name="note" placeholder="Opsional"></div></div><button class="btn">Simpan restok</button> <a class="btn alt" href="?view=products">Batal</a></form></section><?php endif;endif ?>
  <form class="tools" method="get"><input type="hidden" name="view" value="products"><div class="field"><label>Cari produk</label><input name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="Nama produk"></div><button class="btn alt">Cari</button><a class="btn" href="?view=products&new=1">＋ Tambah produk</a></form>
  <?php $filtered=array_filter($products,fn($p)=>!isset($_GET['q'])||stripos($p['name'],(string)$_GET['q'])!==false); ?><section class="panel"><div class="tablewrap"><table><thead><tr><th>Produk</th><th>Kategori</th><th class="num">Harga</th><th class="num">Modal</th><th class="num">Stok</th><th>Aksi</th></tr></thead><tbody><?php foreach($filtered as $p): ?><tr><td><?= h($p['name']) ?></td><td><?= h($p['category']) ?></td><td class="num"><?= money($p['price']) ?></td><td class="num"><?= money($p['cost']) ?></td><td class="num"><span class="tag <?= $p['stock']<=10?'low':'' ?>"><?= (int)$p['stock'] ?></span></td><td><div class="actions"><a class="btn alt sm" href="?view=products&edit_product=<?= (int)$p['id'] ?>">Ubah</a><a class="btn warn sm" href="?view=products&restock=<?= (int)$p['id'] ?>">Restok</a><form method="post" onsubmit="return confirm('Hapus produk ini?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="task" value="delete_product"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn danger sm">Hapus</button></form></div></td></tr><?php endforeach ?></tbody></table></div></section>

<?php elseif ($view==='customers'): ?>
  <h1>Pelanggan</h1><p class="intro">Simpan informasi kontak dan kota pelanggan.</p>
  <?php if (isset($_GET['new'])||$editingCustomer): $c=$editingCustomer?:['id'=>'','name'=>'','city'=>'','phone'=>'']; ?><section class="panel"><h2><?= $c['id']?'Ubah pelanggan':'Tambah pelanggan' ?></h2><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="task" value="save_customer"><input type="hidden" name="id" value="<?= h($c['id']) ?>"><div class="form-grid"><div class="field"><label>Nama</label><input name="name" required value="<?= h($c['name']) ?>"></div><div class="field"><label>Kota</label><input name="city" required value="<?= h($c['city']) ?>"></div><div class="field"><label>No. telepon</label><input name="phone" type="tel" value="<?= h($c['phone']) ?>"></div></div><button class="btn">Simpan pelanggan</button> <a class="btn alt" href="?view=customers">Batal</a></form></section><?php endif ?>
  <div class="tools"><a class="btn" href="?view=customers&new=1">＋ Tambah pelanggan</a></div><section class="panel"><div class="tablewrap"><table><thead><tr><th>Nama</th><th>Kota</th><th>Telepon</th><th>Aksi</th></tr></thead><tbody><?php foreach($customers as $c): ?><tr><td><?= h($c['name']) ?></td><td><?= h($c['city']) ?></td><td><?= h($c['phone'] ?: '—') ?></td><td><div class="actions"><a class="btn alt sm" href="?view=customers&edit_customer=<?= (int)$c['id'] ?>">Ubah</a><form method="post" onsubmit="return confirm('Hapus pelanggan? Riwayat transaksi akan dipertahankan.')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="task" value="delete_customer"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn danger sm">Hapus</button></form></div></td></tr><?php endforeach ?></tbody></table></div></section>

<?php elseif ($view==='sales'): ?>
  <h1>Transaksi penjualan</h1><p class="intro">Catat penjualan, kelola transaksi, cetak nota, dan proses retur.</p>
  <?php if (isset($_GET['new'])||$editingSale): $s=$editingSale?:['id'=>'','customer_id'=>'','sale_date'=>date_today(),'note'=>'','items'=>[]]; ?><section class="panel"><h2><?= $s['id']?'Ubah transaksi':'Transaksi baru' ?></h2><form method="post" id="sale-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="task" value="save_sale"><input type="hidden" name="id" value="<?= h($s['id']) ?>"><div class="form-grid"><div class="field"><label>Pelanggan</label><select name="customer_id" required><option value="">Pilih pelanggan</option><?php foreach($customers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$s['customer_id']===(int)$c['id']?'selected':'' ?>><?= h($c['name'].' · '.$c['city']) ?></option><?php endforeach ?></select></div><div class="field"><label>Tanggal</label><input type="date" name="sale_date" value="<?= h($s['sale_date']) ?>" required></div><div class="field span2"><label>Catatan</label><input name="note" value="<?= h($s['note'] ?? '') ?>"></div></div><p class="hint">Pilih produk dan jumlah. Stok diperiksa kembali saat disimpan. Harga mengikuti harga produk terkini.</p><div id="sale-lines"><?php foreach($s['items'] as $item): ?><div class="form-grid sale-line"><div class="field"><label>Produk</label><select name="product_id[]" required><option value="">Pilih produk</option><?php foreach($products as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$item['product_id']===(int)$p['id']?'selected':'' ?>><?= h($p['name'].' · stok '.$p['stock'].' · '.money($p['price'])) ?></option><?php endforeach ?></select></div><div class="field"><label>Jumlah</label><input name="quantity[]" type="number" min="1" value="<?= (int)$item['quantity'] ?>" required></div></div><?php endforeach ?></div><button class="btn alt sm" type="button" onclick="addSaleLine()">＋ Tambah barang</button><p class="hint" id="sale-total"></p><button class="btn" type="submit">Simpan transaksi</button> <a class="btn alt" href="?view=sales">Batal</a></form></section><?php endif ?>
  <form class="tools" method="get"><input type="hidden" name="view" value="sales"><div class="field"><label>Filter pelanggan</label><select name="customer"><option value="">Semua pelanggan</option><?php foreach($customers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($_GET['customer']??0)===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option><?php endforeach ?></select></div><button class="btn alt">Filter</button><a class="btn" href="?view=sales&new=1">＋ Transaksi baru</a></form>
  <?php if(isset($_GET['return'])): $returnId=positive_int($_GET['return']);$rs=$pdo->prepare('SELECT * FROM sales WHERE id=?');$rs->execute([$returnId]);$returnSale=$rs->fetch();if($returnSale):$rs=$pdo->prepare('SELECT * FROM sale_items WHERE sale_id=?');$rs->execute([$returnId]);$returnItems=$rs->fetchAll(); ?><section class="panel"><h2>Retur · <?= h($returnSale['invoice']) ?></h2><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="task" value="return_sale"><input type="hidden" name="id" value="<?= $returnId ?>"><div class="tablewrap"><table><thead><tr><th>Produk</th><th>Terjual</th><th>Sudah diretur</th><th>Retur sekarang</th></tr></thead><tbody><?php foreach($returnItems as $item):$remaining=(int)$item['quantity']-(int)$item['returned_quantity']; ?><tr><td><?= h($item['product_name']) ?></td><td><?= (int)$item['quantity'] ?></td><td><?= (int)$item['returned_quantity'] ?></td><td><input style="max-width:110px" type="number" min="0" max="<?= $remaining ?>" name="return_quantity[<?= (int)$item['id'] ?>]" value="0"></td></tr><?php endforeach ?></tbody></table></div><button class="btn warn">Simpan retur</button> <a class="btn alt" href="?view=sales">Batal</a></form></section><?php endif;endif ?>
  <section class="panel"><div class="tablewrap"><table><thead><tr><th>Nota</th><th>Tanggal</th><th>Pelanggan</th><th>Status</th><th class="num">Total bersih</th><th>Aksi</th></tr></thead><tbody><?php foreach($sales as $s): ?><tr><td><?= h($s['invoice']) ?></td><td><?= h($s['sale_date']) ?></td><td><?= h($s['customer_name']) ?></td><td><span class="tag <?= h($s['status']) ?>"><?= h($s['status']) ?></span></td><td class="num"><?= money(amount_for_sale($s)) ?></td><td><div class="actions"><a class="btn alt sm" href="?view=receipt&id=<?= (int)$s['id'] ?>">Nota</a><?php if($s['status']==='completed'): ?><a class="btn alt sm" href="?view=sales&edit_sale=<?= (int)$s['id'] ?>">Ubah</a><?php endif ?><?php if(in_array($s['status'],['completed','partial_return'],true)): ?><a class="btn warn sm" href="?view=sales&return=<?= (int)$s['id'] ?>">Retur</a><form method="post" onsubmit="return confirm('Batalkan transaksi ini dan kembalikan sisa barang ke stok?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="task" value="cancel_sale"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="btn danger sm">Batalkan</button></form><?php endif ?></div></td></tr><?php endforeach ?></tbody></table></div></section>

<?php elseif ($view==='reports'): ?>
  <h1>Laporan usaha</h1><p class="intro">Ringkasan pendapatan dan estimasi laba berdasarkan periode.</p><form class="tools" method="get"><input type="hidden" name="view" value="reports"><div class="field"><label>Dari tanggal</label><input type="date" name="from" value="<?= h($from) ?>"></div><div class="field"><label>Sampai tanggal</label><input type="date" name="to" value="<?= h($to) ?>"></div><button class="btn">Tampilkan</button></form>
  <div class="cards"><div class="card primary"><small>Penjualan bersih</small><strong><?= money($report['revenue']) ?></strong></div><div class="card"><small>Estimasi modal barang terjual</small><strong><?= money($report['cogs']) ?></strong></div><div class="card"><small>Estimasi laba kotor</small><strong><?= money($report['profit']) ?></strong></div><div class="card"><small>Transaksi</small><strong><?= (int)$report['transactions'] ?></strong></div></div><p class="hint">Laba dihitung dari harga jual dikurangi modal produk saat transaksi. Biaya operasional, pajak, dan biaya lain belum termasuk.</p>
  <section class="panel"><div class="tablewrap"><table><thead><tr><th>Nota</th><th>Tanggal</th><th>Pelanggan</th><th>Status</th><th class="num">Penjualan bersih</th></tr></thead><tbody><?php foreach($reportRows as $r): ?><tr><td><a href="?view=receipt&id=<?= (int)$r['id'] ?>"><?= h($r['invoice']) ?></a></td><td><?= h($r['sale_date']) ?></td><td><?= h($r['customer_name']) ?></td><td><?= h($r['status']) ?></td><td class="num"><?= money($r['revenue']) ?></td></tr><?php endforeach ?></tbody></table></div></section>
  <div class="grid"><section class="panel"><h2>Backup penuh</h2><p>Unduh data pelanggan, produk, transaksi, detail, dan riwayat stok sebagai JSON.</p><a class="btn" href="?action=backup">Unduh backup JSON</a></section><section class="panel"><h2>Pulihkan backup</h2><p class="notice warn">Pemulihan akan mengganti seluruh data toko saat ini. Buat backup terlebih dahulu.</p><form method="post" enctype="multipart/form-data" onsubmit="return confirm('Ganti seluruh data toko dengan isi backup ini?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="task" value="restore_backup"><div class="field"><label>File backup JSON</label><input type="file" name="backup" accept="application/json,.json" required></div><button class="btn danger">Pulihkan data</button></form></section></div>

<?php elseif ($view==='stock'): ?>
  <h1>Riwayat stok</h1><p class="intro">Audit penerimaan, penjualan, retur, dan penyesuaian stok (maksimal 300 catatan terakhir).</p><section class="panel"><div class="tablewrap"><table><thead><tr><th>Waktu</th><th>Produk</th><th>Jenis</th><th class="num">Perubahan</th><th class="num">Modal/unit</th><th>Referensi / catatan</th><th>Petugas</th></tr></thead><tbody><?php foreach($movements as $m): ?><tr><td><?= h($m['created_at']) ?></td><td><?= h($m['product_name']) ?></td><td><?= h($m['movement_type']) ?></td><td class="num"><?= (int)$m['quantity']>0?'+':'' ?><?= (int)$m['quantity'] ?></td><td class="num"><?= money($m['unit_cost']) ?></td><td><?= h(($m['reference_id']?'#'.$m['reference_id'].' ':'').($m['note']??'')) ?></td><td><?= h($m['user_name']??'—') ?></td></tr><?php endforeach ?></tbody></table></div></section>

<?php elseif ($view==='receipt'): ?>
  <?php if (empty($receipt)): ?><section class="panel"><h1>Nota tidak ditemukan</h1><a href="?view=sales">Kembali ke transaksi</a></section><?php else: ?><section class="panel receipt"><div class="receipt-head"><h1>TOKO KITA</h1><p>Ponorogo · Madiun · Magetan</p><strong>NOTA PENJUALAN</strong></div><p><strong>No:</strong> <?= h($receipt['invoice']) ?><br><strong>Tanggal:</strong> <?= h($receipt['sale_date']) ?><br><strong>Pelanggan:</strong> <?= h($receipt['customer_name']) ?><br><strong>Status:</strong> <?= h($receipt['status']) ?></p><div class="tablewrap"><table><thead><tr><th>Produk</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Subtotal</th></tr></thead><tbody><?php foreach($receipt['items'] as $item):$net=(int)$item['quantity']-(int)$item['returned_quantity']; ?><tr><td><?= h($item['product_name']) ?><?= $net!==(int)$item['quantity']?' (retur '.(int)$item['returned_quantity'].')':'' ?></td><td class="num"><?= $net ?></td><td class="num"><?= money($item['unit_price']) ?></td><td class="num"><?= money($net*$item['unit_price']) ?></td></tr><?php endforeach ?></tbody></table></div><p class="receipt-total">Total: <?= money(amount_for_sale($receipt)) ?></p><p class="hint"><?= h($receipt['note']??'') ?></p><div class="toolbar no-print"><button class="btn" onclick="window.print()">Cetak nota</button><a class="btn alt" href="?view=sales">Kembali</a></div></section><?php endif ?>
<?php endif ?>
<p class="footer">Toko Kita · Data tersimpan di MySQL lokal · Cadangkan data secara berkala.</p>
</main></div></div>
<script>
function addSaleLine(){const host=document.getElementById('sale-lines');const row=document.createElement('div');row.className='form-grid sale-line';row.innerHTML=document.querySelector('.sale-line')?.innerHTML||'';if(!row.innerHTML){return}host.appendChild(row);bindTotals()}
function bindTotals(){const form=document.getElementById('sale-form');if(!form)return;const update=()=>{let total=0;form.querySelectorAll('.sale-line').forEach(line=>{const sel=line.querySelector('[name="product_id[]"]'),qty=line.querySelector('[name="quantity[]"]');if(sel?.selectedOptions[0]){const text=sel.selectedOptions[0].textContent.match(/Rp ([\d.]+)/);if(text)total+=Number(text[1].replace(/\./g,''))*(Number(qty?.value)||0)}});document.getElementById('sale-total').textContent='Perkiraan total: Rp '+new Intl.NumberFormat('id-ID').format(total)};form.addEventListener('input',update);form.addEventListener('change',update);update()}
bindTotals();
</script>
<?php endif ?>
</body></html>
