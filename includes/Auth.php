<?php
declare(strict_types=1);

/**
 * CourtIQMZ - Auth
 * Versão 6.3.0
 * - Normalização de role e paths
 * - Controlo de tentativas de login (rate limit / lockout)
 * - Autenticação com password_verify (via attempt())
 * - Suporte a Database::getConnection() (Singleton PDO)
 * - FIX: placeholders únicos (:u1, :u2) para compatibilidade com
 *        PDO::ATTR_EMULATE_PREPARES = false (MySQL nativo)
 */

require_once __DIR__ . '/Database.php';

class Auth
{
    // ========================================================================
    // CONFIGURAÇÃO DE TENTATIVAS
    // ========================================================================
    private const MAX_ATTEMPTS    = 5;   // falhas na mesma conta antes de bloquear
    private const MAX_ATTEMPTS_IP = 20;  // falhas vindas do mesmo endereço IP (várias contas)
    private const LOCKOUT_TIME    = 900; // janela e duração do bloqueio: 15 minutos

    // ========================================================================
    // ESTADO / SESSÃO
    // ========================================================================

    public static function check(): bool
    {
        return isset($_SESSION['user'])
            && is_array($_SESSION['user'])
            && !empty($_SESSION['user']['id']);
    }

    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public static function id(): int
    {
        return (int)($_SESSION['user']['id'] ?? 0);
    }

    /**
     * Role NORMALIZADA (minúsculas, sem espaços).
     */
    public static function role(): string
    {
        return strtolower(trim((string)($_SESSION['user']['role'] ?? '')));
    }

    // ========================================================================
    // AUTORIZAÇÃO
    // ========================================================================

    public static function requireRole(array $roles, ?string $redirect = null): void
    {
        // Muitas páginas passam '/login.php', que só existe se o site estiver na raiz do servidor.
        // Nesse caso usa-se o caminho real do login (ex.: /CourtIQ/login.php).
        $loginPath = ($redirect === null || $redirect === '/login.php') ? self::getLoginPath() : $redirect;

        if (!self::check()) {
            header('Location: ' . $loginPath);
            exit;
        }

        $userRole = self::role();
        $allowed  = array_map(fn($r) => strtolower(trim((string)$r)), $roles);

        if (!in_array($userRole, $allowed, true)) {
            header('Location: ' . $loginPath);
            exit;
        }
    }

    public static function requireLogin(?string $redirect = null): void
    {
        if (!self::check()) {
            header('Location: ' . (($redirect === null || $redirect === '/login.php') ? self::getLoginPath() : $redirect));
            exit;
        }
    }

    // ========================================================================
    // LOGIN / LOGOUT
    // ========================================================================

    /**
     * Login — normaliza a role ANTES de guardar.
     */
    public static function login(array $user): void
    {
        if (isset($user['role'])) {
            $user['role'] = strtolower(trim((string)$user['role']));
        }

        $_SESSION['user'] = $user;
        session_regenerate_id(true);
    }

    /**
     * Verifica credenciais e faz login.
     *
     * @param string      $username Utilizador ou email
     * @param string      $password Palavra-passe em texto simples
     * @param string|null $table    Tabela (default: 'users')
     */
    public static function attempt(string $username, string $password, ?string $table = 'users'): bool
    {
        $pdo = self::getPdo();
        if ($pdo === null) {
            error_log('[CourtIQ][Auth] PDO indisponível em attempt()');
            return false;
        }

        $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);

        try {
            // ⚠️ NÃO reutilizar o mesmo placeholder (:u).
            // Com PDO::ATTR_EMULATE_PREPARES = false, o MySQL nativo
            // rejeita a reutilização com: SQLSTATE[HY093] Invalid parameter number
            $sql = "SELECT * FROM `{$table}`
                    WHERE username = :u1 OR email = :u2
                    LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':u1' => $username,
                ':u2' => $username,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[CourtIQ][Auth] attempt() SQL falhou: ' . $e->getMessage());
            return false;
        }

