<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../team_helper.php';

/** GameRepository — jogos e estatísticas por jogador/jogo. */
final class GameRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function listByTeam(int $teamId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM games WHERE team_id = :t ORDER BY game_date DESC');
        $stmt->execute(['t' => $teamId]);
        return $stmt->fetchAll();
    }

    public function upcoming(int $teamId, int $limit = 5): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM games WHERE team_id = :t AND status = 'scheduled' AND game_date >= CURDATE()
             ORDER BY game_date ASC LIMIT :lim"
        );
        $stmt->bindValue('t', $teamId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function finished(int $teamId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM games WHERE team_id = :t AND status = 'finished'
             ORDER BY game_date DESC LIMIT :lim"
        );
        $stmt->bindValue('t', $teamId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM games WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO games (team_id, opponent, game_date, game_time, location, home_away, status)
             VALUES (:team_id, :opponent, :date, :time, :location, :home_away, "scheduled")'
        );
        $stmt->execute([
            'team_id'   => $data['team_id'],
            'opponent'  => $data['opponent'],
            'date'      => $data['game_date'],
            'time'      => $data['game_time'] ?: null,
            'location'  => $data['location'] ?? null,
            'home_away' => $data['home_away'] ?? 'home',
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        if (!team_owns('games', $id)) {
            return false; // registo de outra equipa
        }
        $stmt = $this->pdo->prepare(
            'UPDATE games SET opponent = :opponent, game_date = :date, game_time = :time, location = :location,
                               home_away = :home_away WHERE id = :id'
        );
        return $stmt->execute([
            'opponent'  => $data['opponent'],
            'date'      => $data['game_date'],
            'time'      => $data['game_time'] ?: null,
            'location'  => $data['location'] ?? null,
            'home_away' => $data['home_away'] ?? 'home',
            'id'        => $id,
        ]);
    }

    /** Regista o resultado final de um jogo (marca-o como terminado). */
    public function finalize(int $id, int $ourScore, int $opponentScore): bool
    {
        if (!team_owns('games', $id)) {
            return false; // registo de outra equipa
        }
        $stmt = $this->pdo->prepare(
            "UPDATE games SET our_score = :our, opponent_score = :opp, status = 'finished' WHERE id = :id"
        );
        return $stmt->execute(['our' => $ourScore, 'opp' => $opponentScore, 'id' => $id]);
    }

    public function delete(int $id): bool
    {
        if (!team_owns('games', $id)) {
            return false; // registo de outra equipa
        }
        $stmt = $this->pdo->prepare('DELETE FROM games WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    /** Estatísticas de todos os jogadores num jogo específico (para a ficha de jogo). */
    public function statsForGame(int $gameId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, u.name, p.jersey_number
             FROM game_player_stats s
             JOIN players p ON p.id = s.player_id
             JOIN users u ON u.id = p.user_id
             WHERE s.game_id = :g
             ORDER BY s.points DESC'
        );
        $stmt->execute(['g' => $gameId]);
        return $stmt->fetchAll();
    }

    /** Grava (cria ou atualiza) a estatística de um jogador num jogo. Upsert por chave única (game_id, player_id). */
    public function saveStat(int $gameId, int $playerId, array $stats): bool
    {
        if (!team_owns('games', $gameId) || !team_owns('players', $playerId)) {
            return false; // jogo ou jogador de outra equipa
        }
        $efficiency = ($stats['points'] + $stats['rebounds'] + $stats['assists'] + $stats['steals'] + $stats['blocks'])
            - ($stats['fouls'] ?? 0);

        $stmt = $this->pdo->prepare(
            'INSERT INTO game_player_stats (game_id, player_id, points, rebounds, assists, steals, blocks, fouls, minutes_played, efficiency)
             VALUES (:g, :p, :pts, :reb, :ast, :stl, :blk, :fou, :min, :eff)
             ON DUPLICATE KEY UPDATE points = :pts2, rebounds = :reb2, assists = :ast2, steals = :stl2,
                                      blocks = :blk2, fouls = :fou2, minutes_played = :min2, efficiency = :eff2'
        );
        return $stmt->execute([
            'g' => $gameId, 'p' => $playerId,
            'pts' => $stats['points'], 'reb' => $stats['rebounds'], 'ast' => $stats['assists'],
            'stl' => $stats['steals'], 'blk' => $stats['blocks'], 'fou' => $stats['fouls'] ?? 0,
            'min' => $stats['minutes_played'] ?? 0, 'eff' => $efficiency,
            'pts2' => $stats['points'], 'reb2' => $stats['rebounds'], 'ast2' => $stats['assists'],
            'stl2' => $stats['steals'], 'blk2' => $stats['blocks'], 'fou2' => $stats['fouls'] ?? 0,
            'min2' => $stats['minutes_played'] ?? 0, 'eff2' => $efficiency,
        ]);
    }
}
