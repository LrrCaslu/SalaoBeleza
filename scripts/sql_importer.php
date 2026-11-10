<?php
declare(strict_types=1);
/** Divide arquivos SQL de schema/seed sem dividir strings nem depender do cliente mysql. */
function sqlStatements(string $sql): array {
    $sql = preg_replace('/^\s*--[^\r\n]*(?:\r?\n|$)/m', '', $sql) ?? $sql;
    $statements = [];
    $current = '';
    $quoted = null;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($quoted !== null) {
            $current .= $char;
            if ($char === '\\' && $i + 1 < $length) {
                $current .= $sql[++$i];
            } elseif ($char === $quoted) {
                if ($i + 1 < $length && $sql[$i + 1] === $quoted) $current .= $sql[++$i];
                else $quoted = null;
            }
            continue;
        }
        if ($char === "'" || $char === '"' || $char === '`') { $quoted = $char; $current .= $char; continue; }
        if ($char === ';') {
            if (trim($current) !== '') $statements[] = trim($current);
            $current = '';
            continue;
        }
        $current .= $char;
    }
    if (trim($current) !== '') $statements[] = trim($current);
    return $statements;
}
function importSqlFile(PDO $pdo, string $file): int {
    $source = file_get_contents($file);
    if ($source === false) throw new RuntimeException('Não foi possível ler ' . basename($file));
    $statements = sqlStatements($source);
    foreach ($statements as $number => $statement) {
        try { $pdo->exec($statement); }
        catch (PDOException $e) { throw new RuntimeException('Falha SQL em ' . basename($file) . ' (comando ' . ($number + 1) . '): ' . $e->getMessage(), 0, $e); }
    }
    return count($statements);
}