        if (!$row) {
            return false;
        }

        // Campos possíveis para o hash
        $hash = $row['password_hash']
             ?? $row['password']
             ?? $row['senha']
             ?? null;

        if (!$hash || !self::verifyPassword($password, (string)$hash)) {
            return false;
        }

        // Bloquear contas inativas (schema usa 'status')
        if (isset($row['status']) && $row['status'] !== 'active') {
            return false;
        }
        if (isset($row['ativo']) && (int)$row['ativo'] === 0) {
            return false;
        }

        $user = [
            'id'       => (int)($row['id'] ?? 0),
            'username' => (string)($row['username'] ?? $username),
            'email'    => (string)($row['email'] ?? ''),
            'nome'     => (string)($row['name'] ?? $row['nome'] ?? $username),
            'name'     => (string)($row['name'] ?? $row['nome'] ?? $username),
            'phone'    => (string)($row['phone'] ?? ''),
            'role'     => strtolower(trim((string)($row['role'] ?? $row['tipo'] ?? 'jogador'))),
            // Multi-equipa: a equipa do utilizador acompanha a sessão
            'team_id'  => isset($row['team_id']) ? (int)$row['team_id'] : null,
            'player_id' => self::findPlayerId($pdo, (int)($row['id'] ?? 0)),
            'avatar_url' => (string)($row['avatar_url'] ?? ''),
        ];

        self::login($user);

