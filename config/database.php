<?php
/**
 * Configuração de ligação à base de dados.
 * Ajuste estes valores conforme o seu ambiente (XAMPP por omissão: root sem password).
 *
 * IMPORTANTE: Este ficheiro nunca deve ser acessível diretamente pelo browser.
 * O .htaccess na pasta /config já bloqueia isso.
 */
return [
    'host'    => 'localhost',
    'port'    => '3306',
    // COURTIQ_DB_NAME permite apontar para outra base (ex.: testes) sem editar este ficheiro
    'dbname'  => getenv('COURTIQ_DB_NAME') ?: 'courtiq',
    'user'    => 'root',
    'pass'    => '',
    'charset' => 'utf8mb4',
];
