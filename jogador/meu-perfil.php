<?php
declare(strict_types=1);

/**
 * CourtIQMZ — Jogador: Meu perfil
 * A ficha do próprio jogador e as avaliações que o treinador lhe fez.
 * (Email, telefone e palavra-passe mudam-se em Configurações.)
 */

$panel = 'jogador';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/panel_helpers.php';

[$pdo, $teamId, $team, $user] = panel_boot(panel_roles());
$player = panel_player($pdo, $teamId, (int) $user['id']);

$pageTitle = 'Meu Perfil';
$activeNav = 'meu-perfil';

if (!$player) {
    require panel_layout('top');
    panel_no_player();
    require panel_layout('bottom');
    exit;
}
$playerId = (int) $player['id'];

$stmt = $pdo->prepare('SELECT * FROM avaliacoes WHERE player_id = :player_id AND team_id = :team_id ORDER BY data_avaliacao DESC, id DESC LIMIT 10');
$stmt->execute(['player_id' => $playerId, 'team_id' => $teamId]);
$avaliacoes = $stmt->fetchAll();
$ultima = $avaliacoes[0] ?? null;

$grupos = [
    'Técnica' => ['passe' => 'Passe', 'drible' => 'Drible', 'lancamento' => 'Lançamento', 'finalizacao' => 'Finalização', 'controlo_bola' => 'Controlo de bola'],
    'Tática'  => ['posicionamento' => 'Posicionamento', 'leitura_jogo' => 'Leitura de jogo', 'decisao' => 'Tomada de decisão', 'defesa_coletiva' => 'Defesa coletiva'],
    'Física'  => ['resistencia' => 'Resistência', 'velocidade' => 'Velocidade', 'agilidade' => 'Agilidade', 'forca' => 'Força', 'recuperacao' => 'Recuperação'],
    'Mental'  => ['concentracao' => 'Concentração', 'disciplina' => 'Disciplina', 'lideranca' => 'Liderança', 'comunicacao' => 'Comunicação', 'atitude' => 'Atitude'],
];

$idade = !empty($player['birth_date']) ? (int) date_diff(date_create($player['birth_date']), date_create('today'))->y : null;
[$estado, $estadoTipo] = pub_player_status($player['status']);

require panel_layout('top');
?>

