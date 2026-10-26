<?php
declare(strict_types=1);

/**
 * permissions.php — quem pode fazer o quê no CourtIQMZ.
 *
 * Perfis:
 *   visitante   sem conta: vê o essencial do site público
 *   adepto      conta criada em registar.php: acompanha um clube (notificações, estatísticas, galeria, bilhetes)
 *   jogador     adepto aceite pelo treinador do clube: painel do jogador
 *   treinador   no máximo 2 por clube
 *   admin       1 por clube
 *   superadmin  1 em toda a plataforma; pode tudo
 *
 * A matriz abaixo é a única fonte: as páginas perguntam can('...') e os formulários dos
 * painéis são verificados em panel_is_post() através de PERM_ACTIONS.
 */

/** Quantas contas de cada papel um clube pode ter. */
const ROLE_LIMITS      = ['admin' => 1, 'treinador' => 2];
const SUPERADMIN_LIMIT = 1;

const ROLE_LABELS = [
    'superadmin' => 'Superadministrador',
    'admin'      => 'Administrador',
    'treinador'  => 'Treinador',
    'jogador'    => 'Jogador',
    'adepto'     => 'Adepto',
    'visitante'  => 'Visitante',
];

/** Permissão => [descrição, papéis que a têm]. O superadmin tem sempre todas. */
const PERMISSIONS = [
    // ---- site público
    'site.basic'            => ['Ver início, jogos, resultados, calendário, classificação, notícias, plantel e contacto', ['visitante', 'adepto', 'jogador', 'treinador', 'admin']],
    'site.stats'            => ['Ver estatísticas detalhadas no site',                 ['adepto', 'jogador', 'treinador', 'admin']],
    'site.gallery'          => ['Ver a galeria de fotografias',                        ['adepto', 'jogador', 'treinador', 'admin']],
    'site.tickets'          => ['Comprar bilhetes',                                    ['adepto', 'jogador', 'treinador', 'admin']],
    'site.follow'           => ['Seguir um clube e receber as novidades',              ['adepto']],
    'site.request_player'   => ['Pedir para entrar no plantel',                        ['adepto']],

    // ---- painel do jogador
    'player.panel'          => ['Painel do jogador (perfil, estatísticas, presenças)', ['jogador']],

    // ---- plantel
    'players.manage'        => ['Criar e editar jogadores',                            ['treinador', 'admin']],
    'players.delete'        => ['Remover jogadores do plantel',                        ['treinador', 'admin']],
    'accounts.create'       => ['Criar contas de acesso para jogadores',               ['treinador', 'admin']],
    'accounts.reset'        => ['Repor a palavra-passe de membros do clube',           ['admin']],
    'requests.decide'       => ['Aceitar ou recusar pedidos de inscrição de jogadores', ['treinador', 'admin']],

    // ---- competição e treino
    'games.manage'          => ['Agendar jogos, registar resultados e estatísticas',   ['treinador', 'admin']],
    'games.delete'          => ['Apagar jogos',                                        ['treinador', 'admin']],
    'trainings.manage'      => ['Gerir treinos',                                       ['treinador', 'admin']],
    'attendance.mark'       => ['Marcar presenças',                                    ['treinador', 'admin']],
    'lineup.manage'         => ['Convocatórias e escalação',                           ['treinador', 'admin']],
    'tactics.manage'        => ['Táticas',                                             ['treinador', 'admin']],
    'evaluations.manage'    => ['Avaliar jogadores',                                   ['treinador', 'admin']],
    'announcements.manage'  => ['Publicar comunicados à equipa',                       ['treinador', 'admin']],
    'notifications.send'    => ['Enviar notificações',                                 ['treinador', 'admin']],

    // ---- gestão do clube
    'team.edit'             => ['Editar dados do clube e equipa técnica',              ['admin']],
    'finances.manage'       => ['Finanças',                                            ['admin']],
    'content.manage'        => ['Notícias, galeria e patrocinadores do site',          ['admin']],
    'tickets.manage'        => ['Bilhetes vendidos e validação à entrada',             ['admin']],
    'messages.manage'       => ['Mensagens recebidas pelo site',                       ['admin']],

    'chat.use'              => ['Chat do clube',                                       ['jogador', 'treinador', 'admin']],
    'chat.moderate'         => ['Apagar mensagens de outros na sala do clube',         ['admin']],

    // ---- plataforma
    'platform.manage'       => ['Clubes, contas, auditoria, manutenção e cópias globais', []],
];

