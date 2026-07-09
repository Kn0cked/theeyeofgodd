<?php
/**
 * Tek dosyalık Steam Oyun Paneli
 * ------------------------------------------------------------
 * Kullanım:
 * 1) Bu dosyayı web sitene yükle: steam_panel.php
 * 2) Aşağıdaki STEAM_API_KEY ve STEAM_ID64 alanlarını doldur.
 * 3) Tarayıcıdan aç: https://site-adresin.com/steam_panel.php
 *
 * Not: Steam profilindeki oyunlar/gametime görünmüyorsa profil gizlilik ayarlarında
 * "Game details / Oyun detayları" herkese açık olmalıdır.
 */

const STEAM_API_KEY = 'BURAYA_STEAM_API_KEY';
const STEAM_ID64    = 'BURAYA_STEAM_ID64';
const CACHE_SECONDS = 300; // API'yi yormamak için 5 dakika cache

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function fetchJson(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header' => "User-Agent: SteamPanel/1.0\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'Steam API isteği başarısız oldu.'];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'Steam API geçersiz JSON döndürdü.'];
    }

    return ['ok' => true, 'data' => $json];
}

function getOwnedGames(): array
{
    if (STEAM_API_KEY === 'BURAYA_STEAM_API_KEY' || STEAM_ID64 === 'BURAYA_STEAM_ID64') {
        return [
            'ok' => false,
            'error' => 'Lütfen dosyanın üstündeki STEAM_API_KEY ve STEAM_ID64 alanlarını doldur.',
            'games' => [],
        ];
    }

    $cacheFile = sys_get_temp_dir() . '/steam_games_' . md5(STEAM_ID64) . '.json';

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < CACHE_SECONDS) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return ['ok' => true, 'games' => $cached, 'cached' => true];
        }
    }

    $query = http_build_query([
        'key' => STEAM_API_KEY,
        'steamid' => STEAM_ID64,
        'include_appinfo' => 'true',
        'include_played_free_games' => 'true',
        'format' => 'json',
    ]);

    $url = 'https://api.steampowered.com/IPlayerService/GetOwnedGames/v1/?' . $query;
    $result = fetchJson($url);

    if (!$result['ok']) {
        return ['ok' => false, 'error' => $result['error'], 'games' => []];
    }

    $games = $result['data']['response']['games'] ?? [];
    if (!is_array($games)) {
        $games = [];
    }

    usort($games, function ($a, $b) {
        return ($b['playtime_forever'] ?? 0) <=> ($a['playtime_forever'] ?? 0);
    });

    @file_put_contents($cacheFile, json_encode($games, JSON_UNESCAPED_UNICODE));

    return ['ok' => true, 'games' => $games, 'cached' => false];
}

$data = getOwnedGames();
$games = $data['games'] ?? [];
$totalMinutes = array_sum(array_map(fn($game) => (int)($game['playtime_forever'] ?? 0), $games));
$totalHours = round($totalMinutes / 60, 1);
$gameCount = count($games);
$search = trim($_GET['q'] ?? '');

