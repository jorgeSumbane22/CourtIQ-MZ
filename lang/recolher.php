<?php
/**
 * Campos que o login pede além do utilizador e da palavra-passe: o perfil e, conforme o perfil,
 * o clube e o escalão. Lê-os da base de dados para a conta indicada (usado pelos scripts de teste).
 */
function login_fields(string $user): array
{
    static $pdo = null;
    $pdo ??= new PDO('mysql:host=localhost;dbname=courtiq;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $stmt = $pdo->prepare('SELECT u.id, u.role, u.team_id, u.escaloes, t.escaloes AS do_clube FROM users u LEFT JOIN teams t ON t.id = u.team_id WHERE u.username = ? OR u.email = ? LIMIT 1');
    $stmt->execute([$user, $user]);
    $u = $stmt->fetch();
    if (!$u) {
        return ['perfil' => 'adepto'];
    }
    $campos = ['perfil' => $u['role']];
    if (in_array($u['role'], ['admin', 'treinador', 'jogador'], true)) {
        $campos['team_id'] = (int) $u['team_id'];
    }
    if ($u['role'] === 'jogador') {
        $stmt = $pdo->prepare('SELECT escalao FROM players WHERE user_id = ? LIMIT 1');
        $stmt->execute([(int) $u['id']]);
        $campos['escalao'] = $stmt->fetchColumn() ?: 'seniores';
    } elseif ($u['role'] === 'treinador') {
        $seus = array_values(array_filter(explode(',', (string) ($u['escaloes'] ?: $u['do_clube'] ?: 'seniores'))));
        $campos['escalao'] = in_array('seniores', $seus, true) ? 'seniores' : ($seus[0] ?? 'seniores');
    }
    return $campos;
}

// Lista os textos que ainda não têm tradução: percorre todas as páginas com cada perfil (contas de
// demonstração, palavra-passe admin123) e escreve um texto por linha, já no molde que o dicionário espera.
// uso (linha de comandos):  set RECOLHER_LANG=en  &&  php lang/recolher.php em-falta.txt
// Depois é só acrescentar 'texto em português' => 'English text' a um ficheiro de lang/en/.
$root = dirname(__DIR__);
$base = getenv('COURTIQ_URL') ?: 'http://localhost/CourtIQ';   // endereço do site a percorrer
$_COOKIE['courtiq_lang'] = getenv('RECOLHER_LANG') ?: 'pt';
require "$root/includes/Database.php";
require "$root/includes/lang.php";
$pdo = Database::getConnection();
$tmp = sys_get_temp_dir(); $n = 0;
function req(string $url, string $jar, ?array $post = null): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIE => 'courtiq_lang=pt']);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $r = (string) curl_exec($ch); curl_close($ch); return $r;
}
$login = function (?string $user) use ($base, $tmp, &$n) {
    $jar = "$tmp/ciq_rec_" . (++$n) . '.txt'; @unlink($jar);
    if ($user !== null) {
        preg_match('/name="csrf_token" value="([^"]+)"/', req("$base/login.php", $jar), $m);
        req("$base/login.php", $jar, ['csrf_token' => $m[1] ?? '', 'username' => $user, 'password' => 'admin123'] + login_fields($user));
    }
    return $jar;
};
$paginas = fn(string $dir) => array_map(fn($f) => "$dir/" . basename($f), glob("$root/$dir/*.php"));
$one = fn(string $sql) => $pdo->query($sql)->fetchColumn();
$g3 = (int) $one("SELECT id FROM games WHERE team_id = 3 AND status = 'finished' LIMIT 1");
$g3s = (int) $one("SELECT id FROM games WHERE team_id = 3 AND status = 'scheduled' LIMIT 1");
$t3 = (int) $one('SELECT id FROM trainings WHERE team_id = 3 LIMIT 1');
$p3 = (int) $one('SELECT id FROM players WHERE team_id = 3 LIMIT 1');
$n3 = (int) $one('SELECT id FROM news WHERE team_id = 3 LIMIT 1');

