<?php
declare(strict_types=1);

/**
 * Database
 *
 * Responsabilidade única (SRP): fornecer e gerir a única ligação PDO do request.
 * Padrão Singleton — uma ligação de BD é um recurso caro e stateful, faz sentido
 * partilhar a mesma instância durante todo o ciclo de vida do request.
 */
final class Database
{
    private static ?PDO $instance = null;

    private function __construct()
    {
        // Impede instanciação direta — só se acede via getConnection().
    }

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $configPath = __DIR__ . '/../config/database.php';

            if (!is_file($configPath)) {
                error_log('[CourtIQ] Ficheiro de config não encontrado: ' . $configPath);
                http_response_code(500);
                die('Erro interno do servidor. Tente novamente mais tarde.');
            }

            $config = require $configPath;

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host']    ?? '127.0.0.1',
                $config['port']    ?? '3306',
                $config['dbname']  ?? 'courtiq',
                $config['charset'] ?? 'utf8mb4'
            );

            try {
                self::$instance = new PDO(
                    $dsn,
                    $config['user'] ?? 'root',
                    $config['pass'] ?? '',
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]
                );
            } catch (PDOException $e) {
                error_log('[CourtIQ] Erro de ligação à BD: ' . $e->getMessage());
                http_response_code(500);
                die('Erro interno do servidor. Tente novamente mais tarde.');
            }
        }

        return self::$instance;
    }
}