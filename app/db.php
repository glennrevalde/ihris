<?php
/**
 * Thin PDO wrapper. All queries use prepared statements.
 *
 * Multi-company: db() is the operational database of the ACTIVE COMPANY (resolved once per request
 * from the signed-in session by app/tenancy.php), so every module keeps calling q() / row() / rows() /
 * insert() / update() / tx() unchanged and always reaches its own company. master_db() (tenancy.php)
 * is the separate platform database (companies, codes, account mapping, Super Admins).
 */

/** PDO connection to one database on the configured MySQL server (pooled per database name). */
function db_pdo(string $dbName): PDO
{
    $pool = &$GLOBALS['__db_pool'];
    if (!isset($pool[$dbName])) {
        $c = $GLOBALS['config'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['db_host'], $c['db_port'], $dbName);
        $pool[$dbName] = db_new_pdo($dsn);
    }
    return $pool[$dbName];
}

/** Drop a pooled connection (after the database was dropped). */
function db_forget(string $dbName): void
{
    unset($GLOBALS['__db_pool'][$dbName]);
}

/** Connection to the MySQL server without a default database (CREATE DATABASE / DROP DATABASE). */
function db_server_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = $GLOBALS['config'];
        $pdo = db_new_pdo(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['db_host'], $c['db_port']));
    }
    return $pdo;
}

function db_new_pdo(string $dsn): PDO
{
    $c = $GLOBALS['config'];
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    return $pdo;
}

/** Name of the database the helpers below work on: a temporary override, else the active company. */
function active_db_name(): string
{
    if (!empty($GLOBALS['__db_override'])) {
        return (string)$GLOBALS['__db_override'];
    }
    return function_exists('tenant_db_name') ? tenant_db_name() : (string)$GLOBALS['config']['db_name'];
}

/** The operational database of the active company. */
function db(): PDO
{
    return db_pdo(active_db_name());
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** Key => value pairs from a two-column query. */
function pairs(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
}

function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int)db()->lastInsertId();
}

function update(string $table, array $data, string $where, array $whereParams = []): int
{
    $set = [];
    foreach (array_keys($data) as $c) {
        $set[] = '`' . $c . '` = ?';
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where;
    return q($sql, array_merge(array_values($data), $whereParams))->rowCount();
}

/** Run a callable inside a transaction (nested calls re-use the outer transaction). */
function tx(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Build "?, ?, ?" placeholder list for IN() clauses. */
function in_list(array $values): string
{
    return implode(',', array_fill(0, max(1, count($values)), '?'));
}
