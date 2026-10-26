<?php
declare(strict_types=1);

/**
 * layout/treinador_top.php — topo das páginas do painel do treinador.
 * Aqui só se define o menu deste painel; a moldura é a mesma em todo o sistema:
 * head.php, sidebar.php e navbar.php (e footer.php no fim, via treinador_bottom.php).
 */

$layout = [
    'panel'       => 'treinador',
    'base'        => '../',
    'title'       => 'Treinador',
    'brandSub'    => 'Treinador',
    'notifHref'   => 'notificacoes.php',
    'chatHref'    => 'chat.php',
    'accountHref' => 'configuracoes.php',
    'sections'    => [
        'Principal' => [
            'dashboard'           => ['href' => 'dashboard.php',            'icon' => 'fa-solid fa-gauge-high',               'label' => 'Dashboard'],
            'plantel'             => ['href' => 'plantel.php',              'icon' => 'fa-solid fa-user-group',               'label' => 'Plantel'],
            'pedidos'             => ['href' => 'pedidos.php',              'icon' => 'fa-solid fa-user-clock',               'label' => 'Pedidos de inscrição'],
            'estatisticas'        => ['href' => 'estatisticas.php',         'icon' => 'fa-solid fa-chart-column',             'label' => 'Estatísticas'],
        ],
        'Gestão' => [
            'jogos'               => ['href' => 'jogos.php',                'icon' => 'fa-solid fa-trophy',                   'label' => 'Jogos'],
            'treinos'             => ['href' => 'treinos.php',              'icon' => 'fa-solid fa-dumbbell',                 'label' => 'Treinos'],
            'presencas'           => ['href' => 'presencas.php',            'icon' => 'fa-solid fa-clipboard-check',          'label' => 'Presenças'],
            'escalacao'           => ['href' => 'escalacao.php',            'icon' => 'fa-solid fa-list-ol',                  'label' => 'Escalação'],
            'taticas'             => ['href' => 'taticas.php',              'icon' => 'fa-solid fa-chess-board',              'label' => 'Táticas'],
        ],
        'Análise' => [
            'avaliacoes'          => ['href' => 'avaliacoes.php',           'icon' => 'fa-solid fa-star',                     'label' => 'Avaliações'],
            'relatorios'          => ['href' => 'relatorios.php',           'icon' => 'fa-solid fa-file-lines',               'label' => 'Relatórios'],
        ],
        'Comunicação' => [
            'comunicados'         => ['href' => 'comunicados.php',          'icon' => 'fa-solid fa-bullhorn',                 'label' => 'Comunicados'],
            'notificacoes'        => ['href' => 'notificacoes.php',         'icon' => 'fa-solid fa-bell',                     'label' => 'Notificações'],
            'chat'                => ['href' => 'chat.php',                 'icon' => 'fa-solid fa-comments',                 'label' => 'Chat'],
        ],
        'Configurações' => [
            'configuracoes'       => ['href' => 'configuracoes.php',        'icon' => 'fa-solid fa-gear',                     'label' => 'Configurações'],
        ],
    ],
];

require __DIR__ . '/head.php';
require __DIR__ . '/sidebar.php';
require __DIR__ . '/navbar.php';
