<?php
declare(strict_types=1);
session_start();

/**
 * Egyszerű naptár + bejegyzések (PHP + SQLite)
 * - Hónap nézet
 * - Nap kiválasztása
 * - Bejegyzés hozzáadás / szerkesztés / törlés
 * - SQLite tárolás (calendar.sqlite)
 */

// ---------- CSRF token ----------
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

// ---------- SQLite kapcsolat ----------
$dbFile = __DIR__ . '/calendar.sqlite';
$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------- Tábla létrehozása ----------
$pdo->exec("
CREATE TABLE IF NOT EXISTS entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entry_date TEXT NOT NULL,         -- YYYY-MM-DD
  title TEXT NOT NULL,
  body TEXT NOT NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_entries_date ON entries(entry_date);
");

// ---------- Segédfüggvények ----------
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function validDate(string $date): bool {
    // egyszerű ellenőrzés YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    return checkdate($m, $d, $y);
}

function clampLen(string $s, int $max): string {
    $s = trim($s);
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}

// ---------- Dátum/nézet paraméterek ----------
$today = new DateTimeImmutable('today');
$year  = isset($_GET['y']) ? (int)$_GET['y'] : (int)$today->format('Y');
$month = isset($_GET['m']) ? (int)$_GET['m'] : (int)$today->format('m');

if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }
if ($year < 1970) $year = 1970;
if ($year > 2100) $year = 2100;

$firstOfMonth = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
$daysInMonth  = (int)$firstOfMonth->format('t');
$monthStart   = $firstOfMonth->format('Y-m-01');
$monthEnd     = $firstOfMonth->format('Y-m-t');

// kiválasztott nap
$selectedDate = $_GET['date'] ?? $today->format('Y-m-d');
if (!validDate($selectedDate)) $selectedDate = $today->format('Y-m-d');

// ---------- POST műveletek (add/update/delete) ----------
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = $_POST['csrf'] ?? '';
    if (!hash_equals($csrf, (string)$postCsrf)) {
        http_response_code(400);
        die('Érvénytelen CSRF token.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $date  = (string)($_POST['entry_date'] ?? '');
        $title = clampLen((string)($_POST['title'] ?? ''), 120);
        $body  = clampLen((string)($_POST['body'] ?? ''), 5000);

        if (!validDate($date)) {
            $flash = "Hibás dátum.";
        } elseif ($title === '' || $body === '') {
            $flash = "A cím és a leírás nem lehet üres.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO entries(entry_date, title, body, created_at) VALUES(?,?,?,?)");
            $stmt->execute([$date, $title, $body, (new DateTimeImmutable())->format('c')]);
            $flash = "Bejegyzés mentve.";
            header("Location: ?y={$year}&m={$month}&date=" . urlencode($date));
            exit;
        }
    }

    if ($action === 'update') {
        $id    = (int)($_POST['id'] ?? 0);
        $date  = (string)($_POST['entry_date'] ?? '');
        $title = clampLen((string)($_POST['title'] ?? ''), 120);
        $body  = clampLen((string)($_POST['body'] ?? ''), 5000);

        if ($id <= 0) {
            $flash = "Hibás azonosító.";
        } elseif (!validDate($date)) {
            $flash = "Hibás dátum.";
        } elseif ($title === '' || $body === '') {
            $flash = "A cím és a leírás nem lehet üres.";
        } else {
            $stmt = $pdo->prepare("UPDATE entries SET entry_date=?, title=?, body=? WHERE id=?");
            $stmt->execute([$date, $title, $body, $id]);
            $flash = "Bejegyzés frissítve.";
            header("Location: ?y={$year}&m={$month}&date=" . urlencode($date));
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $date = (string)($_POST['entry_date'] ?? $selectedDate);
        if ($id <= 0) {
            $flash = "Hibás azonosító.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM entries WHERE id=?");
            $stmt->execute([$id]);
            $flash = "Bejegyzés törölve.";
            header("Location: ?y={$year}&m={$month}&date=" . urlencode($date));
            exit;
        }
    }
}

// ---------- Lekérdezések ----------
// Havi bejegyzések egyben (teljesítmény ok)
$stmt = $pdo->prepare("SELECT id, entry_date, title FROM entries
                       WHERE entry_date BETWEEN ? AND ?
                       ORDER BY entry_date ASC, id ASC");
$stmt->execute([$monthStart, $monthEnd]);
$monthEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// dátum -> bejegyzések listája
$entriesByDate = [];
foreach ($monthEntries as $e) {
    $entriesByDate[$e['entry_date']][] = $e;
}

// Kiválasztott nap részletes bejegyzései
$stmt = $pdo->prepare("SELECT id, entry_date, title, body, created_at FROM entries
                       WHERE entry_date = ?
                       ORDER BY id DESC");
$stmt->execute([$selectedDate]);
$selectedEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Szerkesztés esetén betöltjük az adott bejegyzést
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editEntry = null;
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT id, entry_date, title, body FROM entries WHERE id=?");
    $stmt->execute([$editId]);
    $editEntry = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($editEntry && $editEntry['entry_date'] !== $selectedDate) {
        // ha más napra mutat, váltsunk oda
        header("Location: ?y={$year}&m={$month}&date=" . urlencode($editEntry['entry_date']) . "&edit={$editId}");
        exit;
    }
}

// ---------- Naptár rács paraméterek (hétfővel kezd) ----------
// PHP: N = 1 (Mon) ... 7 (Sun)
$startWeekday = (int)$firstOfMonth->format('N'); // 1-7
$leadingBlanks = $startWeekday - 1;

// navigáció
$prev = $firstOfMonth->modify('-1 month');
$next = $firstOfMonth->modify('+1 month');

$hunMonthNames = [
    1=>'Január',2=>'Február',3=>'Március',4=>'Április',5=>'Május',6=>'Június',
    7=>'Július',8=>'Augusztus',9=>'Szeptember',10=>'Október',11=>'November',12=>'December'
];
$weekdays = ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];

