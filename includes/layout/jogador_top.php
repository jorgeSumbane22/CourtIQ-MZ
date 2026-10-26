<?php
declare(strict_types=1);

/**
 * layout/jogador_top.php — topo das páginas do painel do jogador.
 * Aqui só se define o menu deste painel; a moldura é a mesma em todo o sistema:
 * head.php, sidebar.php e navbar.php (e footer.php no fim, via jogador_bottom.php).
 */

$layout = [
    'panel'       => 'jogador',
    'base'        => '../',
    'title'       => 'Jogador',
    'brandSub'    => 'Jogador',
    'notifHref'   => 'notificacoes.php',
    'chatHref'    => 'chat.php',
    'accountHref' => 'meu-perfil.php',
    'sections'    => [
        'Principal' => [
            'dashboard'           => ['href' => 'dashboard.php',            'icon' => 'fa-solid fa-gauge-high',               'label' => 'Dashboard'],
            'meu-perfil'          => ['href' => 'meu-perfil.php',           'icon' => 'fa-solid fa-user',                     'label' => 'Meu Perfil'],
            'minha-equipa'        => ['href' => 'minha-equipa.php',         'icon' => 'fa-solid fa-basketball',               'label' => 'Minha Equipa'],
            'minha-presenca'      => ['href' => 'minha-presenca.php',       'icon' => 'fa-solid fa-clipboard-check',          'label' => 'Minha Presença'],
        ],
        'Desportivo' => [
            'calendario'          => ['href' => 'calendario.php',           'icon' => 'fa-solid fa-calendar-days',            'label' => 'Calendário'],
            'meus-jogos'          => ['href' => 'meus-jogos.php',           'icon' => 'fa-solid fa-trophy',                   'label' => 'Meus Jogos'],
            'meus-recordes'       => ['href' => 'meus-recordes.php',        'icon' => 'fa-solid fa-medal',                    'label' => 'Meus Recordes'],
            'meus-treinos'        => ['href' => 'meus-treinos.php',         'icon' => 'fa-solid fa-dumbbell',                 'label' => 'Meus Treinos'],
            'minhas-estatisticas' => ['href' => 'minhas-estatisticas.php',  'icon' => 'fa-solid fa-chart-column',             'label' => 'Minhas Estatísticas'],
        ],
        'Comunicação' => [
            'chat'                => ['href' => 'chat.php',                 'icon' => 'fa-solid fa-comments',                 'label' => 'Chat'],
            'notificacoes'        => ['href' => 'notificacoes.php',         'icon' => 'fa-solid fa-bell',                     'label' => 'Notificações'],
        ],
        'Sistema' => [
            'configuracoes'       => ['href' => 'configuracoes.php',        'icon' => 'fa-solid fa-gear',                     'label' => 'Configurações'],
        ],
    ],
];

require __DIR__ . '/head.php';
require __DIR__ . '/sidebar.php';
require __DIR__ . '/navbar.php';
