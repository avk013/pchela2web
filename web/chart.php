<?php
$db_file    = __DIR__ . '/irka.db';
$table_name = 'hours';

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Список месяцев
    $months = $pdo->query("
        SELECT DISTINCT substr(DATE_TIME, 1, 7) AS month,
               substr(DATE_TIME, 1, 4) || ' год ' || substr(DATE_TIME, 6, 2) || ' месяц' AS month_name
        FROM `$table_name`
        ORDER BY month DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Список ID_BAR
    $bars = $pdo->query("
        SELECT DISTINCT ID_BAR FROM `$table_name` ORDER BY ID_BAR
    ")->fetchAll(PDO::FETCH_COLUMN);

    // Выбранные параметры
    $prev_month     = date('Y-m', strtotime(date('Y-m') . ' -1 month'));
    $selected_month = $_GET['month'] ?? $prev_month;
    $selected_bar   = $_GET['id_bar'] ?? ($bars[0] ?? '');
    $threshold      = isset($_GET['threshold']) && $_GET['threshold'] !== ''
                        ? (int)$_GET['threshold']
                        : 300;

    // Предыдущий месяц для корректного LAG на границе
    $prev_month_str = date('Y-m', strtotime($selected_month . '-01 -1 month'));

    // Данные с LAG
    $stmt = $pdo->prepare("
        SELECT
            DATE_TIME,
            VAL,
            LAG(VAL) OVER (PARTITION BY ID_BAR ORDER BY DATE_TIME) AS prev_val,
            substr(DATE_TIME, 1, 7) AS row_month
        FROM `$table_name`
        WHERE ID_BAR = ?
          AND (substr(DATE_TIME, 1, 7) = ? OR substr(DATE_TIME, 1, 7) = ?)
        ORDER BY DATE_TIME
    ");
    $stmt->execute([$selected_bar, $selected_month, $prev_month_str]);
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Фильтруем только выбранный месяц, считаем дельту
    $labels = [];
    $deltas = [];
    foreach ($raw as $row) {
        if ($row['row_month'] !== $selected_month) continue;
        if ($row['prev_val'] === null) continue; // первая запись без предыдущей — пропускаем
        $delta = $row['VAL'] - $row['prev_val'];
        // Отображаем дату как DD.MM HH:MM
        $labels[] = substr($row['DATE_TIME'], 8, 2) . '.' .
                    substr($row['DATE_TIME'], 5, 2) . ' ' .
                    substr($row['DATE_TIME'], 11, 5);
        $deltas[] = $delta;
    }

} catch (Exception $e) {
    die("Ошибка базы: " . htmlspecialchars($e->getMessage()));
}

$labels_json    = json_encode($labels, JSON_UNESCAPED_UNICODE);
$deltas_json    = json_encode($deltas);
$threshold_json = json_encode($threshold);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>График Delta_VAL</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/hammer.js/2.0.8/hammer.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-zoom/2.0.1/chartjs-plugin-zoom.min.js"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #fafafa; }
        h2   { margin-bottom: 10px; }
        .controls {
            display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;
            background: #fff; padding: 14px 18px; border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,.1); margin-bottom: 20px;
        }
        .controls label { font-size: 13px; color: #555; display: flex; flex-direction: column; gap: 4px; }
        .controls select, .controls input[type=number] {
            padding: 6px 10px; border: 1px solid #ccc; border-radius: 5px;
            font-size: 14px; min-width: 160px;
        }
        .controls button {
            padding: 7px 20px; background: #1a73e8; color: #fff;
            border: none; border-radius: 5px; font-size: 14px; cursor: pointer;
        }
        .controls button:hover { background: #1558b0; }
        .chart-wrap {
            background: #fff; border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,.1); padding: 20px;
            position: relative;
        }
        .zoom-hint { font-size: 12px; color: #888; margin-bottom: 8px; }
        .zoom-hint kbd {
            background: #f0f0f0; border: 1px solid #ccc;
            border-radius: 3px; padding: 1px 5px; font-size: 11px;
        }
        #resetZoom {
            position: absolute; top: 14px; right: 14px;
            padding: 5px 14px; font-size: 12px; background: #f5f5f5;
            border: 1px solid #ccc; border-radius: 5px; cursor: pointer;
            display: none;
        }
        #resetZoom:hover { background: #e0e0e0; }
        .nav { margin-top: 16px; font-size: 13px; }
        .nav a { color: #1a73e8; text-decoration: none; }
    </style>
</head>
<body>
<h2>График Delta_VAL</h2>

<form method="get" class="controls">
    <label>Месяц:
        <select name="month">
            <?php foreach ($months as $m): ?>
                <option value="<?= $m['month'] ?>"
                    <?= $m['month'] === $selected_month ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['month_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>ID_BAR:
        <select name="id_bar">
            <?php foreach ($bars as $bar): ?>
                <option value="<?= htmlspecialchars($bar) ?>"
                    <?= $bar == $selected_bar ? 'selected' : '' ?>>
                    <?= htmlspecialchars($bar) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>Порог (макс. Delta):
        <input type="number" name="threshold"
               value="<?= $threshold !== null ? htmlspecialchars($threshold) : '300' ?>"
               >
    </label>

    <button type="submit">Показать</button>
</form>

<div class="chart-wrap">
    <div class="zoom-hint">
        🔍 Приближение: <kbd>колесо мыши</kbd> или <kbd>Ctrl</kbd> + выделение &nbsp;|&nbsp;
        Перемещение: <kbd>зажать мышь</kbd> и тянуть
    </div>
    <button id="resetZoom" onclick="resetZoom()">✕ Сбросить zoom</button>
    <canvas id="deltaChart"></canvas>
</div>

<div class="nav">
    <a href="view.php">← Просмотр таблицы</a>
</div>

<script>
const labels    = <?= $labels_json ?>;
const deltas    = <?= $deltas_json ?>;
const threshold = <?= $threshold_json ?>;

// Цвет каждой точки: красный если превышает порог, иначе синий
const pointColors = deltas.map(v =>
    (threshold !== null && v > threshold) ? '#e53935' : '#1a73e8'
);
const barColors = deltas.map(v =>
    (threshold !== null && v > threshold)
        ? 'rgba(229,57,53,0.6)'
        : 'rgba(26,115,232,0.5)'
);

const datasets = [{
    label: 'Delta_VAL (ID_BAR <?= htmlspecialchars($selected_bar) ?>)',
    data: deltas,
    type: 'bar',
    backgroundColor: barColors,
    borderColor: barColors,
    borderWidth: 1,
    order: 2
}, {
    label: 'Delta_VAL (линия)',
    data: deltas,
    type: 'line',
    borderColor: '#1a73e8',
    backgroundColor: 'transparent',
    pointBackgroundColor: pointColors,
    pointRadius: labels.length > 200 ? 0 : 3,
    borderWidth: 1.5,
    tension: 0.3,
    order: 1
}];

// Добавляем линию порога если задана
if (threshold !== null) {
    datasets.push({
        label: 'Порог (' + threshold + ')',
        data: new Array(labels.length).fill(threshold),
        type: 'line',
        borderColor: '#e53935',
        borderWidth: 2,
        borderDash: [6, 4],
        pointRadius: 0,
        backgroundColor: 'transparent',
        order: 0
    });
}

new Chart(document.getElementById('deltaChart'), {
    data: { labels, datasets },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top' },
            tooltip: {
                callbacks: {
                    label: ctx => ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString('ru-RU')
                }
            },
            zoom: {
                pan: {
                    enabled: true,
                    mode: 'x',
                    onPan() { document.getElementById('resetZoom').style.display = 'block'; }
                },
                zoom: {
                    wheel: { enabled: true },
                    pinch: { enabled: true },
                    drag: {
                        enabled: true,
                        modifierKey: 'ctrl',
                        backgroundColor: 'rgba(26,115,232,0.15)',
                        borderColor: '#1a73e8',
                        borderWidth: 1
                    },
                    mode: 'x',
                    onZoom() { document.getElementById('resetZoom').style.display = 'block'; }
                }
            }
        },
        scales: {
            x: {
                ticks: {
                    maxTicksLimit: 30,
                    maxRotation: 60,
                    font: { size: 11 }
                }
            },
            y: {
                title: { display: true, text: 'Delta_VAL' },
                ticks: {
                    callback: v => v.toLocaleString('ru-RU')
                }
            }
        }
    }
});

function resetZoom() {
    Chart.getChart('deltaChart').resetZoom();
    document.getElementById('resetZoom').style.display = 'none';
}
</script>
<p><a href="\">← Вернуться</a></p>

</body>
</html>