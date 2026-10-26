<?php
declare(strict_types=1);

/**
 * lang.php — idioma do sistema (português e inglês).
 *
 * As páginas estão escritas em português. Quando o idioma escolhido é o inglês, a página
 * inteira é traduzida no momento de ser enviada: o HTML passa por lang_translate_html(),
 * que troca cada texto, e cada placeholder / title / aria-label, pelo que estiver no
 * dicionário lang/en.php. Não é preciso mexer nas páginas para elas mudarem de idioma;
 * um texto novo só precisa de entrar no dicionário.
 *
 * Os textos com partes variáveis (números, nomes de clubes, o nome de quem tem sessão)
 * são reduzidos a um molde antes de procurar no dicionário:
 *     "8 atletas no plantel de Beira Eagles."  →  "{n} atletas no plantel de {0}."
 * O que foi escrito pelas pessoas (nomes, notícias, mensagens) nunca é traduzido.
 */

const LANGS = [
    'pt' => ['Português', 'pt'],
    'en' => ['English', 'en'],
];
const LANG_COOKIE = 'courtiq_lang';

/** Idioma em uso: o do cookie, ou português. */
function lang_current(): string
{
    static $lang = null;
    if ($lang === null) {
        $pedido = (string) ($_COOKIE[LANG_COOKIE] ?? 'pt');
        $lang   = isset(LANGS[$pedido]) ? $pedido : 'pt';
    }
    return $lang;
}

/**
 * ?lang=en (ou pt) em qualquer página grava a escolha e volta à mesma página sem o parâmetro.
 * Chama-se no arranque (bootstrap.php), antes de qualquer saída.
 */
function lang_handle_switch(): void
{
    if (!isset($_GET['lang']) || PHP_SAPI === 'cli') {
        return;
    }
    $novo = (string) $_GET['lang'];
    if (isset(LANGS[$novo]) && !headers_sent()) {
        setcookie(LANG_COOKIE, $novo, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        $params = $_GET;
        unset($params['lang']);
        $caminho = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
        header('Location: ' . $caminho . ($params ? '?' . http_build_query($params) : ''));
        exit;
    }
}

/** Endereço da página atual no idioma indicado (para os botões do seletor). */
function lang_url(string $lang): string
{
    $params = $_GET;
    $params['lang'] = $lang;
    return '?' . http_build_query($params);
}

/** Dicionário do idioma em uso (vazio em português). */
function lang_dictionary(): array
{
    static $dict = null;
    if ($dict === null) {
        $file = dirname(__DIR__) . '/lang/' . lang_current() . '.php';
        $dict = lang_current() !== 'pt' && is_file($file) ? (array) require $file : [];
    }
    return $dict;
}

/**
 * Valores que nunca se traduzem e que se tiram do texto antes de procurar o molde:
 * nomes de clubes, cidades, pavilhões, pessoas, jogadores, patrocinadores e adversários.
 */
function lang_entities(): array
{
    static $entities = null;
    if ($entities !== null) {
        return $entities;
    }
    $entities = [];
    $fontes = [
        'SELECT name FROM teams', 'SELECT city FROM teams', 'SELECT stadium FROM teams',
        'SELECT name FROM users LIMIT 2000', 'SELECT name FROM players LIMIT 3000', 'SELECT name FROM sponsors LIMIT 500',
        'SELECT name FROM coaches LIMIT 500', 'SELECT DISTINCT opponent FROM games LIMIT 1000',
        "SELECT username FROM users WHERE username LIKE '%.%' LIMIT 2000",   // admin.eagles, joao.jogador…
    ];
    try {
        $pdo = Database::getConnection();
        foreach ($fontes as $sql) {
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $v) {
                if (is_string($v)) $entities[] = trim($v);
            }
        }
    } catch (Throwable $e) {
        // sem base de dados traduz-se só o que for fixo
    }
    foreach (['name', 'username'] as $campo) {
        $v = trim((string) ($_SESSION['user'][$campo] ?? ''));
        if ($v !== '') {
            $entities[] = $v;
            $entities[] = explode(' ', $v)[0];   // "Olá, Marta"
        }
    }
    $entities = array_values(array_unique(array_filter($entities, fn($v) => mb_strlen($v) >= 4)));
    usort($entities, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));   // os mais compridos primeiro
    return $entities;
}

/**
 * Reduz um texto ao seu molde: os nomes conhecidos passam a {0}, {1}… e os números a {n}.
 * Devolve [molde, nomes, números], para depois repor os valores na tradução.
 */
