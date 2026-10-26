<?php
declare(strict_types=1);

/**
 * bootstrap.php — ponto de entrada único para qualquer página do sistema.
 * Carrega tudo pela ordem certa: sessão -> helpers -> BD -> autenticação.
 * Todas as páginas (protegidas ou públicas) devem começar com:
 *     require_once __DIR__ . '/../includes/bootstrap.php';
 */

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/flash.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/lang.php';

lang_handle_switch();   // ?lang=en|pt em qualquer página muda o idioma do sistema
