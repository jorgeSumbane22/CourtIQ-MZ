<?php
declare(strict_types=1);

/**
 * functions.php — pequenos helpers puros e reutilizáveis, usados por toda a app.
 * Mantidos fora de classes de propósito porque não têm estado nem dependências.
 */

/** Escapa output para HTML, prevenindo XSS. Uso: <?= e($valor) ?> */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Redireciona e termina a execução do script. */
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/** Gera (ou reutiliza) o token CSRF da sessão atual. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Valida um token CSRF recebido num formulário, com comparação segura contra timing attacks. */
function csrf_verify(?string $token): bool
{
    return isset($_SESSION['csrf_token']) && $token !== null && hash_equals($_SESSION['csrf_token'], $token);
}

/** Formata um valor numérico como percentagem com 1 casa decimal. */
function format_percent(float $value): string
{
    return number_format($value, 1, ',', '.') . '%';
}

/** Formata uma data SQL (Y-m-d) para o formato português (d/m/Y). */
function format_date_pt(?string $sqlDate): string
{
    if (!$sqlDate) {
        return '—';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $sqlDate);
    return $dt ? $dt->format('d/m/Y') : '—';
}