/**
 * Ações dos formulários dos painéis: página => [ação => permissão]. '*' vale para as restantes ações.
 * As páginas que não aparecem aqui ficam só com o controlo de entrada do painel (panel_boot).
 */
const PERM_ACTIONS = [
    'jogadores.php'      => ['delete' => 'players.delete', '*' => 'players.manage'],
    'plantel.php'        => ['delete' => 'players.delete', '*' => 'players.manage'],
    'pedidos.php'        => ['*' => 'requests.decide'],
    'jogos.php'          => ['delete' => 'games.delete', '*' => 'games.manage'],
    'treinos.php'        => ['*' => 'trainings.manage'],
    'presencas.php'      => ['*' => 'attendance.mark'],
    'escalacao.php'      => ['*' => 'lineup.manage'],
    'taticas.php'        => ['*' => 'tactics.manage'],
    'avaliacoes.php'     => ['*' => 'evaluations.manage'],
    'comunicados.php'    => ['*' => 'announcements.manage'],
    'notificacoes.php'   => ['send' => 'notifications.send', 'message_status' => 'messages.manage', 'message_delete' => 'messages.manage'],
    'equipas.php'        => ['reset_link' => 'accounts.reset', '*' => 'team.edit'],
    'financas.php'       => ['*' => 'finances.manage'],
    'noticias.php'       => ['*' => 'content.manage'],
    'galeria.php'        => ['*' => 'content.manage'],
    'patrocinadores.php' => ['*' => 'content.manage'],
    'bilhetes.php'       => ['*' => 'tickets.manage'],
];

/** Papel de quem está a usar o site ('visitante' se não tiver sessão). */
function current_role(): string
{
    $role = strtolower(trim((string) ($_SESSION['user']['role'] ?? '')));
    return isset(ROLE_LABELS[$role]) && $role !== 'visitante' && !empty($_SESSION['user']['id']) ? $role : 'visitante';
}

/** O papel indicado (por omissão, o de quem está a usar o site) tem esta permissão? */
function can(string $permission, ?string $role = null): bool
{
    $role ??= current_role();
    if ($role === 'superadmin') {
        return true;
    }
    return isset(PERMISSIONS[$permission]) && in_array($role, PERMISSIONS[$permission][1], true);
}

/** Lança o erro mostrado ao utilizador se faltar a permissão. */
function require_can(string $permission): void
{
    if (!can($permission)) {
        throw new RuntimeException('O seu perfil não tem permissão para esta ação.');
    }
}

/** Permissão exigida por uma ação de um formulário do painel (null = sem regra própria). */
function permission_for_action(string $page, string $action): ?string
{
    $rules = PERM_ACTIONS[basename($page)] ?? null;
    return $rules === null ? null : ($rules[$action] ?? $rules['*'] ?? null);
}

function role_label(?string $role): string
{
    return ROLE_LABELS[strtolower((string) $role)] ?? ucfirst((string) $role);
}

/**
 * Garante os limites de contas: 1 superadmin na plataforma, 1 administrador e 2 treinadores por clube.
 * $ignoreUserId é a conta que está a ser editada (não conta contra si própria).
 */
function role_limit_check(PDO $pdo, string $role, ?int $teamId, int $ignoreUserId = 0): void
{
    if ($role === 'superadmin') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND id <> :id");
        $stmt->execute(['id' => $ignoreUserId]);
        if ((int) $stmt->fetchColumn() >= SUPERADMIN_LIMIT) {
            throw new RuntimeException('Só pode existir ' . SUPERADMIN_LIMIT . ' superadministrador na plataforma.');
        }
        return;
    }
    if (!isset(ROLE_LIMITS[$role]) || !$teamId) {
        return;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = :role AND team_id = :team_id AND id <> :id');
    $stmt->execute(['role' => $role, 'team_id' => $teamId, 'id' => $ignoreUserId]);
    if ((int) $stmt->fetchColumn() >= ROLE_LIMITS[$role]) {
        $max = ROLE_LIMITS[$role];
        throw new RuntimeException($role === 'admin'
            ? "Este clube já tem $max administrador. Cada clube só pode ter $max."
            : "Este clube já tem $max treinadores. Cada clube só pode ter $max.");
    }
}

