<?php
declare(strict_types=1);

/**
 * session_init.php — inicializa a sessão de forma segura.
 * Só configura e inicia se a sessão ainda NÃO estiver ativa.
 */

if (session_status() === PHP_SESSION_ACTIVE) {
    return; // Sessão já iniciada — não mexe mais
}

// Configuração — SÓ antes do session_start()
session_name('COURTIQSESSID');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => false,      // true se HTTPS
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();