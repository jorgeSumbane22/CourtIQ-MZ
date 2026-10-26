<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../team_helper.php';

/**
 * NewsRepository — notícias do site público.
 * Compatível com tabelas que tenham ou não a coluna author_id
 */
final class NewsRepository
{
    private PDO $pdo;
    private bool $hasAuthorColumn;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
        $this->hasAuthorColumn = $this->checkAuthorColumn();
    }

    /**
     * Verifica se a coluna author_id existe na tabela news
     */
    private function checkAuthorColumn(): bool
    {
        try {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM news LIKE 'author_id'");
            $stmt->execute();
            return (bool) $stmt->fetch();
        } catch (PDOException $e) {
            error_log("NewsRepository::checkAuthorColumn - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Busca as últimas notícias
     */
    public function latest(int $limit = 10, ?int $teamId = null): array
    {
        try {
            // Multi-equipa: com $teamId só devolve as notícias dessa equipa
            $where = $teamId !== null ? 'WHERE n.team_id = :team' : '';

            // Se a coluna author_id existir, faz o JOIN, senão busca apenas da tabela news
            if ($this->hasAuthorColumn) {
                $sql = "SELECT n.*, u.name AS author_name, u.username AS author_username
                        FROM news n
                        LEFT JOIN users u ON u.id = n.author_id
                        {$where}
                        ORDER BY n.published_at DESC, n.created_at DESC
                        LIMIT :lim";
            } else {
                $sql = "SELECT n.*, NULL AS author_name, NULL AS author_username
                        FROM news n
                        {$where}
                        ORDER BY n.published_at DESC, n.created_at DESC
                        LIMIT :lim";
            }

            $stmt = $this->pdo->prepare($sql);
            if ($teamId !== null) {
                $stmt->bindValue('team', $teamId, PDO::PARAM_INT);
            }
            $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("NewsRepository::latest - " . $e->getMessage());
            return [];
        }
    }

    /**
     * Busca uma notícia por ID
     */
    public function find(int $id): ?array
    {
        try {
            if ($this->hasAuthorColumn) {
                $sql = 'SELECT n.*, u.name AS author_name, u.username AS author_username 
                        FROM news n 
                        LEFT JOIN users u ON u.id = n.author_id 
                        WHERE n.id = :id';
            } else {
                $sql = 'SELECT n.*, NULL AS author_name, NULL AS author_username 
                        FROM news n 
                        WHERE n.id = :id';
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (PDOException $e) {
            error_log("NewsRepository::find - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Cria uma nova notícia
     */
    public function create(array $data, int $authorId = null): int
    {
        try {
            // Se a coluna author_id não existir, ignora o authorId
            if ($this->hasAuthorColumn && $authorId !== null) {
                $sql = 'INSERT INTO news (team_id, title, content, image_url, author_id, published_at)
                        VALUES (:team_id, :title, :content, :image, :author, :published_at)';
                $params = [
                    'team_id'      => $data['team_id'],
                    'title'        => $data['title'],
                    'content'      => $data['content'],
                    'image'        => $data['image_url'] ?? null,
                    'author'       => $authorId,
                    'published_at' => $data['published_at'] ?? date('Y-m-d')
                ];
            } else {
                $sql = 'INSERT INTO news (team_id, title, content, image_url, published_at)
                        VALUES (:team_id, :title, :content, :image, :published_at)';
                $params = [
                    'team_id'      => $data['team_id'],
                    'title'        => $data['title'],
                    'content'      => $data['content'],
                    'image'        => $data['image_url'] ?? null,
                    'published_at' => $data['published_at'] ?? date('Y-m-d')
                ];
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("NewsRepository::create - " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Atualiza uma notícia existente
     */
    public function update(int $id, array $data): bool
    {
        if (!team_owns('news', $id)) {
            return false; // registo de outra equipa
        }
        try {
            $fields = [];
            $params = ['id' => $id];

            if (isset($data['title'])) {
                $fields[] = 'title = :title';
                $params['title'] = $data['title'];
            }

            if (isset($data['content'])) {
                $fields[] = 'content = :content';
                $params['content'] = $data['content'];
            }

            if (isset($data['image_url'])) {
                $fields[] = 'image_url = :image_url';
                $params['image_url'] = $data['image_url'];
            }

            if (isset($data['published_at'])) {
                $fields[] = 'published_at = :published_at';
                $params['published_at'] = $data['published_at'];
            }

            if ($this->hasAuthorColumn && isset($data['author_id'])) {
                $fields[] = 'author_id = :author_id';
                $params['author_id'] = $data['author_id'];
            }

            if (empty($fields)) {
                return false;
            }

            $fields[] = 'updated_at = NOW()';
            $sql = 'UPDATE news SET ' . implode(', ', $fields) . ' WHERE id = :id';

            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("NewsRepository::update - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove uma notícia
     */
    public function delete(int $id): bool
    {
        if (!team_owns('news', $id)) {
            return false; // registo de outra equipa
        }
        try {
            $stmt = $this->pdo->prepare('DELETE FROM news WHERE id = :id');
            return $stmt->execute(['id' => $id]);
        } catch (PDOException $e) {
            error_log("NewsRepository::delete - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Busca notícias com paginação
     */
    public function paginate(int $page = 1, int $perPage = 10, ?int $teamId = null): array
    {
        try {
            $offset = ($page - 1) * $perPage;
            $where  = $teamId !== null ? 'WHERE n.team_id = :team' : '';

            // Total de registros
            $stmtCount = $this->pdo->prepare("SELECT COUNT(*) as total FROM news n {$where}");
            $stmtCount->execute($teamId !== null ? ['team' => $teamId] : []);
            $total = (int) $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

            // Buscar notícias com paginação
            if ($this->hasAuthorColumn) {
                $sql = "SELECT n.*, u.name AS author_name, u.username AS author_username
                        FROM news n
                        LEFT JOIN users u ON u.id = n.author_id
                        {$where}
                        ORDER BY n.published_at DESC, n.created_at DESC
                        LIMIT :offset, :perPage";
            } else {
                $sql = "SELECT n.*, NULL AS author_name, NULL AS author_username
                        FROM news n
                        {$where}
                        ORDER BY n.published_at DESC, n.created_at DESC
                        LIMIT :offset, :perPage";
            }

            $stmt = $this->pdo->prepare($sql);
            if ($teamId !== null) {
                $stmt->bindValue('team', $teamId, PDO::PARAM_INT);
            }
            $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
            $stmt->bindValue('perPage', $perPage, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'data'     => $data,
                'total'    => $total,
                'page'     => $page,
                'perPage'  => $perPage,
                'lastPage' => ceil($total / $perPage)
            ];
        } catch (PDOException $e) {
            error_log("NewsRepository::paginate - " . $e->getMessage());
            return ['data' => [], 'total' => 0, 'page' => 1, 'perPage' => 10, 'lastPage' => 0];
        }
    }

    /**
     * Busca notícias recentes (alias para latest)
     */
    public function getRecent(int $limit = 5, ?int $teamId = null): array
    {
        return $this->latest($limit, $teamId);
    }

    /**
     * Busca notícias por categoria/tag (se houver)
     */
    public function findByCategory(string $category, int $limit = 10, ?int $teamId = null): array
    {
        try {
            // Verificar se a coluna category existe
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM news LIKE 'category'");
            $stmt->execute();
            $hasCategory = (bool) $stmt->fetch();

            if (!$hasCategory) {
                return $this->latest($limit, $teamId);
            }

            $teamFilter = $teamId !== null ? 'AND n.team_id = :team' : '';
            $sql = "SELECT n.*, u.name AS author_name
                    FROM news n
                    LEFT JOIN users u ON u.id = n.author_id
                    WHERE n.category = :category {$teamFilter}
                    ORDER BY n.published_at DESC
                    LIMIT :lim";

            $stmt = $this->pdo->prepare($sql);
            if ($teamId !== null) {
                $stmt->bindValue('team', $teamId, PDO::PARAM_INT);
            }
            $stmt->bindValue('category', $category, PDO::PARAM_STR);
            $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("NewsRepository::findByCategory - " . $e->getMessage());
            return [];
        }
    }
}