// ============================================================================
// ESCALÕES — cada clube trabalha por escalão: iniciados, juniores e seniores.
// Jogadores, jogos e treinos pertencem a um escalão; o clube escolhe os que tem.
// ============================================================================
const ESCALOES = ['iniciados' => 'Iniciados', 'juniores' => 'Juniores', 'seniores' => 'Seniores'];

function escalao_label(?string $escalao): string
{
    return ESCALOES[(string) $escalao] ?? 'Seniores';
}

/** Escalões que o clube tem (teams.escaloes), pela ordem iniciados → seniores. Sem nada definido, todos. */
function team_escaloes(?array $team): array
{
    $tem = array_filter(array_map('trim', explode(',', (string) ($team['escaloes'] ?? ''))));
    $out = array_intersect_key(ESCALOES, array_flip($tem));
    return $out ?: ESCALOES;
}

/**
 * Escalões que um treinador treina (users.escaloes) dentro dos do clube.
 * Sem nada indicado, treina todos os do clube.
 */
function coach_escaloes(?array $team, ?string $guardado): array
{
    $doClube = team_escaloes($team);
    $seus    = array_intersect_key($doClube, array_flip(array_filter(array_map('trim', explode(',', (string) $guardado)))));
    return $seus ?: $doClube;
}

/**
 * Escalões com que quem está a usar o painel pode trabalhar: os do clube, exceto para um
 * treinador com escalões atribuídos, que fica só com os seus. Lê-se da base de dados em cada
 * pedido, para a alteração feita pelo administrador valer de imediato.
 */
function my_escaloes(?array $team): array
{
    static $cache = [];
    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    if (current_role() !== 'treinador' || $userId <= 0) {
        return team_escaloes($team);
    }
    $key = $userId . ':' . (string) ($team['escaloes'] ?? '');
    if (!isset($cache[$key])) {
        try {
            $stmt = Database::getConnection()->prepare('SELECT escaloes FROM users WHERE id = :id');
            $stmt->execute(['id' => $userId]);
            $cache[$key] = coach_escaloes($team, (string) $stmt->fetchColumn());
        } catch (Throwable $e) {
            $cache[$key] = team_escaloes($team);
        }
    }
    return $cache[$key];
}

/** Valida o escalão vindo de um formulário contra os permitidos; devolve o primeiro válido se não servir. */
function escalao_from_post(?array $team, $valor): string
{
    $validos = my_escaloes($team);
    $valor   = (string) $valor;
    return isset($validos[$valor]) ? $valor : (isset($validos['seniores']) ? 'seniores' : (string) array_key_first($validos));
}

/** Lista de escalões de um formulário (caixas de seleção) → texto guardado em teams.escaloes. */
function escaloes_from_post($valores): string
{
    $escolhidos = array_intersect(array_keys(ESCALOES), array_map('strval', (array) $valores));
    return implode(',', $escolhidos ?: array_keys(ESCALOES));
}

/**
 * Escalão que o utilizador está a ver nas listas ('' = todos). Escolhe-se com ?escalao=…
 * e fica guardado na sessão para as páginas seguintes.
 */
function escalao_atual(?array $team): string
{
    $validos = my_escaloes($team);
    if (isset($_GET['escalao'])) {
        $pedido = (string) $_GET['escalao'];
        $_SESSION['escalao'] = isset($validos[$pedido]) ? $pedido : '';
    }
    $atual = (string) ($_SESSION['escalao'] ?? '');
    if (isset($validos[$atual])) {
        return $atual;
    }
    // um treinador que não treina todos os escalões do clube nunca vê "todos": fica num dos seus
    return count($validos) < count(team_escaloes($team)) ? (string) array_key_first($validos) : '';
}

/** Quem está a usar o painel pode trabalhar com este escalão? (um treinador só com os seus) */
function escalao_allowed(?array $team, ?string $escalao): bool
{
    return isset(my_escaloes($team)[(string) $escalao]);
}

/** Das linhas dadas (jogadores, jogos, treinos…), só as do escalão em vista. Com '' ficam todas. */
function escalao_only(array $rows, string $escalao): array
{
    return $escalao === '' ? $rows : array_values(array_filter($rows, fn($r) => ($r['escalao'] ?? 'seniores') === $escalao));
}

/**
 * Pedaço de SQL para limitar uma consulta ao escalão em vista: " AND p.escalao = 'juniores'".
 * Com '' devolve vazio. O valor vem sempre da lista fixa ESCALOES, nunca do pedido.
 */
