<?php
/*
* BeBot - An Anarchy Online & Age of Conan Chat Automaton
* Copyright (C) 2004 Jonas Jax
* Copyright (C) 2005-2020 J-Soft and the BeBot development team.
*
* Developed by:
* - Alreadythere (RK2)
* - Blondengy (RK1)
* - Blueeagl3 (RK1)
* - Glarawyn (RK1)
* - Khalem (RK1)
* - Naturalistic (RK1)
* - Temar (RK1)
* - Bitnykk (RK5)
*
* See Credits file for all acknowledgements.
*
*  This program is free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; version 2 of the License only.
*
*  This program is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  You should have received a copy of the GNU General Public License
*  along with this program; if not, write to the Free Software
*  Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307
*  USA
*/
//From Main.php
/*
Detect if we are being run from a shell or if someone is stupid enough to try and run from a web browser.
*/
if ((!empty($_SERVER['HTTP_HOST'])) || (!empty($_SERVER['HTTP_USER_AGENT']))) {
    die("BeBot does not support being run from a web server and it is inherently dangerous to do so!\nFor your own good and for the safety of your account information, please do not attempt to run BeBot from a web server!");
}
// The minimum required PHP version to run.
if ((float)phpversion() < 5.4) {
    die("BeBot requires PHP version 5.4.0 or later to work.\n");
}
// The recommended PHP version to run.
if ((float)phpversion() < 8.0) {
    echo "BeBot recommends PHP version 8.0.0 or later to run.\n";
}

// Extensions must normally be enabled in php.ini. Runtime loading via dl()
// is unavailable or restricted on modern PHP versions and was previously
// attempted because the OS check used a non-empty string literal.
function bebot_require_extension($extension, $label = null)
{
    if (!extension_loaded($extension)) {
        $label = $label ?: $extension;
        die("The PHP extension '$label' is required to run BeBot. Enable it in php.ini.\n");
    }
}

/* SQL drivers are detected here, but the selected driver is validated only
 * after the database configuration has been loaded. */
function bebot_sql_driver_available($driver)
{
    $driver = strtolower(trim($driver));
    if ($driver === 'mariadb') $driver = 'mysql';
    // The current MySQL implementation is still mysqli-based. PDO MySQL is
    // reported separately until the PDO MySQL driver is wired into the factory.
    if ($driver === 'mysql') return extension_loaded('mysqli');
    if ($driver === 'sqlite') return extension_loaded('pdo_sqlite');
    return false;
}

function bebot_sql_driver_details()
{
    return array(
        'mysql' => array(
            'available' => extension_loaded('mysqli'),
            'extensions' => array('mysqli' => extension_loaded('mysqli'), 'pdo_mysql' => extension_loaded('pdo_mysql'))
        ),
        'sqlite' => array(
            'available' => extension_loaded('pdo_sqlite'),
            'extensions' => array('pdo_sqlite' => extension_loaded('pdo_sqlite'))
        )
    );
}

function bebot_sql_available_drivers()
{
    $available = array();
    foreach (bebot_sql_driver_details() as $driver => $details) {
        if ($details['available']) $available[] = $driver;
    }
    return $available;
}

function bebot_require_sql_driver($driver)
{
    $driver = strtolower(trim($driver));
    $lookup = $driver === 'mariadb' ? 'mysql' : $driver;
    if (!bebot_sql_driver_available($driver)) {
        $details = bebot_sql_driver_details();
        $missing = array();
        if (isset($details[$lookup])) {
            foreach ($details[$lookup]['extensions'] as $extension => $loaded) {
                if (!$loaded) $missing[] = $extension;
            }
        }
        die("The configured SQL driver '$driver' is unavailable. Missing PHP extension(s): " . implode(', ', $missing) . ".\n");
    }
}

// Informational only: the user has not selected a database backend yet.
foreach (bebot_sql_driver_details() as $bebot_sql_driver => $bebot_sql_details) {
    echo "SQL driver {$bebot_sql_driver}: " . ($bebot_sql_details['available'] ? 'available' : 'not available') . ".\n";
}

/*
Load extentions we need
*/
bebot_require_extension("sockets", "Sockets");
if (!extension_loaded('mysqli') && !extension_loaded('pdo_sqlite')) {
    die("No usable SQL driver is available. Enable mysqli for MySQL/MariaDB or pdo_sqlite for SQLite.\n");
}
bebot_require_extension("mbstring", "MbString");
bebot_require_extension("bcmath", "BCMath");
//From AOChat.php
// The minimum required PHP version to run.
if ((float)phpversion() < 5.2) {
    die("AOChat class needs PHP version >= 5.2.0 to work.\n");
}
// We need sockets to work
// Already checked above.
// For Authentication we need gmp or bcmath
// Already checked above.
// Check if we have curl available
if (!extension_loaded("curl")) {
    echo "Curl not available; optional HTTP integrations may be disabled.\n";
}
?>
