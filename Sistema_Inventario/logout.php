<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$reason = (string) ($_GET['reason'] ?? 'logout');
$status = $reason === 'timeout' ? 'timeout' : 'logout';

destroySessionAndCookies();

header('Location: login.php?status=' . urlencode($status));
exit;
