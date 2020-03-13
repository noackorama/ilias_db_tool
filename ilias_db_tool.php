#!/usr/bin/php
<?php
/**
 * ilias_db_tool.php
 *
 * konvertiert Ilias Datenbank von myisam zu innodb
 * ändert Tabellentyp Antelope zu Barracuda
 * ändert collation der Tabellen
 *
 * @author    André Noack <noack@data-quest.de>, Suchi & Berg GmbH <info@data-quest.de>
 * @license   GPL2 or any later version
 * @copyright 2020 André Noack
 */
isset($_SERVER['argv']) OR die();


$path_to_ini = @$_SERVER['argv'][1];
$cmd = @$_SERVER['argv'][2];
if (!$cmd || !$path_to_ini) {
    die('Benutzung: '. $_SERVER['argv'][0] .' <path to client.ini.php> [info|convert_collation|myisam2innodb|antelope2barracuda]' . chr(10));
}
if ($cmd == 'convert_collation') {
    $convert_collation = @$_SERVER['argv'][3];
    if (!$convert_collation) {
        die('collation muss angegeben werden' . chr(10));
    }
}
if (is_file($path_to_ini)) {
    $settings = parse_ini_file($path_to_ini, true);
    $db_settings = $settings['db'];
}
if (!in_array($db_settings['type'], ['mysql','innodb'])) {
    die('Finde keine passende Datenbank in ' . $path_to_ini . chr(10));
}

try {
    $pdo = new PDO(
        "mysql:host={$db_settings['host']};dbname={$db_settings['name']}" . ($db_settings['port'] ? ";port=" . $db_settings['port'] : ""),
        $db_settings['user'],
        $db_settings['pass'],
        array(
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET CHARACTER SET utf8',
        )
    );
} catch (PDOException $e) {
    die('Kann Datenbank nicht öffnen: ' . "mysql:host={$db_settings['host']};dbname={$db_settings['name']}" . ($db_settings['port'] ? ";port=" . $db_settings['port'] : "") . chr(10));
}

if ($cmd == 'info') {
    $rs = $pdo->query("SELECT table_collation, count(*) as c FROM information_schema.tables where table_schema='{$db_settings['name']}' GROUP BY table_collation");
    echo 'Datenbank: ' . $db_settings['name'] . chr(10);
    echo 'Gefundene collations:' . chr(10);
    foreach ($rs as $r) {
        echo $r['table_collation'] ."\t" . $r['c'] . "\n";
    }
    $rs = $pdo->query("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$db_settings['name']}'");
    $r = $rs->fetch();
    echo 'Datenbank default:' . chr(10);
    echo "CHARACTER SET " . $r['DEFAULT_CHARACTER_SET_NAME'] . " COLLATE " . $r['DEFAULT_COLLATION_NAME'] . "\n";
    $rs = $pdo->query("SELECT ENGINE,ROW_FORMAT, count(*) as c FROM information_schema.tables where table_schema='{$db_settings['name']}' group by ENGINE,ROW_FORMAT");
    echo 'Gefundene Tabellentypen:' . chr(10);
    foreach ($rs as $r) {
        echo $r['ENGINE'] ."\t" . $r['ROW_FORMAT'] ."\t" . $r['c'] . "\n";
    }
}

if ($cmd == 'convert_collation') {
    $pdo->exec("ALTER DATABASE `{$db_settings['name']}` DEFAULT CHARACTER SET utf8 COLLATE " . $convert_collation);
    echo 'Konvertiere Datenbank: ' . $db_settings['name'] . chr(10);
    $rs = $pdo->query("SELECT table_name, table_collation FROM information_schema.tables where table_schema='{$db_settings['name']}' AND table_collation <> '$convert_collation'");
    foreach ($rs->fetchAll() as $r) {
        echo 'Tabelle: ' . $r['table_name'] . ' ' . $r['table_collation'] . ' -> ' . $convert_collation . chr(10);
        $pdo->exec("ALTER TABLE `{$db_settings['name']}`.`{$r['table_name']}` CONVERT TO CHARACTER SET utf8 COLLATE " . $convert_collation);
    }
}

