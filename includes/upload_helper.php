<?php
declare(strict_types=1);

/**
 * upload_helper.php — envio de imagens (fotografias, emblemas, capas de notícias).
 *
 * Os ficheiros ficam em /uploads/equipa_{id}/{pasta}/ com um nome aleatório.
 * Regras de segurança:
 *   - só imagens verdadeiras (o tipo é lido do conteúdo, não da extensão nem do browser)
 *   - a extensão do ficheiro guardado é escolhida por nós a partir do tipo real
 *   - /uploads não executa PHP (ver uploads/.htaccess)
 *   - só se apagam ficheiros dentro da pasta da própria equipa
 */

const UPLOAD_MAX_BYTES = 4 * 1024 * 1024;   // 4 MB
const UPLOAD_TYPES     = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

/** Foi enviado um ficheiro neste campo? */
function upload_has(string $field): bool
{
    return isset($_FILES[$field]) && is_array($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/**
 * Guarda a imagem enviada no campo $field e devolve o caminho a gravar na base de dados
 * (ex.: "/uploads/equipa_3/noticias/ab12cd34.jpg"). Lança RuntimeException com uma
 * mensagem para o utilizador se o ficheiro não servir.
 */
function upload_image(string $field, string $folder, int $teamId): string
{
    $file = $_FILES[$field] ?? null;
    if (!$file || !is_array($file) || is_array($file['error'] ?? null)) {
        throw new RuntimeException('Nenhum ficheiro enviado.');
    }

    switch ((int) $file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new RuntimeException('A imagem é demasiado grande (máximo 4 MB).');
        default:
            throw new RuntimeException('O envio da imagem falhou. Tente novamente.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Ficheiro inválido.');
    }
    if ((int) $file['size'] <= 0 || (int) $file['size'] > UPLOAD_MAX_BYTES) {
        throw new RuntimeException('A imagem é demasiado grande (máximo 4 MB).');
    }

    // tipo real, lido do conteúdo
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!$info || !isset(UPLOAD_TYPES[$mime])) {
        throw new RuntimeException('Só são aceites imagens JPG, PNG, WEBP ou GIF.');
    }
    if ($info[0] > 6000 || $info[1] > 6000) {
        throw new RuntimeException('A imagem tem dimensões demasiado grandes (máximo 6000 px).');
    }

    $folder  = preg_replace('/[^a-z0-9_-]/', '', strtolower($folder)) ?: 'outros';
    $relDir  = 'uploads/equipa_' . $teamId . '/' . $folder;
    $absDir  = dirname(__DIR__) . '/' . $relDir;
    if (!is_dir($absDir) && !mkdir($absDir, 0775, true) && !is_dir($absDir)) {
        throw new RuntimeException('Não foi possível criar a pasta de imagens.');
    }

    $name = bin2hex(random_bytes(8)) . '.' . UPLOAD_TYPES[$mime];
    if (!move_uploaded_file($file['tmp_name'], $absDir . '/' . $name)) {
        throw new RuntimeException('Não foi possível guardar a imagem.');
    }

    return '/' . $relDir . '/' . $name;
}

/**
 * Apaga uma imagem enviada, mas só se estiver dentro de /uploads/equipa_{teamId}/.
 * Caminhos de exemplo (/imagens/...) ou de outra equipa são ignorados.
 */
function upload_delete(?string $path, int $teamId): void
{
    $path = ltrim((string) $path, '/');
    $prefix = 'uploads/equipa_' . $teamId . '/';
    if ($path === '' || strpos($path, $prefix) !== 0 || strpos($path, '..') !== false) {
        return;
    }
    $abs = dirname(__DIR__) . '/' . $path;
    if (is_file($abs)) {
        @unlink($abs);
    }
}
