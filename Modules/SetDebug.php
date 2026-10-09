<?php
/*
* SetDebug.php - A simple module to change the debug flag :
	0 minimal verbosity LOG/LOGIN/FATAL/SECURITY/STATUS 
	1 adds START/UPDATE/LOAD levels
	2 adds ERROR level
	3 adds WARNING/OUTGOING/INCOMING/RELAY level
	4 adds NOTICE/INFO level (default)
	5 adds DEBUG level
	6 log backtrace & maximal verbosity
*
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
$setdebug = new SetDebug($bot);
/*
The Class itself...
*/
class SetDebug extends BaseActiveModule
{

    function __construct(&$bot)
    {
        parent::__construct($bot, get_class($this));
        $this->register_command('tell', 'setdebug', 'OWNER');
        $this->register_command('tell', 'getdebug', 'OWNER');
		$this->register_module("setdebug");
		$this->bot->core("settings")
            ->create("SetDebug", "Verbosity", 4, "Sets the debugger verbosity mode from 0 to 6.", '0;1;2;3;4;5;6');
    }


    function command_handler($name, $msg, $origin)
    {
		if (preg_match("/^getdebug$/i", $msg)) {
			if ($this->bot->debug) return "Debugging is currently on";
			else return "Debugging is currently off";
		} else {
			$this->bot->debug = !$this->bot->debug;
			if ($this->bot->debug&&$this->bot->core("settings")->get("SetDebug", "Verbosity")==6) {
				$debug_file = rtrim($this->bot->log_path, "/\\")
					. "/aoc-debug-" . gmdate("Y-m-d-His") . ".bin";
				$this->bot->aoc->debug = @fopen($debug_file, "ab");
				if (!is_resource($this->bot->aoc->debug)) {
					$this->bot->debug = false;
					$this->bot->aoc->debug = null;
					return "Debugging could not be enabled: unable to open the AO packet log.";
				}
				return "Debugging output enabled!";
			}
			if (is_resource($this->bot->aoc->debug)) {
				fclose($this->bot->aoc->debug);
			}
			$this->bot->aoc->debug = null;
			return "Debugging output disabled!";
		}
    }
}

?>
