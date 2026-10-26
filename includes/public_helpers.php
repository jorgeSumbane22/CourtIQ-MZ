<?php
declare(strict_types=1);

/**
 * public_helpers.php — funções partilhadas pelas páginas do site público
 * (index.php e /visitante). Tudo o que estas páginas mostram pertence à
 * equipa escolhida pelo visitante: public_team_id().
 */

require_once __DIR__ . '/team_helper.php';
require_once __DIR__ . '/repositories/TeamRepository.php';
require_once __DIR__ . '/repositories/GameRepository.php';
require_once __DIR__ . '/repositories/PlayerRepository.php';

const PUB_FALLBACK_IMG = 'https://images.unsplash.com/photo-1546519638-68e109498ffc?w=800&q=80';

function pub_e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Converte um caminho guardado na base de dados ("/imagens/x.jpg") num src utilizável
 * a partir da página atual. Devolve '' se o ficheiro não existir.
 */
function pub_img(?string $path, string $basePath): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $relative = ltrim($path, '/');
    return is_file(dirname(__DIR__) . '/' . $relative) ? $basePath . $relative : '';
}

function pub_initial(?string $name): string
{
    return pub_e(mb_strtoupper(mb_substr(trim((string) $name) ?: '?', 0, 1)));
}

function pub_date(?string $date, string $format = 'd/m/Y'): string
{
    $ts = $date ? strtotime($date) : false;
    return $ts ? date($format, $ts) : '—';
}

function pub_time(?string $time): string
{
    return $time ? substr($time, 0, 5) : '';
}

function pub_month(int $month, bool $short = true): string
{
    $names = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
              'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $name = $names[max(1, min(12, $month)) - 1];
    return $short ? mb_strtoupper(mb_substr($name, 0, 3)) : $name;
}

function pub_position(?string $code): string
{
    return [
        'PG' => 'Base',
        'SG' => 'Base-extremo',
        'SF' => 'Extremo',
        'PF' => 'Extremo-poste',
        'C'  => 'Poste',
    ][$code ?? ''] ?? 'Atleta';
}

function pub_player_status(?string $status): array
{
    return [
        'active'    => ['Disponível', 'win'],
        'injured'   => ['Lesionado', 'loss'],
        'suspended' => ['Suspenso', 'loss'],
        'inactive'  => ['Inativo', ''],
    ][$status ?? ''] ?? ['—', ''];
}

/**
 * Clube escolhido pelo visitante: [id, linha da tabela teams].
 * Nas páginas de um clube ($precisaClube), quem ainda não escolheu vai para a página da liga
 * (visitante/inicio.php) escolher. Nas páginas da liga devolve [0, …] enquanto não houver escolha.
 */
function pub_team(bool $precisaClube = true): array
{
    $teamId = public_team_chosen();
    $team   = $teamId ? TeamHelper::getTeamInfo($teamId) : null;
    if (!$team) {
        if ($precisaClube) {
            flash_set('info', 'Escolha primeiro o clube que quer ver.');
            header('Location: inicio.php');
            exit;
        }
        return [0, ['id' => 0, 'name' => 'Liga Nacional', 'city' => '', 'stadium' => '', 'founded_year' => null, 'escaloes' => '']];
    }
    return [$teamId, $team];
}

/**
 * Páginas reservadas a quem tem conta: se o visitante não tiver sessão, mostra o convite
 * para criar conta e devolve true (a página termina aí).
 */
function pub_account_wall(string $oQue, string $basePath = '../'): bool
{
    if (current_role() !== 'visitante') {
        return false;
    }
    $equipa = public_team_id();
    ?>
    <div class="lp">
        <section class="lp-card lp-pad" style="text-align:center;max-width:620px;margin:40px auto;width:100%;">
            <i class="fa-solid fa-lock" style="font-size:34px;color:var(--accent);margin-bottom:14px;display:inline-block;"></i>
            <h1 style="font-family:var(--display);font-size:34px;font-weight:800;text-transform:uppercase;color:var(--text);line-height:1.05;margin-bottom:10px;">Só para quem tem conta</h1>
            <p style="color:var(--text-secondary);margin-bottom:6px;"><?= pub_e(ucfirst($oQue)) ?> estão reservadas a adeptos com conta.</p>
            <p style="color:var(--muted);font-size:14px;margin-bottom:22px;">A conta é gratuita: segue o seu clube, recebe os resultados e as notícias, vê as estatísticas e a galeria e compra bilhetes.</p>
            <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                <a class="lp-btn lp-btn--primary" href="<?= pub_e($basePath) ?>registar.php<?= $equipa ? '?equipa=' . $equipa : '' ?>"><i class="fa-solid fa-user-plus"></i> Criar conta</a>
                <a class="lp-btn lp-btn--ghost" href="<?= pub_e($basePath) ?>login.php"><i class="fa-solid fa-right-to-bracket"></i> Já tenho conta</a>
            </div>
        </section>
    </div>
    <?php
    return true;
}