<div class="lp pn">
    <section class="lp-page-head">
        <div style="display:flex;align-items:center;gap:22px;flex-wrap:wrap;">
            <?= pn_avatar($player['photo_url'], $player['name'], '../', 'lg') ?>
            <div>
                <span class="lp-hero-tag">#<?= (int) $player['jersey_number'] ?> &bull; <?= pub_e(pub_position($player['position'])) ?></span>
                <h1><?= pub_e($player['name']) ?></h1>
                <div class="lp-chips">
                    <span class="lp-badge lp-badge--<?= $estadoTipo ?>"><?= pub_e($estado) ?></span>
                    <?php if ($player['is_captain']): ?><span class="lp-badge lp-badge--gold"><i class="fa-solid fa-star"></i> Capitão</span><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="lp-hero-record">
            <div><strong><?= (int) $player['games_played'] ?></strong><span>Jogos</span></div>
            <div><strong><?= number_format((float) $player['avg_pts'], 1) ?></strong><span>Pontos por jogo</span></div>
            <div><strong><?= $ultima ? number_format((float) $ultima['nota_geral'], 0) : '—' ?></strong><span>Última avaliação</span></div>
        </div>
    </section>

    <div class="pn-cols pn-cols--even">
        <section class="lp-card lp-pad">
            <h2 class="lp-sub">A minha ficha</h2>
            <ul class="lp-info">
                <li><span class="lp-info-icon"><i class="fa-solid fa-shield-halved"></i></span><div><small>Equipa</small><b><?= pub_e($team['name']) ?></b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-shirt"></i></span><div><small>Número e posição</small><b>#<?= (int) $player['jersey_number'] ?> &bull; <?= pub_e(pub_position($player['position'])) ?> (<?= pub_e($player['position']) ?>)</b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-cake-candles"></i></span><div><small>Nascimento</small><b><?= pub_e(pub_date($player['birth_date'])) ?><?= $idade !== null ? ' (' . $idade . ' anos)' : '' ?></b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-ruler-vertical"></i></span><div><small>Altura e peso</small><b><?= $player['height'] ? number_format((float) $player['height'], 2, ',', '') . ' m' : '—' ?> &bull; <?= $player['weight'] ? number_format((float) $player['weight'], 0) . ' kg' : '—' ?></b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-envelope"></i></span><div><small>Email</small><b><?= pub_e($player['email'] ?: '—') ?></b></div></li>
                <li><span class="lp-info-icon"><i class="fa-solid fa-phone"></i></span><div><small>Telefone</small><b><?= pub_e($player['phone'] ?: '—') ?></b></div></li>
            </ul>
            <p class="pn-help" style="margin-top:18px;">
                O número, a posição e os dados físicos são geridos pelo treinador.
                Para mudar o email, o telefone ou a palavra-passe vá a <a href="configuracoes.php" style="color:var(--accent-text);font-weight:600;">Configurações</a>.
            </p>
        </section>

        <section class="lp-card lp-pad" id="avaliacoes">
            <h2 class="lp-sub">Última avaliação<?= $ultima ? ' · ' . pub_e(pub_date($ultima['data_avaliacao'])) : '' ?></h2>
            <?php if ($ultima): ?>
                <div style="display:flex;align-items:baseline;gap:10px;margin-bottom:18px;">
                    <b style="font-family:var(--display);font-size:48px;font-weight:800;color:var(--accent-text);line-height:1;"><?= number_format((float) $ultima['nota_geral'], 0) ?></b>
                    <span style="color:var(--muted);">/ 100</span>
                </div>
                <?php foreach ($grupos as $grupo => $campos):
                    $preenchidos = array_filter($campos, fn($campo) => (float) $ultima[$campo] > 0, ARRAY_FILTER_USE_KEY);
                    if (!$preenchidos) continue; ?>
                <h3 style="font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--muted);margin:14px 0 10px;"><?= $grupo ?></h3>
                <div class="lp-bars">
                    <?php foreach ($preenchidos as $campo => $rotulo): $v = (int) $ultima[$campo]; ?>
                    <div class="lp-bar-row"><span><?= $rotulo ?></span><span class="lp-bar <?= $v >= 75 ? 'lp-bar--green' : '' ?>"><i style="width:<?= min(100, $v) ?>%"></i></span><b><?= $v ?></b></div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <?php if (!empty($ultima['feedback']) || !empty($ultima['comentario'])): ?>
                    <p style="margin-top:18px;font-size:14px;color:var(--text-secondary);line-height:1.6;"><i class="fa-solid fa-quote-left" style="color:var(--accent);margin-right:8px;"></i><?= pub_e($ultima['feedback'] ?: $ultima['comentario']) ?></p>
                <?php endif; ?>
                <?php if (!empty($ultima['objetivos'])): ?>
                    <p style="margin-top:10px;font-size:14px;color:var(--text-secondary);line-height:1.6;"><b style="color:var(--text);">Objetivos:</b> <?= pub_e($ultima['objetivos']) ?></p>
                <?php endif; ?>
            <?php else: ?>
                <p style="color:var(--muted);font-size:14px;">O treinador ainda não registou nenhuma avaliação.</p>
            <?php endif; ?>
        </section>
    </div>

    <?php if (count($avaliacoes) > 1): ?>
    <section class="lp-card">
        <div class="pn-section-title"><h2><i class="fa-solid fa-clock-rotate-left"></i>Histórico de avaliações</h2><small><?= count($avaliacoes) ?> avaliações</small></div>
        <div class="pn-list">
            <?php foreach ($avaliacoes as $i => $a):
                $anterior = $avaliacoes[$i + 1] ?? null;
                $diff = $anterior ? (float) $a['nota_geral'] - (float) $anterior['nota_geral'] : null;
            ?>
            <div style="align-items:flex-start;">
                <b style="font-family:var(--display);font-size:26px;font-weight:800;color:var(--accent-text);width:44px;text-align:center;line-height:1;"><?= number_format((float) $a['nota_geral'], 0) ?></b>
                <div class="grow">
                    <b><?= pub_e(pub_date($a['data_avaliacao'])) ?> · <?= pub_e(ucfirst((string) $a['tipo'])) ?></b>
                    <?php if (!empty($a['feedback']) || !empty($a['comentario'])): ?><small style="white-space:normal;font-size:13px;"><?= pub_e($a['feedback'] ?: $a['comentario']) ?></small><?php endif; ?>
                </div>
                <?php if ($diff !== null): ?><span class="lp-badge lp-badge--<?= $diff > 0 ? 'win' : ($diff < 0 ? 'loss' : '') ?>"><?= ($diff > 0 ? '+' : '') . number_format($diff, 0) ?></span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>

<?php require panel_layout('bottom'); ?>
