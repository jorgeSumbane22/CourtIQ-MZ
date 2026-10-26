-- ============================================================================
-- CourtIQMZ — SCHEMA MULTI-EQUIPA
-- Banco:   courtiq
-- Versão:  5.0
--
-- Modelo de isolamento:
--   * Cada equipa (teams) é dona dos seus dados. Todas as tabelas de negócio
--     têm team_id (ou chegam à equipa através de game_id / player_id).
--   * Cada utilizador pertence a UMA equipa (users.team_id). A exceção é o
--     superadmin, que tem team_id NULL e vê tudo.
--   * Apagar uma equipa apaga em cascata tudo o que lhe pertence.
--
-- Dados de exemplo: 5 equipas, cada uma com 1 admin, 1 treinador, 8 jogadores
-- (2 deles com conta de acesso), jogos, treinos, finanças, notícias, etc.
-- As datas são relativas ao dia da importação, por isso há sempre jogos
-- passados e jogos por disputar.
--
-- Password de TODAS as contas: admin123
--
-- ATENÇÃO: este script APAGA e recria todas as tabelas abaixo.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS courtiq
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE courtiq;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS
    ticket_replies, support_tickets, password_resets, login_attempts, player_requests, club_requests,
    taticas, ticket_sales, chat_messages, audit_logs, system_config, contact_messages,
    notifications, gallery_photos, news, sponsors, finances, comunicados,
    avaliacoes, estrategias_jogo, escalacao, attendance, game_player_stats,
    games, trainings, coaches, players, users, teams;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- 1. TEAMS — a raiz de tudo
