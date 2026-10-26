<?php
declare(strict_types=1);

/**
 * layout/admin_top.php — topo das páginas do painel de administração do clube.
 * Aqui só se define o menu deste painel; a moldura é a mesma em todo o sistema:
 * head.php, sidebar.php e navbar.php (e footer.php no fim, via admin_bottom.php).
 */

require_once __DIR__ . '/../team_helper.php';

$layout = [
    'panel'       => 'admin',
    'base'        => '../',
    'title'       => 'Admin',
    'brandSub'    => 'Administração',
    'notifHref'   => 'notificacoes.php',
    'chatHref'    => 'chat.php',
    'accountHref' => 'configuracoes.php',
    'sections'    => [
        'Principal' => [
            'dashboard'           => ['href' => 'dashboard.php',            'icon' => 'fa-solid fa-gauge-high',               'label' => 'Dashboard'],
            'jogadores'           => ['href' => 'jogadores.php',            'icon' => 'fa-solid fa-user-group',               'label' => 'Jogadores'],
            'jogos'               => ['href' => 'jogos.php',                'icon' => 'fa-solid fa-trophy',                   'label' => 'Jogos'],
            'estatisticas'        => ['href' => 'estatisticas.php',         'icon' => 'fa-solid fa-chart-column',             'label' => 'Estatísticas'],
        ],
        'Gestão' => [
            'equipa'              => ['href' => 'equipas.php',              'icon' => 'fa-solid fa-building-shield',          'label' => 'Equipa'],
            'pedidos'             => ['href' => 'pedidos.php',              'icon' => 'fa-solid fa-user-clock',               'label' => 'Pedidos de inscrição'],
            'treinos'             => ['href' => 'treinos.php',              'icon' => 'fa-solid fa-bullseye',                 'label' => 'Treinos'],
            'presencas'           => ['href' => 'presencas.php',            'icon' => 'fa-solid fa-clipboard-check',          'label' => 'Presenças'],
            'financas'            => ['href' => 'financas.php',             'icon' => 'fa-solid fa-sack-dollar',              'label' => 'Finanças'],
            'relatorios'          => ['href' => 'relatorios.php',           'icon' => 'fa-solid fa-file-lines',               'label' => 'Relatórios'],
        ],
        'Site público' => [
            'noticias'            => ['href' => 'noticias.php',             'icon' => 'fa-solid fa-newspaper',                'label' => 'Notícias'],
            'galeria'             => ['href' => 'galeria.php',              'icon' => 'fa-solid fa-images',                   'label' => 'Galeria'],
            'patrocinadores'      => ['href' => 'patrocinadores.php',       'icon' => 'fa-solid fa-handshake',                'label' => 'Patrocinadores'],
            'bilhetes'            => ['href' => 'bilhetes.php',             'icon' => 'fa-solid fa-ticket',                   'label' => 'Bilhetes'],
        ],
        'Comunicação' => [
            'notificacoes'        => ['href' => 'notificacoes.php',         'icon' => 'fa-solid fa-bell',                     'label' => 'Notificações'],
            'chat'                => ['href' => 'chat.php',                 'icon' => 'fa-solid fa-comments',                 'label' => 'Chat'],
        ],
        'Sistema' => [

            'configuracoes'       => ['href' => 'configuracoes.php',        'icon' => 'fa-solid fa-gear',                     'label' => 'Configurações'],
        ],
    ],
];

// o superadministrador pode entrar neste painel e escolher o clube que quer gerir
if (TeamHelper::isSuperadmin()) {
    $layout['teamSwitch'] = ['label' => 'Clube (superadmin)', 'teams' => public_teams(), 'current' => (int) (current_team_id() ?? 0)];
}

require __DIR__ . '/head.php';
require __DIR__ . '/sidebar.php';
require __DIR__ . '/navbar.php';
