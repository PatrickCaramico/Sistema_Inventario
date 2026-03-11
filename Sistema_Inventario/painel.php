<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

requireLogin();

$pdo = db();
$message = '';
$messageType = 'success';
$isAdmin = isAdmin();
$userRole = currentUserRole();
$roleLabel = $isAdmin ? 'Administrador' : 'Funcionario';
$sessionRemainingSeconds = sessionRemainingSeconds();
$section = trim((string) ($_GET['secao'] ?? 'inicio'));

if (!in_array($section, ['inicio', 'produtos', 'categorias', 'usuarios'], true)) {
    $section = 'inicio';
}

if (!$isAdmin && $section === 'usuarios') {
    $section = 'inicio';
    $message = 'Seu perfil nao tem permissao para acessar a gestao de usuarios.';
    $messageType = 'error';
}

function formatRoleLabel(string $role): string
{
    return $role === 'admin' ? 'Administrador' : 'Funcionario';
}

function registerAuditLog(PDO $pdo, string $action, ?int $productId, string $details): void
{
    $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, user_name, action, product_id, details) VALUES (:user_id, :user_name, :action, :product_id, :details)');
    $stmt->execute([
        ':user_id' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
        ':user_name' => (string) ($_SESSION['user_name'] ?? 'Usuario'),
        ':action' => $action,
        ':product_id' => $productId,
        ':details' => $details,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (in_array($action, ['save_product', 'delete_product', 'adjust_stock', 'create_user', 'update_user', 'delete_user'], true) && !$isAdmin) {
        $message = 'Seu perfil nao tem permissao para alterar dados administrativos.';
        $messageType = 'error';
    }

    if ($action === 'save_product' && $isAdmin) {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = trim((string) ($_POST['category'] ?? ''));
        $price = (float) ($_POST['price'] ?? 0);
        $quantity = (int) ($_POST['quantity'] ?? 0);

        if ($name === '' || $category === '') {
            $message = 'Nome e categoria sao obrigatorios.';
            $messageType = 'error';
        } elseif ($price < 0 || $quantity < 0) {
            $message = 'Preco e quantidade devem ser valores positivos.';
            $messageType = 'error';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE products SET name = :name, category = :category, price = :price, quantity = :quantity, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmt->execute([
                    ':name' => $name,
                    ':category' => $category,
                    ':price' => $price,
                    ':quantity' => $quantity,
                    ':id' => $id,
                ]);
                registerAuditLog($pdo, 'update_product', $id, 'Produto atualizado: ' . $name . ' | categoria=' . $category . ' | quantidade=' . $quantity);
                $message = 'Produto atualizado com sucesso.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO products (name, category, price, quantity) VALUES (:name, :category, :price, :quantity)');
                $stmt->execute([
                    ':name' => $name,
                    ':category' => $category,
                    ':price' => $price,
                    ':quantity' => $quantity,
                ]);
                $newId = (int) $pdo->lastInsertId();
                registerAuditLog($pdo, 'create_product', $newId > 0 ? $newId : null, 'Produto cadastrado: ' . $name . ' | categoria=' . $category . ' | quantidade=' . $quantity);
                $message = 'Produto cadastrado com sucesso.';
            }
        }
    } elseif ($action === 'delete_product' && $isAdmin) {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $beforeDelete = $pdo->prepare('SELECT name FROM products WHERE id = :id LIMIT 1');
            $beforeDelete->execute([':id' => $id]);
            $deletedProduct = $beforeDelete->fetch();

            $stmt = $pdo->prepare('DELETE FROM products WHERE id = :id');
            $stmt->execute([':id' => $id]);

            $deletedName = (string) ($deletedProduct['name'] ?? 'ID ' . $id);
            registerAuditLog($pdo, 'delete_product', $id, 'Produto removido: ' . $deletedName);
            $message = 'Produto removido com sucesso.';
        } else {
            $message = 'Produto invalido para exclusao.';
            $messageType = 'error';
        }
    } elseif ($action === 'adjust_stock' && $isAdmin) {
        $id = (int) ($_POST['id'] ?? 0);
        $delta = (int) ($_POST['delta'] ?? 0);

        if ($id <= 0 || !in_array($delta, [-1, 1], true)) {
            $message = 'Acao de estoque invalida.';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare('UPDATE products SET quantity = GREATEST(quantity + :delta, 0), updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([
                ':delta' => $delta,
                ':id' => $id,
            ]);

            registerAuditLog($pdo, 'adjust_stock', $id, 'Ajuste de estoque aplicado: delta=' . $delta);

            $message = $delta > 0 ? 'Estoque incrementado.' : 'Estoque decrementado.';
        }
    } elseif ($action === 'create_user' && $isAdmin) {
        $newUserName = trim((string) ($_POST['user_name'] ?? ''));
        $newUserEmail = trim((string) ($_POST['user_email'] ?? ''));
        $newUserPassword = (string) ($_POST['user_password'] ?? '');
        $newUserRole = trim((string) ($_POST['user_role'] ?? 'funcionario'));

        if ($newUserName === '' || $newUserEmail === '' || $newUserPassword === '') {
            $message = 'Nome, e-mail e senha do usuario sao obrigatorios.';
            $messageType = 'error';
        } elseif (!filter_var($newUserEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Informe um e-mail valido para o novo usuario.';
            $messageType = 'error';
        } elseif (strlen($newUserPassword) < 6) {
            $message = 'A senha do novo usuario deve ter pelo menos 6 caracteres.';
            $messageType = 'error';
        } elseif (!in_array($newUserRole, ['admin', 'funcionario'], true)) {
            $message = 'Perfil de acesso invalido.';
            $messageType = 'error';
        } else {
            $checkUserStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $checkUserStmt->execute([':email' => $newUserEmail]);

            if ($checkUserStmt->fetch()) {
                $message = 'Ja existe um usuario cadastrado com este e-mail.';
                $messageType = 'error';
            } else {
                $insertUserStmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)');
                $insertUserStmt->execute([
                    ':name' => $newUserName,
                    ':email' => $newUserEmail,
                    ':password_hash' => password_hash($newUserPassword, PASSWORD_DEFAULT),
                    ':role' => $newUserRole,
                ]);

                $createdUserId = (int) $pdo->lastInsertId();
                registerAuditLog($pdo, 'create_user', $createdUserId > 0 ? $createdUserId : null, 'Usuario cadastrado: ' . $newUserName . ' | email=' . $newUserEmail . ' | perfil=' . $newUserRole);
                $message = 'Usuario cadastrado com sucesso.';
            }
        }
    } elseif ($action === 'update_user' && $isAdmin) {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $newUserName = trim((string) ($_POST['user_name'] ?? ''));
        $newUserEmail = trim((string) ($_POST['user_email'] ?? ''));
        $newUserPassword = (string) ($_POST['user_password'] ?? '');
        $newUserRole = trim((string) ($_POST['user_role'] ?? 'funcionario'));

        if ($targetUserId <= 0) {
            $message = 'Usuario invalido para edicao.';
            $messageType = 'error';
        } elseif ($newUserName === '' || $newUserEmail === '') {
            $message = 'Nome e e-mail sao obrigatorios.';
            $messageType = 'error';
        } elseif (!filter_var($newUserEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Informe um e-mail valido para o usuario.';
            $messageType = 'error';
        } elseif ($newUserPassword !== '' && strlen($newUserPassword) < 6) {
            $message = 'Se informada, a senha deve ter pelo menos 6 caracteres.';
            $messageType = 'error';
        } elseif (!in_array($newUserRole, ['admin', 'funcionario'], true)) {
            $message = 'Perfil de acesso invalido.';
            $messageType = 'error';
        } else {
            $checkUserStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
            $checkUserStmt->execute([
                ':email' => $newUserEmail,
                ':id' => $targetUserId,
            ]);

            if ($checkUserStmt->fetch()) {
                $message = 'Ja existe outro usuario cadastrado com este e-mail.';
                $messageType = 'error';
            } else {
                if ($newUserPassword !== '') {
                    $updateUserStmt = $pdo->prepare('UPDATE users SET name = :name, email = :email, role = :role, password_hash = :password_hash WHERE id = :id');
                    $updateUserStmt->execute([
                        ':name' => $newUserName,
                        ':email' => $newUserEmail,
                        ':role' => $newUserRole,
                        ':password_hash' => password_hash($newUserPassword, PASSWORD_DEFAULT),
                        ':id' => $targetUserId,
                    ]);
                } else {
                    $updateUserStmt = $pdo->prepare('UPDATE users SET name = :name, email = :email, role = :role WHERE id = :id');
                    $updateUserStmt->execute([
                        ':name' => $newUserName,
                        ':email' => $newUserEmail,
                        ':role' => $newUserRole,
                        ':id' => $targetUserId,
                    ]);
                }

                registerAuditLog($pdo, 'update_user', $targetUserId, 'Usuario atualizado: ' . $newUserName . ' | email=' . $newUserEmail . ' | perfil=' . $newUserRole . ($newUserPassword !== '' ? ' | senha=alterada' : ' | senha=mantida'));
                $message = 'Usuario atualizado com sucesso.';
            }
        }
    } elseif ($action === 'delete_user' && $isAdmin) {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        $currentUserId = (int) ($_SESSION['user_id'] ?? 0);

        if ($targetUserId <= 0) {
            $message = 'Usuario invalido para exclusao.';
            $messageType = 'error';
        } elseif ($targetUserId === $currentUserId) {
            $message = 'Voce nao pode excluir o proprio usuario logado.';
            $messageType = 'error';
        } else {
            $readUserStmt = $pdo->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
            $readUserStmt->execute([':id' => $targetUserId]);
            $targetUser = $readUserStmt->fetch();

            if (!$targetUser) {
                $message = 'Usuario nao encontrado.';
                $messageType = 'error';
            } else {
                $deleteUserStmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
                $deleteUserStmt->execute([':id' => $targetUserId]);
                registerAuditLog($pdo, 'delete_user', $targetUserId, 'Usuario removido: ' . (string) $targetUser['name']);
                $message = 'Usuario excluido com sucesso.';
            }
        }
    }
}