/** Vitórias, derrotas e médias a partir de uma lista de jogos terminados. */
function pub_record(array $finishedGames): array
{
    $r = ['jogos' => count($finishedGames), 'vitorias' => 0, 'derrotas' => 0, 'marcados' => 0, 'sofridos' => 0];
    foreach ($finishedGames as $g) {
        $our = (int) $g['our_score'];
        $opp = (int) $g['opponent_score'];
        $r['marcados'] += $our;
        $r['sofridos'] += $opp;
        $our > $opp ? $r['vitorias']++ : $r['derrotas']++;
    }
    $n = max(1, $r['jogos']);
    $r['aproveitamento'] = $r['jogos'] ? round($r['vitorias'] / $n * 100, 1) : 0;
    $r['media_marcados'] = $r['jogos'] ? round($r['marcados'] / $n, 1) : 0;
    $r['media_sofridos'] = $r['jogos'] ? round($r['sofridos'] / $n, 1) : 0;
    return $r;
}

/**
 * Cabeçalho das páginas interiores.
 * $stats: lista de [valor, legenda] mostrada à direita.
 */
function pub_page_head(string $eyebrow, string $title, string $lead = '', array $stats = []): void
{
    echo '<header class="lp-page-head"><div>';
    echo '<span class="lp-hero-tag"><i class="fa-solid fa-basketball"></i>' . pub_e($eyebrow) . '</span>';
    echo '<h1>' . pub_e($title) . '</h1>';
    if ($lead !== '') {
        echo '<p>' . pub_e($lead) . '</p>';
    }
    echo '</div>';
    if ($stats) {
        echo '<div class="lp-hero-record">';
        foreach ($stats as [$value, $label]) {
            echo '<div><strong>' . pub_e($value) . '</strong><span>' . pub_e($label) . '</span></div>';
        }
        echo '</div>';
    }
    echo '</header>';
}

/** Uma linha de jogo (próximo ou terminado), na perspetiva da equipa escolhida. */
function pub_game_row(array $g, string $teamName, string $basePath, array $extra = []): void
{
    $ts       = strtotime((string) $g['game_date']);
    $finished = ($g['status'] ?? '') === 'finished' && $g['our_score'] !== null;
    $home     = ($g['home_away'] ?? 'home') !== 'away';
    $win      = $finished && (int) $g['our_score'] > (int) $g['opponent_score'];

    $statusLabels = [
        'scheduled'   => ['Agendado', 'info'],
        'in_progress' => ['A decorrer', 'accent'],
        'cancelled'   => ['Cancelado', 'loss'],
        'postponed'   => ['Adiado', 'gold'],
    ];

    $tags = trim(($finished ? 'finished ' . ($win ? 'win' : 'loss') : 'upcoming') . ' ' . ($home ? 'home' : 'away'));
    echo '<article class="lp-card lp-game" data-filter-item data-tags="' . $tags . '" data-name="' . pub_e(mb_strtolower((string) $g['opponent'])) . '">';
    echo '<div class="lp-date"><b>' . date('d', $ts) . '</b><span>' . pub_month((int) date('n', $ts)) . '</span></div>';
    echo '<div style="min-width:0;">';
    echo '<div class="lp-game-teams"><span class="is-us">' . pub_e($teamName) . '</span><small>vs</small><span>' . pub_e($g['opponent']) . '</span></div>';
    echo '<div class="lp-game-meta">';
    if (!empty($g['game_time'])) {
        echo '<span><i class="fa-solid fa-clock"></i>' . pub_e(pub_time($g['game_time'])) . '</span>';
    }
    echo '<span><i class="fa-solid fa-location-dot"></i>' . pub_e($g['location'] ?: 'Local a definir') . '</span>';
    echo '<span><i class="fa-solid fa-' . ($home ? 'house' : 'plane') . '"></i>' . ($home ? 'Casa' : 'Fora') . '</span>';
    if (!empty($g['jornada'])) {
        echo '<span><i class="fa-solid fa-trophy"></i>' . pub_e($g['jornada']) . '</span>';
    }
    if (!empty($g['escalao'])) {
        echo '<span><i class="fa-solid fa-layer-group"></i>' . pub_e(escalao_label($g['escalao'])) . '</span>';
    }
    echo '</div></div>';

    echo '<div class="lp-game-side">';
    if ($finished) {
        echo '<span class="lp-score">' . (int) $g['our_score'] . ' &ndash; ' . (int) $g['opponent_score'] . '</span>';
        echo '<span class="lp-badge lp-badge--' . ($win ? 'win' : 'loss') . '">' . ($win ? 'Vitória' : 'Derrota') . '</span>';
    } else {
        [$label, $kind] = $statusLabels[$g['status'] ?? 'scheduled'] ?? ['Agendado', 'info'];
        echo '<span class="lp-badge lp-badge--' . $kind . '">' . $label . '</span>';
        if (!empty($extra['tickets']) && $home && ($g['status'] ?? '') === 'scheduled') {
            echo '<a class="lp-btn lp-btn--ghost" style="padding:9px 16px;font-size:13px;" href="' . pub_e($basePath) . 'visitante/bilhetes.php?jogo_id=' . (int) $g['id'] . '"><i class="fa-solid fa-ticket"></i> Bilhetes</a>';
        }
    }
    echo '</div></article>';
}
