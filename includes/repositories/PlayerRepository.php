<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../team_helper.php';

/**
 * PlayerRepository
 *
 * Isola todo o acesso a dados relativo a um jogador. As páginas (views) nunca
 * escrevem SQL diretamente — só chamam métodos daqui. Isto respeita SRP e torna
 * trivial trocar a fonte de dados no futuro (ex: cache, API) sem tocar nas views.
 */
final class PlayerRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    /**
     * Verifica se uma coluna existe na tabela
     */
    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :column");
            $stmt->execute(['column' => $column]);
            return (bool) $stmt->fetch();
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Busca perfil completo do jogador
     */
    public function findProfile(int $playerId): ?array
    {
        try {
            // Verificar se a coluna user_id existe
            $hasUserId = $this->columnExists('players', 'user_id');
            
            if ($hasUserId) {
                // LEFT JOIN: nem todos os jogadores têm conta de acesso
                $sql = 'SELECT p.*, COALESCE(u.name, p.name) AS name, u.email, u.username, u.avatar_initials,
                               t.name AS team_name
                        FROM players p
                        LEFT JOIN users u ON u.id = p.user_id
                        JOIN teams t ON t.id = p.team_id
                        WHERE p.id = :id
                        LIMIT 1';
            } else {
                // Buscar dados do jogador sem JOIN com users
                $sql = 'SELECT p.*, NULL AS email, NULL AS username, NULL AS avatar_initials,
                               t.name AS team_name
                        FROM players p
                        JOIN teams t ON t.id = p.team_id
                        WHERE p.id = :id
                        LIMIT 1';
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $playerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Se não encontrou, tenta buscar sem JOIN com teams
            if (!$row) {
                $sql = 'SELECT p.*, NULL AS team_name FROM players p WHERE p.id = :id LIMIT 1';
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(['id' => $playerId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            return $row ?: null;
        } catch (PDOException $e) {
            error_log("PlayerRepository::findProfile - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Médias da época atual (pontos, assistências, ressaltos, eficiência)
     */
    public function seasonAverages(int $playerId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT
                    COUNT(*)               AS jogos,
                    COALESCE(AVG(points), 0)     AS pts,
                    COALESCE(AVG(assists), 0)    AS ast,
                    COALESCE(AVG(rebounds), 0)   AS reb,
                    COALESCE(AVG(steals), 0)     AS steals,
                    COALESCE(AVG(blocks), 0)     AS blocks
                 FROM game_player_stats
                 WHERE player_id = :id'
            );
            $stmt->execute(['id' => $playerId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: ['jogos' => 0, 'pts' => 0, 'ast' => 0, 'reb' => 0, 'steals' => 0, 'blocks' => 0];
        } catch (PDOException $e) {
            error_log("PlayerRepository::seasonAverages - " . $e->getMessage());
            return ['jogos' => 0, 'pts' => 0, 'ast' => 0, 'reb' => 0, 'steals' => 0, 'blocks' => 0];
        }
    }

    /**
     * Melhores marcas individuais (recordes pessoais)
     */
    public function personalRecords(int $playerId): array
    {
        try {
            // Melhor pontuação
            $stmt = $this->pdo->prepare(
                'SELECT s.points, g.opponent, g.game_date
                 FROM game_player_stats s
                 JOIN games g ON g.id = s.game_id
                 WHERE s.player_id = :id
                 ORDER BY s.points DESC LIMIT 1'
            );
            $stmt->execute(['id' => $playerId]);
            $bestPoints = $stmt->fetch(PDO::FETCH_ASSOC);

            // Melhor assistências
            $stmt = $this->pdo->prepare(
                'SELECT s.assists, g.opponent, g.game_date
                 FROM game_player_stats s
                 JOIN games g ON g.id = s.game_id
                 WHERE s.player_id = :id
                 ORDER BY s.assists DESC LIMIT 1'
            );
            $stmt->execute(['id' => $playerId]);
            $bestAssists = $stmt->fetch(PDO::FETCH_ASSOC);

            // Melhor rebounds
            $stmt = $this->pdo->prepare(
                'SELECT s.rebounds, g.opponent, g.game_date
                 FROM game_player_stats s
                 JOIN games g ON g.id = s.game_id
                 WHERE s.player_id = :id
                 ORDER BY s.rebounds DESC LIMIT 1'
            );
            $stmt->execute(['id' => $playerId]);
            $bestRebounds = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'pts'              => (int) ($bestPoints['points'] ?? 0),
                'ast'              => (int) ($bestAssists['assists'] ?? 0),
                'reb'              => (int) ($bestRebounds['rebounds'] ?? 0),
                'adversario_pts'   => $bestPoints['opponent'] ?? '—',
                'adversario_ast'   => $bestAssists['opponent'] ?? '—',
                'adversario_reb'   => $bestRebounds['opponent'] ?? '—',
                'data_pts'         => $bestPoints['game_date'] ?? null,
                'data_ast'         => $bestAssists['game_date'] ?? null,
                'data_reb'         => $bestRebounds['game_date'] ?? null,
            ];
        } catch (PDOException $e) {
            error_log("PlayerRepository::personalRecords - " . $e->getMessage());
            return [
                'pts' => 0, 'ast' => 0, 'reb' => 0,
                'adversario_pts' => '—', 'adversario_ast' => '—', 'adversario_reb' => '—',
                'data_pts' => null, 'data_ast' => null, 'data_reb' => null
            ];
        }
    }

    /**
     * Percentagem de presença (treinos + jogos) do jogador
     */
    public function attendanceRate(int $playerId): float
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT
                    SUM(present = 1) AS presentes,
                    COUNT(*)         AS total
                 FROM attendance
                 WHERE player_id = :id'
            );
            $stmt->execute(['id' => $playerId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $total = (int) ($row['total'] ?? 0);
            if ($total === 0) {
                return 0.0;
            }
            return round(((int) $row['presentes'] / $total) * 100, 1);
        } catch (PDOException $e) {
            error_log("PlayerRepository::attendanceRate - " . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Próximo evento agendado (jogo ou treino) da equipa do jogador
     */
    public function nextEvent(int $teamId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "(SELECT 'jogo' AS tipo, opponent AS titulo, game_date AS data, game_time AS hora, location
                  FROM games WHERE team_id = :team_id1 AND status = 'scheduled' AND game_date >= CURDATE())
                 UNION ALL
                 (SELECT 'treino' AS tipo, name AS titulo, training_date AS data, start_time AS hora, location
                  FROM trainings WHERE team_id = :team_id2 AND status = 'scheduled' AND training_date >= CURDATE())
                 ORDER BY data ASC, hora ASC
                 LIMIT 1"
            );
            $stmt->execute(['team_id1' => $teamId, 'team_id2' => $teamId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log("PlayerRepository::nextEvent - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Série de pontos por jogo, para o gráfico Chart.js
     */
    public function pointsHistory(int $playerId, int $limit = 10): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT s.points, s.assists, s.rebounds, s.steals, s.blocks, 
                        g.opponent, g.game_date
                 FROM game_player_stats s
                 JOIN games g ON g.id = s.game_id
                 WHERE s.player_id = :id
                 ORDER BY g.game_date ASC
                 LIMIT :limit'
            );
            $stmt->bindValue('id', $playerId, PDO::PARAM_INT);
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("PlayerRepository::pointsHistory - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Histórico completo de estatísticas do jogador, jogo a jogo (mais recente primeiro)
     */
    public function gameLog(int $playerId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT s.*, g.opponent, g.game_date, g.game_time, g.location,
                        g.our_score, g.opponent_score, g.home_away
                 FROM game_player_stats s
                 JOIN games g ON g.id = s.game_id
                 WHERE s.player_id = :id
                 ORDER BY g.game_date DESC, g.game_time DESC'
            );
            $stmt->execute(['id' => $playerId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("PlayerRepository::gameLog - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Histórico de presenças (treinos + jogos) do jogador, mais recente primeiro
     */
    public function attendanceLog(int $playerId): array
    {
        try {
            $stmt = $this->pdo->prepare(
                "(SELECT 'jogo' AS tipo, g.opponent AS titulo, g.game_date AS data, 
                         a.present, a.status
                  FROM attendance a 
                  JOIN games g ON g.id = a.event_id AND a.event_type = 'game'
                  WHERE a.player_id = :id1)
                 UNION ALL
                 (SELECT 'treino' AS tipo, t.name AS titulo, t.training_date AS data,
                         a.present, a.status
                  FROM attendance a 
                  JOIN trainings t ON t.id = a.event_id AND a.event_type = 'training'
                  WHERE a.player_id = :id2)
                 ORDER BY data DESC"
            );
            $stmt->execute(['id1' => $playerId, 'id2' => $playerId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("PlayerRepository::attendanceLog - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Lista todos os jogadores de uma equipa
     */
    public function listByTeam(int $teamId): array
    {
        try {
            // Verificar se a coluna user_id existe
            $hasUserId = $this->columnExists('players', 'user_id');
            
            if ($hasUserId) {
                // LEFT JOIN: nem todos os jogadores têm conta de acesso
                $sql = 'SELECT p.*, COALESCE(u.name, p.name) AS name, u.email, u.username, u.avatar_initials,
                                COALESCE(AVG(s.points), 0) AS avg_pts,
                                COALESCE(AVG(s.rebounds), 0) AS avg_reb,
                                COALESCE(AVG(s.assists), 0) AS avg_ast
                         FROM players p
                         LEFT JOIN users u ON u.id = p.user_id
                         LEFT JOIN game_player_stats s ON s.player_id = p.id
                         WHERE p.team_id = :team_id
                         GROUP BY p.id
                         ORDER BY p.jersey_number ASC';
            } else {
                $sql = 'SELECT p.*, NULL AS email, NULL AS username, NULL AS avatar_initials,
                                COALESCE(AVG(s.points), 0) AS avg_pts,
                                COALESCE(AVG(s.rebounds), 0) AS avg_reb,
                                COALESCE(AVG(s.assists), 0) AS avg_ast
                         FROM players p
                         LEFT JOIN game_player_stats s ON s.player_id = p.id
                         WHERE p.team_id = :team_id
                         GROUP BY p.id
                         ORDER BY p.jersey_number ASC';
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['team_id' => $teamId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("PlayerRepository::listByTeam - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Busca jogador por ID
     */
    public function find(int $playerId): ?array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM players WHERE id = :id');
            $stmt->execute(['id' => $playerId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log("PlayerRepository::find - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Busca jogador por user_id (se a coluna existir)
     */
    public function findByUserId(int $userId): ?array
    {
        try {
            if (!$this->columnExists('players', 'user_id')) {
                return null;
            }
            $stmt = $this->pdo->prepare('SELECT * FROM players WHERE user_id = :user_id');
            $stmt->execute(['user_id' => $userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            error_log("PlayerRepository::findByUserId - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Cria um jogador novo
     */
    public function create(array $data): int
    {
        $this->pdo->beginTransaction();
        try {
            $hasUserId = $this->columnExists('players', 'user_id');
            $playerId = 0;

            if ($hasUserId && isset($data['user_id'])) {
                // Se tem user_id, usa diretamente
                $stmt = $this->pdo->prepare(
                    'INSERT INTO players (user_id, team_id, name, jersey_number, position, 
                                         birth_date, height, weight, status, is_captain, photo_url)
                     VALUES (:user_id, :team_id, :name, :jersey, :position, 
                             :birth_date, :height, :weight, :status, :captain, :photo)'
                );
                $stmt->execute([
                    'user_id'    => $data['user_id'],
                    'team_id'    => $data['team_id'],
                    'name'       => $data['name'],
                    'jersey'     => $data['jersey_number'] ?? 0,
                    'position'   => $data['position'] ?? 'PG',
                    'birth_date' => $data['birth_date'] ?? null,
                    'height'     => $data['height'] ?? null,
                    'weight'     => $data['weight'] ?? null,
                    'status'     => $data['status'] ?? 'active',
                    'captain'    => $data['is_captain'] ?? 0,
                    'photo'      => $data['photo_url'] ?? null,
                ]);
                $playerId = (int) $this->pdo->lastInsertId();
            } else {
                // Se não tem user_id, insere apenas na tabela players
                $stmt = $this->pdo->prepare(
                    'INSERT INTO players (team_id, name, jersey_number, position, 
                                         birth_date, height, weight, status, is_captain, photo_url)
                     VALUES (:team_id, :name, :jersey, :position, 
                             :birth_date, :height, :weight, :status, :captain, :photo)'
                );
                $stmt->execute([
                    'team_id'    => $data['team_id'],
                    'name'       => $data['name'],
                    'jersey'     => $data['jersey_number'] ?? 0,
                    'position'   => $data['position'] ?? 'PG',
                    'birth_date' => $data['birth_date'] ?? null,
                    'height'     => $data['height'] ?? null,
                    'weight'     => $data['weight'] ?? null,
                    'status'     => $data['status'] ?? 'active',
                    'captain'    => $data['is_captain'] ?? 0,
                    'photo'      => $data['photo_url'] ?? null,
                ]);
                $playerId = (int) $this->pdo->lastInsertId();
            }

            $this->pdo->commit();
            return $playerId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('[CourtIQ] Falha ao criar jogador: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Atualiza um jogador
     */
    public function update(int $playerId, array $data): bool
    {
        if (!team_owns('players', $playerId)) {
            return false; // registo de outra equipa
        }
        try {
            $fields = [];
            $params = ['id' => $playerId];

            if (isset($data['name'])) {
                $fields[] = 'name = :name';
                $params['name'] = $data['name'];
            }
            if (isset($data['team_id'])) {
                $fields[] = 'team_id = :team_id';
                $params['team_id'] = $data['team_id'];
            }
            if (isset($data['jersey_number'])) {
                $fields[] = 'jersey_number = :jersey_number';
                $params['jersey_number'] = $data['jersey_number'];
            }
            if (isset($data['position'])) {
                $fields[] = 'position = :position';
                $params['position'] = $data['position'];
            }
            if (isset($data['birth_date'])) {
                $fields[] = 'birth_date = :birth_date';
                $params['birth_date'] = $data['birth_date'];
            }
            if (isset($data['height'])) {
                $fields[] = 'height = :height';
                $params['height'] = $data['height'];
            }
            if (isset($data['weight'])) {
                $fields[] = 'weight = :weight';
                $params['weight'] = $data['weight'];
            }
            if (isset($data['status'])) {
                $fields[] = 'status = :status';
                $params['status'] = $data['status'];
            }
            if (isset($data['is_captain'])) {
                $fields[] = 'is_captain = :is_captain';
                $params['is_captain'] = $data['is_captain'];
            }
            if (isset($data['photo_url'])) {
                $fields[] = 'photo_url = :photo_url';
                $params['photo_url'] = $data['photo_url'];
            }

            if (empty($fields)) {
                return false;
            }

            $sql = 'UPDATE players SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("PlayerRepository::update - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Apaga um jogador
     */
    public function delete(int $playerId): bool
    {
        if (!team_owns('players', $playerId)) {
            return false; // registo de outra equipa
        }
        try {
            $stmt = $this->pdo->prepare('DELETE FROM players WHERE id = :id');
            return $stmt->execute(['id' => $playerId]);
        } catch (PDOException $e) {
            error_log("PlayerRepository::delete - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifica se username existe
     */
    public function usernameExists(string $username): bool
    {
        try {
            // Verificar se a tabela users existe
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'users'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                return false;
            }
            
            $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE username = :u LIMIT 1');
            $stmt->execute(['u' => $username]);
            return (bool) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("PlayerRepository::usernameExists - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Permite ao próprio jogador atualizar o seu nome/email
     */
    public function updateOwnContact(int $userId, string $name, string $email): bool
    {
        try {
            $stmt = $this->pdo->prepare('UPDATE users SET name = :name, email = :email WHERE id = :id');
            return $stmt->execute(['name' => $name, 'email' => $email, 'id' => $userId]);
        } catch (PDOException $e) {
            error_log("PlayerRepository::updateOwnContact - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Busca estatísticas do jogador por temporada
     */
    public function getStatsBySeason(int $playerId, string $season = null): array
    {
        try {
            $sql = 'SELECT 
                        COUNT(*) as games_played,
                        SUM(points) as total_points,
                        SUM(rebounds) as total_rebounds,
                        SUM(assists) as total_assists,
                        SUM(steals) as total_steals,
                        SUM(blocks) as total_blocks,
                        AVG(points) as avg_points,
                        AVG(rebounds) as avg_rebounds,
                        AVG(assists) as avg_assists,
                        MAX(points) as max_points,
                        MAX(rebounds) as max_rebounds,
                        MAX(assists) as max_assists
                    FROM game_player_stats
                    WHERE player_id = :id';
            
            $params = ['id' => $playerId];
            
            if ($season) {
                $sql .= ' AND season = :season';
                $params['season'] = $season;
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("PlayerRepository::getStatsBySeason - " . $e->getMessage());
            return [];
        }
    }
}