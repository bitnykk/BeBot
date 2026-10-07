<?php
// Non-interactive configuration/bootstrap launcher for BeBot SQLite Express
chdir(__DIR__);
$args = $argv; array_shift($args);
$value = function ($i, $default) use ($args) { return isset($args[$i]) && $args[$i] !== '' ? $args[$i] : $default; };
$quote = function ($v) { return addslashes((string)$v); };
$hydrate = function ($text, $name, $value) use ($quote) {
    return preg_replace('/(\$' . preg_quote($name, '/') . '\s*=\s*)"[^"]*"\s*;/', '$1"' . $quote($value) . '";', $text, 1);
};
$die = false;
$username = preg_replace('/[^A-Za-z0-9]/', '', $value(0, ''));
if($username === '') {
	echo 'Invalid username.'.PHP_EOL;
	$die = true;
}
$password = $value(1, '');
if($password === '') {
	echo 'Empty password.'.PHP_EOL;
	$die = true;
}
$botname = preg_replace('/[^A-Za-z0-9]/', '', $value(2, ''));
if($botname === '') {
	echo 'Invalid botname.'.PHP_EOL;
	$die = true;
}
$dimension = preg_replace('/[^A-Za-z0-9]/', '', $value(3, ''));
if($dimension === '') {
	echo 'Invalid dimension.'.PHP_EOL;
	$die = true;
}
$owner = preg_replace('/[^A-Za-z0-9]/', '', $value(4, ''));
if($owner === '') {
	echo 'Invalid owner.'.PHP_EOL;
	$die = true;
}
$guildbot = strtolower($value(5, 'false'));
$guildtest = in_array($guildbot, array('1', 'true', 'yes', 'y', 'guild', 'guildbot'));
if($guildtest) {
	echo 'Guildbot profile is on.'.PHP_EOL;
	$guildbot = 'true';
} else {
	echo 'Raidbot profile is on.'.PHP_EOL;
	$guildbot = 'false';
}
if($guildtest) $id = $value(6, '00000001');
else $id = $value(6, '-1');
if($guildtest) echo 'Guild ID set as '.$id.'.'.PHP_EOL;
else echo 'Raid ID set as '.$id.'.'.PHP_EOL;
if ($die) die(' Correct params order is: username password botname dimension owner guildbot guildID|raidID'.PHP_EOL);

$config_name = ucfirst(strtolower($botname));
$main_file = 'Conf/' . $config_name . '.Bot.conf';
$db_file = 'Conf/' . $config_name . '.Mysql.conf';
$sqlite_file = 'Custom/Core/' . strtolower($config_name) . '-' . $dimension . '.sqlite';
if (!is_dir('Conf')) mkdir('Conf', 0777, true);
if (!is_dir('Custom/Core')) mkdir('Custom/Core', 0777, true);

if (!file_exists($main_file)) {
    $template = 'Conf/Bot.conf.dist';
    if (!file_exists($template)) die("Missing template: $template\n");
    $main = file_get_contents($template);
    foreach (array('ao_username' => $username, 'ao_password' => $password, 'bot_name' => $botname, 'dimension' => $dimension, 'owner' => $owner) as $key => $val) $main = $hydrate($main, $key, $val);
    $main = preg_replace('/(\$guildbot\s*=\s*)[^;]+;/', '${1}' . $guildbot . ';', $main, 1);
    if($guildtest) $main = preg_replace('/(\$guild_id\s*=\s*)[^;]+;/', '${1}' . (is_numeric($id) ? $id : '00000001') . ';', $main, 1);
	else $main = preg_replace('/(\$raid_id\s*=\s*)[^;]+;/', '${1}' . (is_numeric($id) ? $id : '-1') . ';', $main, 1);
    file_put_contents($main_file, $main);
    echo "Created $main_file from Conf/Bot.conf.dist".PHP_EOL;
} else echo "$main_file already exists; keeping it unchanged.".PHP_EOL;

if (!file_exists($db_file)) {
    $template = 'Conf/Mysql.conf.dist';
    if (!file_exists($template)) die("Missing template: $template");
    $db = $hydrate(file_get_contents($template), 'db_driver', 'sqlite');
    $db = preg_replace('/\$dpath\s*=\s*array\s*\([^;]*\);/s', '$dpath = array("driver" => "sqlite", "path" => "' . $quote($sqlite_file) . '");', $db, 1);
    $db = preg_replace('/\s*\/\*\s*\R\s*Database name.*?\R\s*\$server\s*=\s*"[^"]*";\s*\R/s', "\n\n", $db, 1);
    $db = preg_replace('/\/\/\$table_prefix\s*=\s*"[^"]*"\s*;/', '$table_prefix = "";', $db, 1);
    $db = preg_replace('/\/\/\$master_tablename\s*=\s*"[^"]*"\s*;/', '$master_tablename = "table_names";', $db, 1);
    file_put_contents($db_file, $db);
    echo "Created $db_file from Conf/Mysql.conf.dist".PHP_EOL;
} else echo "$db_file already exists; keeping it unchanged".PHP_EOL;

if (!file_exists($sqlite_file)) {
    $template = 'Custom/Core/SQLite-Express.sqlite';
    if (!file_exists($template)) die("Missing template: $template".PHP_EOL);
    if (!copy($template, $sqlite_file)) die("Unable to copy SQLite template.");
    echo "Created $sqlite_file from $template".PHP_EOL;
	try {
		$INIT = new PDO('sqlite:' . $sqlite_file);
		try {
			$INIT->query("DELETE FROM bots");
		} catch (PDOException $e) {
			echo "Truncate minor error: ".$e->getMessage().PHP_EOL;
		}
		try {
			$INIT->query("DELETE FROM whois");
		} catch (PDOException $e) {
			echo "Truncate minor error: ".$e->getMessage().PHP_EOL;
		}
		try {
			$INIT->query("UPDATE settings SET value='".$owner."' WHERE module='Recruit' AND setting IN ('LastOfficer','LastOfficer1','LastOfficer2')");
		} catch (PDOException $e) {
			echo "Update minor error: ".$e->getMessage().PHP_EOL;
		}
		try {
			$INIT->query("UPDATE settings SET value='#".ucfirst($botname)."' WHERE module='Irc' AND setting='Channel'");
		} catch (PDOException $e) {
			echo "Update minor error: ".$e->getMessage().PHP_EOL;
		}
		try {
			$INIT->query("UPDATE settings SET value='".ucfirst($botname)."' WHERE module='Irc' AND setting='Nick'");
		} catch (PDOException $e) {
			echo "Update minor error: ".$e->getMessage().PHP_EOL;
		}
		try {
			$INIT->query("UPDATE settings SET value='".ucfirst($botname)."' WHERE module='Online' AND setting='IRCbot'");
		} catch (PDOException $e) {
			echo "Update minor error: ".$e->getMessage().PHP_EOL;
		}		
		try {
			$INIT->query("UPDATE settings SET value='You are reinvited to ".ucfirst($botname)."!' WHERE module='Reinvite' AND setting='Notify'");
		} catch (PDOException $e) {
			echo "Update minor error: ".$e->getMessage().PHP_EOL;
		}		
		$INIT = null;
	} catch (PDOException $e) {
		echo "Connexion critical error: ".$e->getMessage().PHP_EOL;
	}
	echo "DB $sqlite_file is initialized and ready".PHP_EOL;
} else echo "$sqlite_file already exists; keeping it unchanged".PHP_EOL;

$argv[1] = ucfirst($botname);
include_once('Startbot.php');

?>
