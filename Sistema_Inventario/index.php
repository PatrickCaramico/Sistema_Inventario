<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

if (isLoggedIn()) {
	header('Location: painel.php');
	exit;
}

header('Location: login.php');
exit;
