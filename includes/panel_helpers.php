<?php
declare(strict_types=1);

/**
 * panel_helpers.php — base comum das páginas dos painéis (admin, treinador, ...).
 *
 * Cada página começa com:
 *     require_once __DIR__ . '/../includes/bootstrap.php';
 *     require_once __DIR__ . '/../includes/panel_helpers.php';
 *     [$pdo, $teamId, $team, $user] = panel_boot(['admin', 'superadmin']);
 *
 * A partir daí $teamId é SEMPRE a equipa do utilizador autenticado: todas as
 * consultas da página têm de filtrar por ele.
 */

require_once __DIR__ . '/public_helpers.php';

/**
 * Autentica, resolve a equipa e devolve [PDO, teamId, equipa, utilizador].
 * Sem equipa atribuída a página termina com um aviso (nunca mostra outra equipa).
 */
function panel_boot(array $roles): array
{
    Auth::requireRole($roles, Auth::getLoginPath());
    TeamHelper::maintenanceGuard();

    $pdo    = Database::getConnection();
    $teamId = current_team_id();
    $team   = $teamId ? TeamHelper::getTeamInfo($teamId) : null;

    if (!$teamId || !$team) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8"><title>CourtIQMZ</title></head>'
           . '<body style="margin:0;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;'
           . 'gap:12px;background:#0a0d0c;color:#f0f6fc;font-family:sans-serif;text-align:center;padding:24px;">'
           . '<h1 style="margin:0;">Sem equipa atribuída</h1>'
           . '<p style="color:#8b949e;margin:0;">A sua conta ainda não está associada a nenhuma equipa. Contacte o superadministrador.</p>'
           . '<a href="' . pub_e(Auth::getLogoutPath()) . '" style="margin-top:8px;padding:10px 24px;background:#00875a;color:#fff;'
           . 'text-decoration:none;border-radius:8px;font-weight:600;">Sair</a></body></html>';
        exit;
    }

    return [$pdo, (int) $teamId, $team, $_SESSION['user']];
}

// ============================================================================
// PÁGINAS PARTILHADAS ENTRE PAINÉIS (includes/panel_pages)
// O ficheiro de entrada define $panel = 'treinador' antes de incluir a página;
// sem isso, a página corre como painel de admin.
// ============================================================================

function panel_name(): string
{
    $panel = $GLOBALS['panel'] ?? 'admin';
    return in_array($panel, ['treinador', 'jogador', 'superadmin'], true) ? $panel : 'admin';
}

/** Papéis que podem entrar no painel atual. */
function panel_roles(): array
{
    return [
        'admin'     => ['admin', 'superadmin'],
        'treinador' => ['treinador', 'admin', 'superadmin'],
        // os adeptos (contas de registar.php) não entram em nenhum painel: a área deles é conta.php
        'jogador'    => ['jogador'],
        'superadmin' => ['superadmin'],
    ][panel_name()];
}

/**
 * Ficha de jogador ligada à conta autenticada (players.user_id), com as médias por jogo.
 * Só procura dentro da equipa do utilizador; devolve null se a conta não tiver ficha.
 */
function panel_player(PDO $pdo, int $teamId, int $userId): ?array
{
    foreach (panel_players($pdo, $teamId) as $player) {
        if ((int) $player['user_id'] === $userId) {
            return $player;
        }
    }
    return null;
}

/** Aviso mostrado nas páginas do jogador quando a conta não tem ficha de jogador. */
function panel_no_player(): void
{
    echo '<div class="lp pn"><div class="lp-card"><div class="pn-empty"><i class="fa-solid fa-user-slash"></i>'
       . 'A sua conta ainda não está ligada a uma ficha de jogador.<br>Peça ao treinador ou ao administrador da equipa para a associar.'
       . '</div></div></div>';
}

/** Caminho do layout ('top' ou 'bottom') do painel atual. */
function panel_layout(string $part): string
{
    return __DIR__ . '/layout/' . panel_name() . '_' . ($part === 'bottom' ? 'bottom' : 'top') . '.php';
}

/** Nome de uma página no painel atual (o plantel chama-se jogadores.php no admin e plantel.php no treinador). */
function panel_url(string $page): string
{
    $coach = ['jogadores.php' => 'plantel.php'];
    return panel_name() === 'treinador' ? ($coach[$page] ?? $page) : $page;
}

/**
 * Arranque das páginas do superadmin: [PDO, utilizador].
 * O superadmin não pertence a nenhuma equipa e vê todas, por isso aqui NÃO há $teamId:
 * cada página decide, à vista, o que mostra de cada equipa.
 */
function panel_boot_global(): array
{
    $GLOBALS['panel'] = 'superadmin';
    Auth::requireRole(['superadmin'], Auth::getLoginPath());
    return [Database::getConnection(), $_SESSION['user']];
}

