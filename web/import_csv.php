<?php
// ================== НАСТРОЙКИ ==================
$db_file   = __DIR__ . '/irka.db';
$table_name = 'hours';
$csv_dir   = __DIR__ . '/csv_uploads';
// ===============================================

if (!is_dir($csv_dir)) mkdir($csv_dir, 0775, true);

echo "<h2>Импорт CSV в SQLite</h2>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    if ($file['error'] === 0) {
        $target = $csv_dir . '/' . basename($file['name']);
        move_uploaded_file($file['tmp_name'], $target);
        try {
            $pdo = new PDO("sqlite:" . $db_file);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Конвертация кодировки Windows-1251 → UTF-8
            $content = file_get_contents($target);
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
            file_put_contents($target, $content);

            $handle = fopen($target, 'r');

            // Заголовок идёт через запятую
            $header_line = fgets($handle);
            $header = str_getcsv($header_line, ',');
            $columns = array_map(function ($col) {
                return preg_replace('/[^a-zA-Z0-9_а-яА-Я]/u', '_', trim($col));
            }, $header);
            $col_count = count($columns);

            // Удаляем старую таблицу и создаём новую
            $pdo->exec("DROP TABLE IF EXISTS `$table_name`");

            $col_definitions = ["`id` INTEGER PRIMARY KEY AUTOINCREMENT"];
            foreach ($columns as $col) {
                $upper = strtoupper($col);
                if (strpos($upper, 'VAL') !== false) {
                    $type = 'INTEGER';       // VAL — целочисленный
                } elseif ($col === 'DATE_TIME') {
                    $type = 'TEXT';          // ISO-формат YYYY-MM-DD HH:MM:SS — TEXT в SQLite
                } else {
                    $type = 'TEXT';
                }
                $col_definitions[] = "`$col` $type";
            }

            $pdo->exec("CREATE TABLE `$table_name` (" . implode(", ", $col_definitions) . ")");
            echo "<p style='color:green'>Таблица <b>$table_name</b> создана ($col_count колонок)</p>";

            // Ищем индекс колонки DATE_TIME среди заголовков
            $dt_index = array_search('DATE_TIME', $columns);

            // === Импорт данных (разделитель ;) ===
            $count  = 0;
            $errors = 0;
            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                if (count($row) < $col_count) continue;

                // Убираем внешние кавычки и пробелы
                $clean_row = array_map(function ($val) {
                    return trim($val, " \t\n\r\0\x0B\"'");
                }, $row);

                // Конвертируем DATE_TIME: DD.MM.YYYY HH:MM:SS → YYYY-MM-DD HH:MM:SS
                if ($dt_index !== false && isset($clean_row[$dt_index])) {
                    $clean_row[$dt_index] = convertDateTime($clean_row[$dt_index]);
                }

                // Убираем точку в конце числовых VAL-значений ("10233799." → "10233799")
                foreach ($columns as $i => $col) {
                    if (strpos(strtoupper($col), 'VAL') !== false && isset($clean_row[$i])) {
                        $clean_row[$i] = rtrim($clean_row[$i], '.');
                    }
                }

                $placeholders = str_repeat('?,', $col_count - 1) . '?';
                $sql = "INSERT INTO `$table_name` ("
                    . implode(", ", array_map(fn($c) => "`$c`", $columns))
                    . ") VALUES ($placeholders)";

                try {
                    $pdo->prepare($sql)->execute(array_slice($clean_row, 0, $col_count));
                    $count++;
                } catch (Exception $e) {
                    $errors++;
                }
            }
            fclose($handle);

            echo "<p style='color:green'><b>✅ Успешно импортировано: $count строк</b></p>";
            if ($errors > 0) {
                echo "<p style='color:orange'>⚠️ Пропущено строк с ошибками: $errors</p>";
            }

        } catch (Exception $e) {
            echo "<p style='color:red'>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}

/**
 * Конвертирует дату из DD.MM.YYYY HH:MM:SS в YYYY-MM-DD HH:MM:SS
 * Если формат не распознан — возвращает строку без изменений
 */
function convertDateTime(string $value): string {
    // Ожидаем: DD.MM.YYYY HH:MM:SS  или  DD.MM.YYYY HH:MM
    if (preg_match(
        '/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}:\d{2}(?::\d{2})?)$/',
        $value,
        $m
    )) {
        return "{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}";
    }
    return $value; // вернём как есть, если формат неожиданный
}
?>
<form method="post" enctype="multipart/form-data">
    <p><input type="file" name="csv_file" accept=".csv"></p>
    <p><input type="submit" value="Загрузить и импортировать CSV"></p>
</form>
<hr>
<p><a href="view.php">Просмотреть таблицу →</a></p>
