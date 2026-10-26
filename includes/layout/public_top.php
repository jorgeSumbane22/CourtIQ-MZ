<?php
declare(strict_types=1);

/**
 * layout/public_top.php — topo das páginas do site público (index.php, conta.php e visitante/).
 * Aqui só se define o menu do site. O site não tem menu lateral: usa head.php e topnav.php
 * (a barra de navegação de cima, com Liga e Clube em menus suspensos) e footer.php no fim, via public_bottom.php.
 * A página define $basePath ('' na raiz, '../' em visitante/) antes de incluir este ficheiro.
 */

require_once __DIR__ . '/../team_helper.php';

TeamHelper::maintenanceGuard();

$basePath  = $basePath ?? '../';
$pageTitle = $pageTitle ?? 'Início';
$pageIcon  = $pageIcon ?? 'fa-solid fa-house';
$activeNav = $activeNav ?? 'inicio';
$publicRole = (string) ($_SESSION['user']['role'] ?? '');

$layout = [
    'panel'       => 'public',
    'base'        => $basePath,
    'title'       => '',
    'brandSub'    => '',
    'accountHref' => $basePath . 'conta.php',
    // quem tem sessão vê a ligação para a sua área: os adeptos em conta.php, os outros no seu painel
    'account'     => ['href' => $basePath . 'conta.php', 'label' => $publicRole === 'adepto' ? 'A minha conta' : 'O meu painel'],
    // o visitante escolhe o clube cujo site quer ver
    'teamSwitch'  => ['label' => 'Clube', 'teams' => public_teams(), 'current' => public_team_chosen()],
    'sections'    => [
        'Principal' => [
            'inicio'          => ['href' => $basePath . 'index.php',                      'icon' => 'fa-solid fa-house',              'label' => 'Início'],
            'jogadores'       => ['href' => $basePath . 'visitante/jogadores.php',        'icon' => 'fa-solid fa-user-group',         'label' => 'Jogadores'],
            'jogos'           => ['href' => $basePath . 'visitante/jogos.php',            'icon' => 'fa-solid fa-trophy',             'label' => 'Jogos'],
            'estatisticas'    => ['href' => $basePath . 'visitante/estatisticas.php',     'icon' => 'fa-solid fa-chart-column',       'label' => 'Estatísticas'],
            'noticias'        => ['href' => $basePath . 'visitante/noticias.php',         'icon' => 'fa-solid fa-newspaper',          'label' => 'Notícias'],
        ],
        'Liga' => [
            'liga-inicio'     => ['href' => $basePath . 'visitante/inicio.php',           'icon' => 'fa-solid fa-basketball',         'label' => 'Clubes da liga'],
            'classificacao'   => ['href' => $basePath . 'visitante/classificacao.php',    'icon' => 'fa-solid fa-ranking-star',       'label' => 'Classificação'],
            'equipas'         => ['href' => $basePath . 'visitante/equipas.php',          'icon' => 'fa-solid fa-building-shield',    'label' => 'Equipas'],
        ],
        'Clube' => [
            'equipa-tecnica'  => ['href' => $basePath . 'visitante/equipa-tecnica.php',   'icon' => 'fa-solid fa-chalkboard-user',    'label' => 'Equipa Técnica'],
            'historia'        => ['href' => $basePath . 'visitante/historia.php',         'icon' => 'fa-solid fa-book-open',          'label' => 'História'],
            'galeria'         => ['href' => $basePath . 'visitante/galeria.php',          'icon' => 'fa-solid fa-images',             'label' => 'Galeria'],
            'bilhetes'        => ['href' => $basePath . 'visitante/bilhetes.php',         'icon' => 'fa-solid fa-ticket',             'label' => 'Bilhetes'],
            'patrocinadores'  => ['href' => $basePath . 'visitante/patrocinadores.php',   'icon' => 'fa-solid fa-handshake',          'label' => 'Patrocinadores'],
            'contacto'        => ['href' => $basePath . 'visitante/contacto.php',         'icon' => 'fa-solid fa-phone',              'label' => 'Contacto'],
        ],
    ],
];

require __DIR__ . '/head.php';
require __DIR__ . '/topnav.php';