-- ============================================================================
CREATE TABLE teams (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    name          VARCHAR(100) NOT NULL,
    city          VARCHAR(100),
    stadium       VARCHAR(100),
    founded_year  YEAR,
    category      VARCHAR(50)  DEFAULT 'Sénior',
    league        VARCHAR(100) DEFAULT 'Liga Nacional',
    escaloes      VARCHAR(60) NOT NULL DEFAULT 'iniciados,juniores,seniores',   -- escalões que o clube tem
    coach_id      INT NULL COMMENT 'users.id do treinador principal',
    logo_url      VARCHAR(255),
    logo_initials VARCHAR(5),
    status        ENUM('active', 'inactive') DEFAULT 'active',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_teams_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. USERS — cada conta pertence a uma equipa (superadmin = NULL)
-- ============================================================================
CREATE TABLE users (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    name            VARCHAR(100) NOT NULL,
    username        VARCHAR(50)  NOT NULL,
    email           VARCHAR(100) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    role            ENUM('admin', 'superadmin', 'treinador', 'jogador', 'adepto') DEFAULT 'adepto',   -- adepto: conta criada em registar.php
    team_id         INT NULL,
    phone           VARCHAR(20),
    status          ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    avatar_initials VARCHAR(5),
    avatar_url      VARCHAR(255) NULL,
    escaloes        VARCHAR(60) NULL,          -- treinadores: escalões que treinam (NULL = todos os do clube)
    last_login      DATETIME NULL,
    last_activity   DATETIME NULL,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_team (team_id, role),
    CONSTRAINT fk_users_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE teams
    ADD CONSTRAINT fk_teams_coach FOREIGN KEY (coach_id) REFERENCES users (id) ON DELETE SET NULL;

-- ============================================================================
-- 3. PLAYERS — user_id liga o jogador à sua conta (opcional)
-- ============================================================================
CREATE TABLE players (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    team_id       INT NOT NULL,
    user_id       INT NULL,
    name          VARCHAR(100) NOT NULL,
    jersey_number INT,
    position      ENUM('PG', 'SG', 'SF', 'PF', 'C') DEFAULT 'PG',
    escalao       ENUM('iniciados', 'juniores', 'seniores') NOT NULL DEFAULT 'seniores',
    birth_date    DATE,
    height        DECIMAL(4,2),
    weight        DECIMAL(5,2),
    status        ENUM('active', 'inactive', 'injured', 'suspended') DEFAULT 'active',
    is_captain    BOOLEAN DEFAULT FALSE,
    photo_url     VARCHAR(255),
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_players_user (user_id),
    KEY idx_players_team (team_id),
    CONSTRAINT fk_players_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
    CONSTRAINT fk_players_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. COACHES — equipa técnica
-- ============================================================================
CREATE TABLE coaches (
    id               INT PRIMARY KEY AUTO_INCREMENT,
    team_id          INT NOT NULL,
    user_id          INT NULL,
    name             VARCHAR(100),
    role_title       VARCHAR(100) DEFAULT 'Treinador',
    bio              TEXT,
    specialization   VARCHAR(100) DEFAULT 'Basquetebol',
    experience_years INT DEFAULT 0,
    certification    VARCHAR(100),
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_coaches_team (team_id),
    CONSTRAINT fk_coaches_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
    CONSTRAINT fk_coaches_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. TRAININGS
-- ============================================================================
CREATE TABLE trainings (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    team_id       INT NOT NULL,
    name          VARCHAR(100) NOT NULL DEFAULT 'Treino',
    training_date DATE NOT NULL,
    start_time    TIME NULL,
    end_time      TIME NULL,
    training_time TIME NULL,
    location      VARCHAR(255),
    type          ENUM('tactical', 'technical', 'physical', 'collective', 'strategic', 'recovery') DEFAULT 'tactical',
    escalao       ENUM('iniciados', 'juniores', 'seniores') NOT NULL DEFAULT 'seniores',
    objective     TEXT,
    description   TEXT,
    notes         TEXT,
    status        ENUM('scheduled', 'in_progress', 'finished', 'cancelled') DEFAULT 'scheduled',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_trainings_team_date (team_id, training_date),
    CONSTRAINT fk_trainings_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. GAMES — um registo por equipa (a perspetiva da equipa dona do registo)
-- ============================================================================
CREATE TABLE games (
    id               INT PRIMARY KEY AUTO_INCREMENT,
    team_id          INT NOT NULL,
    opponent         VARCHAR(100) NOT NULL,
    game_date        DATE NOT NULL,
    game_time        TIME NULL,
    location         VARCHAR(255),
    home_away        ENUM('home', 'away') DEFAULT 'home',
    our_score        INT DEFAULT NULL,
    opponent_score   INT DEFAULT NULL,
    competition      VARCHAR(100) DEFAULT 'Liga Nacional',
    escalao          ENUM('iniciados', 'juniores', 'seniores') NOT NULL DEFAULT 'seniores',
    jornada          VARCHAR(50),
    arbitros         VARCHAR(255),
    condicoes_clima  VARCHAR(100),
    escalacao_status VARCHAR(50) DEFAULT 'rascunho',
    capitao_id       INT DEFAULT NULL,
    vice_capitao_id  INT DEFAULT NULL,
    status           ENUM('scheduled', 'in_progress', 'finished', 'cancelled', 'postponed') DEFAULT 'scheduled',
    notes            TEXT,
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_games_team_date (team_id, game_date),
    KEY idx_games_team_status (team_id, status),
    CONSTRAINT fk_games_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. GAME_PLAYER_STATS — pertence à equipa através de game_id / player_id
-- ============================================================================
CREATE TABLE game_player_stats (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    game_id        INT NOT NULL,
    player_id      INT NOT NULL,
    points         INT DEFAULT 0,
    rebounds       INT DEFAULT 0,
    assists        INT DEFAULT 0,
    steals         INT DEFAULT 0,
    blocks         INT DEFAULT 0,
    fouls          INT DEFAULT 0,
    minutes_played INT DEFAULT 0,
    field_goals    INT DEFAULT 0,
    three_pointers INT DEFAULT 0,
    free_throws    INT DEFAULT 0,
    efficiency     DECIMAL(6,2) DEFAULT 0,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stats_game_player (game_id, player_id),
    KEY idx_stats_player (player_id),
    CONSTRAINT fk_stats_game   FOREIGN KEY (game_id)   REFERENCES games (id)   ON DELETE CASCADE,
    CONSTRAINT fk_stats_player FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. ATTENDANCE — event_id aponta para trainings.id ou games.id (event_type)
-- ============================================================================
CREATE TABLE attendance (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    event_id      INT NOT NULL,
    player_id     INT NOT NULL,
    event_type    ENUM('training', 'game') DEFAULT 'training',
    present       BOOLEAN DEFAULT FALSE,
    status        ENUM('present', 'absent', 'excused', 'injured', 'suspended') DEFAULT 'absent',
    justification VARCHAR(255),
    marked_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_event_player (event_type, event_id, player_id),
    KEY idx_attendance_player (player_id),
    CONSTRAINT fk_attendance_player FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 9. ESCALACAO — convocatória por jogo
-- ============================================================================
CREATE TABLE escalacao (
    id                INT PRIMARY KEY AUTO_INCREMENT,
    game_id           INT NOT NULL,
    player_id         INT NOT NULL,
    position          ENUM('PG', 'SG', 'SF', 'PF', 'C') DEFAULT 'PG',
    posicao_campo     VARCHAR(50) DEFAULT NULL,
    is_starter        BOOLEAN DEFAULT FALSE,
    is_titular        TINYINT(1) DEFAULT 0,
    is_captain        TINYINT(1) DEFAULT 0,
    is_vice_captain   TINYINT(1) DEFAULT 0,
    funcao_especial   VARCHAR(50) DEFAULT NULL,
    minutos_planeados INT DEFAULT NULL,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_escalacao_game_player (game_id, player_id),
    KEY idx_escalacao_player (player_id),
    CONSTRAINT fk_escalacao_game   FOREIGN KEY (game_id)   REFERENCES games (id)   ON DELETE CASCADE,
    CONSTRAINT fk_escalacao_player FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10. ESTRATEGIAS_JOGO — uma estratégia por jogo
-- ============================================================================
CREATE TABLE estrategias_jogo (
    id                INT PRIMARY KEY AUTO_INCREMENT,
    game_id           INT NOT NULL,
    team_id           INT NULL,
    title             VARCHAR(200),
    description       TEXT,
    type              ENUM('defesa', 'ataque', 'transicao', 'especial') DEFAULT 'defesa',
    sistema_ataque    VARCHAR(100),
    sistema_defesa    VARCHAR(100),
    plano_jogo        TEXT,
    pontos_fortes     TEXT,
    jogadas_especiais TEXT,
    substituicoes     TEXT,
    gestao_minutos    TEXT,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_estrategias_game (game_id),
    KEY idx_estrategias_team (team_id),
    CONSTRAINT fk_estrategias_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_estrategias_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 11. AVALIACOES — avaliação de jogadores pelo treinador
-- ============================================================================
CREATE TABLE avaliacoes (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    player_id       INT NOT NULL,
    team_id         INT NOT NULL,
    tipo            VARCHAR(50) DEFAULT 'treino',
    data_avaliacao  DATE NOT NULL,
    nota_total      DECIMAL(3,1) DEFAULT 0,
    estrelas        TINYINT DEFAULT 0,
    comentario      TEXT,
    nota_geral      DECIMAL(5,2) DEFAULT 0,
    passe           DECIMAL(5,2) DEFAULT 0,
    drible          DECIMAL(5,2) DEFAULT 0,
    lancamento      DECIMAL(5,2) DEFAULT 0,
    finalizacao     DECIMAL(5,2) DEFAULT 0,
    controlo_bola   DECIMAL(5,2) DEFAULT 0,
    posicionamento  DECIMAL(5,2) DEFAULT 0,
    leitura_jogo    DECIMAL(5,2) DEFAULT 0,
    decisao         DECIMAL(5,2) DEFAULT 0,
    defesa_coletiva DECIMAL(5,2) DEFAULT 0,
    resistencia     DECIMAL(5,2) DEFAULT 0,
    velocidade      DECIMAL(5,2) DEFAULT 0,
    agilidade       DECIMAL(5,2) DEFAULT 0,
    forca           DECIMAL(5,2) DEFAULT 0,
    recuperacao     DECIMAL(5,2) DEFAULT 0,
    concentracao    DECIMAL(5,2) DEFAULT 0,
    disciplina      DECIMAL(5,2) DEFAULT 0,
    lideranca       DECIMAL(5,2) DEFAULT 0,
    comunicacao     DECIMAL(5,2) DEFAULT 0,
    atitude         DECIMAL(5,2) DEFAULT 0,
    feedback        TEXT,
    objetivos       TEXT,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_avaliacoes_team (team_id, data_avaliacao),
    KEY idx_avaliacoes_player (player_id),
    CONSTRAINT fk_avaliacoes_team   FOREIGN KEY (team_id)   REFERENCES teams (id)   ON DELETE CASCADE,
    CONSTRAINT fk_avaliacoes_player FOREIGN KEY (player_id) REFERENCES players (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 12. COMUNICADOS — avisos internos da equipa
-- ============================================================================
CREATE TABLE comunicados (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    team_id         INT NOT NULL,
    titulo          VARCHAR(255) NOT NULL,
    conteudo        TEXT,
    categoria       VARCHAR(50) DEFAULT 'geral',
    prioridade      VARCHAR(20) DEFAULT 'normal',
    status          VARCHAR(20) DEFAULT 'publicado',
    imagem_capa     VARCHAR(255) DEFAULT NULL,
    destinatarios   LONGTEXT,
    data_envio      DATE NULL,
    data_publicacao DATETIME DEFAULT NULL,
    data_expiracao  DATETIME DEFAULT NULL,
    autor_id        INT NULL,
    fixado          BOOLEAN DEFAULT FALSE,
    visualizacoes   INT DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_comunicados_team (team_id, status),
    CONSTRAINT fk_comunicados_team  FOREIGN KEY (team_id)  REFERENCES teams (id) ON DELETE CASCADE,
    CONSTRAINT fk_comunicados_autor FOREIGN KEY (autor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 13. FINANCES
-- ============================================================================
CREATE TABLE finances (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    team_id     INT NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    type        ENUM('income', 'expense') DEFAULT 'income',
    category    VARCHAR(50) DEFAULT 'outros',
    date        DATE NOT NULL,
    ticket_game_id INT NULL,          -- receita de bilheteira lançada a partir de Bilhetes: um movimento por jogo
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_finances_team_date (team_id, date),
    UNIQUE KEY uq_finances_ticket_game (ticket_game_id),
    CONSTRAINT fk_finances_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
    CONSTRAINT fk_finances_ticket_game FOREIGN KEY (ticket_game_id) REFERENCES games (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 14. SPONSORS — patrocinadores de cada equipa
-- ============================================================================
CREATE TABLE sponsors (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    team_id     INT NOT NULL,
    name        VARCHAR(100) NOT NULL,
    tier        ENUM('ouro', 'prata', 'bronze') DEFAULT 'bronze',
    sector      VARCHAR(100),
    country     VARCHAR(100) DEFAULT 'Moçambique',
    contact     VARCHAR(150),
    website     VARCHAR(255),
    logo_url    VARCHAR(255),
    description TEXT,
    start_date  DATE,
    end_date    DATE,
    status      ENUM('active', 'inactive') DEFAULT 'active',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_sponsors_team (team_id),
    CONSTRAINT fk_sponsors_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 15. NEWS — notícias públicas de cada equipa
-- ============================================================================
CREATE TABLE news (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    team_id      INT NOT NULL,
    author_id    INT NULL,
    title        VARCHAR(200) NOT NULL,
    content      TEXT NOT NULL,
    category     VARCHAR(50) DEFAULT 'geral',
    image_url    VARCHAR(255),
    published_at DATE NOT NULL,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_news_team_date (team_id, published_at),
    CONSTRAINT fk_news_team   FOREIGN KEY (team_id)   REFERENCES teams (id) ON DELETE CASCADE,
    CONSTRAINT fk_news_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 16. GALLERY_PHOTOS
-- ============================================================================
CREATE TABLE gallery_photos (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    team_id     INT NOT NULL,
    title       VARCHAR(200),
    description TEXT,
    image_url   VARCHAR(255) NOT NULL,
    category    ENUM('jogos', 'treinos', 'eventos', 'equipa', 'outros') DEFAULT 'outros',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gallery_team (team_id),
    CONSTRAINT fk_gallery_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 17. NOTIFICATIONS — pessoais (isoladas pelo utilizador)
-- ============================================================================
CREATE TABLE notifications (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    user_id    INT NOT NULL,
    title      VARCHAR(200) NOT NULL,
    message    TEXT NOT NULL,
    type       ENUM('info', 'warning', 'success', 'error') DEFAULT 'info',
    is_read    BOOLEAN DEFAULT FALSE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_user (user_id, is_read),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 17a. PLAYER_REQUESTS — adeptos que pedem para entrar no plantel; o treinador aceita ou recusa
-- ============================================================================
CREATE TABLE player_requests (
    id            INT PRIMARY KEY AUTO_INCREMENT,
    user_id       INT NOT NULL,
    team_id       INT NOT NULL,
    position      ENUM('PG', 'SG', 'SF', 'PF', 'C') DEFAULT 'PG',
    escalao       ENUM('iniciados', 'juniores', 'seniores') NOT NULL DEFAULT 'seniores',
    birth_date    DATE NULL,
    jersey_number INT NULL,
    message       VARCHAR(500) NULL,
    status        ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    decided_by    INT NULL,
    decided_at    DATETIME NULL,
    decision_note VARCHAR(255) NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_player_requests_team (team_id, status),
    KEY idx_player_requests_user (user_id, status),
    CONSTRAINT fk_player_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_player_requests_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
    CONSTRAINT fk_player_requests_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 17b. PASSWORD_RESETS — ligações de uso único para escolher palavra-passe nova
--      (guarda-se só o SHA-256 do código, nunca o código)
-- ============================================================================
CREATE TABLE password_resets (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    user_id    INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    created_by INT NULL,
    ip_address VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id, created_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 17c. LOGIN_ATTEMPTS — tentativas de login falhadas (bloqueio por conta e por IP)
-- ============================================================================
CREATE TABLE login_attempts (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    identifier VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45),
    created_at DATETIME NOT NULL,
    KEY idx_login_attempts_identifier (identifier, created_at),
    KEY idx_login_attempts_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 17d. CLUB_REQUESTS — clubes que pedem para aderir (registar-clube.php);
--      o superadministrador aprova (cria o clube e o administrador) ou recusa
-- ============================================================================
CREATE TABLE club_requests (
    id             INT PRIMARY KEY AUTO_INCREMENT,
    club_name      VARCHAR(100) NOT NULL,
    city           VARCHAR(100) NULL,
    stadium        VARCHAR(100) NULL,
    founded_year   YEAR NULL,
    escaloes       VARCHAR(60) NOT NULL DEFAULT 'iniciados,juniores,seniores',
    admin_name     VARCHAR(100) NOT NULL,
    admin_username VARCHAR(50) NOT NULL,
    admin_email    VARCHAR(100) NOT NULL,
    admin_phone    VARCHAR(20) NULL,
    password_hash  VARCHAR(255) NOT NULL,      -- apagado quando o pedido é decidido
    message        VARCHAR(500) NULL,
    status         ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    decision_note  VARCHAR(255) NULL,
    decided_by     INT NULL,
    decided_at     DATETIME NULL,
    team_id        INT NULL,
    ip_address     VARCHAR(45) NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_club_requests_status (status, created_at),
    CONSTRAINT fk_club_requests_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_club_requests_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 18. CONTACT_MESSAGES — mensagens do site público, enviadas a uma equipa
-- ============================================================================
CREATE TABLE contact_messages (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    team_id    INT NULL,
    name       VARCHAR(100) NOT NULL,
    email      VARCHAR(100) NOT NULL,
    subject    VARCHAR(200) NOT NULL,
    message    TEXT NOT NULL,
    status     ENUM('unread', 'read', 'replied') DEFAULT 'unread',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_contact_team (team_id, status),
    CONSTRAINT fk_contact_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 19. CHAT_MESSAGES
--     recipient_id = 0  -> chat geral DA EQUIPA (team_id)
--     recipient_id > 0  -> mensagem privada entre dois utilizadores
-- ============================================================================
CREATE TABLE chat_messages (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    team_id      INT NULL,
    user_id      INT NOT NULL,
    recipient_id INT NOT NULL DEFAULT 0,
    content      TEXT NOT NULL,
    message_type VARCHAR(20) DEFAULT 'private',
    is_read      TINYINT(1) DEFAULT 0,
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_chat_team_general (team_id, recipient_id, id),
    KEY idx_chat_recipient (recipient_id, is_read),
    KEY idx_chat_user (user_id),
    CONSTRAINT fk_chat_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 20. TICKET_SALES — bilhetes (pertencem à equipa através de game_id)
-- ============================================================================
CREATE TABLE ticket_sales (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    game_id     INT NOT NULL,
    user_id     INT NULL,
    buyer_name  VARCHAR(100) NOT NULL,
    buyer_email VARCHAR(100) NOT NULL,
    quantity    INT NOT NULL DEFAULT 1,
    ticket_type VARCHAR(30) DEFAULT 'normal',
    unit_price  DECIMAL(10,2) NOT NULL DEFAULT 0,
    total       DECIMAL(10,2) NOT NULL DEFAULT 0,
    code        VARCHAR(40) NOT NULL,
    status      VARCHAR(20) DEFAULT 'confirmed',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_code (code),
    KEY idx_ticket_game (game_id),
    KEY idx_ticket_email (buyer_email),
    CONSTRAINT fk_ticket_game FOREIGN KEY (game_id) REFERENCES games (id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 21. SYSTEM_CONFIG — global (só o superadmin gere)
-- ============================================================================
CREATE TABLE system_config (
    id           INT PRIMARY KEY AUTO_INCREMENT,
    config_key   VARCHAR(100) NOT NULL,
    config_value TEXT,
    description  VARCHAR(255),
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_config_key (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 21b. TATICAS — caderno tático do treinador
-- ============================================================================
CREATE TABLE taticas (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    team_id    INT NOT NULL,
    nome       VARCHAR(100) NOT NULL,
    categoria  VARCHAR(50) DEFAULT 'ofensiva',
    descricao  TEXT,
    objetivo   TEXT,
    prioridade INT DEFAULT 1,
    formacao   LONGTEXT,
    jogadas    LONGTEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_taticas_team (team_id),
    CONSTRAINT fk_taticas_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 21c. SUPPORT_TICKETS / TICKET_REPLIES — pedidos de suporte ao superadmin
-- ============================================================================
CREATE TABLE support_tickets (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    user_id    INT NOT NULL,
    team_id    INT NULL,
    subject    VARCHAR(200) NOT NULL,
    message    TEXT NOT NULL,
    priority   ENUM('low', 'normal', 'high') DEFAULT 'normal',
    status     ENUM('open', 'answered', 'closed') DEFAULT 'open',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_tickets_status (status, created_at),
    KEY idx_tickets_user (user_id),
    CONSTRAINT fk_tickets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_tickets_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_replies (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    ticket_id  INT NOT NULL,
    user_id    INT NULL,
    message    TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_replies_ticket (ticket_id),
    CONSTRAINT fk_replies_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE,
    CONSTRAINT fk_replies_user   FOREIGN KEY (user_id)   REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 22. AUDIT_LOGS — team_id permite ao admin ver só os registos da sua equipa
-- ============================================================================
CREATE TABLE audit_logs (
    id         INT PRIMARY KEY AUTO_INCREMENT,
    user_id    INT NULL,
    team_id    INT NULL,
    username   VARCHAR(50),
    action     VARCHAR(100) NOT NULL,
    details    TEXT,
    ip_address VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_team (team_id, created_at),
    KEY idx_audit_user (user_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_team FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ############################################################################
-- DADOS DE EXEMPLO
-- ############################################################################

-- ----------------------------------------------------------------------------
-- 5 EQUIPAS
-- ----------------------------------------------------------------------------
INSERT INTO teams (id, name, city, stadium, founded_year, logo_url, logo_initials) VALUES
(1, 'Maputo Titans',   'Maputo',    'Pavilhão do Maxaquene', 2015, '/imagens/logotipos/logo1.jpg', 'MT'),
(2, 'Maputo Warriors',  'Maputo',    'Pavilhão da FDJ',       2018, '/imagens/logotipos/logo2.jpg', 'MW'),
(3, 'Beira Eagles',     'Beira',     'Pavilhão da Beira',     2010, '/imagens/logotipos/logo3.jpg', 'BE'),
(4, 'Nampula Lions',    'Nampula',   'Pavilhão de Nampula',   2012, '/imagens/logotipos/logo4.jpg', 'NL'),
(5, 'Quelimane Sharks', 'Quelimane', 'Pavilhão de Quelimane', 2016, '/imagens/logotipos/logo5.jpg', 'QS');

-- ----------------------------------------------------------------------------
-- UTILIZADORES (password de todos: admin123)
--   1       superadmin (sem equipa, vê tudo)
--   2..6    admin de cada equipa
--   7..11   treinador de cada equipa
--   12..21  jogadores com conta (2 por equipa)
-- ----------------------------------------------------------------------------
SET @pwd = '$2y$10$IzdroDOxl/poeNADZaF/0ez0o7WmE8yu.ZjcdUvtiULmrQigfzNai';

INSERT INTO users (id, name, username, email, password_hash, role, team_id, phone, avatar_initials) VALUES
( 1, 'Super Administrador', 'superadmin',      'superadmin@courtiq.com',      @pwd, 'superadmin', NULL, '+258 84 000 0001', 'SA'),

( 2, 'Helena Machava',      'admin',           'admin@titans.co.mz',          @pwd, 'admin',      1,    '+258 84 100 0001', 'HM'),
( 3, 'Miguel Santos',       'admin.warriors',  'admin@warriors.co.mz',        @pwd, 'admin',      2,    '+258 84 100 0002', 'MS'),
( 4, 'Teresa Mondlane',     'admin.eagles',    'admin@eagles.co.mz',          @pwd, 'admin',      3,    '+258 84 100 0003', 'TM'),
( 5, 'Armando Muianga',     'admin.lions',     'admin@lions.co.mz',           @pwd, 'admin',      4,    '+258 84 100 0004', 'AM'),
( 6, 'Lúcia Nhantumbo',     'admin.sharks',    'admin@sharks.co.mz',          @pwd, 'admin',      5,    '+258 84 100 0005', 'LN'),

( 7, 'Carlos Oliveira',     'carlos.oliveira', 'carlos.oliveira@titans.co.mz',@pwd, 'treinador',  1,    '+258 84 200 0001', 'CO'),
( 8, 'Ana Santos',          'ana.santos',      'ana.santos@warriors.co.mz',   @pwd, 'treinador',  2,    '+258 84 200 0002', 'AS'),
( 9, 'Miguel Costa',        'miguel.costa',    'miguel.costa@eagles.co.mz',   @pwd, 'treinador',  3,    '+258 84 200 0003', 'MC'),
(10, 'Ana Ferreira',        'ana.ferreira',    'ana.ferreira@lions.co.mz',    @pwd, 'treinador',  4,    '+258 84 200 0004', 'AF'),
(11, 'João Silva',          'joao.silva',      'joao.silva@sharks.co.mz',     @pwd, 'treinador',  5,    '+258 84 200 0005', 'JS'),

(12, 'João Macuácua',       'joao.jogador',    'joao.jogador@titans.co.mz',   @pwd, 'jogador',    1,    '+258 84 300 0001', 'JM'),
(13, 'Miguel Chissano',     'miguel.jogador',  'miguel.jogador@titans.co.mz', @pwd, 'jogador',    1,    '+258 84 300 0002', 'MC'),
(14, 'Pedro Langa',         'pedro.jogador',   'pedro.jogador@warriors.co.mz',@pwd, 'jogador',    2,    '+258 84 300 0003', 'PL'),
(15, 'Tiago Cossa',         'tiago.jogador',   'tiago.jogador@warriors.co.mz',@pwd, 'jogador',    2,    '+258 84 300 0004', 'TC'),
(16, 'André Sitoe',         'andre.jogador',   'andre.jogador@eagles.co.mz',  @pwd, 'jogador',    3,    '+258 84 300 0005', 'AS'),
(17, 'Fábio Mabunda',       'fabio.jogador',   'fabio.jogador@eagles.co.mz',  @pwd, 'jogador',    3,    '+258 84 300 0006', 'FM'),
(18, 'Luís Matsinhe',       'luis.jogador',    'luis.jogador@lions.co.mz',    @pwd, 'jogador',    4,    '+258 84 300 0007', 'LM'),
(19, 'Bruno Zandamela',     'bruno.jogador',   'bruno.jogador@lions.co.mz',   @pwd, 'jogador',    4,    '+258 84 300 0008', 'BZ'),
(20, 'Rui Tembe',           'rui.jogador',     'rui.jogador@sharks.co.mz',    @pwd, 'jogador',    5,    '+258 84 300 0009', 'RT'),
(21, 'Nelson Guambe',       'nelson.jogador',  'nelson.jogador@sharks.co.mz', @pwd, 'jogador',    5,    '+258 84 300 0010', 'NG');

UPDATE teams SET coach_id = id + 6;

-- ----------------------------------------------------------------------------
-- EQUIPA TÉCNICA (treinador principal com conta + adjunto sem conta)
-- ----------------------------------------------------------------------------
INSERT INTO coaches (team_id, user_id, name, role_title, bio, experience_years, certification) VALUES
(1,  7,   'Carlos Oliveira', 'Treinador Principal', 'Lidera os Titans desde 2021, com foco em defesa agressiva e transição rápida.', 12, 'FIBA Nível 3'),
(1,  NULL,'Dércio Mucavele', 'Treinador Adjunto',   'Responsável pelo trabalho individual dos bases.',                               5,  'FIBA Nível 1'),
(2,  8,   'Ana Santos',      'Treinadora Principal','Antiga internacional, aposta num jogo coletivo e de muita circulação de bola.', 9,  'FIBA Nível 3'),
(2,  NULL,'Ivo Manhique',    'Treinador Adjunto',   'Especialista em preparação física.',                                            4,  'FIBA Nível 1'),
(3,  9,   'Miguel Costa',    'Treinador Principal', 'Formador de talentos na Beira há mais de uma década.',                          14, 'FIBA Nível 2'),
(3,  NULL,'Sérgio Dimande',  'Treinador Adjunto',   'Analista de vídeo e scouting de adversários.',                                  3,  'FIBA Nível 1'),
(4,  10,  'Ana Ferreira',    'Treinadora Principal','Construiu a equipa mais jovem da liga em Nampula.',                             7,  'FIBA Nível 2'),
(4,  NULL,'Abel Namburete',  'Treinador Adjunto',   'Trabalha o jogo interior com os postes.',                                       6,  'FIBA Nível 1'),
(5,  11,  'João Silva',      'Treinador Principal', 'Defende um basquetebol de ritmo alto e lançamento exterior.',                   10, 'FIBA Nível 2'),
(5,  NULL,'Zito Mahumane',   'Treinador Adjunto',   'Coordena a formação dos Sharks.',                                               4,  'FIBA Nível 1');

-- ----------------------------------------------------------------------------
-- JOGADORES — 8 por equipa (os dois primeiros de cada equipa têm conta)
-- ----------------------------------------------------------------------------
INSERT INTO players (id, team_id, user_id, name, jersey_number, position, birth_date, height, weight, is_captain) VALUES
-- Maputo Titans
( 1, 1, 12,   'João Macuácua',     7,  'PG', '1998-03-15', 1.85, 78.5, TRUE),
( 2, 1, 13,   'Miguel Chissano',   10, 'SG', '1999-07-22', 1.90, 82.0, FALSE),
( 3, 1, NULL, 'Edson Nhaca',       23, 'SF', '1997-11-02', 1.98, 90.0, FALSE),
( 4, 1, NULL, 'Hélder Bila',       15, 'PF', '1996-01-30', 2.03, 98.0, FALSE),
( 5, 1, NULL, 'Stélio Mabote',     34, 'C',  '1995-05-18', 2.08, 105.0, FALSE),
( 6, 1, NULL, 'Délcio Ubisse',     4,  'PG', '2001-09-09', 1.82, 75.0, FALSE),
( 7, 1, NULL, 'Ivan Massingue',    11, 'SG', '2000-12-12', 1.91, 84.0, FALSE),
( 8, 1, NULL, 'Kelvin Chongo',     21, 'C',  '1998-06-25', 2.05, 102.0, FALSE),
-- Maputo Warriors
( 9, 2, 14,   'Pedro Langa',       5,  'PG', '1997-02-11', 1.83, 77.0, TRUE),
(10, 2, 15,   'Tiago Cossa',       9,  'SG', '2000-04-03', 1.89, 81.0, FALSE),
(11, 2, NULL, 'Wilson Magaia',     14, 'SF', '1998-08-19', 1.97, 89.0, FALSE),
(12, 2, NULL, 'Dário Mussagy',     22, 'PF', '1996-10-27', 2.02, 97.0, FALSE),
(13, 2, NULL, 'Cremildo Uamusse',  33, 'C',  '1994-12-05', 2.09, 108.0, FALSE),
(14, 2, NULL, 'Yuran Mandlate',    3,  'PG', '2002-01-14', 1.80, 73.0, FALSE),
(15, 2, NULL, 'Gerson Novela',     12, 'SG', '1999-03-08', 1.92, 85.0, FALSE),
(16, 2, NULL, 'Salvador Mate',     44, 'C',  '1997-07-17', 2.06, 104.0, FALSE),
-- Beira Eagles
(17, 3, 16,   'André Sitoe',       8,  'PG', '1998-05-21', 1.84, 78.0, TRUE),
(18, 3, 17,   'Fábio Mabunda',     13, 'SG', '1999-09-13', 1.91, 83.0, FALSE),
(19, 3, NULL, 'Nuno Jossias',      20, 'SF', '1997-04-06', 1.99, 91.0, FALSE),
(20, 3, NULL, 'Arsénio Dombo',     24, 'PF', '1995-11-23', 2.04, 99.0, FALSE),
(21, 3, NULL, 'Manuel Chiziane',   31, 'C',  '1996-02-28', 2.10, 110.0, FALSE),
(22, 3, NULL, 'Elton Gove',        2,  'PG', '2001-06-10', 1.81, 74.0, FALSE),
(23, 3, NULL, 'Jaime Macamo',      17, 'SG', '2000-08-30', 1.90, 82.5, FALSE),
(24, 3, NULL, 'Osvaldo Tivane',    41, 'C',  '1998-10-16', 2.07, 103.0, FALSE),
-- Nampula Lions
(25, 4, 18,   'Luís Matsinhe',     6,  'PG', '1999-01-19', 1.86, 79.0, TRUE),
(26, 4, 19,   'Bruno Zandamela',   16, 'SG', '2000-05-07', 1.88, 80.5, FALSE),
(27, 4, NULL, 'Amade Abdala',      25, 'SF', '1998-09-01', 1.96, 88.0, FALSE),
(28, 4, NULL, 'Momade Issufo',     30, 'PF', '1996-03-12', 2.01, 96.0, FALSE),
(29, 4, NULL, 'Chande Amisse',     35, 'C',  '1995-07-29', 2.08, 106.0, FALSE),
(30, 4, NULL, 'Faizal Selemane',   1,  'PG', '2002-11-04', 1.79, 72.0, FALSE),
(31, 4, NULL, 'Nelito Muhate',     18, 'SG', '2001-02-22', 1.93, 86.0, FALSE),
(32, 4, NULL, 'Aly Momade',        42, 'C',  '1997-12-09', 2.05, 101.0, FALSE),
-- Quelimane Sharks
(33, 5, 20,   'Rui Tembe',         0,  'PG', '1998-04-27', 1.84, 77.5, TRUE),
(34, 5, 21,   'Nelson Guambe',     19, 'SG', '1999-10-15', 1.90, 82.0, FALSE),
(35, 5, NULL, 'Castigo Sambo',     27, 'SF', '1997-06-08', 1.98, 90.5, FALSE),
(36, 5, NULL, 'Belmiro Zucula',    32, 'PF', '1996-08-21', 2.03, 98.5, FALSE),
(37, 5, NULL, 'Tomás Nhampossa',   45, 'C',  '1994-09-30', 2.11, 111.0, FALSE),
(38, 5, NULL, 'Dino Mucavel',      26, 'PG', '2001-03-17', 1.82, 75.5, FALSE),
(39, 5, NULL, 'Ercílio Malate',    28, 'SG', '2000-07-05', 1.91, 83.5, FALSE),
(40, 5, NULL, 'Valter Nhabanga',   50, 'C',  '1998-12-24', 2.06, 103.5, FALSE);

-- Só existem 15 fotografias em /imagens/jogadores, por isso repetem-se
UPDATE players SET photo_url = CONCAT('/imagens/jogadores/', ((id - 1) % 15) + 1, '.jpg');
UPDATE players SET status = 'injured' WHERE id IN (8, 24, 37);

-- ----------------------------------------------------------------------------
-- JOGOS — liga a uma volta entre as 5 equipas (10 jogos)
-- Cada jogo gera DOIS registos em games: um para a equipa da casa e outro
-- para a visitante, cada um com team_id da respetiva equipa.
-- ----------------------------------------------------------------------------
CREATE TEMPORARY TABLE seed_matches (
    jornada    INT PRIMARY KEY,
    home_id    INT NOT NULL,
    away_id    INT NOT NULL,
    day_offset INT NOT NULL,
    game_time  TIME NOT NULL,
    status     VARCHAR(20) NOT NULL
);

INSERT INTO seed_matches VALUES
( 1, 1, 2, -42, '18:00:00', 'finished'),
( 2, 3, 4, -35, '17:00:00', 'finished'),
( 3, 5, 1, -28, '19:00:00', 'finished'),
( 4, 2, 3, -21, '18:30:00', 'finished'),
( 5, 4, 5, -14, '17:30:00', 'finished'),
( 6, 1, 3,  -7, '19:00:00', 'finished'),
( 7, 2, 4,   3, '18:00:00', 'scheduled'),
( 8, 3, 5,  10, '17:00:00', 'scheduled'),
( 9, 1, 4,  17, '19:00:00', 'scheduled'),
(10, 2, 5,  24, '18:30:00', 'scheduled');

INSERT INTO games (team_id, opponent, game_date, game_time, location, home_away, competition, jornada, status)
SELECT x.team_id, x.opponent, CURDATE() + INTERVAL x.day_offset DAY, x.game_time, x.location, x.home_away,
       'Liga Nacional', CONCAT('Jornada ', x.jornada), x.status
FROM (
    SELECT m.jornada, m.home_id AS team_id, a.name AS opponent, m.day_offset, m.game_time,
           h.stadium AS location, 'home' AS home_away, m.status
    FROM seed_matches m JOIN teams h ON h.id = m.home_id JOIN teams a ON a.id = m.away_id
    UNION ALL
    SELECT m.jornada, m.away_id, h.name, m.day_offset, m.game_time,
           h.stadium, 'away', m.status
    FROM seed_matches m JOIN teams h ON h.id = m.home_id JOIN teams a ON a.id = m.away_id
) x
ORDER BY x.jornada, x.home_away DESC;

DROP TEMPORARY TABLE seed_matches;

-- ----------------------------------------------------------------------------
-- ESTATÍSTICAS — uma linha por jogador em cada jogo terminado da sua equipa
-- (valores gerados de forma determinística a partir dos ids)
-- ----------------------------------------------------------------------------
INSERT INTO game_player_stats
    (game_id, player_id, points, rebounds, assists, steals, blocks, fouls, minutes_played, three_pointers, free_throws)
SELECT g.id, p.id,
       6 + ((g.id * 7 + p.id * 13 + p.jersey_number) % 11),
       1 + ((g.id * 3 + p.id * 5) % 9),
       (g.id * 5 + p.id * 3) % 8,
       (g.id + p.id) % 4,
       (g.id * 2 + p.id) % 3,
       (g.id + p.id * 2) % 5,
       12 + ((g.id * 11 + p.id * 7) % 24),
       (g.id + p.id) % 4,
       (g.id * 3 + p.id) % 3
FROM games g
JOIN players p ON p.team_id = g.team_id
WHERE g.status = 'finished';

UPDATE game_player_stats
SET three_pointers = LEAST(three_pointers, points DIV 3),
    free_throws    = LEAST(free_throws, points - three_pointers * 3);
UPDATE game_player_stats
SET field_goals = (points - three_pointers * 3 - free_throws) DIV 2 + three_pointers,
    efficiency  = points + rebounds + assists + steals + blocks;

-- O resultado de cada jogo é a soma dos pontos dos jogadores...
UPDATE games g
JOIN (SELECT game_id, SUM(points) AS total FROM game_player_stats GROUP BY game_id) s ON s.game_id = g.id
SET g.our_score = s.total;

-- ...e o resultado do adversário é o "our_score" do registo espelho
UPDATE games g
JOIN games o ON o.jornada = g.jornada AND o.id <> g.id
SET g.opponent_score = o.our_score
WHERE g.status = 'finished';

-- ----------------------------------------------------------------------------
-- CONVOCATÓRIA e ESTRATÉGIA para os jogos por disputar
-- ----------------------------------------------------------------------------
INSERT INTO escalacao (game_id, player_id, position, posicao_campo, is_starter, is_titular, is_captain)
SELECT g.id, p.id, p.position, p.position,
       (p.id - 1) % 8 < 5, (p.id - 1) % 8 < 5, p.is_captain
FROM games g
JOIN players p ON p.team_id = g.team_id AND p.status = 'active'
WHERE g.status = 'scheduled';

INSERT INTO estrategias_jogo (game_id, team_id, title, description, type, sistema_ataque, sistema_defesa, plano_jogo)
SELECT g.id, g.team_id,
       CONCAT('Plano para ', g.opponent),
       'Pressionar a saída de bola e controlar o ressalto defensivo.',
       IF(g.home_away = 'home', 'ataque', 'defesa'),
       IF(g.home_away = 'home', 'Motion 4-out 1-in', 'Pick and roll central'),
       IF(g.home_away = 'home', 'Homem a homem', 'Zona 2-3'),
       CONCAT('Entrar forte no primeiro período frente ao ', g.opponent, ' e rodar o banco cedo.')
FROM games g
WHERE g.status = 'scheduled';

-- ----------------------------------------------------------------------------
-- TREINOS — 5 por equipa (3 realizados, 2 agendados)
-- ----------------------------------------------------------------------------
INSERT INTO trainings (team_id, name, training_date, start_time, end_time, training_time, location, type, objective, description, status)
SELECT t.id, x.name, CURDATE() + INTERVAL x.day_offset DAY, x.start_time, x.end_time, x.start_time,
       t.stadium, x.type, x.objective, x.objective, x.status
FROM teams t
CROSS JOIN (
    SELECT -10 AS day_offset, 'Treino tático'        AS name, '18:00:00' AS start_time, '20:00:00' AS end_time, 'tactical'  AS type, 'Sistemas defensivos e rotações'         AS objective, 'finished'  AS status
    UNION ALL SELECT -6, 'Treino físico',         '07:00:00', '08:30:00', 'physical',  'Resistência e trabalho de força',        'finished'
    UNION ALL SELECT -3, 'Treino técnico',        '18:00:00', '19:30:00', 'technical', 'Lançamento exterior e lances livres',    'finished'
    UNION ALL SELECT  2, 'Treino coletivo',       '18:00:00', '20:00:00', 'collective','Jogo 5x5 e situações de fim de período', 'scheduled'
    UNION ALL SELECT  5, 'Preparação do jogo',    '17:00:00', '18:30:00', 'strategic', 'Estudo do próximo adversário',           'scheduled'
) x
ORDER BY t.id, x.day_offset;

-- PRESENÇAS nos treinos já realizados
INSERT INTO attendance (event_id, player_id, event_type, present, status, justification)
SELECT tr.id, p.id, 'training',
       (tr.id + p.id * 3) % 6 <> 0,
       CASE WHEN (tr.id + p.id * 3) % 6 <> 0 THEN 'present'
            WHEN p.status = 'injured'        THEN 'injured'
            WHEN p.id % 2 = 0                THEN 'excused'
            ELSE 'absent' END,
       CASE WHEN (tr.id + p.id * 3) % 6 = 0 AND p.id % 2 = 0 THEN 'Motivos profissionais' END
FROM trainings tr
JOIN players p ON p.team_id = tr.team_id
WHERE tr.status = 'finished';

-- ----------------------------------------------------------------------------
-- AVALIAÇÕES — uma por jogador
-- ----------------------------------------------------------------------------
INSERT INTO avaliacoes
    (player_id, team_id, tipo, data_avaliacao, nota_total, estrelas, comentario, nota_geral,
     passe, drible, lancamento, posicionamento, resistencia, velocidade, disciplina, atitude, feedback)
SELECT p.id, p.team_id, 'treino', CURDATE() - INTERVAL (p.id % 9) DAY,
       5.5 + ((p.id * 7) % 40) / 10,
       3 + (p.id % 3),
       'Boa evolução nas últimas semanas.',
       55 + ((p.id * 7) % 40),
       50 + ((p.id * 11) % 45), 50 + ((p.id * 13) % 45), 50 + ((p.id * 17) % 45), 50 + ((p.id * 19) % 45),
       50 + ((p.id * 23) % 45), 50 + ((p.id * 29) % 45), 60 + ((p.id * 31) % 40), 60 + ((p.id * 37) % 40),
       'Manter a intensidade defensiva e melhorar a tomada de decisão.'
FROM players p;

-- ----------------------------------------------------------------------------
-- COMUNICADOS — 2 por equipa, escritos pelo treinador
-- ----------------------------------------------------------------------------
INSERT INTO comunicados (team_id, titulo, conteudo, categoria, prioridade, status, data_envio, data_publicacao, autor_id, fixado)
SELECT t.id, x.titulo, CONCAT(x.conteudo, ' — ', t.name), x.categoria, x.prioridade, 'publicado',
       CURDATE() - INTERVAL x.dias DAY, NOW() - INTERVAL x.dias DAY, t.coach_id, x.fixado
FROM teams t
CROSS JOIN (
    SELECT 'Convocatória para o próximo jogo' AS titulo, 'A lista de convocados já está disponível na área de jogos. Concentração 90 minutos antes' AS conteudo, 'jogo' AS categoria, 'alta' AS prioridade, 1 AS dias, TRUE AS fixado
    UNION ALL SELECT 'Alteração no horário de treino', 'O treino físico passa para as 07h00 no pavilhão habitual', 'treino', 'normal', 4, FALSE
) x;

-- ----------------------------------------------------------------------------
-- FINANÇAS — 6 movimentos por equipa
-- ----------------------------------------------------------------------------
INSERT INTO finances (team_id, description, amount, type, category, date)
SELECT t.id, x.description, ROUND(x.amount * (0.8 + t.id * 0.1), 2), x.type, x.category,
       CURDATE() - INTERVAL x.dias DAY
FROM teams t
CROSS JOIN (
    SELECT 'Patrocínio principal da época' AS description, 250000.00 AS amount, 'income' AS type, 'patrocinio' AS category, 40 AS dias
    UNION ALL SELECT 'Receita de bilheteira',          45000.00, 'income',  'bilhetes',       20
    UNION ALL SELECT 'Aluguer do pavilhão',            30000.00, 'expense', 'infraestrutura', 18
    UNION ALL SELECT 'Equipamentos e bolas',           22000.00, 'expense', 'material',       15
    UNION ALL SELECT 'Deslocação para jogo fora',      38000.00, 'expense', 'viagens',        9
    UNION ALL SELECT 'Salários da equipa técnica',     90000.00, 'expense', 'salarios',       3
) x;

-- ----------------------------------------------------------------------------
-- PATROCINADORES — 3 por equipa
-- ----------------------------------------------------------------------------
INSERT INTO sponsors (team_id, name, tier, sector, contact, website, description, start_date, end_date) VALUES
(1, 'Banco Índico',          'ouro',   'Banca',            'parcerias@bancoindico.co.mz', 'https://www.bancoindico.example', 'Patrocinador principal dos Titans.',        '2026-01-01', '2027-12-31'),
(1, 'Águas do Maxaquene',    'prata',  'Bebidas',          'marketing@aguasmax.example',  'https://www.aguasmax.example',    'Hidratação oficial da equipa.',             '2026-01-01', '2026-12-31'),
(1, 'Sport Zone Maputo',     'bronze', 'Desporto',         'loja@sportzone.example',      'https://www.sportzone.example',   'Fornecedor de equipamentos.',               '2026-03-01', '2026-12-31'),
(2, 'Telecom Moçambique',    'ouro',   'Telecomunicações', 'patrocinios@telecom.example', 'https://www.telecom.example',     'Patrocinador principal dos Warriors.',      '2026-01-01', '2027-12-31'),
(2, 'Seguros Costa do Sol',  'prata',  'Seguros',          'geral@costadosol.example',    'https://www.costadosol.example',  'Seguro desportivo do plantel.',             '2026-01-01', '2026-12-31'),
(2, 'Padaria Polana',        'bronze', 'Alimentação',      'ola@padariapolana.example',   'https://www.padariapolana.example','Apoio às camadas jovens.',                 '2026-02-01', '2026-12-31'),
(3, 'Porto da Beira',        'ouro',   'Logística',        'comunicacao@portobeira.example','https://www.portobeira.example','Patrocinador principal dos Eagles.',        '2026-01-01', '2027-12-31'),
(3, 'Cimentos de Sofala',    'prata',  'Construção',       'info@cimsofala.example',      'https://www.cimsofala.example',   'Apoio à renovação do pavilhão.',            '2026-01-01', '2026-12-31'),
(3, 'Farmácia Chiveve',      'bronze', 'Saúde',            'geral@chiveve.example',       'https://www.chiveve.example',     'Parceiro médico da equipa.',                '2026-04-01', '2026-12-31'),
(4, 'Caju do Norte',         'ouro',   'Agroindústria',    'parcerias@cajunorte.example', 'https://www.cajunorte.example',   'Patrocinador principal dos Lions.',         '2026-01-01', '2027-12-31'),
(4, 'Transportes Nacala',    'prata',  'Transportes',      'reservas@tnacala.example',    'https://www.tnacala.example',     'Transporte oficial nas deslocações.',       '2026-01-01', '2026-12-31'),
(4, 'Hotel Girassol Nampula','bronze', 'Hotelaria',        'eventos@girassol.example',    'https://www.girassol.example',    'Alojamento de equipas visitantes.',         '2026-02-01', '2026-12-31'),
(5, 'Chá da Zambézia',       'ouro',   'Alimentação',      'marca@chazambezia.example',   'https://www.chazambezia.example', 'Patrocinador principal dos Sharks.',        '2026-01-01', '2027-12-31'),
(5, 'Pescas do Índico',      'prata',  'Pescas',           'geral@pescasindico.example',  'https://www.pescasindico.example','Apoio ao programa de formação.',            '2026-01-01', '2026-12-31'),
(5, 'Rádio Quelimane FM',    'bronze', 'Média',            'antena@quelimanefm.example',  'https://www.quelimanefm.example', 'Rádio oficial dos jogos em casa.',          '2026-03-01', '2026-12-31');

-- ----------------------------------------------------------------------------
-- NOTÍCIAS — 3 por equipa
-- ----------------------------------------------------------------------------
INSERT INTO news (team_id, author_id, title, content, category, image_url, published_at)
SELECT t.id, t.id + 1,
       REPLACE(x.title, '{equipa}', t.name),
       REPLACE(REPLACE(x.content, '{equipa}', t.name), '{pavilhao}', t.stadium),
       x.category, x.image_url, CURDATE() - INTERVAL x.dias DAY
FROM teams t
CROSS JOIN (
    SELECT '{equipa} prepara a próxima jornada' AS title,
           'O plantel do {equipa} voltou ao trabalho no {pavilhao} com o foco totalmente virado para a próxima jornada da Liga Nacional.' AS content,
           'equipa' AS category, '/imagens/NT1.jpg' AS image_url, 1 AS dias
    UNION ALL SELECT '{equipa} anuncia novo patrocinador',
           'A direção do {equipa} confirmou uma nova parceria que reforça o projeto desportivo do clube para as próximas épocas.',
           'clube', '/imagens/NT2.jpg', 5
    UNION ALL SELECT 'Bilhetes à venda para os jogos em casa',
           'Já estão disponíveis os bilhetes para os próximos jogos do {equipa} no {pavilhao}. Os sócios têm 20% de desconto.',
           'bilhetes', '/imagens/NT3.jpg', 9
) x
ORDER BY t.id, x.dias;

-- ----------------------------------------------------------------------------
-- GALERIA — 4 fotografias por equipa (imagens/1.jpg ... imagens/20.jpg)
-- ----------------------------------------------------------------------------
INSERT INTO gallery_photos (team_id, title, description, image_url, category)
SELECT t.id,
       CONCAT(x.title, ' — ', t.name),
       x.description,
       -- não existe imagens/15.jpg (é .jfif), usa-se a 21 nesse caso
       CONCAT('/imagens/', IF((t.id - 1) * 4 + x.n = 15, 21, (t.id - 1) * 4 + x.n), '.jpg'),
       x.category
FROM teams t
CROSS JOIN (
    SELECT 1 AS n, 'Dia de jogo' AS title, 'Ambiente no pavilhão antes do salto inicial.' AS description, 'jogos' AS category
    UNION ALL SELECT 2, 'Treino da semana',  'Sessão de trabalho tático.',                'treinos'
    UNION ALL SELECT 3, 'Foto de equipa',    'Plantel completo da temporada.',            'equipa'
    UNION ALL SELECT 4, 'Encontro com adeptos','Sessão de autógrafos com os sócios.',     'eventos'
) x
ORDER BY t.id, x.n;

-- ----------------------------------------------------------------------------
-- NOTIFICAÇÕES — pessoais
-- ----------------------------------------------------------------------------
INSERT INTO notifications (user_id, title, message, type, is_read)
SELECT u.id, 'Bem-vindo ao CourtIQMZ',
       CONCAT('Olá ', u.name, ', a sua conta está ativa', IF(t.id IS NULL, '.', CONCAT(' na equipa ', t.name, '.'))),
       'success', TRUE
FROM users u LEFT JOIN teams t ON t.id = u.team_id;

INSERT INTO notifications (user_id, title, message, type, is_read)
SELECT u.id, 'Próximo jogo agendado',
       CONCAT('O ', t.name, ' joga contra ', g.opponent, ' em ', DATE_FORMAT(g.game_date, '%d/%m/%Y'), '.'),
       'info', FALSE
FROM users u
JOIN teams t ON t.id = u.team_id
JOIN games g ON g.id = (SELECT MIN(g2.id) FROM games g2 WHERE g2.team_id = t.id AND g2.status = 'scheduled');

-- ----------------------------------------------------------------------------
-- MENSAGENS DE CONTACTO — 2 por equipa
-- ----------------------------------------------------------------------------
INSERT INTO contact_messages (team_id, name, email, subject, message, status)
SELECT t.id, x.name, x.email, x.subject, CONCAT(x.message, ' (', t.name, ')'), x.status
FROM teams t
CROSS JOIN (
    SELECT 'Marta Bila' AS name, 'marta.bila@example.com' AS email, 'Inscrições na formação' AS subject, 'Gostaria de saber como inscrever o meu filho nos escalões de formação.' AS message, 'unread' AS status
    UNION ALL SELECT 'Paulo Honwana', 'paulo.honwana@example.com', 'Bilhetes de época', 'Ainda há bilhetes de época disponíveis?', 'read'
) x;

-- ----------------------------------------------------------------------------
-- CHAT — mensagens no chat geral de cada equipa
-- ----------------------------------------------------------------------------
INSERT INTO chat_messages (team_id, user_id, recipient_id, content, message_type, is_read, created_at)
SELECT t.id, t.coach_id, 0, CONCAT('Bom trabalho no último treino, ', t.name, '! Amanhã à mesma hora.'), 'general', 1, NOW() - INTERVAL 2 DAY
FROM teams t
UNION ALL
SELECT t.id, t.id + 1, 0, 'Os equipamentos novos chegam esta semana.', 'general', 1, NOW() - INTERVAL 1 DAY
FROM teams t;

-- ----------------------------------------------------------------------------
-- CONFIGURAÇÃO GLOBAL
-- ----------------------------------------------------------------------------
INSERT INTO system_config (config_key, config_value, description) VALUES
('site_name',        'CourtIQMZ',                    'Nome do site'),
('site_description', 'Sistema de Gestão Desportiva',  'Descrição do site'),
('maintenance_mode', '0',                             'Modo de manutenção (0=off, 1=on)'),
('default_theme',    'dark',                          'Tema padrão (dark/light)'),
('league_name',      'Liga Nacional de Basquetebol',  'Nome da liga'),
('season_year',      YEAR(CURDATE()),                 'Ano da temporada'),
('contact_email',    'info@courtiq.com',              'Email de contacto'),
('contact_phone',    '+258 84 123 4567',              'Telefone de contacto'),
('timezone',         'Africa/Maputo',                 'Fuso horário'),
('max_players',      '15',                            'Número máximo de jogadores por equipa'),
('max_teams',        '20',                            'Número máximo de equipas'),
('club_auto_approve', '1',                            'Clubes ficam ativos ao inscrever-se (1) ou esperam aprovação (0)');

-- ----------------------------------------------------------------------------
-- AUDITORIA
-- ----------------------------------------------------------------------------
INSERT INTO audit_logs (user_id, team_id, username, action, details, ip_address, created_at)
SELECT u.id, u.team_id, u.username, 'login', 'Sessão iniciada', '127.0.0.1', NOW() - INTERVAL u.id HOUR
FROM users u;


-- ============================================================================
-- ESCALÕES DE FORMAÇÃO — dados de demonstração dos Juniores e dos Iniciados
-- 6 jogadores, 8 jogos da liga (5 terminados, 3 por disputar) e 3 treinos por
-- clube em cada escalão. Bloco independente: não usa ids fixos.
-- ============================================================================
INSERT INTO players (team_id, name, jersey_number, position, escalao, birth_date, height, weight, is_captain) VALUES
(1, 'Kelvin Mabunda', 4, 'PG', 'juniores', '2008-01-01', 1.73, 66.0, TRUE),
(1, 'Tiago Langa', 6, 'SG', 'juniores', '2009-06-12', 1.80, 71.0, FALSE),
(1, 'Emílio Mondlane', 8, 'SF', 'juniores', '2008-11-23', 1.87, 78.0, FALSE),
(1, 'Leonel Uamusse', 11, 'PF', 'juniores', '2009-04-06', 1.89, 85.0, FALSE),
(1, 'Wilson Tembe', 14, 'C', 'juniores', '2008-09-17', 1.96, 88.0, FALSE),
(1, 'Feliciano Manhique', 21, 'SG', 'juniores', '2009-02-28', 1.79, 69.0, FALSE),
(2, 'Márcio Matsinhe', 4, 'PG', 'juniores', '2008-07-11', 1.73, 68.0, TRUE),
(2, 'Yuran Cossa', 6, 'SG', 'juniores', '2009-12-22', 1.80, 73.0, FALSE),
(2, 'Gilberto Machava', 8, 'SF', 'juniores', '2008-05-05', 1.87, 76.0, FALSE),
(2, 'Nilton Guambe', 11, 'PF', 'juniores', '2009-10-16', 1.89, 83.0, FALSE),
(2, 'Zacarias Zandamela', 14, 'C', 'juniores', '2008-03-27', 1.96, 90.0, FALSE),
(2, 'Ayrton Mabunda', 21, 'SG', 'juniores', '2009-08-10', 1.79, 71.0, FALSE),
(3, 'Osvaldo Manhique', 4, 'PG', 'juniores', '2008-01-21', 1.73, 66.0, TRUE),
(3, 'Baltazar Nhantumbo', 6, 'SG', 'juniores', '2009-06-04', 1.80, 71.0, FALSE),
(3, 'Délcio Macamo', 8, 'SF', 'juniores', '2008-11-15', 1.87, 78.0, FALSE),
(3, 'Rui Sitoe', 11, 'PF', 'juniores', '2009-04-26', 1.89, 85.0, FALSE),
(3, 'Celso Chambule', 14, 'C', 'juniores', '2008-09-09', 1.96, 88.0, FALSE),
(3, 'Ivan Matsinhe', 21, 'SG', 'juniores', '2009-02-20', 1.79, 69.0, FALSE),
(4, 'Stélio Mabunda', 4, 'PG', 'juniores', '2008-07-03', 1.73, 68.0, TRUE),
(4, 'Dário Langa', 6, 'SG', 'juniores', '2009-12-14', 1.80, 73.0, FALSE),
(4, 'Kelvin Mondlane', 8, 'SF', 'juniores', '2008-05-25', 1.87, 76.0, FALSE),
(4, 'Tiago Uamusse', 11, 'PF', 'juniores', '2009-10-08', 1.89, 83.0, FALSE),
(4, 'Emílio Tembe', 14, 'C', 'juniores', '2008-03-19', 1.96, 90.0, FALSE),
(4, 'Leonel Manhique', 21, 'SG', 'juniores', '2009-08-02', 1.79, 71.0, FALSE),
(5, 'Wilson Matsinhe', 4, 'PG', 'juniores', '2008-01-13', 1.73, 66.0, TRUE),
(5, 'Feliciano Cossa', 6, 'SG', 'juniores', '2009-06-24', 1.80, 71.0, FALSE),
(5, 'Márcio Machava', 8, 'SF', 'juniores', '2008-11-07', 1.87, 78.0, FALSE),
(5, 'Yuran Guambe', 11, 'PF', 'juniores', '2009-04-18', 1.89, 85.0, FALSE),
(5, 'Gilberto Zandamela', 14, 'C', 'juniores', '2008-09-01', 1.96, 88.0, FALSE),
(5, 'Nilton Mabunda', 21, 'SG', 'juniores', '2009-02-12', 1.79, 69.0, FALSE),
(1, 'Zacarias Mabunda', 4, 'PG', 'iniciados', '2012-07-23', 1.51, 46.0, TRUE),
(1, 'Ayrton Langa', 6, 'SG', 'iniciados', '2013-12-06', 1.56, 50.0, FALSE),
(1, 'Osvaldo Mondlane', 8, 'SF', 'iniciados', '2012-05-17', 1.63, 52.0, FALSE),
(1, 'Baltazar Uamusse', 11, 'PF', 'iniciados', '2013-10-28', 1.65, 57.0, FALSE),
(1, 'Délcio Tembe', 14, 'C', 'iniciados', '2012-03-11', 1.72, 64.0, FALSE),
(1, 'Rui Manhique', 21, 'SG', 'iniciados', '2013-08-22', 1.56, 49.0, FALSE),
(2, 'Celso Matsinhe', 4, 'PG', 'iniciados', '2012-01-05', 1.51, 44.0, TRUE),
(2, 'Ivan Cossa', 6, 'SG', 'iniciados', '2013-06-16', 1.56, 48.0, FALSE),
(2, 'Stélio Machava', 8, 'SF', 'iniciados', '2012-11-27', 1.63, 54.0, FALSE),
(2, 'Dário Guambe', 11, 'PF', 'iniciados', '2013-04-10', 1.65, 59.0, FALSE),
(2, 'Kelvin Zandamela', 14, 'C', 'iniciados', '2012-09-21', 1.72, 62.0, FALSE),
(2, 'Tiago Mabunda', 21, 'SG', 'iniciados', '2013-02-04', 1.56, 47.0, FALSE),
(3, 'Emílio Manhique', 4, 'PG', 'iniciados', '2012-07-15', 1.51, 46.0, TRUE),
(3, 'Leonel Nhantumbo', 6, 'SG', 'iniciados', '2013-12-26', 1.56, 50.0, FALSE),
(3, 'Wilson Macamo', 8, 'SF', 'iniciados', '2012-05-09', 1.63, 52.0, FALSE),
(3, 'Feliciano Sitoe', 11, 'PF', 'iniciados', '2013-10-20', 1.65, 57.0, FALSE),
(3, 'Márcio Chambule', 14, 'C', 'iniciados', '2012-03-03', 1.72, 64.0, FALSE),
(3, 'Yuran Matsinhe', 21, 'SG', 'iniciados', '2013-08-14', 1.56, 49.0, FALSE),
(4, 'Gilberto Mabunda', 4, 'PG', 'iniciados', '2012-01-25', 1.51, 44.0, TRUE),
(4, 'Nilton Langa', 6, 'SG', 'iniciados', '2013-06-08', 1.56, 48.0, FALSE),
(4, 'Zacarias Mondlane', 8, 'SF', 'iniciados', '2012-11-19', 1.63, 54.0, FALSE),
(4, 'Ayrton Uamusse', 11, 'PF', 'iniciados', '2013-04-02', 1.65, 59.0, FALSE),
(4, 'Osvaldo Tembe', 14, 'C', 'iniciados', '2012-09-13', 1.72, 62.0, FALSE),
(4, 'Baltazar Manhique', 21, 'SG', 'iniciados', '2013-02-24', 1.56, 47.0, FALSE),
(5, 'Délcio Matsinhe', 4, 'PG', 'iniciados', '2012-07-07', 1.51, 46.0, TRUE),
(5, 'Rui Cossa', 6, 'SG', 'iniciados', '2013-12-18', 1.56, 50.0, FALSE),
(5, 'Celso Machava', 8, 'SF', 'iniciados', '2012-05-01', 1.63, 52.0, FALSE),
(5, 'Ivan Guambe', 11, 'PF', 'iniciados', '2013-10-12', 1.65, 57.0, FALSE),
(5, 'Stélio Zandamela', 14, 'C', 'iniciados', '2012-03-23', 1.72, 64.0, FALSE),
(5, 'Dário Mabunda', 21, 'SG', 'iniciados', '2013-08-06', 1.56, 49.0, FALSE);

CREATE TEMPORARY TABLE seed_formacao (
    jornada    INT PRIMARY KEY,
    home_id    INT NOT NULL,
    away_id    INT NOT NULL,
    day_offset INT NOT NULL,
    game_time  TIME NOT NULL,
    status     VARCHAR(20) NOT NULL
);
INSERT INTO seed_formacao VALUES
(1, 1, 2, -36, '10:00:00', 'finished'),
(2, 3, 4, -29, '09:30:00', 'finished'),
(3, 5, 1, -22, '10:30:00', 'finished'),
(4, 2, 3, -15, '10:00:00', 'finished'),
(5, 4, 5,  -8, '09:30:00', 'finished'),
(6, 1, 3,   6, '10:00:00', 'scheduled'),
(7, 2, 4,  13, '10:30:00', 'scheduled'),
(8, 3, 5,  20, '09:30:00', 'scheduled');

-- cada jogo gera dois registos (casa e fora), nos dois escalões; os iniciados jogam na véspera
INSERT INTO games (team_id, opponent, game_date, game_time, location, home_away, competition, escalao, jornada, status)
SELECT x.team_id, x.opponent, CURDATE() + INTERVAL (x.day_offset - IF(e.escalao = 'iniciados', 1, 0)) DAY, x.game_time, x.location, x.home_away,
       CONCAT('Liga Nacional de ', IF(e.escalao = 'juniores', 'Juniores', 'Iniciados')), e.escalao, CONCAT('Jornada ', x.jornada), x.status
FROM (
    SELECT m.jornada, m.home_id AS team_id, a.name AS opponent, m.day_offset, m.game_time, h.stadium AS location, 'home' AS home_away, m.status
    FROM seed_formacao m JOIN teams h ON h.id = m.home_id JOIN teams a ON a.id = m.away_id
    UNION ALL
    SELECT m.jornada, m.away_id, h.name, m.day_offset, m.game_time, h.stadium, 'away', m.status
    FROM seed_formacao m JOIN teams h ON h.id = m.home_id JOIN teams a ON a.id = m.away_id
) x
CROSS JOIN (SELECT 'juniores' AS escalao UNION ALL SELECT 'iniciados') e
ORDER BY e.escalao DESC, x.jornada, x.home_away DESC;

DROP TEMPORARY TABLE seed_formacao;

-- estatísticas: uma linha por jogador do escalão em cada jogo terminado desse escalão
INSERT INTO game_player_stats
    (game_id, player_id, points, rebounds, assists, steals, blocks, fouls, minutes_played, three_pointers, free_throws)
SELECT g.id, p.id,
       IF(g.escalao = 'juniores', 6, 3) + ((n.j * 7 + p.team_id * 13 + p.jersey_number * 3) % 11) + IF((g.home_away = 'home') = (n.j % 2 = 1), 2, 0),
       1 + ((n.j * 3 + p.team_id * 5 + p.jersey_number) % 8),
       (n.j * 5 + p.team_id * 3 + p.jersey_number) % 7,
       (n.j + p.team_id + p.jersey_number) % 4,
       (n.j * 2 + p.jersey_number) % 3,
       (n.j + p.jersey_number * 2) % 5,
       14 + ((n.j * 11 + p.team_id * 7 + p.jersey_number) % 20),
       (n.j + p.jersey_number) % 3,
       (n.j * 3 + p.jersey_number) % 3
FROM games g
JOIN (SELECT id, CAST(SUBSTRING(jornada, 9) AS UNSIGNED) AS j FROM games) n ON n.id = g.id
JOIN players p ON p.team_id = g.team_id AND p.escalao = g.escalao
WHERE g.status = 'finished' AND g.escalao IN ('juniores', 'iniciados');

UPDATE game_player_stats s JOIN games g ON g.id = s.game_id
SET s.three_pointers = LEAST(s.three_pointers, s.points DIV 3)
WHERE g.escalao IN ('juniores', 'iniciados');
UPDATE game_player_stats s JOIN games g ON g.id = s.game_id
SET s.free_throws = LEAST(s.free_throws, s.points - s.three_pointers * 3)
WHERE g.escalao IN ('juniores', 'iniciados');
UPDATE game_player_stats s JOIN games g ON g.id = s.game_id
SET s.field_goals = (s.points - s.three_pointers * 3 - s.free_throws) DIV 2 + s.three_pointers,
    s.efficiency  = s.points + s.rebounds + s.assists + s.steals + s.blocks
WHERE g.escalao IN ('juniores', 'iniciados');

-- o resultado é a soma dos pontos dos jogadores; o do adversário vem do registo espelho
UPDATE games g
JOIN (SELECT game_id, SUM(points) AS total FROM game_player_stats GROUP BY game_id) s ON s.game_id = g.id
SET g.our_score = s.total
WHERE g.escalao IN ('juniores', 'iniciados');

UPDATE games g
JOIN games o ON o.escalao = g.escalao AND o.jornada = g.jornada AND o.id <> g.id
SET g.opponent_score = o.our_score
WHERE g.status = 'finished' AND g.escalao IN ('juniores', 'iniciados');

-- convocatória para os jogos por disputar
INSERT INTO escalacao (game_id, player_id, position, posicao_campo, is_starter, is_titular, is_captain)
SELECT g.id, p.id, p.position, p.position, p.jersey_number <> 21, p.jersey_number <> 21, p.is_captain
FROM games g
JOIN players p ON p.team_id = g.team_id AND p.escalao = g.escalao AND p.status = 'active'
WHERE g.status = 'scheduled' AND g.escalao IN ('juniores', 'iniciados');

-- treinos: 3 por clube em cada escalão (2 realizados, 1 agendado)
INSERT INTO trainings (team_id, name, training_date, start_time, end_time, training_time, location, type, escalao, objective, description, status)
SELECT t.id, x.name, CURDATE() + INTERVAL x.day_offset DAY, IF(e.escalao = 'juniores', '16:00:00', '14:30:00'), IF(e.escalao = 'juniores', '17:30:00', '15:45:00'),
       IF(e.escalao = 'juniores', '16:00:00', '14:30:00'), t.stadium, x.type, e.escalao, x.objective, x.objective, x.status
FROM teams t
CROSS JOIN (SELECT 'juniores' AS escalao UNION ALL SELECT 'iniciados') e
CROSS JOIN (
    SELECT -9 AS day_offset, 'Treino de fundamentos' AS name, 'technical' AS type, 'Drible, passe e lançamento na passada' AS objective, 'finished' AS status
    UNION ALL SELECT -4, 'Treino coletivo',     'collective', 'Jogo reduzido 3x3 e 4x4',               'finished'
    UNION ALL SELECT  3, 'Preparação do jogo',  'strategic',  'Defesa individual e transição rápida', 'scheduled'
) x
WHERE t.id <= 5
ORDER BY e.escalao DESC, t.id, x.day_offset;

-- presenças nos treinos de formação já realizados
INSERT INTO attendance (event_id, player_id, event_type, present, status, justification)
SELECT tr.id, p.id, 'training',
       (p.jersey_number + p.team_id + DAYOFYEAR(tr.training_date)) % 7 <> 0,
       IF((p.jersey_number + p.team_id + DAYOFYEAR(tr.training_date)) % 7 <> 0, 'present', 'excused'),
       IF((p.jersey_number + p.team_id + DAYOFYEAR(tr.training_date)) % 7 = 0, 'Motivos escolares', NULL)
FROM trainings tr
JOIN players p ON p.team_id = tr.team_id AND p.escalao = tr.escalao
WHERE tr.status = 'finished' AND tr.escalao IN ('juniores', 'iniciados');

-- ############################################################################
-- VERIFICAÇÃO — o que cada equipa tem
-- ############################################################################
SELECT t.id, t.name AS equipa,
       (SELECT COUNT(*) FROM users u          WHERE u.team_id = t.id) AS contas,
       (SELECT COUNT(*) FROM players p        WHERE p.team_id = t.id) AS jogadores,
       (SELECT COUNT(*) FROM games g          WHERE g.team_id = t.id) AS jogos,
       (SELECT COUNT(*) FROM trainings tr     WHERE tr.team_id = t.id) AS treinos,
       (SELECT COUNT(*) FROM finances f       WHERE f.team_id = t.id) AS financas,
       (SELECT COUNT(*) FROM news n           WHERE n.team_id = t.id) AS noticias,
       (SELECT COUNT(*) FROM sponsors s       WHERE s.team_id = t.id) AS patrocinadores,
       (SELECT COUNT(*) FROM gallery_photos gp WHERE gp.team_id = t.id) AS fotos
FROM teams t
ORDER BY t.id;