$search = trim((string) ($_GET['q'] ?? ''));
$categoryFilter = trim((string) ($_GET['categoria'] ?? ''));
$sort = trim((string) ($_GET['sort'] ?? 'created_at'));
$dir = strtolower(trim((string) ($_GET['dir'] ?? 'desc')));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 8;

$sortColumns = [
    'id' => 'id',
    'name' => 'name',
    'category' => 'category',
    'price' => 'price',
    'quantity' => 'quantity',
    'created_at' => 'created_at',
];

if (!isset($sortColumns[$sort])) {
    $sort = 'created_at';
}

if (!in_array($dir, ['asc', 'desc'], true)) {
    $dir = 'desc';
}

$categoryStmt = $pdo->query('SELECT DISTINCT category FROM products ORDER BY category ASC');
$categories = $categoryStmt->fetchAll();

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(name LIKE :search OR category LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($categoryFilter !== '') {
    $where[] = 'category = :categoryFilter';
    $params[':categoryFilter'] = $categoryFilter;
}

$sql = 'SELECT id, name, category, price, quantity, created_at FROM products';
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$countSql = 'SELECT COUNT(*) AS total FROM products';
if ($where !== []) {
    $countSql .= ' WHERE ' . implode(' AND ', $where);
}

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int) (($countStmt->fetch()['total'] ?? 0));
$totalPages = max(1, (int) ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;
$sql .= ' ORDER BY ' . $sortColumns[$sort] . ' ' . strtoupper($dir) . ' LIMIT :limit OFFSET :offset';

$productStmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $productStmt->bindValue($key, $value);
}
$productStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$productStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$productStmt->execute();
$products = $productStmt->fetchAll();