$perfis = [
    ['Visitante', null, array_merge(['index.php', 'login.php', 'login.php?erro=sessao', 'login.php?ok=saiu', 'login.php?ok=redefinida', 'registar.php', 'registar.php?tipo=jogador', 'registar-clube.php', 'registar-clube.php?ok=1', 'recuperar.php', 'redefinir.php?token=x'], $paginas('visitante'), ["visitante/noticias.php?id=$n3"])],
    ['Teresa Mondlane', 'admin.eagles', array_merge($paginas('admin'), ['index.php', 'visitante/estatisticas.php', 'visitante/galeria.php', 'visitante/bilhetes.php', "admin/jogos.php?estatisticas=$g3", "admin/jogador-perfil.php?id=$p3", "admin/presencas.php?treino_id=$t3", "admin/presencas.php?jogo_id=$g3", 'admin/notificacoes.php?tab=enviar', 'admin/relatorios.php?tipo=financeiro'])],
    ['Miguel Costa', 'miguel.costa', array_merge($paginas('treinador'), ["treinador/escalacao.php?game_id=$g3s", "treinador/presencas.php?treino_id=$t3", "treinador/jogos.php?estatisticas=$g3", "treinador/jogador-perfil.php?id=$p3"])],
    ['Nelson Guambe', 'nelson.jogador', $paginas('jogador')],
    ['Super Administrador', 'superadmin', array_merge($paginas('superadmin'), ['admin/dashboard.php?equipa=3'])],
];
$GLOBALS['LANG_COLLECT'] = []; $onde = []; $total = 0;
foreach ($perfis as [$nome, $user, $lista]) {
    $jar = $login($user);
    $_SESSION['user'] = $user ? ['name' => $nome, 'username' => $user] : [];
    foreach ($lista as $p) {
        $html = req("$base/$p", $jar);
        if (strlen($html) < 200 || strpos($html, '<html') === false) continue;
        $total++;
        $antes = array_keys($GLOBALS['LANG_COLLECT']);
        lang_translate_html($html);
        foreach (array_diff(array_keys($GLOBALS['LANG_COLLECT']), $antes) as $k) $onde[$k] = $p;
    }
}
// fora ficam os textos que são dados (nomes, títulos, notícias, mensagens…) guardados na base de dados
$dados = [];
$cols = $pdo->query("SELECT table_name t, column_name c FROM information_schema.columns WHERE table_schema = 'courtiq' AND data_type IN ('varchar','text','mediumtext','char')")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $x) {
    if (in_array($x['t'], ['system_config'], true) && $x['c'] === 'description') continue;
    foreach ($pdo->query("SELECT DISTINCT `{$x['c']}` FROM `{$x['t']}` WHERE `{$x['c']}` IS NOT NULL AND `{$x['c']}` <> ''")->fetchAll(PDO::FETCH_COLUMN) as $v) {
        $v = (string) $v;
        $dados[lang_key(htmlspecialchars($v, ENT_QUOTES, 'UTF-8'))] = 1;
        $dados[lang_key($v)] = 1;
        $dados[lang_key(mb_strimwidth($v, 0, 70, '…'))] = 1;
    }
}
$enums = ['active' => 1, 'inactive' => 1];
$fica = [];
$collect = $GLOBALS['LANG_COLLECT'];
foreach ($collect as $k => $orig) {
    if (isset($dados[$k]) || isset($enums[$k])) continue;
    if (preg_match('/^[\w.+-]+@[\w.-]+$/', $k) || preg_match('/^[a-z0-9._-]+\.(jogador|eagles|warriors|lions|sharks|oliveira|santos|costa|ferreira|silva)$/i', $k)) continue;
    if (!preg_match('/\p{L}{2}/u', preg_replace('/\{\w+\}/', '', $k))) continue;
    $fica[$k] = $onde[$k] ?? '';
}
ksort($fica, SORT_FLAG_CASE | SORT_STRING);
$out = $argv[1] ?? "$tmp/ciq_keys.txt";
file_put_contents($out, implode("\n", array_keys($fica)) . "\n");
file_put_contents($out . '.onde', implode("\n", array_map(fn($k, $p) => "$p\t$k", array_keys($fica), $fica)) . "\n");
$pal = array_sum(array_map(fn($k) => str_word_count($k), array_keys($fica)));
echo "$total páginas · " . count($collect) . ' textos distintos · ' . count($fica) . " por traduzir (≈$pal palavras) → $out\n";
foreach (glob("$tmp/ciq_rec_*.txt") as $f) @unlink($f);
