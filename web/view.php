<?php
$db_file    = __DIR__ . '/irka.db';
$table_name = 'hours';

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // DATE_TIME теперь хранится в ISO-формате: YYYY-MM-DD HH:MM:SS
    // substr(DATE_TIME, 1, 7) даёт YYYY-MM — используем для группировки по месяцу

    // Список месяцев
    $months = $pdo->query("
        SELECT DISTINCT
            substr(DATE_TIME, 1, 7) as month,
            substr(DATE_TIME, 1, 4) || ' год ' || substr(DATE_TIME, 6, 2) || ' месяц' as month_name
        FROM `$table_name`
        ORDER BY month DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Выбор месяца (по умолчанию — последний доступный не позже предыдущего)
    if (empty($_GET['month']) && !empty($months)) {
        $prev_month   = date('Y-m', strtotime(date('Y-m') . ' -1 month'));
        $selected_month = $prev_month;
        foreach ($months as $m) {
            if ($m['month'] <= $prev_month) {
                $selected_month = $m['month'];
                break;
            }
        }
    } else {
        $selected_month = $_GET['month'] ?? ($months[0]['month'] ?? '');
    }

    // Вычисляем предыдущий месяц в формате YYYY-MM
    $prev_month_str = date('Y-m', strtotime($selected_month . '-01 -1 month'));

    // Получаем данные выбранного месяца + последние записи предыдущего месяца
    // LAG сортирует по DATE_TIME — теперь ISO-строка, сортировка корректная
    $sql = "
        SELECT
            DATE_TIME,
            ID_BAR,
            VAL,
            STATUS,
            TYPE_BAR,
            LAG(VAL) OVER (PARTITION BY ID_BAR ORDER BY DATE_TIME) AS prev_val,
            substr(DATE_TIME, 1, 7) AS row_month
        FROM `$table_name`
        WHERE substr(DATE_TIME, 1, 7) = ?
           OR substr(DATE_TIME, 1, 7) = ?
        ORDER BY DATE_TIME
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$selected_month, $prev_month_str]);
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Оставляем только выбранный месяц, дельта уже корректна благодаря строкам предыдущего
    $data = [];
    foreach ($raw_data as $row) {
        if ($row['row_month'] === $selected_month) {
            $row['Delta_VAL'] = ($row['prev_val'] !== null)
                ? $row['VAL'] - $row['prev_val']
                : null;
            // Форматируем дату для отображения: YYYY-MM-DD HH:MM:SS → DD.MM.YYYY HH:MM:SS
            $row['DATE_TIME_DISPLAY'] = isset($row['DATE_TIME'])
                ? substr($row['DATE_TIME'], 8, 2) . '.' .
                  substr($row['DATE_TIME'], 5, 2) . '.' .
                  substr($row['DATE_TIME'], 0, 4) . ' ' .
                  substr($row['DATE_TIME'], 11)
                : '';
            $data[] = $row;
        }
    }

    // Статистика
    $stats = $pdo->prepare("
        SELECT COUNT(*) as total_records,
               SUM(VAL)  as total_val,
               AVG(VAL)  as avg_val
        FROM `$table_name`
        WHERE substr(DATE_TIME, 1, 7) = ?
    ");
    $stats->execute([$selected_month]);
    $stat_row = $stats->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Ошибка базы: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Просмотр данных</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #999; padding: 8px; text-align: center; }
        th { background: #f4f4f4; }
        .delta-positive { color: green; font-weight: bold; }
        .delta-negative { color: red; font-weight: bold; }
        .stats { background: #e8f5e9; padding: 12px; border-radius: 6px; margin: 15px 0; }
    </style>
</head>
<body>
<h2>Просмотр таблицы hours</h2>

<form method="get">
    <label><strong>Месяц:</strong> </label>
    <select name="month" onchange="this.form.submit()">
        <?php foreach ($months as $m): ?>
            <option value="<?= $m['month'] ?>"
                <?= $m['month'] === $selected_month ? 'selected' : '' ?>>
                <?= htmlspecialchars($m['month_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<div class="stats">
    <strong>Статистика за <?= htmlspecialchars($selected_month) ?>:</strong><br>
    Записей: <b><?= number_format($stat_row['total_records'] ?? 0) ?></b> |
    Сумма VAL: <b><?= number_format($stat_row['total_delta_val'] ?? 0) ?></b>
</div>

<table>
    <tr>
        <th>Дата и время</th>
        <th>ID_BAR</th>
        <th>VAL</th>
        <th>Delta_VAL</th>
        <th>STATUS</th>
        <th>TYPE_BAR</th>
    </tr>
    <?php foreach ($data as $row):
        $delta       = $row['Delta_VAL'];
        $delta_class = ($delta > 0) ? 'delta-positive' : (($delta < 0) ? 'delta-negative' : '');
    ?>
    <tr>
        <td><?= htmlspecialchars($row['DATE_TIME_DISPLAY']) ?></td>
        <td><?= htmlspecialchars($row['ID_BAR']) ?></td>
        <td><b><?= number_format($row['VAL']) ?></b></td>
        <td class="<?= $delta_class ?>"><?= $delta !== null ? number_format($delta) : '—' ?></td>
        <td><?= htmlspecialchars($row['STATUS']) ?></td>
        <td><?= htmlspecialchars($row['TYPE_BAR']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<p><a href="\">← Вернуться</a></p>
</body>
</html>

root@contools:/var/www/html#
cat view.php
<?php
$db_file    = __DIR__ . '/irka.db';
$table_name = 'hours';

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // DATE_TIME теперь хранится в ISO-формате: YYYY-MM-DD HH:MM:SS
    // substr(DATE_TIME, 1, 7) даёт YYYY-MM — используем для группировки по месяцу

    // Список месяцев
    $months = $pdo->query("
        SELECT DISTINCT
            substr(DATE_TIME, 1, 7) as month,
            substr(DATE_TIME, 1, 4) || ' год ' || substr(DATE_TIME, 6, 2) || ' месяц' as month_name
        FROM `$table_name`
        ORDER BY month DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Выбор месяца (по умолчанию — последний доступный не позже предыдущего)
    if (empty($_GET['month']) && !empty($months)) {
        $prev_month   = date('Y-m', strtotime(date('Y-m') . ' -1 month'));
        $selected_month = $prev_month;
        foreach ($months as $m) {
            if ($m['month'] <= $prev_month) {
                $selected_month = $m['month'];
                break;
            }
        }
    } else {
        $selected_month = $_GET['month'] ?? ($months[0]['month'] ?? '');
    }

    // Вычисляем предыдущий месяц в формате YYYY-MM
    $prev_month_str = date('Y-m', strtotime($selected_month . '-01 -1 month'));

    // Получаем данные выбранного месяца + последние записи предыдущего месяца
    // LAG сортирует по DATE_TIME — теперь ISO-строка, сортировка корректная
    $sql = "
        SELECT
            DATE_TIME,
            ID_BAR,
            VAL,
            STATUS,
            TYPE_BAR,
            LAG(VAL) OVER (PARTITION BY ID_BAR ORDER BY DATE_TIME) AS prev_val,
            substr(DATE_TIME, 1, 7) AS row_month
        FROM `$table_name`
        WHERE substr(DATE_TIME, 1, 7) = ?
           OR substr(DATE_TIME, 1, 7) = ?
        ORDER BY DATE_TIME
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$selected_month, $prev_month_str]);
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Оставляем только выбранный месяц, дельта уже корректна благодаря строкам предыдущего
    $data = [];
    foreach ($raw_data as $row) {
        if ($row['row_month'] === $selected_month) {
            $row['Delta_VAL'] = ($row['prev_val'] !== null)
                ? $row['VAL'] - $row['prev_val']
                : null;
            // Форматируем дату для отображения: YYYY-MM-DD HH:MM:SS → DD.MM.YYYY HH:MM:SS
            $row['DATE_TIME_DISPLAY'] = isset($row['DATE_TIME'])
                ? substr($row['DATE_TIME'], 8, 2) . '.' .
                  substr($row['DATE_TIME'], 5, 2) . '.' .
                  substr($row['DATE_TIME'], 0, 4) . ' ' .
                  substr($row['DATE_TIME'], 11)
                : '';
            $data[] = $row;
        }
    $total_delta = null;
    foreach ($data as $row)
        if ($row['Delta_VAL'] !== null) {
            $total_delta += $row['Delta_VAL'];
        }
    }

    // Статистика
    $stats = $pdo->prepare("
        SELECT COUNT(*) as total_records,
               SUM(VAL)  as total_val,
               AVG(VAL)  as avg_val
        FROM `$table_name`
        WHERE substr(DATE_TIME, 1, 7) = ?
    ");
    $stats->execute([$selected_month]);
    $stat_row = $stats->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Ошибка базы: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Просмотр данных</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #999; padding: 8px; text-align: center; }
        th { background: #f4f4f4; }
        .delta-positive { color: green; font-weight: bold; }
        .delta-negative { color: red; font-weight: bold; }
        .stats { background: #e8f5e9; padding: 12px; border-radius: 6px; margin: 15px 0; }
    </style>
</head>
<body>
<h2>Просмотр таблицы hours</h2>

<form method="get">
    <label><strong>Месяц:</strong> </label>
    <select name="month" onchange="this.form.submit()">
        <?php foreach ($months as $m): ?>
            <option value="<?= $m['month'] ?>"
                <?= $m['month'] === $selected_month ? 'selected' : '' ?>>
                <?= htmlspecialchars($m['month_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
</form>

<div class="stats">
    <strong>Статистика за <?= htmlspecialchars($selected_month) ?>:</strong><br>
    Записей: <b><?= number_format($stat_row['total_records'] ?? 0) ?></b> |
    Сумма VAL: <b><?= $total_delta !== null ? number_format($total_delta, 0, '.', ' ') : '-' ?></b>
</div>

<table>
    <tr>
        <th>Дата и время</th>
        <th>ID_BAR</th>
        <th>VAL</th>
        <th>Delta_VAL</th>
        <th>STATUS</th>
        <th>TYPE_BAR</th>
    </tr>
    <?php foreach ($data as $row):
        $delta       = $row['Delta_VAL'];
        $delta_class = ($delta > 0) ? 'delta-positive' : (($delta < 0) ? 'delta-negative' : '');
    ?>
    <tr>
        <td><?= htmlspecialchars($row['DATE_TIME_DISPLAY']) ?></td>
        <td><?= htmlspecialchars($row['ID_BAR']) ?></td>
        <td><b><?= number_format($row['VAL']) ?></b></td>
        <td class="<?= $delta_class ?>"><?= $delta !== null ? number_format($delta) : '—' ?></td>
        <td><?= htmlspecialchars($row['STATUS']) ?></td>
        <td><?= htmlspecialchars($row['TYPE_BAR']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<p><a href="\">← Вернуться</a></p>
</body>
</html>