function escalao_sql(string $column, string $escalao): string
{
    return isset(ESCALOES[$escalao]) && preg_match('/^[a-z_.]+$/', $column) ? " AND $column = '$escalao'" : '';
}

/** Botões para escolher o escalão em vista. */
function escalao_chips(?array $team, string $atual, string $page): string
{
    $validos  = my_escaloes($team);
    $restrito = count($validos) < count(team_escaloes($team));
    if (count($validos) < 2 && !$restrito) {
        return '';
    }
    $sep  = strpos($page, '?') === false ? '?' : (substr($page, -1) === '?' ? '' : '&');
    $html = '<nav class="lp-chips" aria-label="Escalão">';
    if (!$restrito) {
        $html .= '<a class="lp-chip' . ($atual === '' ? ' is-active' : '') . '" href="' . htmlspecialchars($page . $sep . 'escalao=todos', ENT_QUOTES) . '">Todos os escalões</a>';
    }
    foreach ($validos as $k => $v) {
        $html .= '<a class="lp-chip' . ($atual === $k ? ' is-active' : '') . '" href="' . htmlspecialchars($page . $sep . 'escalao=' . $k, ENT_QUOTES) . '"'
               . ($atual === $k ? ' aria-current="true"' : '') . '>' . $v . '</a>';
    }
    return $html . '</nav>';
}

/** <option>s de escalão para um <select>. */
function escalao_options(?array $team, string $selecionado = ''): string
{
    $html = '';
    foreach (my_escaloes($team) as $k => $v) {
        $html .= '<option value="' . $k . '"' . ($k === $selecionado ? ' selected' : '') . '>' . $v . '</option>';
    }
    return $html;
}

/** "CourtIQ" é o nome da plataforma (CourtIQMZ): nenhum clube o pode usar no seu nome. */
function team_name_check(string $name): void
{
    if (preg_match('/court\s*-?\s*iq/iu', $name)) {
        throw new RuntimeException('"CourtIQ" é o nome da plataforma e não pode fazer parte do nome de um clube. Escolha outro nome.');
    }
}

/** Avisa os adeptos de um clube (aparece em "A minha conta"). Nunca interrompe a ação que o chamou. */
function notify_fans(PDO $pdo, int $teamId, string $title, string $message, string $type = 'info'): int
{
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE team_id = :team_id AND role = 'adepto' AND status = 'active'");
        $stmt->execute(['team_id' => $teamId]);
        $ins = $pdo->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (:user_id, :title, :message, :type)');
        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $ins->execute(['user_id' => (int) $userId, 'title' => mb_substr($title, 0, 200), 'message' => $message, 'type' => $type]);
            $n++;
        }
        return $n;
    } catch (Throwable $e) {
        error_log('[CourtIQ] notify_fans: ' . $e->getMessage());
        return 0;
    }
}

/** Avisa uma conta. */
function notify_user(PDO $pdo, int $userId, string $title, string $message, string $type = 'info'): void
{
    try {
        $pdo->prepare('INSERT INTO notifications (user_id, title, message, type) VALUES (:user_id, :title, :message, :type)')
            ->execute(['user_id' => $userId, 'title' => mb_substr($title, 0, 200), 'message' => $message, 'type' => $type]);
    } catch (Throwable $e) {
        error_log('[CourtIQ] notify_user: ' . $e->getMessage());
    }
}

/**
 * Atualiza a sessão se o papel ou o clube da conta mudaram na base de dados
 * (por exemplo, quando o treinador aceita um pedido de jogador). Devolve true se mudou.
 */
function session_sync_role(PDO $pdo): bool
{
    $id = (int) ($_SESSION['user']['id'] ?? 0);
    if ($id <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT role, team_id, status, (SELECT p.id FROM players p WHERE p.user_id = u.id LIMIT 1) AS player_id FROM users u WHERE u.id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status'] !== 'active') {
        Auth::logout();
        return true;
    }
    $role = strtolower((string) $row['role']);
    $team = $row['team_id'] !== null ? (int) $row['team_id'] : null;
    if ($role === ($_SESSION['user']['role'] ?? '') && $team === ($_SESSION['user']['team_id'] ?? null)) {
        return false;
    }
    $_SESSION['user']['role']      = $role;
    $_SESSION['user']['team_id']   = $team;
    $_SESSION['user']['player_id'] = $row['player_id'] !== null ? (int) $row['player_id'] : null;
    return true;
}