?>
<!doctype html>
<html lang="hu">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Egyszerű Naptár</title>
<style>
    :root{
        --bg:#0b1220; --panel:#111b2e; --panel2:#0f1729;
        --text:#e8eefc; --muted:#9db0d1; --accent:#4aa3ff; --danger:#ff5c5c;
        --border:rgba(255,255,255,.08);
    }
    body{margin:0; font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial; background:var(--bg); color:var(--text);}
    a{color:inherit; text-decoration:none}
    .wrap{max-width:1100px; margin:0 auto; padding:18px;}
    .topbar{display:flex; gap:12px; align-items:center; justify-content:space-between; margin-bottom:12px;}
    .nav{display:flex; gap:8px; align-items:center;}
    .btn{display:inline-flex; align-items:center; gap:8px; padding:10px 12px; background:var(--panel); border:1px solid var(--border); border-radius:10px;}
    .btn:hover{border-color:rgba(74,163,255,.6)}
    .title{font-size:20px; font-weight:700}
    .grid{display:grid; grid-template-columns: 1.4fr 1fr; gap:14px;}
    @media(max-width:900px){ .grid{grid-template-columns:1fr;} }
    .card{background:var(--panel); border:1px solid var(--border); border-radius:14px; overflow:hidden;}
    .card h2{margin:0; padding:14px 14px 10px; font-size:16px; color:var(--muted); border-bottom:1px solid var(--border); background:var(--panel2);}
    .calendar{padding:12px;}
    .cal-head{display:grid; grid-template-columns:repeat(7,1fr); gap:8px; margin-bottom:8px;}
    .cal-head div{color:var(--muted); font-weight:600; text-align:center; font-size:13px;}
    .cal-body{display:grid; grid-template-columns:repeat(7,1fr); gap:8px;}
    .day{
        min-height:90px; padding:8px; border:1px solid var(--border); border-radius:12px;
        background:rgba(255,255,255,.03);
        display:flex; flex-direction:column; gap:6px;
    }
    .day:hover{border-color:rgba(74,163,255,.5)}
    .day .num{display:flex; justify-content:space-between; align-items:center; font-weight:700;}
    .pill{font-size:12px; padding:2px 8px; border-radius:999px; background:rgba(74,163,255,.18); color:var(--accent); border:1px solid rgba(74,163,255,.35);}
    .empty{opacity:.25; min-height:90px; border:1px dashed var(--border); border-radius:12px;}
    .today{outline:2px solid rgba(74,163,255,.55)}
    .selected{outline:2px solid rgba(255,255,255,.25)}
    .items{display:flex; flex-direction:column; gap:4px; font-size:12px; color:var(--muted)}
    .item{white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
    .panel{padding:12px 14px;}
    .flash{margin:0 0 10px; padding:10px 12px; border:1px solid var(--border); border-radius:12px; background:rgba(255,255,255,.03); color:var(--muted);}
    form{display:flex; flex-direction:column; gap:10px;}
    input[type="text"], textarea, input[type="date"]{
        width:100%; box-sizing:border-box;
        padding:10px 12px; border-radius:10px; border:1px solid var(--border);
        background:rgba(255,255,255,.04); color:var(--text);
    }
    textarea{min-height:120px; resize:vertical;}
    .row{display:flex; gap:10px; align-items:center; flex-wrap:wrap;}
    .actions{display:flex; gap:10px; flex-wrap:wrap;}
    .primary{background:rgba(74,163,255,.22); border-color:rgba(74,163,255,.45);}
    .danger{background:rgba(255,92,92,.14); border-color:rgba(255,92,92,.35);}
    .list{display:flex; flex-direction:column; gap:10px; margin-top:12px;}
    .entry{padding:12px; border:1px solid var(--border); border-radius:12px; background:rgba(255,255,255,.03);}
    .entry .meta{display:flex; justify-content:space-between; gap:10px; color:var(--muted); font-size:12px;}
    .entry h3{margin:6px 0 6px; font-size:15px;}
    .entry p{margin:0; color:var(--text); white-space:pre-wrap;}
    .small{font-size:12px; color:var(--muted)}
</style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <div class="nav">
            prev->format('Y') ?>&m=<?= (int)$prev->format('m') ?>&date=<?= h($selectedDate) ?>">◀ Előző</a>
            <div class="title"><?= h($hunMonthNames[$month] ?? (string)$month) ?> <?= (int)$year ?></div>
            next->format('Y') ?>&m=<?= (int)$next->format('m') ?>&date=<?= h($selectedDate) ?>">Következő ▶</a>
        </div>
        today->format('Y') ?>&m=<?= (int)$today->format('m') ?>&date=<?= h($today->format('Y-m-d')) ?>">Ma</a>
    </div>

    <div class="grid">
        <!-- Naptár -->
        <div class="card">
            <h2>Naptár</h2>
            <div class="calendar">
                <div class="cal-head">
                    <?php foreach ($weekdays as $wd): ?>
                        <div><?= h($wd) ?></div>
                    <?php endforeach; ?>
                </div>

                <div class="cal-body">
                    <?php for ($i=0; $i<$leadingBlanks; $i++): ?>
                        <div class="empty"></div>
                    <?php endfor; ?>

                    <?php for ($day=1; $day<=$daysInMonth; $day++):
                        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        $isToday = ($date === $today->format('Y-m-d'));
                        $isSelected = ($date === $selectedDate);
                        $count = isset($entriesByDate[$date]) ? count($entriesByDate[$date]) : 0;

                        $classes = 'day';
                        if ($isToday) $classes .= ' today';
                        if ($isSelected) $classes .= ' selected';
                        ?>
                        year ?>&m=<?= (int)$month ?>&date=<?= h($date) ?>">
                            <div class="num">
                                <span><?= (int)$day ?></span>
                                <?php if ($count > 0): ?>
                                    <span class="pill"><?= (int)$count ?> db</span>
                                <?php endif; ?>
                            </div>
                            <div class="items">
                                <?php
                                if ($count > 0) {
                                    $preview = array_slice($entriesByDate[$date], 0, 3);
                                    foreach ($preview as $e) {
                                        echo '<div class="item">• ' . h($e['title']) . '</div>';
                                    }
                                    if ($count > 3) echo '<div class="item">…</div>';
                                } else {
                                    echo '<div class="item small">Nincs bejegyzés</div>';
                                }
                                ?>
                            </div>
                        </a>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- Nap részletek + űrlap -->
        <div class="card">
            <h2>Bejegyzések: <?= h($selectedDate) ?></h2>
            <div class="panel">
                <?php if ($flash): ?>
                    <div class="flash"><?= h($flash) ?></div>
                <?php endif; ?>

                <?php if ($editEntry): ?>
                    <div class="flash">Szerkesztés módban vagy.</div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="<?= $editEntry ? 'update' : 'add' ?>">
                    <?php if ($editEntry): ?>
                        <input type="hidden" name="id" value="<?= (int)$editEntry['id'] ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div style="flex:1">
                            <label class="small">Dátum</label>
                            <input type="date" name="entry_date" value="<?= h($editEntry['entry_date'] ?? $selectedDate) ?>" required>
                        </div>
                        <div style="flex:2">
                            <label class="small">Cím</label>
                            <input type="text" name="title" placeholder="Pl.: Szerviz / Meeting / Emlékeztető..."
                                   value="<?= h($editEntry['title'] ?? '') ?>" required maxlength="120">
                        </div>
                    </div>

                    <div>
                        <label class="small">Leírás</label>
                        <textarea name="body" placeholder="Írd ide a részleteket..." required><?= h($editEntry['body'] ?? '') ?></textarea>
                    </div>

                    <div class="actions">
                        <button class="btn primary" type="submit">
                            <?= $editEntry ? 'Mentés (frissítés)' : 'Hozzáadás' ?>
                        </button>

                        <?php if ($editEntry): ?>
                            year ?>&m=<?= (int)$month ?>&date=<?= h($selectedDate) ?>">Mégse</a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="list">
                    <?php if (!$selectedEntries): ?>
                        <div class="entry"><div class="small">Ehhez a naphoz még nincs bejegyzés.</div></div>
                    <?php else: ?>
                        <?php foreach ($selectedEntries as $e): ?>
                            <div class="entry">
                                <div class="meta">
                                    <span>#<?= (int)$e['id'] ?> • <?= h($e['created_at']) ?></span>
                                    <span class="row" style="gap:8px;">
                                        year ?>&m=<?= (int)$month ?>&date=<?= h($selectedDate) ?>&edit=<?= (int)$e['id'] ?>">Szerkesztés</a>
                                        <form method="post" onsubmit="return confirm('Biztosan törlöd?');" style="margin:0;">
                                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
                                            <input type="hidden" name="entry_date" value="<?= h($selectedDate) ?>">
                                            <button class="btn danger" type="submit">Törlés</button>
                                        </form>
                                    </span>
                                </div>
                                <h3><?= h($e['title']) ?></h3>
                                <p><?= h($e['body']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>
</body>
</html>