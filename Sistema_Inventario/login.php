<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) {
    header('Location: painel.php');
    exit;
}

$error = '';
$status = (string) ($_GET['status'] ?? '');

if ($status === 'timeout') {
    $error = 'Sua sessao expirou por inatividade. Entre novamente.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Preencha e-mail e senha.';
    } else {
        $stmt = db()->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, (string) $user['password_hash'])) {
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_name'] = (string) $user['name'];
            $_SESSION['user_email'] = (string) $user['email'];
            $_SESSION['user_role'] = (string) ($user['role'] ?? 'funcionario');
            $_SESSION['login_at'] = time();
            $_SESSION['last_activity_at'] = time();

            header('Location: painel.php');
            exit;
        }

        $error = 'Credenciais invalidas.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Gestor de Inventario</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <main class="page-shell">
        <section class="auth-wrap">
            <div class="auth-right auth-login-side">
                <form class="login-card" method="post" action="login.php">
                    <span class="brand-chip brand-chip-login">Gestor de Inventario</span>
                    <h2>Entrar na conta</h2>
                    <p class="subtle">Acesso rapido para operacao diaria do estoque.</p>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <div class="field">
                        <label for="email">E-mail</label>
                        <input type="email" id="email" name="email" placeholder="admin@inventario.com" value="<?= htmlspecialchars((string) ($_POST['email'] ?? '')) ?>" required>
                    </div>

                    <div class="field">
                        <label for="password">Senha</label>
                        <input type="password" id="password" name="password" placeholder="Digite sua senha" required>
                    </div>

                    <button class="btn btn-primary" type="submit" style="width:100%;">Entrar</button>
                    <button class="btn btn-soft" type="button" id="forgotPasswordBtn" style="width:100%; margin-top:8px;">Esqueci minha senha</button>

                    <div class="demo-box" id="demoCredentials">
                        Admin: admin@inventario.com | 123456<br>
                        Funcionario: funcionario@inventario.com | 123456
                    </div>
                </form>
            </div>

            <aside class="auth-left auth-visual-side">
                <h1>Controle de produtos, categorias e estoque em um unico painel.</h1>
                <p>Entre com sua conta para gerenciar o inventario com seguranca e acompanhar os indicadores em tempo real.</p>
                <img class="auth-hero" src="assets/images/Imagem_login.png" alt="Ilustracao de controle de inventario">
            </aside>
        </section>

        <footer class="login-footer">Feito por Patrick Souza</footer>
    </main>

    <script>
        (function () {
            const forgotBtn = document.getElementById('forgotPasswordBtn');
            const demoCredentials = document.getElementById('demoCredentials');

            if (!forgotBtn || !demoCredentials) {
                return;
            }

            forgotBtn.addEventListener('click', function () {
                const confirmed = window.confirm('Deseja recuperar a senha do ambiente de demonstracao?');
                if (!confirmed) {
                    return;
                }

                demoCredentials.style.outline = '2px solid #2e8ae6';
                demoCredentials.style.outlineOffset = '2px';
                demoCredentials.scrollIntoView({ behavior: 'smooth', block: 'center' });
                window.alert('Use as credenciais abaixo para entrar no sistema.');
            });
        })();
    </script>
</body>
</html>
