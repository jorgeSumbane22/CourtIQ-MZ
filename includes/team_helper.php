<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * TeamHelper — isolamento de dados por equipa (multi-equipa)
 *
 * Regra única: a equipa de um utilizador é users.team_id.
 *   - admin / treinador / jogador  -> só vêem a equipa a que pertencem
 *   - superadmin (team_id NULL)    -> pode entrar em qualquer equipa (?equipa=ID)
 *   - visitante do site público    -> escolhe a equipa que quer ver (?equipa=ID)
 *
 * Nunca se cai para "a primeira equipa da tabela": um utilizador sem equipa
 * fica sem acesso em vez de ver os dados de outra equipa.
 *
 * @package CourtIQ
 * @version 3.0
 */
class TeamHelper
{
    /** @var array Cache por pedido para evitar consultas repetidas */
    private static array $cache = [];

    /**
     * ID da equipa do utilizador autenticado (null se não tiver equipa).
     *
     * @param string|null $role Mantido por compatibilidade; a equipa já não depende do papel.
     */
    public static function getCurrentTeamId(?string $role = null): ?int
    {
        if (empty($_SESSION['user']['id'])) {
            return null;
        }

        if (array_key_exists('current', self::$cache)) {
            return self::$cache['current'];
        }

        $user = self::fetchUser((int) $_SESSION['user']['id']);
        if (!$user) {
            return self::$cache['current'] = null;
        }

        if ($user['role'] === 'superadmin') {
            return self::$cache['current'] = self::superadminTeamId();
        }

        $teamId = $user['team_id'] !== null ? (int) $user['team_id'] : null;
        $_SESSION['user']['team_id'] = $teamId;

        return self::$cache['current'] = $teamId;
    }

    /**
     * O superadmin não pertence a nenhuma equipa: escolhe em qual quer entrar
     * com ?equipa=ID (fica guardado na sessão). Por omissão, a primeira ativa.
     */
    private static function superadminTeamId(): ?int
    {
        $requested = isset($_GET['equipa']) ? (int) $_GET['equipa'] : 0;
        if ($requested > 0 && self::getTeamInfo($requested)) {
            $_SESSION['acting_team_id'] = $requested;
        }

        $acting = (int) ($_SESSION['acting_team_id'] ?? 0);
        if ($acting > 0 && self::getTeamInfo($acting)) {
            return $acting;
        }

        $teams = self::activeTeams();
        return $teams ? (int) $teams[0]['id'] : null;
    }

    /**
     * Equipa a mostrar no site público.
     * Ordem: ?equipa=ID -> última escolhida -> equipa do utilizador autenticado -> primeira ativa.
     */
    public static function getPublicTeamId(): int
    {
        if (isset(self::$cache['public'])) {
            return self::$cache['public'];
        }

        $teams = self::activeTeams();
        $valid = array_map('intval', array_column($teams, 'id'));

        $requested = isset($_GET['equipa']) ? (int) $_GET['equipa'] : 0;
        if ($requested > 0 && in_array($requested, $valid, true) && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['public_team_id'] = $requested;
            unset($_SESSION['public_liga']);
        } elseif (isset($_GET['equipa']) && $requested === 0 && session_status() === PHP_SESSION_ACTIVE) {
            // ?equipa=0 ("Toda a liga"): esquece o clube escolhido e volta à página da liga
            unset($_SESSION['public_team_id']);
            $_SESSION['public_liga'] = true;
        }

        $candidates = [
            $requested,
            (int) ($_SESSION['public_team_id'] ?? 0),
            (int) ($_SESSION['user']['team_id'] ?? 0),
            $valid[0] ?? 0,
        ];
        foreach ($candidates as $candidate) {
            if ($candidate > 0 && in_array($candidate, $valid, true)) {
                return self::$cache['public'] = $candidate;
            }
        }

        return self::$cache['public'] = 0;
    }

    /**
     * Clube que o visitante escolheu ver (ou, com sessão, o da sua conta); 0 se ainda não escolheu nenhum.
     * Ao contrário de getPublicTeamId(), não cai no primeiro clube: o site usa-o para mandar
     * quem ainda não escolheu para a página da liga (visitante/inicio.php).
     */
    public static function getChosenPublicTeamId(): int
    {
        self::getPublicTeamId();   // trata ?equipa= e guarda a escolha na sessão
        $valid = array_map('intval', array_column(self::activeTeams(), 'id'));
        $candidates = [(int) ($_GET['equipa'] ?? 0), (int) ($_SESSION['public_team_id'] ?? 0)];
        if (empty($_SESSION['public_liga'])) {
            $candidates[] = (int) ($_SESSION['user']['team_id'] ?? 0);
        }
        foreach ($candidates as $candidate) {
            if ($candidate > 0 && in_array($candidate, $valid, true)) {
                return $candidate;
            }
        }
        return 0;
    }