if ($search !== '') {
    $games = array_values(array_filter($games, function ($game) use ($search) {
        return stripos($game['name'] ?? '', $search) !== false;
    }));
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Steam Oyun Paneli</title>
    <style>
        :root {
            --bg: #0b1220;
            --card: #111b2e;
            --card2: #16243b;
            --text: #e8eefc;
            --muted: #9fb0cc;
            --line: rgba(255,255,255,.08);
            --accent: #66c0f4;
            --danger: #ff7070;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: radial-gradient(circle at top, #18345a 0, var(--bg) 42%);
            color: var(--text);
            min-height: 100vh;
        }
        .wrap { max-width: 1180px; margin: 0 auto; padding: 32px 18px; }
        .top {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            align-items: center;
            margin-bottom: 22px;
        }
        h1 { margin: 0 0 8px; font-size: 34px; }
        .muted { color: var(--muted); }
        .stats { display: flex; gap: 12px; flex-wrap: wrap; }
        .stat {
            background: rgba(255,255,255,.06);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px 18px;
            min-width: 140px;
        }
        .stat b { display: block; font-size: 24px; }
        .panel {
            background: rgba(17,27,46,.82);
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 18px;
            box-shadow: 0 24px 80px rgba(0,0,0,.25);
        }
        form { display: flex; gap: 10px; margin-bottom: 18px; }
        input {
            width: 100%;
            border: 1px solid var(--line);
            background: #0d1628;
            color: var(--text);
            border-radius: 12px;
            padding: 13px 14px;
            outline: none;
        }
        button, .btn {
            border: 0;
            background: var(--accent);
            color: #06111f;
            border-radius: 12px;
            padding: 12px 15px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 14px;
        }
        .game {
            background: linear-gradient(180deg, var(--card2), var(--card));
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px;
        }
        .game-head { display: flex; gap: 12px; align-items: center; min-height: 48px; }
        .icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: #0d1628;
            object-fit: cover;
            flex: 0 0 auto;
        }
        .name { font-weight: 700; line-height: 1.25; }
        .hours { margin: 14px 0; color: var(--muted); }
        .hours strong { color: var(--text); font-size: 22px; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .secondary { background: rgba(255,255,255,.09); color: var(--text); }
        .error {
            border: 1px solid rgba(255,112,112,.35);
            background: rgba(255,112,112,.12);
            color: #ffd6d6;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 18px;
        }
        .empty { padding: 30px; text-align: center; color: var(--muted); }
        @media (max-width: 720px) {
            .top { display: block; }
            h1 { font-size: 28px; }
            form { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>Steam Oyun Paneli</h1>
            <div class="muted">Oyunlarını listele, saatlerini görüntüle ve Steam üzerinden başlat.</div>
        </div>
        <div class="stats">
            <div class="stat"><span class="muted">Toplam oyun</span><b><?= h((string)$gameCount) ?></b></div>
            <div class="stat"><span class="muted">Toplam saat</span><b><?= h((string)$totalHours) ?></b></div>
        </div>
    </div>

    <div class="panel">
        <?php if (!($data['ok'] ?? false)): ?>
            <div class="error"><?= h($data['error'] ?? 'Bilinmeyen hata oluştu.') ?></div>
        <?php endif; ?>

        <form method="get">
            <input name="q" value="<?= h($search) ?>" placeholder="Oyun ara... örn: Counter-Strike">
            <button type="submit">Ara</button>
            <?php if ($search !== ''): ?>
                <a class="btn secondary" href="<?= h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">Temizle</a>
            <?php endif; ?>
        </form>

        <?php if (empty($games)): ?>
            <div class="empty">Oyun bulunamadı. Profil gizliliğini veya API bilgilerini kontrol et.</div>
        <?php else: ?>
            <div class="grid">
                <?php foreach ($games as $game):
                    $appid = (int)($game['appid'] ?? 0);
                    $name = (string)($game['name'] ?? 'Bilinmeyen oyun');
                    $hours = round(((int)($game['playtime_forever'] ?? 0)) / 60, 1);
                    $iconHash = (string)($game['img_icon_url'] ?? '');
                    $icon = $iconHash !== ''
                        ? "https://media.steampowered.com/steamcommunity/public/images/apps/{$appid}/{$iconHash}.jpg"
                        : '';
                    $storeUrl = "https://store.steampowered.com/app/{$appid}";
                    $runUrl = "steam://run/{$appid}";
                ?>
                    <div class="game">
                        <div class="game-head">
                            <?php if ($icon): ?>
                                <img class="icon" src="<?= h($icon) ?>" alt="">
                            <?php else: ?>
                                <div class="icon"></div>
                            <?php endif; ?>
                            <div class="name"><?= h($name) ?></div>
                        </div>
                        <div class="hours"><strong><?= h((string)$hours) ?></strong> saat oynandı</div>
                        <div class="actions">
                            <a class="btn" href="<?= h($runUrl) ?>">Oyunu Başlat</a>
                            <a class="btn secondary" href="<?= h($storeUrl) ?>" target="_blank" rel="noopener">Mağaza</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