function lang_mold(string $texto): array
{
    $nomes = [];
    foreach (lang_entities() as $valor) {
        if (mb_strpos($texto, $valor) === false) {
            $valor = htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');   // "D&#039;Almeida" tal como vem no HTML
            if (mb_strpos($texto, $valor) === false) continue;
        }
        // só como palavra inteira: "Beira" não pode apanhar "Beirão", nem um nome curto o meio de outra palavra
        $novo = preg_replace('/(?<![\p{L}\p{N}_.@-])' . preg_quote($valor, '/') . '(?![\p{L}\p{N}_@-])/u', "\x01" . chr(65 + count($nomes)) . "\x02", $texto);
        if ($novo === null || $novo === $texto) continue;
        $nomes[] = $valor;
        $texto   = $novo;                                  // marcador sem dígitos, para não se confundir com os números
        if (count($nomes) >= 26) break;
    }
    $numeros = [];
    $texto = (string) preg_replace_callback('/\d+(?:[.,:\/-]\d+)*/u', function ($m) use (&$numeros) {
        $numeros[] = $m[0];
        return '{n}';
    }, $texto);
    $molde = (string) preg_replace_callback('/\x01([A-Z])\x02/', fn($m) => '{' . (ord($m[1]) - 65) . '}', $texto);
    return [$molde, $nomes, $numeros];
}

/** Molde de um texto, tal como o dicionário o espera. */
function lang_key(string $text): string
{
    return lang_mold(trim((string) preg_replace('/\s+/u', ' ', $text)))[0];
}

/** Procura um texto no dicionário, diretamente ou pelo seu molde. Devolve null se não houver. */
function lang_lookup(string $chave, array $dict): ?string
{
    if (isset($dict[$chave])) {
        return (string) $dict[$chave];
    }
    [$molde, $nomes, $numeros] = lang_mold($chave);
    if ($molde === $chave || !isset($dict[$molde])) {
        // frases com um pedaço escrito pelas pessoas (um título entre plicas, um nome novo): lang/en/patterns.php
        foreach ((array) ($dict['~'] ?? []) as [$padrao, $traducao]) {
            if (preg_match($padrao, $chave)) {
                return (string) preg_replace($padrao, $traducao, $chave);
            }
        }
        return null;
    }
    $saida = (string) preg_replace_callback('/\{(\d+)\}/', fn($m) => $nomes[(int) $m[1]] ?? $m[0], (string) $dict[$molde]);
    $i = 0;
    return (string) preg_replace_callback('/\{n\}/', function () use (&$i, $numeros) { return $numeros[$i++] ?? ''; }, $saida);
}

/** "Conta: andre.jogador" → traduz o rótulo antes dos dois pontos e mantém o resto. */
function lang_lookup_prefixed(string $chave, array $dict): ?string
{
    $pos = mb_strpos($chave, ': ');
    if ($pos === false || $pos > 40) {
        return null;
    }
    $rotulo = mb_substr($chave, 0, $pos);
    $t = $dict[$rotulo . ':'] ?? $dict[$rotulo] ?? null;
    return $t === null ? null : rtrim((string) $t, ':') . mb_substr($chave, $pos);
}

/** Separadores de textos compostos: "Seniores · Liga Nacional · Jornada 8", "CourtIQMZ - Admin | Jogos". */
const LANG_SPLIT = '/( · | • | &bull; | &middot; | — | &mdash; | – | - | \| )/u';

/**
 * Traduz um texto solto (sem etiquetas HTML). Devolve-o igual se não houver tradução.
 * Com $GLOBALS['LANG_COLLECT'] definido como array, regista aí os moldes sem tradução
 * (é assim que se recolhem os textos novos para completar o dicionário).
 */
function lang_translate_text(string $text): string
{
    $dict    = lang_dictionary();
    $recolhe = isset($GLOBALS['LANG_COLLECT']) && is_array($GLOBALS['LANG_COLLECT']);
    if (!$dict && !$recolhe) {
        return $text;
    }
    if (!preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $m) || $m[2] === '') {
        return $text;
    }
    [, $antes, $miolo, $depois] = $m;
    $chave = (string) preg_replace('/\s+/u', ' ', $miolo);
    if (!preg_match('/\p{L}{2}/u', $chave)) {
        return $text;                                    // só números ou pontuação
    }

    $traduzido = lang_lookup($chave, $dict);
    if ($traduzido === null) {
        $partes = preg_split(LANG_SPLIT, $chave, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$chave];
        $mudou  = false;
        foreach ($partes as $i => $parte) {
            if ($i % 2 === 1 || !preg_match('/\p{L}{2}/u', $parte)) continue;
            $t = lang_lookup($parte, $dict) ?? lang_lookup_prefixed($parte, $dict);
            if ($t !== null) {
                $partes[$i] = $t;
                $mudou = true;
            } elseif ($recolhe) {
                $molde = lang_key($parte);
                if (preg_match('/\p{L}{2}/u', (string) preg_replace('/\{\w+\}/', '', $molde))) {
                    $GLOBALS['LANG_COLLECT'][$molde] = $parte;
                }
            }
        }
        $traduzido = $mudou ? implode('', $partes) : null;
    }
    return $traduzido === null ? $text : $antes . $traduzido . $depois;
}

const LANG_ATTRS = 'placeholder|title|aria-label|alt|data-confirm|label';

/**
 * Traduz uma página HTML inteira: os textos entre etiquetas e os atributos de LANG_ATTRS.
 * O que está dentro de <script>, <style>, <textarea> e <code>, e as etiquetas marcadas
 * com data-no-translate, ficam como estão.
 */
