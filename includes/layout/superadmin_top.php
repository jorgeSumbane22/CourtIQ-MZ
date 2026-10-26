<?php
declare(strict_types=1);

/**
 * layout/superadmin_top.php — topo das páginas do superadministrador.
 * Aqui só se define o menu deste painel; a moldura é a mesma em todo o sistema:
 * head.php, sidebar.php e navbar.php (e footer.php no fim, via superadmin_bottom.php).
 */

$layout = [
    'panel'       => 'superadmin',
    'base'        => '../',
    'title'       => 'Super Admin',
    'brandSub'    => 'Super Admin',
    'notifHref'   => 'notificacoes.php',
    'chatHref'    => 'chat.php',
    'accountHref' => 'perfil.php',
    'sections'    => [
        'Principal' => [
            'dashboard'           => ['href' => 'dashboard.php',            'icon' => 'fa-solid fa-gauge-high',               'label' => 'Dashboard'],
            'equipas'             => ['href' => 'equipas.php',              'icon' => 'fa-solid fa-building-shield',          'label' => 'Equipas'],
            'pedidos-clubes'      => ['href' => 'pedidos-clubes.php',       'icon' => 'fa-solid fa-building-circle-check',    'label' => 'Pedidos de clubes'],
            'utilizadores'        => ['href' => 'utilizadores.php',         'icon' => 'fa-solid fa-users',                    'label' => 'Utilizadores'],
        ],
        'Manutenção' => [
            'backup-global'       => ['href' => 'backup-global.php',        'icon' => 'fa-solid fa-database',                 'label' => 'Backup'],
            'manutencao'          => ['href' => 'manutencao.php',           'icon' => 'fa-solid fa-screwdriver-wrench',       'label' => 'Manutenção'],
            'metricas-sistema'    => ['href' => 'metricas-sistema.php',     'icon' => 'fa-solid fa-chart-line',               'label' => 'Métricas'],
            'tickets'             => ['href' => 'tickets.php',              'icon' => 'fa-solid fa-ticket',                   'label' => 'Tickets'],
        ],
        'Comunicação' => [
            'chat'                => ['href' => 'chat.php',                 'icon' => 'fa-solid fa-comments',                 'label' => 'Chat'],
            'notificacoes'        => ['href' => 'notificacoes.php',         'icon' => 'fa-solid fa-bell',                     'label' => 'Notificações'],
        ],
        'Sistema' => [
            'perfil'              => ['href' => 'perfil.php',              'icon' => 'fa-solid fa-user-gear',                'label' => 'O meu perfil'],

            'auditoria'           => ['href' => 'auditoria.php',            'icon' => 'fa-solid fa-clipboard-list',           'label' => 'Auditoria'],
            'config-gerais'       => ['href' => 'config-gerais.php',        'icon' => 'fa-solid fa-gear',                     'label' => 'Configurações'],

            'relatorios-globais'  => ['href' => 'relatorios-globais.php',   'icon' => 'fa-solid fa-chart-pie',                'label' => 'Relatórios'],
        ],
    ],
];

require __DIR__ . '/head.php';
require __DIR__ . '/sidebar.php';
require __DIR__ . '/navbar.php';