if ($cmd == 'myisam2innodb') {
    $rs = $pdo->query("SHOW VARIABLES LIKE 'innodb_file_format'");
    $file_format = strtolower($rs->fetch()['Value']);
    $rs = $pdo->query("SHOW VARIABLES LIKE 'innodb_file_per_table'");
    $file_per_table = strtolower($rs->fetch()['Value']);
    $rs = $pdo->query("SHOW VARIABLES LIKE 'innodb_large_prefix'");
    $large_prefix = strtolower($rs->fetch()['Value']);
    if ($file_per_table != 'on' || $file_format != 'barracuda' ||$large_prefix != 'on') {
        die('Bitte in my.cnf innodb_file_format=Barracuda,innodb_file_per_table=On,innodb_large_prefix=On setzen.' . chr(10));
    }
    echo 'Konvertiere Datenbank: ' . $db_settings['name'] . chr(10);
    $rs = $pdo->query("SELECT table_name FROM information_schema.tables where table_schema='{$db_settings['name']}' AND TABLE_TYPE='BASE TABLE' AND ENGINE = 'MyISAM'");
    foreach ($rs->fetchAll() as $r) {
        $local_start = microtime(true);
        echo 'Tabelle: ' . $r['table_name'] . ' MyISAM -> InnoDB' . chr(10);
        $pdo->exec("ALTER TABLE `{$db_settings['name']}`.`{$r['table_name']}` ENGINE=InnoDB ROW_FORMAT=DYNAMIC");
        $local_end = microtime(true);
        $local_duration = $local_end - $local_start;
        $human_local_duration = sprintf("%02d:%02d:%02d",
            ($local_duration / 60 / 60) % 24, ($local_duration / 60) % 60, $local_duration % 60);
        echo 'Tabelle: ' . $r['table_name'] . ' ' . $human_local_duration . chr(10);
    }
    $rs = $pdo->query("SHOW VARIABLES LIKE 'default_storage_engine'");
    $default_storage_engine = $rs->fetch()['Value'];
    if ($default_storage_engine != 'InnoDB') {
        echo 'default_storage_engine=InnoDB in my.cnf setzen!' . chr(10);
    }
    if ($db_settings['type'] != 'innodb') {
        echo 'type=innodb in client.ini.php setzen!' . chr(10);
    }
}

if ($cmd == 'antelope2barracuda') {
    $rs = $pdo->query("SHOW VARIABLES LIKE 'innodb_file_format'");
    $file_format = strtolower($rs->fetch()['Value']);
    $rs = $pdo->query("SHOW VARIABLES LIKE 'innodb_file_per_table'");
    $file_per_table = strtolower($rs->fetch()['Value']);
    $rs = $pdo->query("SHOW VARIABLES LIKE 'innodb_large_prefix'");
    $large_prefix = strtolower($rs->fetch()['Value']);
    if ($file_per_table != 'on' || $file_format != 'barracuda' ||$large_prefix != 'on') {
        die('Bitte in my.cnf innodb_file_format=Barracuda,innodb_file_per_table=On,innodb_large_prefix=On setzen.' . chr(10));
    }
    echo 'Konvertiere Datenbank: ' . $db_settings['name'] . chr(10);
    $rs = $pdo->query("SELECT table_name,row_format FROM information_schema.tables where table_schema='{$db_settings['name']}' AND TABLE_TYPE='BASE TABLE' AND ENGINE = 'InnoDB' AND ROW_FORMAT IN ('Compact', 'Redundant')");
    foreach ($rs->fetchAll() as $r) {
        $local_start = microtime(true);
        echo 'Tabelle: ' . $r['table_name'] . ' ' . $r['row_format'] . ' -> DYNAMIC (Barracuda)' . chr(10);
        $pdo->exec("ALTER TABLE `{$db_settings['name']}`.`{$r['table_name']}` ROW_FORMAT=DYNAMIC");
        $local_end = microtime(true);
        $local_duration = $local_end - $local_start;
        $human_local_duration = sprintf("%02d:%02d:%02d",
            ($local_duration / 60 / 60) % 24, ($local_duration / 60) % 60, $local_duration % 60);
        echo 'Tabelle: ' . $r['table_name'] . ' ' . $human_local_duration . chr(10);
    }
    $rs = $pdo->query("SHOW VARIABLES LIKE 'default_storage_engine'");
    $default_storage_engine = $rs->fetch()['Value'];
    if ($default_storage_engine != 'InnoDB') {
        echo 'default_storage_engine=InnoDB in my.cnf setzen!' . chr(10);
    }
    if ($db_settings['type'] != 'innodb') {
        echo 'type=innodb in client.ini.php setzen!' . chr(10);
    }
}