/** Regista uma ação global (sem equipa) no histórico. */
function panel_log_global(PDO $pdo, string $action, string $details = '', ?int $teamId = null): void
{
    try {
        $pdo->prepare(
            'INSERT INTO audit_logs (user_id, team_id, username, action, details, ip_address)
             VALUES (:user_id, :team_id, :username, :action, :details, :ip)'
        )->execute([
            'user_id'  => (int) ($_SESSION['user']['id'] ?? 0) ?: null,
            'team_id'  => $teamId,
            'username' => (string) ($_SESSION['user']['username'] ?? ''),
            'action'   => $action,
            'details'  => $details,
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[CourtIQ] Erro ao registar ação: ' . $e->getMessage());
    }
}

/** Equipas (todas), indexadas por id — para os seletores e rótulos do superadmin. */
function panel_all_teams(PDO $pdo): array
{
    return array_column($pdo->query('SELECT * FROM teams ORDER BY name')->fetchAll(PDO::FETCH_ASSOC), null, 'id');
}

/** Campo oculto com o token CSRF, para pôr dentro de cada <form method="post">. */
function panel_csrf(): string
{
    return '<input type="hidden" name="csrf_token" value="' . pub_e(csrf_token()) . '">';
}

/** O pedido é um POST com token válido? Se o token falhar, regista um aviso. */
function panel_is_post(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return false;
    }
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'A sessão expirou. Tente novamente.');
        return false;
    }
    // permissões por ação (includes/permissions.php): o formulário não é processado se o perfil não puder
    $permissao = permission_for_action((string) ($_SERVER['SCRIPT_NAME'] ?? ''), (string) ($_POST['action'] ?? ''));
    if ($permissao !== null && !can($permissao)) {
        flash_set('error', 'O seu perfil não tem permissão para esta ação.');
        return false;
    }
    return true;
}

function panel_redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/** Regista uma ação no histórico da equipa (audit_logs). */
function panel_log(PDO $pdo, int $teamId, string $action, string $details = ''): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, team_id, username, action, details, ip_address)
             VALUES (:user_id, :team_id, :username, :action, :details, :ip)'
        );
        $stmt->execute([
            'user_id'  => (int) ($_SESSION['user']['id'] ?? 0) ?: null,
            'team_id'  => $teamId,
            'username' => (string) ($_SESSION['user']['username'] ?? ''),
            'action'   => $action,
            'details'  => $details,
            'ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[CourtIQ] Erro ao registar ação: ' . $e->getMessage());
    }
}

function pn_money(float|int|string|null $value): string
{
    return number_format((float) $value, 2, ',', '.') . ' MT';
}

/** Valores para o atributo data-values (preenche os formulários das janelas). */
function pn_values(array $values): string
{
    return pub_e(json_encode($values, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP));
}

/** Cabeçalho de página: título, descrição e botões à direita (HTML já escapado). */
function panel_head(string $title, string $subtitle = '', string $actionsHtml = ''): void
{
    echo '<header class="pn-head"><div><h1>' . pub_e($title) . '</h1>';
    if ($subtitle !== '') {
        echo '<p>' . pub_e($subtitle) . '</p>';
    }
    echo '</div>';
    if ($actionsHtml !== '') {
        echo '<div class="pn-head-actions">' . $actionsHtml . '</div>';
    }
    echo '</header>';
}

/**
 * Jogadores da equipa com as médias por jogo.
 * LEFT JOIN em users: nem todos os jogadores têm conta de acesso.
 */
function panel_players(PDO $pdo, int $teamId): array
{
    $stmt = $pdo->prepare(
        'SELECT p.*, COALESCE(u.name, p.name) AS name, u.email, u.username, u.phone,
                COUNT(s.id)                       AS games_played,
                COALESCE(AVG(s.points), 0)        AS avg_pts,
                COALESCE(AVG(s.rebounds), 0)      AS avg_reb,
                COALESCE(AVG(s.assists), 0)       AS avg_ast,
                COALESCE(AVG(s.steals), 0)        AS avg_stl,
                COALESCE(AVG(s.blocks), 0)        AS avg_blk,
                COALESCE(AVG(s.minutes_played), 0) AS avg_min,
                COALESCE(AVG(s.efficiency), 0)    AS avg_eff
         FROM players p
         LEFT JOIN users u ON u.id = p.user_id
         LEFT JOIN game_player_stats s ON s.player_id = p.id
         WHERE p.team_id = :team_id
         GROUP BY p.id
         ORDER BY p.jersey_number, p.id'
    );
    $stmt->execute(['team_id' => $teamId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Avatar redondo de um jogador (fotografia ou inicial). $basePath = '../' nos painéis. */
function pn_avatar(?string $photoUrl, ?string $name, string $basePath = '../', string $size = 'sm'): string
{
    $src = pub_img($photoUrl, $basePath);
    if ($src !== '') {
        return '<img class="lp-avatar lp-avatar--' . $size . '" src="' . pub_e($src) . '" alt="" loading="lazy">';
    }
    return '<span class="lp-avatar lp-avatar--' . $size . '">' . pub_initial($name) . '</span>';
}

function pn_training_type(?string $type): string
{
    return [
        'tactical'   => 'Tático',
        'technical'  => 'Técnico',
        'physical'   => 'Físico',
        'collective' => 'Coletivo',
        'strategic'  => 'Estratégico',
        'recovery'   => 'Recuperação',
    ][$type ?? ''] ?? 'Treino';
}

/** [rótulo, tipo de etiqueta] para o estado de um jogo ou treino. */
function pn_event_status(?string $status): array
{
    return [
        'scheduled'   => ['Agendado', 'info'],
        'in_progress' => ['A decorrer', 'accent'],
        'finished'    => ['Terminado', 'win'],
        'cancelled'   => ['Cancelado', 'loss'],
        'postponed'   => ['Adiado', 'gold'],
    ][$status ?? ''] ?? ['—', ''];
}