$stats = $pdo->query('SELECT
    COALESCE(SUM(quantity), 0) AS total_items,
    COALESCE(SUM(CASE WHEN quantity < 5 THEN 1 ELSE 0 END), 0) AS low_stock,
    COALESCE(SUM(price * quantity), 0) AS total_value,
    COUNT(*) AS total_products
FROM products')->fetch();

$categoryStats = $pdo->query('SELECT category, COUNT(*) AS total_products, SUM(quantity) AS total_quantity
FROM products
GROUP BY category
ORDER BY total_quantity DESC, category ASC')->fetchAll();

$recentActivity = $pdo->query('SELECT name, category, COALESCE(updated_at, created_at) AS activity_at
FROM products
ORDER BY COALESCE(updated_at, created_at) DESC
LIMIT 5')->fetchAll();

$auditLogs = $pdo->query('SELECT user_name, action, details, created_at
FROM audit_logs
ORDER BY created_at DESC
LIMIT 6')->fetchAll();

$users = [];
if ($isAdmin) {
    $users = $pdo->query('SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC, name ASC')->fetchAll();
}

$topCategory = $categoryStats[0]['category'] ?? 'Sem dados';

$lowStockProducts = $pdo->query('SELECT name, quantity FROM products WHERE quantity < 5 ORDER BY quantity ASC, name ASC LIMIT 5')->fetchAll();

$editingId = (int) ($_GET['edit'] ?? 0);
$editingUserId = (int) ($_GET['edit_usuario'] ?? 0);
$editingProduct = null;
$editingUser = null;

if ($editingId > 0) {
    $editStmt = $pdo->prepare('SELECT id, name, category, price, quantity FROM products WHERE id = :id LIMIT 1');
    $editStmt->execute([':id' => $editingId]);
    $editingProduct = $editStmt->fetch() ?: null;
}

if ($editingUserId > 0 && $isAdmin) {
    $editUserStmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = :id LIMIT 1');
    $editUserStmt->execute([':id' => $editingUserId]);
    $editingUser = $editUserStmt->fetch() ?: null;
}

$showUserModal = $isAdmin && (
    isset($_GET['novo_usuario']) ||
    $editingUser !== null ||
    (
        in_array((string) ($_POST['action'] ?? ''), ['create_user', 'update_user'], true)
        && $messageType === 'error'
    )
);

$showModal = $isAdmin && (isset($_GET['novo']) || $editingProduct !== null);

if (!$isAdmin && (isset($_GET['novo']) || $editingId > 0)) {
    $message = 'Perfil funcionario possui acesso somente de leitura.';
    $messageType = 'error';
}

function formatMoney(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

$assistantContext = [
    'total_items' => (int) ($stats['total_items'] ?? 0),
    'total_products' => (int) ($stats['total_products'] ?? 0),
    'low_stock' => (int) ($stats['low_stock'] ?? 0),
    'total_value' => formatMoney((float) ($stats['total_value'] ?? 0)),
    'top_category' => (string) $topCategory,
    'categories' => array_values(array_map(static fn (array $item): string => (string) $item['category'], $categoryStats)),
    'low_stock_products' => array_values(array_map(static fn (array $item): string => (string) $item['name'] . ' (' . (int) $item['quantity'] . ')', $lowStockProducts)),
];

$chartLabels = array_values(array_map(static fn (array $item): string => (string) $item['category'], $categoryStats));
$chartValues = array_values(array_map(static fn (array $item): int => (int) $item['total_quantity'], $categoryStats));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel | Gestor de Inventario</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="<?= ($showModal || $showUserModal) ? 'modal-open' : '' ?>">
    <main class="page-shell">
        <div class="dashboard">
            <aside class="sidebar">
                <div class="sidebar-main">
                    <div class="logo-pill">Gestao Inventario</div>

                    <div class="sidebar-user">
                        <strong><?= htmlspecialchars(currentUserName()) ?></strong>
                        <span><?= htmlspecialchars($roleLabel) ?> autenticado</span>
                    </div>

                    <nav class="menu">
                        <a class="<?= $section === 'inicio' ? 'active' : '' ?>" href="painel.php?secao=inicio">
                            <span class="menu-label">Inicio</span>
                            <small>Visao geral do estoque</small>
                        </a>
                        <?php if ($isAdmin): ?>
                        <a class="<?= $section === 'produtos' ? 'active' : '' ?>" href="painel.php?secao=produtos&novo=1">
                            <span class="menu-label">Produtos</span>
                            <small>Cadastrar e editar itens</small>
                        </a>
                        <?php else: ?>
                        <a class="<?= $section === 'produtos' ? 'active' : '' ?>" href="painel.php?secao=produtos">
                            <span class="menu-label">Produtos</span>
                            <small>Consulta de itens cadastrados</small>
                        </a>
                        <?php endif; ?>
                        <a class="<?= $section === 'categorias' ? 'active' : '' ?>" href="painel.php?secao=categorias#categoryFilters">
                            <span class="menu-label">Categorias</span>
                            <small>Filtrar por tipo de produto</small>
                        </a>
                        <?php if ($isAdmin): ?>
                        <a class="<?= $section === 'usuarios' ? 'active' : '' ?>" href="painel.php?secao=usuarios">
                            <span class="menu-label">Usuarios</span>
                            <small>Criar acessos e consultar equipe</small>
                        </a>
                        <?php endif; ?>
                    </nav>

                    <div class="sidebar-kpis">
                        <article>
                            <span>Estoque baixo</span>
                            <strong><?= (int) ($stats['low_stock'] ?? 0) ?> itens</strong>
                        </article>
                        <article>
                            <span>Valor em estoque</span>
                            <strong><?= formatMoney((float) ($stats['total_value'] ?? 0)) ?></strong>
                        </article>
                    </div>
                </div>

            </aside>

            <section class="main">
                <header class="topbar">
                    <div class="title">
                        <h1>Painel de Estoque</h1>
                        <p>Bem-vindo, <?= htmlspecialchars(currentUserName()) ?>.</p>
                        <div class="session-meta">
                            <span>Perfil: <strong><?= htmlspecialchars($roleLabel) ?></strong></span>
                            <span>Sessao: <strong id="sessionCountdown" data-seconds="<?= (int) $sessionRemainingSeconds ?>">--:--</strong></span>
                        </div>
                    </div>
                    <div class="topbar-actions">
                        <?php if ($isAdmin): ?>
                            <?php if ($section === 'usuarios'): ?>
                                <a class="btn btn-primary" href="painel.php?secao=usuarios&novo_usuario=1">+ Novo usuario</a>
                            <?php else: ?>
                                <a class="btn btn-primary" href="painel.php?secao=produtos&novo=1">+ Novo produto</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="role-badge">Perfil leitura</span>
                        <?php endif; ?>
                        <a class="btn btn-danger btn-logout-top" href="logout.php">Sair</a>
                    </div>
                </header>

                <?php if ($isAdmin && $section === 'usuarios'): ?>
                    <section class="panel users-panel">
                        <div class="panel-tools users-panel-tools">
                            <div>
                                <h3 class="chart-title">Usuarios cadastrados</h3>
                                <p class="users-subtitle">Gerencie acessos administrativos e perfis da equipe.</p>
                            </div>
                            <a class="btn btn-primary" href="painel.php?secao=usuarios&novo_usuario=1">Cadastrar usuario</a>
                        </div>

                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nome</th>
                                        <th>E-mail</th>
                                        <th>Perfil</th>
                                        <th>Data de cadastro</th>
                                        <th>Acao</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($users === []): ?>
                                        <tr>
                                            <td colspan="6">Nenhum usuario cadastrado.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($users as $user): ?>
                                            <tr>
                                                <td><?= (int) $user['id'] ?></td>
                                                <td><?= htmlspecialchars((string) $user['name']) ?></td>
                                                <td><?= htmlspecialchars((string) $user['email']) ?></td>
                                                <td><span class="user-role-chip <?= (string) $user['role'] === 'admin' ? 'role-admin' : 'role-funcionario' ?>"><?= htmlspecialchars(formatRoleLabel((string) $user['role'])) ?></span></td>
                                                <td><?= date('d/m/Y H:i', strtotime((string) $user['created_at'])) ?></td>
                                                <td>
                                                    <div class="table-actions">
                                                        <a class="btn btn-warning" href="painel.php?secao=usuarios&edit_usuario=<?= (int) $user['id'] ?>">Editar</a>
                                                        <?php if ((int) $user['id'] !== (int) ($_SESSION['user_id'] ?? 0)): ?>
                                                            <form method="post" action="painel.php?secao=usuarios" onsubmit="return confirm('Deseja excluir este usuario?');">
                                                                <input type="hidden" name="action" value="delete_user">
                                                                <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                                                <button class="btn btn-danger" type="submit">Excluir</button>
                                                            </form>
                                                        <?php else: ?>
                                                            <span class="table-view-only">Usuario atual</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if ($section !== 'usuarios'): ?>
                <div class="summary-grid">
                    <article class="summary-card summary-total">
                        <strong>Total de Itens</strong>
                        <span><?= (int) ($stats['total_items'] ?? 0) ?></span>
                    </article>
                    <article class="summary-card summary-low">
                        <strong>Itens com Estoque Baixo</strong>
                        <span><?= (int) ($stats['low_stock'] ?? 0) ?></span>
                    </article>
                    <article class="summary-card summary-value">
                        <strong>Valor Total em Estoque</strong>
                        <span><?= formatMoney((float) ($stats['total_value'] ?? 0)) ?></span>
                    </article>
                    <article class="summary-card summary-products">
                        <strong>Total de Produtos</strong>
                        <span><?= (int) ($stats['total_products'] ?? 0) ?></span>
                    </article>
                </div>

                <section class="insights-grid">
                    <article class="insight-card">
                        <h3>Categoria em destaque</h3>
                        <p><?= htmlspecialchars((string) $topCategory) ?></p>
                    </article>
                    <article class="insight-card">
                        <h3>Produtos com estoque critico</h3>
                        <ul>
                            <?php if ($lowStockProducts === []): ?>
                                <li>Nenhum item com estoque critico.</li>
                            <?php else: ?>
                                <?php foreach ($lowStockProducts as $critical): ?>
                                    <li><?= htmlspecialchars((string) $critical['name']) ?> (<?= (int) $critical['quantity'] ?>)</li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </article>
                    <article class="insight-card">
                        <h3>Distribuicao por categoria</h3>
                        <ul>
                            <?php if ($categoryStats === []): ?>
                                <li>Sem dados para exibir.</li>
                            <?php else: ?>
                                <?php foreach ($categoryStats as $item): ?>
                                    <li><?= htmlspecialchars((string) $item['category']) ?>: <?= (int) $item['total_quantity'] ?> itens</li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </article>
                    <article class="insight-card">
                        <h3>Atividade recente</h3>
                        <ul>
                            <?php if ($recentActivity === []): ?>
                                <li>Nenhuma atividade registrada.</li>
                            <?php else: ?>
                                <?php foreach ($recentActivity as $activity): ?>
                                    <li><?= htmlspecialchars((string) $activity['name']) ?> - <?= date('d/m H:i', strtotime((string) $activity['activity_at'])) ?></li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </article>
                    <article class="insight-card">
                        <h3>Auditoria de acoes</h3>
                        <ul>
                            <?php if ($auditLogs === []): ?>
                                <li>Nenhum evento de auditoria.</li>
                            <?php else: ?>
                                <?php foreach ($auditLogs as $log): ?>
                                    <li><?= htmlspecialchars((string) $log['user_name']) ?> - <?= htmlspecialchars((string) $log['action']) ?> (<?= date('d/m H:i', strtotime((string) $log['created_at'])) ?>)</li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </article>
                </section>

                <section class="panel chart-panel">
                    <h3 class="chart-title">Itens por categoria</h3>
                    <div class="chart-wrap">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </section>

                <section class="panel">
                    <div class="panel-tools">
                        <form class="search-form" method="get" action="painel.php">
                            <input type="hidden" name="secao" value="<?= htmlspecialchars($section) ?>">
                            <?php if ($categoryFilter !== ''): ?>
                                <input type="hidden" name="categoria" value="<?= htmlspecialchars($categoryFilter) ?>">
                            <?php endif; ?>
                            <input type="text" name="q" placeholder="Buscar por nome ou categoria" value="<?= htmlspecialchars($search) ?>">
                            <button class="btn btn-soft" type="submit">Filtrar</button>
                            <a class="btn btn-soft" href="painel.php">Limpar</a>
                        </form>

                        <div class="category-filters" id="categoryFilters">
                            <?php
                                $baseAll = ['secao' => 'categorias'];
                                if ($search !== '') {
                                    $baseAll['q'] = $search;
                                }
                            ?>
                            <a class="chip <?= $categoryFilter === '' ? 'active' : '' ?>" href="painel.php?<?= http_build_query($baseAll) ?>#categoryFilters">Tudo</a>
                            <?php foreach ($categories as $cat): ?>
                                <?php $catName = (string) $cat['category']; ?>
                                <?php
                                    $query = ['secao' => 'categorias', 'categoria' => $catName];
                                    if ($search !== '') {
                                        $query['q'] = $search;
                                    }
                                ?>
                                <a class="chip <?= $categoryFilter === $catName ? 'active' : '' ?>" href="painel.php?<?= http_build_query($query) ?>#categoryFilters"><?= htmlspecialchars($catName) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($message !== ''): ?>
                        <div class="alert <?= $messageType === 'error' ? 'alert-error' : 'alert-success' ?>"><?= htmlspecialchars($message) ?></div>
                    <?php endif; ?>

                    <div class="table-wrap">
                        <?php
                            $baseQuery = [
                                'secao' => $section,
                                'q' => $search,
                                'categoria' => $categoryFilter,
                                'sort' => $sort,
                                'dir' => $dir,
                            ];
                            $makeSortLink = static function (string $column) use ($baseQuery, $sort, $dir): string {
                                $nextDir = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';
                                $query = $baseQuery;
                                $query['sort'] = $column;
                                $query['dir'] = $nextDir;
                                $query['page'] = 1;
                                return 'painel.php?' . http_build_query($query);
                            };
                        ?>
                        <table>
                            <thead>
                                <tr>
                                    <th><a class="th-sort" href="<?= htmlspecialchars($makeSortLink('id')) ?>">ID</a></th>
                                    <th><a class="th-sort" href="<?= htmlspecialchars($makeSortLink('name')) ?>">Nome Produto</a></th>
                                    <th><a class="th-sort" href="<?= htmlspecialchars($makeSortLink('category')) ?>">Categoria</a></th>
                                    <th><a class="th-sort" href="<?= htmlspecialchars($makeSortLink('price')) ?>">Preco</a></th>
                                    <th><a class="th-sort" href="<?= htmlspecialchars($makeSortLink('quantity')) ?>">Quantidade</a></th>
                                    <th><a class="th-sort" href="<?= htmlspecialchars($makeSortLink('created_at')) ?>">Data de Cadastro</a></th>
                                    <th>Acao</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($products === []): ?>
                                    <tr>
                                        <td colspan="7">Nenhum produto encontrado.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($products as $product): ?>
                                        <?php $isLow = (int) $product['quantity'] < 5; ?>
                                        <tr class="<?= $isLow ? 'low-stock' : '' ?>">
                                            <td><?= (int) $product['id'] ?></td>
                                            <td><?= htmlspecialchars((string) $product['name']) ?></td>
                                            <td><?= htmlspecialchars((string) $product['category']) ?></td>
                                            <td><?= formatMoney((float) $product['price']) ?></td>
                                            <td><?= (int) $product['quantity'] ?></td>
                                            <td><?= date('d/m/Y H:i', strtotime((string) $product['created_at'])) ?></td>
                                            <td>
                                                <?php if ($isAdmin): ?>
                                                <div class="table-actions">
                                                    <a class="btn btn-warning" href="painel.php?edit=<?= (int) $product['id'] ?>">Editar</a>
                                                    <form method="post" action="painel.php" onsubmit="return confirm('Deseja excluir este produto?');">
                                                        <input type="hidden" name="action" value="delete_product">
                                                        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                                        <button class="btn btn-danger" type="submit">Excluir</button>
                                                    </form>
                                                    <form method="post" action="painel.php">
                                                        <input type="hidden" name="action" value="adjust_stock">
                                                        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                                        <input type="hidden" name="delta" value="-1">
                                                        <button class="btn btn-soft btn-qty" type="submit">-1</button>
                                                    </form>
                                                    <form method="post" action="painel.php">
                                                        <input type="hidden" name="action" value="adjust_stock">
                                                        <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                                        <input type="hidden" name="delta" value="1">
                                                        <button class="btn btn-soft btn-qty" type="submit">+1</button>
                                                    </form>
                                                </div>
                                                <?php else: ?>
                                                    <span class="table-view-only">Somente leitura</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="helper-row">
                        <span>Linhas em vermelho indicam menos de 5 unidades.</span>
                        <span>Exibindo <?= count($products) ?> de <?= (int) $totalRows ?> registros</span>
                    </div>

                    <div class="pagination">
                        <?php
                            $prevPage = max(1, $page - 1);
                            $nextPage = min($totalPages, $page + 1);
                            $pageQuery = $baseQuery;
                        ?>
                        <?php $pageQuery['page'] = $prevPage; ?>
                        <a class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : htmlspecialchars('painel.php?' . http_build_query($pageQuery)) ?>">Anterior</a>
                        <span class="page-indicator">Pagina <?= (int) $page ?> de <?= (int) $totalPages ?></span>
                        <?php $pageQuery['page'] = $nextPage; ?>
                        <a class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : htmlspecialchars('painel.php?' . http_build_query($pageQuery)) ?>">Proxima</a>
                    </div>
                </section>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <div class="modal <?= $showModal ? 'show' : '' ?>" id="productModal">
        <div class="modal-card">
            <div class="modal-head">
                <h3><?= $editingProduct ? 'Editar Produto' : 'Novo Produto' ?></h3>
                <a class="btn btn-soft" href="painel.php">X</a>
            </div>

            <form method="post" action="painel.php">
                <input type="hidden" name="action" value="save_product">
                <input type="hidden" name="id" value="<?= (int) ($editingProduct['id'] ?? 0) ?>">

                <div class="field">
                    <label for="name">Nome</label>
                    <input type="text" id="name" name="name" required value="<?= htmlspecialchars((string) ($editingProduct['name'] ?? '')) ?>">
                </div>

                <div class="field">
                    <label for="category">Categoria</label>
                    <select id="category" name="category" required>
                        <?php
                            $categoryOptions = ['Mouses', 'Teclados', 'Monitores', 'Notebooks', 'Cabos', 'Acessorios'];
                            $selectedCategory = (string) ($editingProduct['category'] ?? '');
                        ?>
                        <option value="">Selecione uma categoria</option>
                        <?php foreach ($categoryOptions as $option): ?>
                            <option value="<?= $option ?>" <?= $selectedCategory === $option ? 'selected' : '' ?>><?= $option ?></option>
                        <?php endforeach; ?>
                        <?php if ($selectedCategory !== '' && !in_array($selectedCategory, $categoryOptions, true)): ?>
                            <option value="<?= htmlspecialchars($selectedCategory) ?>" selected><?= htmlspecialchars($selectedCategory) ?></option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="two-cols">
                    <div class="field">
                        <label for="price">Preco</label>
                        <input type="number" id="price" name="price" required min="0" step="0.01" value="<?= htmlspecialchars((string) ($editingProduct['price'] ?? '')) ?>">
                    </div>
                    <div class="field">
                        <label for="quantity">Quantidade inicial</label>
                        <input type="number" id="quantity" name="quantity" required min="0" step="1" value="<?= htmlspecialchars((string) ($editingProduct['quantity'] ?? '0')) ?>">
                    </div>
                </div>

                <div class="actions">
                    <a class="btn btn-soft" href="painel.php">Cancelar</a>
                    <button class="btn btn-primary" type="submit">Salvar produto</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal <?= $showUserModal ? 'show' : '' ?>" id="userModal">
        <div class="modal-card">
            <div class="modal-head">
                <h3><?= $editingUser ? 'Editar usuario' : 'Novo usuario' ?></h3>
                <a class="btn btn-soft" href="painel.php?secao=usuarios">X</a>
            </div>

            <form method="post" action="painel.php?secao=usuarios">
                <?php
                    $isUserUpdate = $editingUser !== null;
                    $postedAction = (string) ($_POST['action'] ?? '');
                    $userFormName = (string) ($_POST['user_name'] ?? ($editingUser['name'] ?? ''));
                    $userFormEmail = (string) ($_POST['user_email'] ?? ($editingUser['email'] ?? ''));
                    $userFormRole = (string) ($_POST['user_role'] ?? ($editingUser['role'] ?? 'funcionario'));
                    $userFormId = (int) ($_POST['user_id'] ?? ($editingUser['id'] ?? 0));

                    if ($postedAction === 'create_user' && $messageType === 'error') {
                        $isUserUpdate = false;
                    }
                ?>
                <input type="hidden" name="action" value="<?= $isUserUpdate ? 'update_user' : 'create_user' ?>">
                <?php if ($isUserUpdate): ?>
                    <input type="hidden" name="user_id" value="<?= $userFormId ?>">
                <?php endif; ?>

                <div class="field">
                    <label for="user_name">Nome</label>
                    <input type="text" id="user_name" name="user_name" required value="<?= htmlspecialchars($userFormName) ?>">
                </div>

                <div class="field">
                    <label for="user_email">E-mail</label>
                    <input type="email" id="user_email" name="user_email" required value="<?= htmlspecialchars($userFormEmail) ?>">
                </div>

                <div class="two-cols">
                    <div class="field">
                        <label for="user_password">Senha <?= $isUserUpdate ? '(opcional para manter atual)' : '' ?></label>
                        <input type="password" id="user_password" name="user_password" <?= $isUserUpdate ? '' : 'required' ?> minlength="6">
                    </div>
                    <div class="field">
                        <label for="user_role">Nivel de acesso</label>
                        <select id="user_role" name="user_role" required>
                            <option value="funcionario" <?= $userFormRole === 'funcionario' ? 'selected' : '' ?>>Funcionario</option>
                            <option value="admin" <?= $userFormRole === 'admin' ? 'selected' : '' ?>>Administrador</option>
                        </select>
                    </div>
                </div>

                <div class="actions">
                    <a class="btn btn-soft" href="painel.php?secao=usuarios">Cancelar</a>
                    <button class="btn btn-primary" type="submit"><?= $isUserUpdate ? 'Salvar alteracoes' : 'Criar usuario' ?></button>
                </div>
            </form>
        </div>
    </div>

    <button class="assistant-fab" id="assistantToggle" type="button" aria-label="Abrir assistente Luigi">
        <img src="assets/images/Imagem.png" alt="Assistente Luigi">
        <span>Luigi</span>
    </button>

    <section class="assistant-panel" id="assistantPanel" aria-hidden="true">
        <header>
            <h3>Luigi - Assistente</h3>
            <button type="button" id="assistantClose">X</button>
        </header>
        <div class="assistant-messages" id="assistantMessages">
            <div class="assistant-msg bot">Oi, eu sou o Luigi. Posso ajudar com estoque, categorias, metricas e boas praticas do painel.</div>
        </div>
        <div class="assistant-suggestions" id="assistantSuggestions">
            <button type="button" class="assistant-suggestion" data-question="Ola">Ola</button>
            <button type="button" class="assistant-suggestion" data-question="Qual o total de itens?">Total de itens</button>
            <button type="button" class="assistant-suggestion" data-question="Quais categorias existem?">Categorias</button>
            <button type="button" class="assistant-suggestion" data-question="Como evitar estoque critico?">Evitar estoque critico</button>
            <button type="button" class="assistant-suggestion" data-question="Quem desenvolveu o sistema?">Quem desenvolveu?</button>
        </div>
        <form id="assistantForm" class="assistant-form">
            <input type="text" id="assistantInput" placeholder="Ex: qual categoria tem mais itens?" required>
            <button class="btn btn-primary" type="submit">Enviar</button>
        </form>
    </section>

    <script>
        (function () {
            const context = <?= json_encode($assistantContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const loggedUserName = <?= json_encode((string) currentUserName(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const chartLabels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const chartValues = <?= json_encode($chartValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const countdownEl = document.getElementById('sessionCountdown');
            const toggle = document.getElementById('assistantToggle');
            const panel = document.getElementById('assistantPanel');
            const closeBtn = document.getElementById('assistantClose');
            const form = document.getElementById('assistantForm');
            const input = document.getElementById('assistantInput');
            const messages = document.getElementById('assistantMessages');
            const suggestions = document.getElementById('assistantSuggestions');
            const suggestionButtons = document.querySelectorAll('.assistant-suggestion');
            const initialAssistantMessage = 'Oi, ' + loggedUserName + '. Eu sou o Luigi e posso ajudar com estoque, categorias, metricas e boas praticas do painel.';

            function formatCountdown(totalSeconds) {
                const minutes = Math.floor(totalSeconds / 60);
                const seconds = totalSeconds % 60;
                return String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
            }

            if (countdownEl) {
                let remaining = Number.parseInt(countdownEl.dataset.seconds || '0', 10);
                if (!Number.isFinite(remaining) || remaining < 0) {
                    remaining = 0;
                }

                countdownEl.textContent = formatCountdown(remaining);

                const timerId = window.setInterval(function () {
                    remaining -= 1;

                    if (remaining <= 0) {
                        countdownEl.textContent = '00:00';
                        window.clearInterval(timerId);
                        window.location.href = 'logout.php?reason=timeout';
                        return;
                    }

                    countdownEl.textContent = formatCountdown(remaining);
                }, 1000);
            }

            const chartEl = document.getElementById('categoryChart');
            if (chartEl && Array.isArray(chartLabels) && chartLabels.length > 0 && typeof Chart !== 'undefined') {
                new Chart(chartEl, {
                    type: 'bar',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            label: 'Quantidade em estoque',
                            data: chartValues,
                            backgroundColor: ['#0b123f', '#2e8ae6', '#77c8c6', '#ff9e1b', '#5b8db4', '#9bb9d9'],
                            borderRadius: 8,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                        },
                        scales: {
                            y: { beginAtZero: true, ticks: { precision: 0 } },
                        },
                    },
                });
            }

            function addMessage(text, type) {
                const div = document.createElement('div');
                div.className = 'assistant-msg ' + type;
                div.textContent = text;
                messages.appendChild(div);
                messages.scrollTop = messages.scrollHeight;
            }

            function resetAssistant() {
                messages.innerHTML = '';
                addMessage(initialAssistantMessage, 'bot');
                suggestions.classList.remove('show');
                input.value = '';
            }

            function submitQuestion(question) {
                const trimmed = question.trim();
                if (!trimmed) {
                    return;
                }

                addMessage(trimmed, 'user');
                addMessage(answer(trimmed), 'bot');
                input.value = '';
                input.focus();
            }

            function answer(question) {
                const q = question.toLowerCase();

                if (q.includes('ola') || q.includes('olá') || q.includes('oi') || q.includes('e ai') || q.includes('e aí')) {
                    return 'Ola, ' + loggedUserName + '! Eu sou o Luigi. Se quiser, posso te mostrar dados do estoque ou te orientar sobre como evitar itens criticos.';
                }

                if (q.includes('bom dia')) {
                    return 'Bom dia, ' + loggedUserName + '! Bora organizar esse estoque. Posso te informar metricas, categorias e riscos de estoque baixo.';
                }

                if (q.includes('boa tarde')) {
                    return 'Boa tarde, ' + loggedUserName + '! Se quiser, posso resumir o estado atual do estoque e apontar itens que merecem atencao.';
                }

                if (q.includes('boa noite')) {
                    return 'Boa noite, ' + loggedUserName + '! Posso te ajudar com um panorama rapido do inventario antes de encerrar o dia.';
                }

                if (q.includes('tudo bem') || q.includes('como voce esta') || q.includes('como você está')) {
                    return 'Tudo certo por aqui, ' + loggedUserName + '. Estou de olho no estoque e pronto para ajudar.';
                }

                if (q.includes('quem e voce') || q.includes('quem é você') || q.includes('quem e vc') || q.includes('quem é vc')) {
                    return 'Sou o Luigi, seu assistente do painel. Eu respondo perguntas com base nos dados exibidos no sistema.';
                }

                if (q.includes('quem te criou') || q.includes('quem te desenvolveu') || q.includes('quem criou voce') || q.includes('quem criou você') || q.includes('quem desenvolveu o sistema') || q.includes('quem fez o sistema')) {
                    return 'Este sistema foi desenvolvido por Patrick Souza, e eu faco parte da experiencia como assistente virtual do painel.';
                }

                if ((q.includes('como') || q.includes('fazer')) && (q.includes('estoque') && (q.includes('critico') || q.includes('baixo')))) {
                    return 'Para evitar estoque critico, acompanhe os itens com menos de 5 unidades, defina uma quantidade minima por produto, revise os mais vendidos com frequencia e reponha antes da ruptura. Aqui no painel, vale monitorar os alertas em vermelho e a lista de estoque critico.';
                }

                if (q.includes('dica') || q.includes('melhorar estoque') || q.includes('organizar estoque')) {
                    return 'Uma boa rotina e: revisar categorias com mais saida, acompanhar produtos em alerta, registrar entradas e saidas rapidamente e manter reposicao planejada para os itens mais importantes.';
                }

                if (q.includes('o que voce faz') || q.includes('o que você faz') || q.includes('como pode ajudar')) {
                    return 'Posso responder sobre total de itens, produtos cadastrados, valor em estoque, categorias, estoque baixo e tambem dar orientacoes simples de operacao.';
                }

                if (q.includes('total') && q.includes('itens')) {
                    return 'Atualmente o estoque tem ' + context.total_items + ' itens no total.';
                }

                if (q.includes('baixo') || q.includes('critico')) {
                    if (context.low_stock === 0) {
                        return 'Boa noticia: nao ha produtos com estoque baixo neste momento.';
                    }

                    const names = context.low_stock_products.length > 0 ? context.low_stock_products.join(', ') : 'sem detalhes.';
                    return 'Temos ' + context.low_stock + ' produtos com estoque baixo. Principais: ' + names + '.';
                }

                if (q.includes('categoria') && (q.includes('mais') || q.includes('destaque'))) {
                    return 'A categoria com mais itens no momento e ' + context.top_category + '.';
                }

                if (q.includes('valor')) {
                    return 'O valor total estimado em estoque e ' + context.total_value + '.';
                }

                if (q.includes('categorias')) {
                    return 'Categorias cadastradas: ' + (context.categories.length ? context.categories.join(', ') : 'nenhuma ainda.') + '.';
                }

                if (q.includes('produtos')) {
                    return 'No total existem ' + context.total_products + ' produtos cadastrados.';
                }

                if (q.includes('ajuda') || q.includes('socorro') || q.includes('me ajuda')) {
                    return 'Claro. Tente me perguntar coisas como: total de itens, valor do estoque, categorias cadastradas, estoque baixo ou como evitar estoque critico.';
                }

                if (q.includes('obrigado') || q.includes('valeu')) {
                    return 'Por nada! Se precisar, continuo por aqui acompanhando o painel com voce.';
                }

                return 'Posso ajudar com: saudacoes, total de itens, estoque baixo, valor total, categorias, produtos cadastrados e dicas para evitar estoque critico.';
            }

            toggle.addEventListener('click', function () {
                panel.classList.toggle('show');
                panel.setAttribute('aria-hidden', panel.classList.contains('show') ? 'false' : 'true');
                if (panel.classList.contains('show')) {
                    suggestions.classList.add('show');
                    input.focus();
                }
            });

            closeBtn.addEventListener('click', function () {
                panel.classList.remove('show');
                panel.setAttribute('aria-hidden', 'true');
                resetAssistant();
            });

            messages.addEventListener('click', function () {
                suggestions.classList.toggle('show');
            });

            input.addEventListener('focus', function () {
                suggestions.classList.add('show');
            });

            suggestionButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    submitQuestion(button.dataset.question || '');
                });
            });

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                const question = input.value.trim();
                submitQuestion(question);
            });
        })();
    </script>
</body>
</html>
