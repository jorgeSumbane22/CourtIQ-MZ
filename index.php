<?php
declare(strict_types=1);

/**
 * CourtIQMZ - Landing Page Ultra Premium com Conteúdo Desportivo
 *
 * @package CourtIQ
 * @version 9.3.0 - Interface da landing redesenhada
 * @author Jorge Sumbane <jsumbane22@gmail.com>
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/team_helper.php';
require_once __DIR__ . '/includes/repositories/TeamRepository.php';
require_once __DIR__ . '/includes/repositories/GameRepository.php';
require_once __DIR__ . '/includes/repositories/NewsRepository.php';
require_once __DIR__ . '/includes/repositories/PlayerRepository.php';
require_once __DIR__ . '/includes/repositories/SponsorRepository.php';

// ============================================================================
// DATA COLLECTION
// ============================================================================
// Multi-equipa: o visitante escolhe a equipa (?equipa=ID)
$pdo    = Database::getConnection();
// sem clube escolhido, o visitante entra pela página da liga, onde escolhe o clube que quer ver
if (public_team_chosen() === 0) {
    header('Location: visitante/inicio.php');
    exit;
}
$teamId = public_team_id();

$teamRepo    = new TeamRepository();
$gameRepo    = new GameRepository();
$newsRepo    = new NewsRepository();
$playerRepo  = new PlayerRepository();
$sponsorRepo = new SponsorRepository();

$team          = $teamRepo->find($teamId);
// escalão em vista (?escalao=…); por omissão mostram-se todos os escalões do clube juntos
$escalaoAtual  = escalao_atual($team);
$proximosJogos = array_slice(escalao_only($gameRepo->upcoming($teamId, 60), $escalaoAtual), 0, 5);
$ultimosJogos  = array_slice(escalao_only($gameRepo->finished($teamId, 200), $escalaoAtual), 0, 15);
$noticias      = $newsRepo->latest(4, $teamId);
$plantel       = escalao_only($playerRepo->listByTeam($teamId), $escalaoAtual);
$sponsors      = $sponsorRepo->all($teamId);

// ============================================================================
// NÚMEROS DA LIGA (informação pública, comum a todas as equipas)
// ============================================================================
$classificacao       = $teamRepo->standings($escalaoAtual ?: 'seniores');   // cada escalão tem a sua tabela
$liga                = $teamRepo->leagueTotals();
$totalEquipas        = $liga['equipas'];
$totalJogadoresGeral = $liga['jogadores'];
$totalJogosGeral     = $liga['jogos'];
$totalCampeonatos    = $liga['competicoes'];

// ============================================================================
// ESTATÍSTICAS DA EQUIPA ESCOLHIDA
// ============================================================================
$totalJogos          = count($ultimosJogos);
$vitorias            = 0;
$derrotas            = 0;
$totalPontos         = 0;
$totalPontosSofridos = 0;
$maiorPontuacao      = 0;
$menorPontuacao      = 999;
$historicoPontos     = [];
$historicoAdversarios= [];

foreach ($ultimosJogos as $g) {
    $ourScore = (int) $g['our_score'];
    $oppScore = (int) $g['opponent_score'];

    $totalPontos          += $ourScore;
    $totalPontosSofridos  += $oppScore;
    $historicoPontos[]     = $ourScore;
    $historicoAdversarios[]= $g['opponent'];

    if ($ourScore > $oppScore) $vitorias++;
    else                       $derrotas++;

    if ($ourScore > $maiorPontuacao) $maiorPontuacao = $ourScore;
    if ($ourScore < $menorPontuacao) $menorPontuacao = $ourScore;
}

$aproveitamento = $totalJogos > 0 ? round(($vitorias / $totalJogos) * 100, 1) : 0;
$mediaPontos    = $totalJogos > 0 ? round($totalPontos / $totalJogos, 1) : 0;
$mediaSofridos  = $totalJogos > 0 ? round($totalPontosSofridos / $totalJogos, 1) : 0;
$saldoPontos    = $mediaPontos - $mediaSofridos;

$sequenciaAtual    = 0;
$sequenciaVitorias = 0;
foreach ($ultimosJogos as $g) {
    if ((int) $g['our_score'] > (int) $g['opponent_score']) {
        $sequenciaAtual++;
        if ($sequenciaAtual > $sequenciaVitorias) $sequenciaVitorias = $sequenciaAtual;
    } else {
        $sequenciaAtual = 0;
    }
}

// ============================================================================
// JOGADORES DA EQUIPA
// ============================================================================
foreach ($plantel as &$jogadorPlantel) {
    if (trim((string) ($jogadorPlantel['name'] ?? '')) === '') {
        $jogadorPlantel['name'] = !empty($jogadorPlantel['jersey_number']) ? 'Jogador #' . $jogadorPlantel['jersey_number'] : 'Jogador';
    }
}
unset($jogadorPlantel);
usort($plantel, fn($a, $b) => ($b['avg_pts'] ?? 0) <=> ($a['avg_pts'] ?? 0));
$topJogadores = array_slice($plantel, 0, 10);

// Destaque: o melhor marcador da equipa
$mvpSemana = null;
if ($topJogadores && (float) ($topJogadores[0]['avg_pts'] ?? 0) > 0) {
    $melhor    = $topJogadores[0];
    $mvpSemana = [
        'name'      => $melhor['name'],
        'position'  => $melhor['position'] ?? 'Atleta',
        'photo_url' => $melhor['photo_url'] ?? null,
        'stats'     => ['pts' => $melhor['avg_pts'], 'reb' => $melhor['avg_reb'], 'ast' => $melhor['avg_ast']],
        'highlight' => 'Melhor marcador da equipa, com ' . number_format((float) $melhor['avg_pts'], 1)
                     . ' pontos de média por jogo nesta temporada.',
    ];
}

// Galeria da equipa
$fotosGaleria = [];
try {
    $stmt = $pdo->prepare('SELECT title, image_url FROM gallery_photos WHERE team_id = :team_id ORDER BY id LIMIT 8');
    $stmt->execute(['team_id' => $teamId]);
    $fotosGaleria = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[CourtIQ] Erro ao carregar galeria: ' . $e->getMessage());
}

// ============================================================================
// CONFIGURAÇÃO DA PÁGINA
// ============================================================================
$pageTitle = 'Início';
$pageIcon  = 'fa-solid fa-house';
$activeNav = 'inicio';
$basePath  = '';
require __DIR__ . '/includes/layout/public_top.php';

// ============================================================================
// FUNÇÕES AUXILIARES
// ============================================================================
function safeOutput(?string $value, string $default = ''): string {
    return htmlspecialchars($value ?? $default, ENT_QUOTES, 'UTF-8');
}

function formatDate(?string $date): string {
    if (empty($date)) return 'Data indisponível';
    return format_date_pt($date);
}

function getPlayerPhoto(?string $photoUrl, string $default = ''): string {
    // Caminhos guardados com "/" inicial apontariam para a raiz do servidor
    $photoUrl = ltrim((string) $photoUrl, '/');
    if ($photoUrl === '') return $default;
    if (file_exists(__DIR__ . '/' . $photoUrl)) return $photoUrl;
    return $default;
}

function getInitial(?string $name): string {
    return safeOutput(mb_strtoupper(mb_substr(trim((string) $name) ?: '?', 0, 1)));
}

$heroBgImage = 'https://images.unsplash.com/photo-1546519638-68e109498ffc?w=1920&q=80';
$anoAtual    = date('Y');
$anoFundacao = $team['founded_year'] ?? 2010;
$nomeEquipa  = $team['name'] ?? 'CourtIQMZ';
$mesesAbrev  = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];

// Próximo jogo: data + hora para a contagem regressiva
$proximoJogo   = $proximosJogos[0] ?? null;
$jogoTimestamp = null;
$jogoDataLabel = '';
$jogoRestante  = 0;
if ($proximoJogo) {
    try {
        $dataJogo      = new DateTime(trim($proximoJogo['game_date'] . ' ' . ($proximoJogo['game_time'] ?? '')));
        $jogoTimestamp = $dataJogo->getTimestamp();
        $jogoRestante  = max(0, $jogoTimestamp - time());
        $jogoDataLabel = $dataJogo->format('d/m/Y');
        if (!empty($proximoJogo['game_time'])) {
            $jogoDataLabel .= ' · ' . $dataJogo->format('H:i');
        }
    } catch (Exception $e) {
        error_log('[CourtIQ] Data de jogo inválida: ' . $e->getMessage());
        $jogoDataLabel = formatDate($proximoJogo['game_date'] ?? null);
    }
}

$formaRecente = array_slice($ultimosJogos, 0, 5);
?>


<div class="lp" id="landing">

    <!-- ======================================================================== -->
    <!-- 1. HERO + PRÓXIMO JOGO -->
    <!-- ======================================================================== -->
    <section class="lp-hero" id="home" style="--hero-image: url('<?php echo $heroBgImage; ?>');">
        <div class="bb-shot" aria-hidden="true"><span class="bb-shot-x"><span class="bb-shot-y"><i class="fa-solid fa-basketball"></i></span></span></div>
        <div>
            <span class="lp-hero-tag">
                <i class="fa-solid fa-basketball"></i>
                <?php echo safeOutput($nomeEquipa); ?> &bull; Temporada <?php echo $anoAtual; ?>
            </span>
            <h1>Gestão inteligente para clubes de <em>basquetebol</em></h1>
            <p class="lp-hero-lead">
                Equipas, jogadores, jogos e estatísticas num só lugar.
                O CourtIQMZ acompanha o clube dentro e fora do campo.
            </p>
            <div class="lp-hero-actions">
                <a href="login.php" class="lp-btn lp-btn--primary">
                    <i class="fa-solid fa-right-to-bracket"></i> Entrar agora
                </a>
                <a href="visitante/jogos.php" class="lp-btn lp-btn--glass">
                    <i class="fa-solid fa-calendar-days"></i> Ver calendário
                </a>
            </div>

            <?php if ($totalJogos > 0): ?>
            <div class="lp-hero-record">
                <div><strong><?php echo $vitorias; ?>&ndash;<?php echo $derrotas; ?></strong><span>Vitórias&ndash;Derrotas</span></div>
                <div><strong><?php echo $aproveitamento; ?>%</strong><span>Aproveitamento</span></div>
                <div><strong><?php echo $mediaPontos; ?></strong><span>Pontos por jogo</span></div>
            </div>
            <?php endif; ?>
        </div>

        <aside class="lp-match<?php echo ($proximoJogo && $jogoTimestamp && $jogoRestante === 0) ? ' is-live' : ''; ?>" id="next-game" aria-label="Próximo jogo">
            <?php if ($proximoJogo): ?>
                <div class="lp-match-top">
                    <span class="lp-live">Próximo jogo</span>
                    <span><?php echo safeOutput($proximoJogo['competition'] ?? 'Liga Nacional'); ?></span>
                </div>

                <div class="lp-match-teams">
                    <div class="lp-match-team">
                        <div class="lp-crest"><?php echo getInitial($nomeEquipa); ?></div>
                        <b><?php echo safeOutput($nomeEquipa); ?></b>
                        <small><?php echo ($proximoJogo['home_away'] ?? 'home') === 'away' ? 'Fora' : 'Casa'; ?></small>
                    </div>
                    <span class="lp-match-vs">VS</span>
                    <div class="lp-match-team">
                        <div class="lp-crest lp-crest--away"><?php echo getInitial($proximoJogo['opponent'] ?? 'A'); ?></div>
                        <b><?php echo safeOutput($proximoJogo['opponent'] ?? 'Adversário'); ?></b>
                        <small>Adversário</small>
                    </div>
                </div>

                <?php if ($jogoTimestamp): ?>
                <div class="lp-countdown" data-countdown="<?php echo $jogoTimestamp; ?>" role="timer" aria-label="Tempo até ao jogo">
                    <div><b data-unit="d"><?php echo intdiv($jogoRestante, 86400); ?></b><span>Dias</span></div>
                    <div><b data-unit="h"><?php echo str_pad((string) intdiv($jogoRestante % 86400, 3600), 2, '0', STR_PAD_LEFT); ?></b><span>Horas</span></div>
                    <div><b data-unit="m"><?php echo str_pad((string) intdiv($jogoRestante % 3600, 60), 2, '0', STR_PAD_LEFT); ?></b><span>Min</span></div>
                    <div><b data-unit="s"><?php echo str_pad((string) ($jogoRestante % 60), 2, '0', STR_PAD_LEFT); ?></b><span>Seg</span></div>
                </div>
                <div class="lp-countdown-live"><i class="fa-solid fa-fire"></i> Dia de jogo</div>
                <?php endif; ?>

                <div class="lp-match-meta">
                    <span><i class="fa-solid fa-calendar-days"></i><?php echo safeOutput($jogoDataLabel); ?></span>
                    <span><i class="fa-solid fa-location-dot"></i><?php echo safeOutput($proximoJogo['location'] ?? 'Local a definir'); ?></span>
                </div>

                <a href="visitante/jogos.php" class="lp-btn lp-btn--glass lp-btn--block">
                    Ver todos os jogos <i class="fa-solid fa-arrow-right"></i>
                </a>
            <?php else: ?>
                <div class="lp-match-empty">
                    <i class="fa-solid fa-calendar-xmark"></i>
                    <p>Ainda não há jogos agendados.<br>Consulte o calendário para novidades.</p>
                    <a href="visitante/jogos.php" class="lp-btn lp-btn--glass lp-btn--block">
                        Abrir calendário <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            <?php endif; ?>
        </aside>
    </section>

    <?= escalao_chips($team, $escalaoAtual, '?') ?>

    <!-- ======================================================================== -->
    <!-- 2. NÚMEROS DA LIGA -->
    <!-- ======================================================================== -->
    <section class="lp-stats lp-card lp-reveal" id="stats" aria-label="Números da liga">
        <?php
        $numerosLiga = [
            ['icon' => 'fa-shield-halved', 'valor' => $totalEquipas,        'label' => 'Equipas na liga'],
            ['icon' => 'fa-user-group',    'valor' => $totalJogadoresGeral, 'label' => 'Atletas registados'],
            ['icon' => 'fa-chart-column',  'valor' => $totalJogosGeral,     'label' => 'Jogos realizados'],
            ['icon' => 'fa-trophy',        'valor' => $totalCampeonatos,    'label' => 'Competições ativas'],
        ];
        foreach ($numerosLiga as $numero): ?>
        <div class="lp-stat">
            <span class="lp-stat-icon"><i class="fa-solid <?php echo $numero['icon']; ?>"></i></span>
            <div>
                <b data-count="<?php echo (int) $numero['valor']; ?>"><?php echo (int) $numero['valor']; ?></b>
                <span><?php echo $numero['label']; ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </section>

    <!-- ======================================================================== -->
    <!-- 3. CLASSIFICAÇÃO + ÚLTIMOS RESULTADOS -->
    <!-- ======================================================================== -->
    <div class="lp-duo<?php echo $ultimosJogos ? '' : ' lp-duo--single'; ?>">
        <section class="lp-reveal" id="standings">
            <div class="lp-head">
                <div>
                    <span class="lp-eyebrow">Liga Nacional &bull; <?php echo $anoAtual; ?></span>
                    <h2>Classificação</h2>
                </div>
                <a class="lp-more" href="visitante/classificacao.php">Tabela completa <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="lp-card lp-table-wrap">
                <table class="lp-table">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Equipa</th>
                            <th scope="col" title="Jogos">J</th>
                            <th scope="col" title="Vitórias">V</th>
                            <th scope="col" title="Derrotas">D</th>
                            <th scope="col" title="Saldo de pontos">Saldo</th>
                            <th scope="col" title="Pontos">Pts</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classificacao as $i => $pos):
                            $nossaEquipa = (int) $pos['id'] === $teamId;
                            $posicao     = $i + 1;
                            $saldo       = (int) $pos['saldo'];
                        ?>
                        <tr<?php echo $nossaEquipa ? ' class="is-us"' : ''; ?>>
                            <td><span class="lp-pos<?php echo $posicao <= 3 ? ' lp-pos--' . $posicao : ''; ?>"><?php echo $posicao; ?></span></td>
                            <td class="lp-table-team"><a href="?equipa=<?php echo (int) $pos['id']; ?>"><?php echo safeOutput($pos['name']); ?></a></td>
                            <td><?php echo (int) $pos['jogos']; ?></td>
                            <td><?php echo (int) $pos['vitorias']; ?></td>
                            <td><?php echo (int) $pos['derrotas']; ?></td>
                            <td class="<?php echo $saldo >= 0 ? 'lp-up' : 'lp-down'; ?>"><?php echo ($saldo > 0 ? '+' : '') . $saldo; ?></td>
                            <td class="lp-table-pts"><?php echo (int) $pos['pontos']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($ultimosJogos): ?>
        <section class="lp-reveal" id="results">
            <div class="lp-head">
                <div>
                    <span class="lp-eyebrow"><?php echo safeOutput($nomeEquipa); ?></span>
                    <h2>Últimos resultados</h2>
                </div>
                <a class="lp-more" href="visitante/jogos.php">Ver todos <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="lp-card">
                <div class="lp-recent">
                    <div class="lp-form-label">Forma recente<br><b><?php echo $aproveitamento; ?>% de aproveitamento</b></div>
                    <div class="lp-form-pills" aria-label="Últimos <?php echo count($formaRecente); ?> jogos, do mais recente para o mais antigo">
                        <?php foreach ($formaRecente as $jogo):
                            $ganhou = (int) $jogo['our_score'] > (int) $jogo['opponent_score'];
                        ?>
                        <span class="lp-pill lp-pill--<?php echo $ganhou ? 'win' : 'loss'; ?>" title="<?php echo $ganhou ? 'Vitória' : 'Derrota'; ?>"><?php echo $ganhou ? 'V' : 'D'; ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php foreach ($formaRecente as $jogo):
                    $ourScore = (int) $jogo['our_score'];
                    $oppScore = (int) $jogo['opponent_score'];
                    $isWin    = $ourScore > $oppScore;
                    $tsJogo   = strtotime((string) $jogo['game_date']);
                ?>
                <div class="lp-result">
                    <div class="lp-date">
                        <b><?php echo date('d', $tsJogo); ?></b>
                        <span><?php echo $mesesAbrev[(int) date('n', $tsJogo) - 1]; ?></span>
                    </div>
                    <div style="min-width:0;">
                        <div class="lp-result-opp"><small>vs</small><?php echo safeOutput($jogo['opponent']); ?></div>
                        <div class="lp-result-meta"><i class="fa-solid fa-location-dot"></i><?php echo safeOutput($jogo['location'] ?? '—'); ?></div>
                    </div>
                    <div class="lp-score"><?php echo $ourScore; ?> &ndash; <?php echo $oppScore; ?></div>
                    <span class="lp-pill lp-pill--<?php echo $isWin ? 'win' : 'loss'; ?>" title="<?php echo $isWin ? 'Vitória' : 'Derrota'; ?>"><?php echo $isWin ? 'V' : 'D'; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <!-- ======================================================================== -->
    <!-- 4. MVP DA SEMANA + RANKING DE JOGADORES -->
    <!-- ======================================================================== -->
    <section class="lp-reveal" id="players">
        <div class="lp-head">
            <div>
                <span class="lp-eyebrow">Destaques da temporada</span>
                <h2>Melhores jogadores</h2>
            </div>
            <a class="lp-more" href="visitante/jogadores.php">Plantel completo <i class="fa-solid fa-arrow-right"></i></a>
        </div>

        <div class="lp-duo<?php echo $mvpSemana ? ' lp-duo--mvp' : ' lp-duo--single'; ?>">
            <?php if ($mvpSemana): ?>
            <article class="lp-card lp-mvp" id="mvp">
                <span class="lp-mvp-badge"><i class="fa-solid fa-star"></i> Destaque da equipa</span>

                <div class="lp-mvp-who">
                    <?php $fotoMvp = getPlayerPhoto($mvpSemana['photo_url']); ?>
                    <?php if ($fotoMvp): ?>
                        <img class="lp-avatar lp-avatar--lg" src="<?php echo safeOutput($fotoMvp); ?>" alt="<?php echo safeOutput($mvpSemana['name']); ?>" loading="lazy">
                    <?php else: ?>
                        <div class="lp-avatar lp-avatar--lg"><?php echo getInitial($mvpSemana['name']); ?></div>
                    <?php endif; ?>
                    <div>
                        <div class="lp-mvp-name"><?php echo safeOutput($mvpSemana['name']); ?></div>
                        <div class="lp-mvp-pos"><?php echo safeOutput($mvpSemana['position']); ?></div>
                    </div>
                </div>

                <div class="lp-mvp-stats">
                    <?php foreach (['pts' => 'PTS', 'reb' => 'REB', 'ast' => 'AST'] as $chave => $rotulo): ?>
                    <div><b><?php echo number_format((float) $mvpSemana['stats'][$chave], 1); ?></b><span><?php echo $rotulo; ?></span></div>
                    <?php endforeach; ?>
                </div>

                <p class="lp-mvp-quote"><i class="fa-solid fa-quote-left"></i><?php echo safeOutput($mvpSemana['highlight']); ?></p>
            </article>
            <?php endif; ?>

            <div class="lp-card">
                <div class="lp-rank-head">
                    <span>Top <?php echo count($topJogadores); ?> &bull; médias por jogo</span>
                    <span>PTS</span><span>REB</span><span>AST</span>
                </div>
                <?php foreach ($topJogadores as $index => $player):
                    $nomeJogador = trim((string) ($player['name'] ?? ''));
                    if ($nomeJogador === '') {
                        $nomeJogador = !empty($player['jersey_number']) ? 'Jogador #' . $player['jersey_number'] : 'Jogador';
                    }
                    $fotoJogador = getPlayerPhoto($player['photo_url'] ?? null, getPlayerPhoto('imagens/jogadores/' . ($index + 1) . '.jpg'));
                ?>
                <div class="lp-rank-row">
                    <span class="lp-rank-n"><?php echo $index + 1; ?></span>
                    <?php if ($fotoJogador): ?>
                        <img class="lp-avatar lp-avatar--sm" src="<?php echo safeOutput($fotoJogador); ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <div class="lp-avatar lp-avatar--sm"><?php echo getInitial($nomeJogador); ?></div>
                    <?php endif; ?>
                    <div style="min-width:0;">
                        <div class="lp-rank-name"><?php echo safeOutput($nomeJogador); ?></div>
                        <div class="lp-rank-pos"><?php echo safeOutput($player['position'] ?? 'Atleta'); ?></div>
                    </div>
                    <span class="lp-rank-num lp-rank-num--main"><?php echo number_format((float) ($player['avg_pts'] ?? 0), 1); ?></span>
                    <span class="lp-rank-num"><?php echo number_format((float) ($player['avg_reb'] ?? 0), 1); ?></span>
                    <span class="lp-rank-num"><?php echo number_format((float) ($player['avg_ast'] ?? 0), 1); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ======================================================================== -->
    <!-- 5. NOTÍCIAS -->
    <!-- ======================================================================== -->
    <section class="lp-reveal" id="news">
        <div class="lp-head">
            <div>
                <span class="lp-eyebrow">Clube</span>
                <h2>Últimas notícias</h2>
            </div>
            <a class="lp-more" href="visitante/noticias.php">Todas as notícias <i class="fa-solid fa-arrow-right"></i></a>
        </div>

        <div class="lp-news">
            <?php
            $imagemPadrao  = 'https://images.unsplash.com/photo-1546519638-68e109498ffc?w=600&q=80';
            $dadosNoticias = [];
            foreach (array_slice($noticias, 0, 3) as $n) {
                $dadosNoticias[] = [
                    'titulo'    => $n['title'],
                    'descricao' => $n['content'],
                    'data'      => !empty($n['published_at']) ? date('d/m/Y', strtotime($n['published_at'])) : '',
                    'imagem'    => getPlayerPhoto($n['image_url'] ?? null, $imagemPadrao),
                ];
            }

            foreach ($dadosNoticias as $noticia):
                $caminhoImagem = $noticia['imagem'];
            ?>
            <a class="lp-card lp-news-card" href="visitante/noticias.php">
                <div class="lp-news-img">
                    <img src="<?php echo $caminhoImagem; ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?php echo $imagemPadrao; ?>'">
                </div>
                <div class="lp-news-body">
                    <span class="lp-news-date"><i class="fa-solid fa-calendar-days"></i><?php echo safeOutput($noticia['data']); ?></span>
                    <h3 class="lp-news-title"><?php echo safeOutput($noticia['titulo']); ?></h3>
                    <p class="lp-news-desc"><?php echo safeOutput($noticia['descricao']); ?></p>
                    <span class="lp-news-link">Ler mais <i class="fa-solid fa-arrow-right"></i></span>
                </div>
            </a>
            <?php endforeach; ?>
            <?php if (!$dadosNoticias): ?>
                <p class="lp-empty">Esta equipa ainda não publicou notícias.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- ======================================================================== -->
    <!-- 6. GALERIA -->
    <!-- ======================================================================== -->
    <?php if ($fotosGaleria): ?>
    <section class="lp-reveal" id="gallery">
        <div class="lp-head">
            <div>
                <span class="lp-eyebrow">Momentos</span>
                <h2>Galeria de fotos</h2>
            </div>
            <a class="lp-more" href="visitante/galeria.php">Ver galeria <i class="fa-solid fa-arrow-right"></i></a>
        </div>

        <div class="lp-gallery">
            <?php foreach ($fotosGaleria as $foto):
                $caminhoImagem = getPlayerPhoto($foto['image_url'], $imagemPadrao);
            ?>
            <a href="visitante/galeria.php">
                <img src="<?php echo safeOutput($caminhoImagem); ?>" alt="<?php echo safeOutput($foto['title'] ?? 'Fotografia'); ?>" loading="lazy" onerror="this.onerror=null;this.src='<?php echo $imagemPadrao; ?>'">
                <span><i class="fa-solid fa-camera"></i><?php echo safeOutput($foto['title'] ?? ''); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ======================================================================== -->
    <!-- 7. PATROCINADORES -->
    <!-- ======================================================================== -->
    <?php if ($sponsors): ?>
    <section class="lp-reveal" id="sponsors">
        <div class="lp-head">
            <div>
                <span class="lp-eyebrow">Quem apoia o desporto</span>
                <h2>Patrocinadores</h2>
            </div>
            <a class="lp-more" href="visitante/patrocinadores.php">Ver todos <i class="fa-solid fa-arrow-right"></i></a>
        </div>

        <div class="lp-sponsors">
            <?php foreach ($sponsors as $sponsor): ?>
            <div class="lp-card lp-sponsor">
                <?php if (!empty($sponsor['logo_url'])): ?>
                    <img src="<?php echo safeOutput($sponsor['logo_url']); ?>" alt="<?php echo safeOutput($sponsor['name']); ?>" loading="lazy" onerror="this.replaceWith(this.alt)">
                <?php else: ?>
                    <?php echo safeOutput($sponsor['name']); ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <!-- ======================================================================== -->
    <!-- 8. CTA -->
    <!-- ======================================================================== -->
    <section class="lp-cta lp-reveal" id="cta">
        <div>
            <h2>Pronto para <em>transformar</em> o seu clube?</h2>
            <p>Entre no CourtIQMZ e passe a gerir equipas, treinos e estatísticas a partir de um único painel.</p>
        </div>
        <div class="lp-cta-actions">
            <a href="login.php" class="lp-btn lp-btn--primary"><i class="fa-solid fa-rocket"></i> Começar agora</a>
            <a href="visitante/contacto.php" class="lp-btn lp-btn--glass"><i class="fa-solid fa-headset"></i> Falar connosco</a>
        </div>
    </section>

</div>

<!-- ============================================================================ -->
<!-- JAVASCRIPT -->
<!-- ============================================================================ -->
<script>
    (function() {
        'use strict';

        var root = document.getElementById('landing');
        if (!root) return;

        // Contagem regressiva do próximo jogo
        var countdown = root.querySelector('[data-countdown]');
        if (countdown) {
            var target = parseInt(countdown.getAttribute('data-countdown'), 10) * 1000;
            var units  = {};
            countdown.querySelectorAll('[data-unit]').forEach(function(el) { units[el.getAttribute('data-unit')] = el; });
            var pad = function(n) { return n < 10 ? '0' + n : String(n); };

            var tick = function() {
                var diff = Math.floor((target - Date.now()) / 1000);
                if (diff <= 0) {
                    countdown.closest('.lp-match').classList.add('is-live');
                    clearInterval(timer);
                    return;
                }
                units.d.textContent = Math.floor(diff / 86400);
                units.h.textContent = pad(Math.floor(diff % 86400 / 3600));
                units.m.textContent = pad(Math.floor(diff % 3600 / 60));
                units.s.textContent = pad(diff % 60);
                virar = !virar;   // alterna entre duas animações iguais para a voltar a correr a cada segundo
                units.s.classList.toggle('tk-a', virar);
                units.s.classList.toggle('tk-b', !virar);
            };
            var virar = false;
            var timer = setInterval(tick, 1000);
            tick();
        }

    })();
</script>

<?php require __DIR__ . '/includes/layout/public_bottom.php'; ?>