        // Regista a entrada no histórico da equipa (página Logs dos painéis)
        try {
            $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id')->execute([':id' => $user['id']]);
            $pdo->prepare(
                "INSERT INTO audit_logs (user_id, team_id, username, action, details, ip_address)
                 VALUES (:user_id, :team_id, :username, 'login', 'Sessão iniciada', :ip)"
            )->execute([
                ':user_id'  => $user['id'],
                ':team_id'  => $user['team_id'],
                ':username' => $user['username'],
                ':ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('[CourtIQ][Auth] não foi possível registar o login: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * Confirma utilizador/email + palavra-passe SEM iniciar sessão. Devolve a conta
     * (id, username, role, team_id) ou null. Serve ao login para verificar o perfil,
     * o clube e o escalão escolhidos antes de deixar entrar.
     */
    public static function verifyCredentials(string $username, string $password): ?array
    {
        $pdo = self::getPdo();
        if ($pdo === null) {
            return null;
        }
        try {
            $stmt = $pdo->prepare('SELECT id, username, role, team_id, escaloes, status, password_hash FROM users WHERE username = :u1 OR email = :u2 LIMIT 1');
            $stmt->execute([':u1' => $username, ':u2' => $username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[CourtIQ][Auth] verifyCredentials() falhou: ' . $e->getMessage());
            return null;
        }
        if (!$row || $row['status'] !== 'active' || !self::verifyPassword($password, (string) $row['password_hash'])) {
            return null;
        }
        unset($row['password_hash']);
        return $row;
    }

    /**
     * Ficha de jogador (players.id) ligada a esta conta, se existir.
     */
    private static function findPlayerId(PDO $pdo, int $userId): ?int
    {
        try {
            $stmt = $pdo->prepare('SELECT id FROM players WHERE user_id = :u LIMIT 1');
            $stmt->execute([':u' => $userId]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int)$id : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function logout(): void
    {
        unset($_SESSION['user'], $_SESSION['session_token']);
        self::clearAttempts();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    // ========================================================================
    // CONTROLO DE TENTATIVAS (RATE LIMIT)
    // ========================================================================

    // As falhas ficam na tabela login_attempts, por conta e por endereço IP: apagar os
    // cookies ou mudar de browser não levanta o bloqueio. Conta-se uma janela deslizante
    // de LOCKOUT_TIME segundos; um login certo (ou uma palavra-passe reposta) limpa a conta.

    /** Chave da conta: o nome de utilizador se a conta existir (para o email contar para o mesmo), senão o texto escrito. */
    private static function attemptKey(?string $identifier): string
    {
        $key = mb_strtolower(trim((string) $identifier));
        if ($key === '') {
            return '';
        }
        try {
            $pdo = self::getPdo();
            if ($pdo) {
                $stmt = $pdo->prepare('SELECT username FROM users WHERE username = :u1 OR email = :u2 LIMIT 1');
                $stmt->execute([':u1' => $key, ':u2' => $key]);
                $found = $stmt->fetchColumn();
                if ($found !== false) {
                    $key = mb_strtolower((string) $found);
                }
            }
        } catch (Throwable $e) {
            // fica a chave escrita
        }
        return mb_substr($key, 0, 100);
    }

    /** [falhas na janela, data da mais antiga] para uma coluna (identifier ou ip_address). */
    private static function attemptWindow(string $column, string $value): array
    {
        $pdo = self::getPdo();
        if ($pdo === null || $value === '') {
            return [0, 0];
        }
        try {
            $column = $column === 'ip_address' ? 'ip_address' : 'identifier';
            $stmt = $pdo->prepare("SELECT COUNT(*), MIN(created_at) FROM login_attempts WHERE {$column} = :v AND created_at > :since");
            $stmt->execute([':v' => $value, ':since' => date('Y-m-d H:i:s', time() - self::LOCKOUT_TIME)]);
            [$count, $oldest] = $stmt->fetch(PDO::FETCH_NUM);
            return [(int) $count, $oldest ? (int) strtotime((string) $oldest) : 0];
        } catch (Throwable $e) {
            error_log('[CourtIQ][Auth] login_attempts: ' . $e->getMessage());
            return [0, 0];
        }
    }

    public static function tooManyAttempts(?string $identifier = null): bool
    {
        return self::lockoutRemaining($identifier) > 0;
    }

    /** Segundos que faltam para a conta (ou o IP) poder voltar a tentar. */
    public static function lockoutRemaining(?string $identifier = null): int
    {
        $remaining = 0;
        [$count, $oldest] = self::attemptWindow('identifier', self::attemptKey($identifier));
        if ($count >= self::MAX_ATTEMPTS) {
            $remaining = max($remaining, $oldest + self::LOCKOUT_TIME - time());
        }
        [$count, $oldest] = self::attemptWindow('ip_address', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($count >= self::MAX_ATTEMPTS_IP) {
            $remaining = max($remaining, $oldest + self::LOCKOUT_TIME - time());
        }
        return max(0, $remaining);
    }

    public static function registerFailedAttempt(?string $identifier = null): void
    {
        $pdo = self::getPdo();
        if ($pdo === null) {
            return;
        }
        try {
            $pdo->prepare('INSERT INTO login_attempts (identifier, ip_address, created_at) VALUES (:i, :ip, :now)')
                ->execute([':i' => self::attemptKey($identifier), ':ip' => $_SERVER['REMOTE_ADDR'] ?? null, ':now' => date('Y-m-d H:i:s')]);
            // a tabela não cresce: o que tem mais de um dia já não conta para nada
            if (random_int(1, 20) === 1) {
                $pdo->prepare('DELETE FROM login_attempts WHERE created_at < :old')->execute([':old' => date('Y-m-d H:i:s', time() - 86400)]);
            }
        } catch (Throwable $e) {
            error_log('[CourtIQ][Auth] login_attempts: ' . $e->getMessage());
        }
    }

    /** Limpa as falhas de uma conta (depois de entrar ou de repor a palavra-passe). */
    public static function clearAttempts(?string $identifier = null): void
    {
        unset($_SESSION['login_attempts'], $_SESSION['failed_attempts'], $_SESSION['last_attempt_time'],
              $_SESSION['login_blocked_until'], $_SESSION['attempts'], $_SESSION['locked_until']);

        $key = self::attemptKey($identifier);
        $pdo = self::getPdo();
        if ($key === '' || $pdo === null) {
            return;
        }
        try {
            $pdo->prepare('DELETE FROM login_attempts WHERE identifier = :i')->execute([':i' => $key]);
        } catch (Throwable $e) {
            error_log('[CourtIQ][Auth] login_attempts: ' . $e->getMessage());
        }
    }

    // ========================================================================
    // PATHS / URLS
    // ========================================================================

    public static function getBasePath(): string
    {
        static $basePath = null;
        if ($basePath !== null) {
            return $basePath;
        }

        if (PHP_SAPI === 'cli' || empty($_SERVER['SCRIPT_NAME'])) {
            return $basePath = '/';
        }

        $scriptPath = str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']);

        $knownFolders = [
            '/treinador/', '/admin/', '/jogador/', '/superadmin/',
            '/visitante/', '/process/', '/api/', '/includes/', '/auth/',
        ];

        foreach ($knownFolders as $folder) {
            $pos = strpos($scriptPath, $folder);
            if ($pos !== false) {
                return $basePath = substr($scriptPath, 0, $pos) . '/';
            }
        }

        $dir = dirname($scriptPath);
        $subFolders = ['treinador', 'admin', 'jogador', 'superadmin', 'visitante', 'process', 'api', 'includes', 'auth'];
        $dirParts = explode('/', trim($dir, '/'));

        if (!empty($dirParts) && in_array(end($dirParts), $subFolders, true)) {
            array_pop($dirParts);
            $dir = '/' . implode('/', $dirParts);
        }

        $basePath = rtrim($dir, '/') . '/';
        if ($basePath === '//' || $basePath === '') {
            $basePath = '/';
        }

        return $basePath;
    }

    public static function getLoginPath(): string
    {
        return self::getBasePath() . 'login.php';
    }

    public static function getLogoutPath(): string
    {
        return self::getBasePath() . 'process/logout.php';
    }

    public static function url(string $path): string
    {
        return self::getBasePath() . ltrim($path, '/');
    }

    public static function absoluteUrl(string $path): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . '://' . $host . self::url($path);
    }

    // ========================================================================
    // HELPERS INTERNOS
    // ========================================================================

    /**
     * Verifica password contra hash bcrypt/argon2 (password_verify),
     * com fallback para MD5/SHA1 (BD antiga).
     */
    private static function verifyPassword(string $plain, string $hash): bool
    {
        // 1) Hash moderno (bcrypt/argon2)
        $info = password_get_info($hash);
        if (!empty($info['algo']) && $info['algo'] !== 0) {
            return password_verify($plain, $hash);
        }

        // 2) MD5 (32 hex)
        if (preg_match('/^[a-f0-9]{32}$/i', $hash)) {
            return hash_equals(strtolower($hash), md5($plain));
        }

        // 3) SHA1 (40 hex)
        if (preg_match('/^[a-f0-9]{40}$/i', $hash)) {
            return hash_equals(strtolower($hash), sha1($plain));
        }

        // 4) Texto simples (migração)
        return hash_equals($hash, $plain);
    }

    /**
     * Obtém PDO: globais → função db() → Database::getConnection().
     */
    private static function getPdo(): ?PDO
    {
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
            return $GLOBALS['conn'];
        }
        if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
            return $GLOBALS['db'];
        }
        if (function_exists('db')) {
            try {
                $maybe = db();
                if ($maybe instanceof PDO) {
                    return $maybe;
                }
            } catch (Throwable $e) {
                // ignora e tenta Database::getConnection()
            }
        }

        if (class_exists('Database') && method_exists('Database', 'getConnection')) {
            try {
                $pdo = Database::getConnection();
                if ($pdo instanceof PDO) {
                    return $pdo;
                }
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }
}