function lang_translate_html(string $html): string
{
    if (!lang_dictionary() && !isset($GLOBALS['LANG_COLLECT'])) {
        return $html;
    }
    $partes = preg_split('/(<!--.*?-->|<(?:script|style|textarea|code)\b.*?<\/(?:script|style|textarea|code)>|<a\b[^>]*data-no-translate[^>]*>.*?<\/a>|<[^>]+>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($partes === false) {
        return $html;
    }
    foreach ($partes as $i => $parte) {
        if ($parte === '') continue;
        if ($i % 2 === 0) {                               // texto entre etiquetas
            $partes[$i] = lang_translate_text($parte);
        } elseif ($parte[1] !== '!' && !preg_match('/^<(?:script|style|textarea|code)\b/i', $parte) && stripos($parte, 'data-no-translate') === false) {
            // atributos visíveis de uma etiqueta
            $partes[$i] = preg_replace_callback('/\b(' . LANG_ATTRS . ')="([^"]*)"/u', fn($m) => $m[1] . '="' . lang_translate_text($m[2]) . '"', $parte) ?? $parte;
            // botões <input type="submit" value="…">
            if (preg_match('/^<input\b[^>]*type="(?:submit|button)"/i', $parte)) {
                $partes[$i] = preg_replace_callback('/\bvalue="([^"]*)"/u', fn($m) => 'value="' . lang_translate_text($m[1]) . '"', $partes[$i]) ?? $partes[$i];
            }
            // títulos das janelas, que o JavaScript lê de data-values='{"janela":"Nova notícia", …}'
            if (strpos($parte, 'data-values="') !== false) {
                $partes[$i] = preg_replace_callback('/(&quot;(?:janela|titulo|quem_rotulo)&quot;:&quot;)(.*?)(&quot;)/u', fn($m) => $m[1] . lang_translate_text($m[2]) . $m[3], $partes[$i]) ?? $partes[$i];
            }
            if (stripos($parte, '<html') === 0) {
                $partes[$i] = preg_replace('/\blang="[a-z-]+"/', 'lang="' . LANGS[lang_current()][1] . '"', $partes[$i]) ?? $partes[$i];
            }
        }
    }
    return implode('', $partes);
}

/** Liga a tradução da página (chama-se no início do <head>, em head.php e auth_page.php). */
function lang_start(): void
{
    if (lang_current() !== 'pt') {
        ob_start(static fn(string $html): string => lang_translate_html($html));   // o PHP passa um 2.º argumento que não interessa
    }
}

/** Tradução de uma frase solta em PHP (mensagens que não passam por uma página HTML). */
function t(string $text): string
{
    return lang_current() === 'pt' ? $text : lang_translate_text($text);
}

/** Textos usados pelo JavaScript (assets/js) no idioma em uso: window.T('…'). */
function lang_js_dictionary(): array
{
    $file = dirname(__DIR__) . '/lang/' . lang_current() . '.js.php';
    return lang_current() !== 'pt' && is_file($file) ? (array) require $file : [];
}

/** Bandeiras do seletor de idioma (SVG, porque os emojis de bandeiras não aparecem no Windows). */
function lang_flag(string $lang): string
{
    if ($lang === 'en') {   // Reino Unido
        return '<svg class="lang-flag" viewBox="0 0 60 40" aria-hidden="true"><rect width="60" height="40" fill="#012169"/>'
             . '<path d="M0 0l60 40M60 0L0 40" stroke="#fff" stroke-width="8"/><path d="M0 0l60 40M60 0L0 40" stroke="#C8102E" stroke-width="3"/>'
             . '<path d="M30 0v40M0 20h60" stroke="#fff" stroke-width="13"/><path d="M30 0v40M0 20h60" stroke="#C8102E" stroke-width="7"/></svg>';
    }
    // Moçambique: verde, preto e amarelo separados por branco, triângulo vermelho com estrela amarela
    return '<svg class="lang-flag" viewBox="0 0 60 40" aria-hidden="true"><rect width="60" height="40" fill="#fff"/>'
         . '<rect width="60" height="12" fill="#007168"/><rect y="14" width="60" height="12" fill="#000"/><rect y="28" width="60" height="12" fill="#FCE100"/>'
         . '<path d="M0 0l26 20L0 40z" fill="#D21034"/>'
         . '<path d="M9 13.2l1.6 4.9h5.1l-4.1 3 1.6 4.9-4.2-3-4.2 3 1.6-4.9-4.1-3h5.1z" fill="#FCE100"/></svg>';
}

/** Seletor de idioma (navbar e páginas de entrada): bandeira + nome de cada idioma. */
function lang_switcher(): string
{
    $html = '<div class="lang-switch" role="group" aria-label="Idioma / Language">';
    foreach (LANGS as $codigo => [$nome]) {
        $ativo = $codigo === lang_current();
        $html .= '<a class="lang-opt' . ($ativo ? ' is-active' : '') . '" href="' . htmlspecialchars(lang_url($codigo), ENT_QUOTES, 'UTF-8') . '" hreflang="' . $codigo . '" lang="' . $codigo . '"'
               . ($ativo ? ' aria-current="true"' : '') . ' data-no-translate>' . lang_flag($codigo) . '<span>' . $nome . '</span></a>';
    }
    return $html . '</div>';
}