    /** Equipas ativas (para os seletores de equipa). */
    public static function activeTeams(): array
    {
        if (isset(self::$cache['teams'])) {
            return self::$cache['teams'];
        }

        try {
            $stmt = Database::getConnection()->query(
                "SELECT id, name, city, logo_url, escaloes FROM teams WHERE status = 'active' ORDER BY id"
            );
            return self::$cache['teams'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[CourtIQ] Erro ao listar equipas: ' . $e->getMessage());
            return [];
        }
    }

    private static function fetchUser(int $userId): ?array
    {
        if (array_key_exists("user_{$userId}", self::$cache)) {
            return self::$cache["user_{$userId}"];
        }

        try {
            $stmt = Database::getConnection()->prepare('SELECT id, role, team_id FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($user) {
                $user['role'] = strtolower(trim((string) $user['role']));
            }
            return self::$cache["user_{$userId}"] = $user;
        } catch (Throwable $e) {
            error_log('[CourtIQ] Erro ao buscar utilizador: ' . $e->getMessage());
            return null;
        }
    }

    public static function getCurrentTeamInfo(): ?array
    {
        $teamId = self::getCurrentTeamId();
        return $teamId ? self::getTeamInfo($teamId) : null;
    }

    public static function getTeamInfo(int $teamId): ?array
    {
        if (array_key_exists("info_{$teamId}", self::$cache)) {
            return self::$cache["info_{$teamId}"];
        }

        try {
            $stmt = Database::getConnection()->prepare('SELECT * FROM teams WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $teamId]);
            return self::$cache["info_{$teamId}"] = ($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
        } catch (Throwable $e) {
            error_log('[CourtIQ] Erro ao buscar informações da equipa: ' . $e->getMessage());
            return null;
        }
    }

    public static function getTeamName(int $teamId): ?string
    {
        return self::getTeamInfo($teamId)['name'] ?? null;
    }

    public static function getCurrentTeamName(): ?string
    {
        $teamId = self::getCurrentTeamId();
        return $teamId ? self::getTeamName($teamId) : null;
    }

    /**
     * O utilizador autenticado pode ver/gerir os dados desta equipa?
     * Só o superadmin atravessa equipas.
     */
    public static function hasTeamAccess(int $teamId): bool
    {
        if (empty($_SESSION['user']['id'])) {
            return false;
        }

        $user = self::fetchUser((int) $_SESSION['user']['id']);
        if (!$user) {
            return false;
        }

        return $user['role'] === 'superadmin' || (int) $user['team_id'] === $teamId;
    }

    /** O superadmin ligou o modo de manutenção? (system_config.maintenance_mode = 1) */
    public static function maintenanceOn(): bool
    {
        if (array_key_exists('maintenance', self::$cache)) {
            return self::$cache['maintenance'];
        }
        try {
            $stmt = Database::getConnection()->query("SELECT config_value FROM system_config WHERE config_key = 'maintenance_mode'");
            return self::$cache['maintenance'] = ((string) $stmt->fetchColumn() === '1');
        } catch (Throwable $e) {
            return self::$cache['maintenance'] = false;
        }
    }

    /**
     * Em manutenção, só o superadmin continua a entrar: os outros vêem um aviso.
     * Chamado pelo site público e pelos painéis.
     */
    public static function maintenanceGuard(): void
    {
        if (!self::maintenanceOn() || self::isSuperadmin()) {
            return;
        }
        http_response_code(503);
        header('Retry-After: 3600');
        echo '<!DOCTYPE html><html lang="pt"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>CourtIQMZ — Em manutenção</title></head>'
           . '<body style="margin:0;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;'
           . 'background:#0a0d0c;color:#f0f6fc;font-family:sans-serif;text-align:center;padding:24px;">'
           . '<div style="font-size:48px;">&#128736;</div><h1 style="margin:0;">Estamos em manutenção</h1>'
           . '<p style="color:#8b949e;margin:0;max-width:46ch;">O CourtIQMZ está temporariamente indisponível para melhorias. Volte dentro de alguns minutos.</p>'
           . '</body></html>';
        exit;
    }

    public static function isSuperadmin(): bool
    {
        if (empty($_SESSION['user']['id'])) {
            return false;
        }
        return (self::fetchUser((int) $_SESSION['user']['id'])['role'] ?? null) === 'superadmin';
    }

    /**
     * O registo $id da tabela $table pertence à equipa do utilizador autenticado?
     * Usar antes de qualquer leitura/edição/remoção feita a partir de um ID vindo do pedido,
     * para que ninguém mexa em dados de outra equipa trocando o ID. O superadmin passa sempre.
     */
    public static function ownsRecord(string $table, int $id): bool
    {
        // tabela => SQL que devolve o team_id dono do registo
        static $owners = [
            'teams'             => 'SELECT id AS team_id FROM teams WHERE id = :id',
            'users'             => 'SELECT team_id FROM users WHERE id = :id',
            'players'           => 'SELECT team_id FROM players WHERE id = :id',
            'coaches'           => 'SELECT team_id FROM coaches WHERE id = :id',
            'games'             => 'SELECT team_id FROM games WHERE id = :id',
            'trainings'         => 'SELECT team_id FROM trainings WHERE id = :id',
            'finances'          => 'SELECT team_id FROM finances WHERE id = :id',
            'sponsors'          => 'SELECT team_id FROM sponsors WHERE id = :id',
            'news'              => 'SELECT team_id FROM news WHERE id = :id',
            'gallery_photos'    => 'SELECT team_id FROM gallery_photos WHERE id = :id',
            'comunicados'       => 'SELECT team_id FROM comunicados WHERE id = :id',
            'avaliacoes'        => 'SELECT team_id FROM avaliacoes WHERE id = :id',
            'contact_messages'  => 'SELECT team_id FROM contact_messages WHERE id = :id',
            'escalacao'         => 'SELECT g.team_id FROM escalacao x JOIN games g ON g.id = x.game_id WHERE x.id = :id',
            'estrategias_jogo'  => 'SELECT g.team_id FROM estrategias_jogo x JOIN games g ON g.id = x.game_id WHERE x.id = :id',
            'game_player_stats' => 'SELECT g.team_id FROM game_player_stats x JOIN games g ON g.id = x.game_id WHERE x.id = :id',
            'ticket_sales'      => 'SELECT g.team_id FROM ticket_sales x JOIN games g ON g.id = x.game_id WHERE x.id = :id',
            'attendance'        => 'SELECT p.team_id FROM attendance x JOIN players p ON p.id = x.player_id WHERE x.id = :id',
        ];

        if ($id <= 0 || !isset($owners[$table]) || empty($_SESSION['user']['id'])) {
            return false;
        }
        if (self::isSuperadmin()) {
            return true;
        }

        $teamId = self::getCurrentTeamId();
        if (!$teamId) {
            return false;
        }

        try {
            $stmt = Database::getConnection()->prepare($owners[$table]);
            $stmt->execute(['id' => $id]);
            $owner = $stmt->fetchColumn();
            return $owner !== false && $owner !== null && (int) $owner === $teamId;
        } catch (Throwable $e) {
            error_log('[CourtIQ] Erro ao verificar dono do registo: ' . $e->getMessage());
            return false;
        }
    }

    /** Papel do utilizador autenticado dentro da equipa indicada (ou null). */
    private static function roleInTeam(?int $teamId): ?string
    {
        $teamId = $teamId ?? self::getCurrentTeamId();
        if (!$teamId || !self::hasTeamAccess($teamId)) {
            return null;
        }
        return self::fetchUser((int) $_SESSION['user']['id'])['role'] ?? null;
    }

    public static function isTeamAdmin(?int $teamId = null): bool
    {
        return in_array(self::roleInTeam($teamId), ['admin', 'superadmin'], true);
    }

    public static function isTeamCoach(?int $teamId = null): bool
    {
        return self::roleInTeam($teamId) === 'treinador';
    }

    public static function canManageTeam(?int $teamId = null): bool
    {
        return self::isTeamAdmin($teamId);
    }

    /** Equipas a que o utilizador tem acesso (uma, ou todas no caso do superadmin). */
    public static function getUserTeams(int $userId): array
    {
        $user = self::fetchUser($userId);
        if (!$user) {
            return [];
        }
        if ($user['role'] === 'superadmin') {
            return self::activeTeams();
        }
        $team = $user['team_id'] !== null ? self::getTeamInfo((int) $user['team_id']) : null;
        return $team ? [$team] : [];
    }

    public static function getUserRoleInTeam(int $userId, int $teamId): ?string
    {
        $user = self::fetchUser($userId);
        if (!$user) {
            return null;
        }
        if ($user['role'] === 'superadmin' || (int) $user['team_id'] === $teamId) {
            return $user['role'];
        }
        return null;
    }

    /** Totais da equipa (jogadores, jogos, vitórias, pontos). */
    public static function getTeamStats(int $teamId): array
    {
        $stats = [
            'total_jogadores' => 0, 'total_jogos' => 0, 'total_vitorias' => 0, 'total_derrotas' => 0,
            'pontos_marcados' => 0, 'pontos_sofridos' => 0, 'aproveitamento' => 0,
        ];

        try {
            $pdo = Database::getConnection();

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM players WHERE team_id = :team_id');
            $stmt->execute(['team_id' => $teamId]);
            $stats['total_jogadores'] = (int) $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT COUNT(*) AS total,
                        COALESCE(SUM(our_score > opponent_score), 0) AS vitorias,
                        COALESCE(SUM(our_score < opponent_score), 0) AS derrotas,
                        COALESCE(SUM(our_score), 0)      AS pontos_marcados,
                        COALESCE(SUM(opponent_score), 0) AS pontos_sofridos
                 FROM games
                 WHERE team_id = :team_id AND status = 'finished'"
            );
            $stmt->execute(['team_id' => $teamId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $stats['total_jogos']     = (int) ($row['total'] ?? 0);
            $stats['total_vitorias']  = (int) ($row['vitorias'] ?? 0);
            $stats['total_derrotas']  = (int) ($row['derrotas'] ?? 0);
            $stats['pontos_marcados'] = (int) ($row['pontos_marcados'] ?? 0);
            $stats['pontos_sofridos'] = (int) ($row['pontos_sofridos'] ?? 0);
            if ($stats['total_jogos'] > 0) {
                $stats['aproveitamento'] = round($stats['total_vitorias'] / $stats['total_jogos'] * 100, 1);
            }
        } catch (Throwable $e) {
            error_log('[CourtIQ] Erro ao obter estatísticas da equipa: ' . $e->getMessage());
        }

        return $stats;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}

// ============================================================================
// FUNÇÕES GLOBAIS
// ============================================================================

/** Equipa do utilizador autenticado, seja qual for o papel. */
function current_team_id(): ?int
{
    return TeamHelper::getCurrentTeamId();
}

/** Nome antigo, usado em todas as páginas de admin e treinador. */
function current_admin_team_id(): ?int
{
    return TeamHelper::getCurrentTeamId();
}

function current_coach_team_id(): ?int
{
    return TeamHelper::getCurrentTeamId();
}

/** O registo pertence à equipa do utilizador autenticado? (ver TeamHelper::ownsRecord) */
function team_owns(string $table, int $id): bool
{
    return TeamHelper::ownsRecord($table, $id);
}

/** Equipa escolhida pelo visitante no site público. */
function public_team_id(): int
{
    return TeamHelper::getPublicTeamId();
}

/** Clube escolhido pelo visitante; 0 enquanto não escolher (ver TeamHelper::getChosenPublicTeamId). */
function public_team_chosen(): int
{
    return TeamHelper::getChosenPublicTeamId();
}

/** Equipas ativas, para os seletores. */
function public_teams(): array
{
    return TeamHelper::activeTeams();
}

function get_team_name(int $teamId): ?string
{
    return TeamHelper::getTeamName($teamId);
}

function is_team_admin(int $teamId): bool
{
    return TeamHelper::isTeamAdmin($teamId);
}

/** Membros (contas) de uma equipa — só para quem tem acesso a essa equipa. */
function get_team_members(int $teamId): array
{
    if (!TeamHelper::hasTeamAccess($teamId)) {
        return [];
    }

    try {
        $stmt = Database::getConnection()->prepare(
            "SELECT u.id, u.name, u.username, u.email, u.role, u.team_id, u.phone, u.status, u.avatar_initials, u.escaloes,
                    CASE u.role
                        WHEN 'admin'     THEN 'Administrador'
                        WHEN 'treinador' THEN 'Treinador'
                        WHEN 'jogador'   THEN 'Jogador'
                        ELSE u.role
                    END AS role_label
             FROM users u
             WHERE u.team_id = :team_id AND u.role <> 'adepto'
             ORDER BY FIELD(u.role, 'admin', 'treinador', 'jogador'), u.name"
        );
        $stmt->execute(['team_id' => $teamId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[CourtIQ] Erro ao buscar membros da equipa: ' . $e->getMessage());
        return [];
    }
}

function team_exists(int $teamId): bool
{
    return TeamHelper::getTeamInfo($teamId) !== null;
}
