<?php
/*
* Tara.php - Handle Tara timer/alert (plus season's event world bosses)
* With linkage to Boss/Buff Timers API courtesy of The Nadybot Team
* BeBot - An Anarchy Online & Age of Conan Chat Automaton
* Copyright (C) 2004 Jonas Jax
* Copyright (C) 2005-2010 Thomas Juberg, ShadowRealm Creations and the BeBot development team.
*
* Developed by:
* - Alreadythere (RK2)
* - Blondengy (RK1)
* - Blueeagl3 (RK1)
* - Glarawyn (RK1)
* - Khalem (RK1)
* - Naturalistic (RK1)
* - Temar (RK1)
* - Bitnykk (RK2) for Gauntlet/WorldBosses additions
* See Credits file for all aknowledgements.
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
*
* File last changed at $LastChangedDate: 2008-11-30 23:09:06 +0100 (Sun, 30 Nov 2008) $
* Revision: $Id: TowerAttack.php 1833 2008-11-30 22:09:06Z alreadythere $
*/

$taraviza = new Taraviza($bot);

/*
The Class itself...
*/
class Taraviza extends BaseActiveModule
{
	var $tcycle, $vcycle, $apiver, $wlist;
	function __construct(&$bot)
	{
		parent::__construct($bot, get_class($this));
		$this->register_module("taraviza");
		$this -> bot -> db -> query("CREATE TABLE IF NOT EXISTS tara (time int NOT NULL default '0')");
		$this -> bot -> db -> query("CREATE TABLE IF NOT EXISTS viza (time int NOT NULL default '0')");		
		$this -> register_command("all", "tara", "GUEST");
		$this -> register_command("all", "gauntlet", "GUEST");
		$this -> register_command("all", "settara", "GUEST");
		$this -> register_command("all", "setviza", "GUEST");
		$this -> register_command("all", "gauntbuff", "GUEST");
		$this -> register_command("all", "world", "GUEST");
		$this -> register_alias("world", "worldbosses");
		$this -> register_alias("world", "worldboss");
		$this -> register_alias("world", "wboss");
		$this -> register_alias("world", "wbosses");
		$this -> register_alias("world", "wb");		
		$this -> register_alias("gauntbuff", "gaunbuff");
		$this -> register_alias("gauntbuff", "gaubuff");
		$this -> register_alias("gauntbuff", "gbuff");		
		$this -> register_alias("gauntlet", "gaunt");
		$this -> register_alias("gauntlet", "vizaresh");
		$this -> register_alias("gauntlet", "viza");
		$this -> register_alias('tara', 'timers');		
		$this -> register_alias("tara", "spawntime");
		$this -> register_alias("tara", "spawn");
		$this -> register_alias("tara", "pop");
		$this -> register_alias("tara", "poptime");
		$this -> help['description'] = 'Allows to have a specific Tarasque/Gauntlet timers & warnings ; both auto-update by API every 6 hours but are still manually overwritable. Also added seasonal !world bosses that are fully automated & could pop more randomly hence no alert/manual option.';
		$this -> help['command']['tara']="Shows time left to next Tarasque.";
		$this -> help['command']['viza']="Shows time left to next Gauntlet.";
		$this -> help['command']['gbuff']="Shows current state for Gauntlet's Buff.";
		$this -> help['command']['settara'] = "Sets the timer from now. Add -/+ number for minute adjusting.";
		$this -> help['command']['setviza'] = "Sets the timer from now. Add -/+ number for minute adjusting.";
		$this -> help['command']['world'] = "Shows all bosses including those available at current season (could be anniversary, halloween, winter, other, etc).";
		
		$this->bot->core("settings")->create('Taraviza', 'TaraAlert', 'None', 'Towards which channel(s) should Tarasque pop alert be sent to (None = disable) ?', 'Both;Guildchat;Private;None');
		$this->bot->core("settings")->create('Taraviza', 'VizaAlert', 'None', 'Towards which channel(s) should Gauntlet start alert be sent to (None = disable) ?', 'Both;Guildchat;Private;None');
		$this->bot->core("settings")->create('Taraviza', 'PopAlertTime', 59, 'How long in minutes before pop/start should non-seasonal alerts be sent to selected channel(s)?', '14;23;32;41;50;59');
		$this->bot->core("settings")->create('Taraviza', 'BuffAlert', 'None', 'Towards which channel(s) should Buff 45min alerts be sent to (None = disable) ?', 'Both;Guildchat;Private;None');
        $this->bot->core("settings")
            ->create("Taraviza", "AlertDisc", false, "Do we alert Discord of non-seasonal bosses spawns ?");
        $this->bot->core("settings")
            ->create("Taraviza", "DiscChanId", "", "What Discord ChannelId in case we separate non-seasonal bosses spawns from main Discord channel (leave empty for all in main channel) ?");
        $this->bot->core("settings")
            ->create("Taraviza", "DiscTag", "", "Should we add a Discord Tag (e.g. @here or @everyone) to non-seasonal bosses spams for notifying Discord users (leave empty for no notification) ?");
        $this->bot->core("settings")
            ->create("Taraviza", "AlertIrc", false, "Do we alert Irc of non-seasonal bosses spawns ?");		
        $this->bot->core("settings")
            ->create("Taraviza", "ApiUrl", "https://timers.aobots.org/api/", "What's the Boss/Buff API URL we should use to auto-update (Nadybot's by default, leave empty to disable automation) ?");			
		$this->register_event("cron", "1min");
		$this->register_event("cron", "5min");
		$this->register_event("cron", "44min");
		$this->tcycle=34200; // 9H30 tara cycle (30=immortality)
		$this->vcycle=61620; // 17H07 viza cycle (7=immortality)
		$this->apiver='v1.1';
		$this->wlist= array();
	}

	function command_handler($name, $msg, $channel)
	{
		if (preg_match("/^tara$/i", $msg))
			return $this -> show_tara("user");
		else if (preg_match("/^gauntlet$/i", $msg))
			return $this -> show_viza("user");
		else if (preg_match("/^gauntbuff$/i", $msg))
			return $this -> show_buff();		
		else if (preg_match("/^settara$/i", $msg))
			return $this -> set_now($name, $channel);
		else if (preg_match("/^settara [+]([0-9]+)$/i", $msg, $info))
			return $this -> set_tara($name, "add", $info, $channel);
		else if (preg_match("/^settara [-]([0-9]+)$/i", $msg, $info))
			return $this -> set_tara($name, "rem", $info, $channel);
		else if (preg_match("/^setviza$/i", $msg))
			return $this -> set_now2($name, $channel);
		else if (preg_match("/^setviza [+]([0-9]+)$/i", $msg, $info))
			return $this -> set_viza($name, "add", $info, $channel);
		else if (preg_match("/^setviza [-]([0-9]+)$/i", $msg, $info))
			return $this -> set_viza($name, "rem", $info, $channel);
		else if (preg_match("/^world$/i", $msg)) {
			return $this -> show_world();
		}
	}

    function cron($cron)
    {
		if ($cron == 60) {
			$ta = $this->bot->core("settings")->get("Taraviza", "TaraAlert");
			if($ta!="None") {
				$pot = $this->bot->core("settings")->get("Taraviza", "PopAlertTime");
				$tt = $this->show_tara("cron");
				if($pot==$tt) {
					$text = "Alert : Tarasque should pop in ".$tt." minutes !";
					if($ta=='Both'||$ta=='Guildchat') {
						$this->bot->send_gc($text);
					}
					if($ta=='Both'||$ta=='Private') {
						$this->bot->send_pgroup($text);
					}
					if ($this->bot->exists_module("discord")&&$this->bot->core("settings")->get("Taraviza", "AlertDisc")) {
						if($this->bot->core("settings")->get("Taraviza", "DiscChanId")) { $chan = $this->bot->core("settings")->get("Taraviza", "DiscChanId"); } else { $chan = ""; }
						if($this->bot->core("settings")->get("Taraviza", "DiscTag")) { $dctag = $this->bot->core("settings")->get("Taraviza", "DiscTag")." "; } else { $dctag = ""; }
						$this->bot->core("discord")->disc_alert($dctag.$text, $chan);
					}
					if ($this->bot->exists_module("irc")&&$this->bot->core("settings")->get("Taraviza", "AlertIrc")) {
						$this->bot->core("irc")->send_irc("", "", $text);
					}				
				}
			}
			$va = $this->bot->core("settings")->get("Taraviza", "VizaAlert");
			if($va!="None") {
				$pot = $this->bot->core("settings")->get("Taraviza", "PopAlertTime");
				$vt = $this->show_viza("cron");
				if($pot==$vt) {
					$text = "Alert : Gauntlet should start in ".$vt." minutes !";
					if($va=='Both'||$va=='Guildchat') {
						$this->bot->send_gc($text);
					}
					if($va=='Both'||$va=='Private') {
						$this->bot->send_pgroup($text);
					}
					if ($this->bot->exists_module("discord")&&$this->bot->core("settings")->get("Taraviza", "AlertDisc")) {
						if($this->bot->core("settings")->get("Taraviza", "DiscChanId")) { $chan = $this->bot->core("settings")->get("Taraviza", "DiscChanId"); } else { $chan = ""; }
						if($this->bot->core("settings")->get("Taraviza", "DiscTag")) { $dctag = $this->bot->core("settings")->get("Taraviza", "DiscTag")." "; } else { $dctag = ""; }
						$this->bot->core("discord")->disc_alert($dctag.$text, $chan);
					}
					if ($this->bot->exists_module("irc")&&$this->bot->core("settings")->get("Taraviza", "AlertIrc")) {
						$this->bot->core("irc")->send_irc("", "", $text);
					}				
				}
			}		
		} elseif ($cron == 300) {
			if($this->bot->core("settings")->get("Taraviza", "ApiUrl")!='') {				
				$url = $this->bot->core("settings")->get("Taraviza", "ApiUrl")."/".$this->apiver."/"."bosses";
				$content = $this->bot->core("tools")->get_site($url);	
				if (!($content instanceof BotError)) {
					if (strpos($content, '"name":') !== false) {							
						$timers = json_decode($content);
						$this->wlist= array();							
						foreach($timers as $timer) {
							if(!isset($timer->dimension)) $timer->dimension=5; // RK5 surveyed by default							
							if($timer->name=='tara'&&$this->bot->dimension==$timer->dimension&&$timer->last_spawn>0) {							
								$this -> bot -> db -> query("TRUNCATE TABLE tara");
								$this -> bot -> db -> query("INSERT INTO tara (time) VALUES ('".$timer->last_spawn."')");
							}
							if($timer->name=='vizaresh'&&$this->bot->dimension==$timer->dimension&&$timer->last_spawn>0) {
								$this -> bot -> db -> query("TRUNCATE TABLE viza");
								$this -> bot -> db -> query("INSERT INTO viza (time) VALUES ('".$timer->last_spawn."')");
							}
							$this -> wlist [$timer->dimension] [$timer->name] = $timer->last_spawn;
						}											
					}
				}
			}
		} elseif ($cron == 2640) {
			$ba = $this->bot->core("settings")->get("Taraviza", "BuffAlert");
			if($ba!="None") {
				$text = "GauntBuff:".$this->show_buff();
				if (strpos($text, 'Currently') !== false) {
					if($ba=='Both'||$ba=='Guildchat') {
						$this->bot->send_gc($text);
					}
					if($ba=='Both'||$ba=='Private') {
						$this->bot->send_pgroup($text);
					}
					if ($this->bot->exists_module("discord")&&$this->bot->core("settings")->get("Taraviza", "AlertDisc")) {
						if($this->bot->core("settings")->get("Taraviza", "DiscChanId")) { $chan = $this->bot->core("settings")->get("Taraviza", "DiscChanId"); } else { $chan = ""; }
						if($this->bot->core("settings")->get("Taraviza", "DiscTag")) { $dctag = $this->bot->core("settings")->get("Taraviza", "DiscTag")." "; } else { $dctag = ""; }
						$this->bot->core("discord")->disc_alert($dctag.$text, $chan);
					}
					if ($this->bot->exists_module("irc")&&$this->bot->core("settings")->get("Taraviza", "AlertIrc")) {
						$this->bot->core("irc")->send_irc("", "", $text);
					}
				}
			}			
		}
	}
	
	function show_world($relay=false)
	{
		$inside = ''; $total = 0;
		foreach ($this -> wlist AS $dim => $bosses) {
			foreach (array_reverse($bosses) AS $boss => $last) {
				switch($boss) {
					case 'abmouth':
						$cycle = 3*3600; // 3H cycle randomized (15=immortality)
						$title = 'Mutated <a href="chatcmd:///waypoint 3150 1550 556">'.ucfirst($boss).'</a>';
						$rtitl = ucfirst($boss); // for relay
						$perce = " [5% chance]";
						$rperc = " [5%]"; // same
						$immor = 15*60;
						break;										
					case 'atma':
						$cycle = 3*3600; // 3H cycle randomized (15=immortality)
						$title = 'Winged <a href="chatcmd:///waypoint 1900 3000 650">'.ucfirst($boss).'</a>';
						$rtitl = ucfirst($boss);
						$perce = " [30% chance]";
						$rperc = " [30%]";
						$immor = 15*60;
						break;										
					case 'cerubin':
						$cycle = 9*3600; // 9h cycle randomized (15=immortality)
						$title = 'Rejected <a href="chatcmd:///waypoint 2100 280 505">'.ucfirst($boss).'</a>';
						$rtitl = ucfirst($boss);
						$perce = " [85% chance]";
						$rperc = " [85%]";
						$immor = 15*60;
						break;
						case 'desert-rider':
						$cycle = 6*3600; // 6h cycle randomized (5=immortality)
						$title = 'Nomad <a href="chatcmd:///waypoint 2232 1586 565">'.ucfirst($boss).'</a>';
						$rtitl = ucfirst($boss);
						$perce = " [unpredictable]";
						$rperc = " [???]";
						$immor = 5*60;
						break;									
					case 'father-time':
						$cycle = 9*3600; // 9H15 cycle (15=immortality)
						$title = 'Timed <a href="chatcmd:///waypoint 2900 300 615">'.ucfirst($boss).'</a>';
						$rtitl = ucfirst($boss);
						$perce = " [99% sure]";
						$rperc = " [99%]";
						$immor = 15*60;
						break;					
					case 'loren':
						$cycle = 9*3600; // 9H15 cycle (15=immortality)
						$title = 'Mercenary <a href="chatcmd:///waypoint 350 500 567">'.ucfirst($boss).'</a>';
						$rtitl = ucfirst($boss);
						$perce = " [99% sure]";
						$rperc = " [99%]";
						$immor = 15*60;
						break;					
					case 'reaper':
						$cycle = 9*3600; // 9H15 cycle (15=immortality)
						$title = 'Dark <a href="chatcmd:///waypoint 1760 2840 595">'.ucfirst($boss).'</a>';
						$rtitl = ucfirst($boss);
						$perce = " [99% sure]";
						$rperc = " [99%]";
						$immor = 15*60;
						break;
					case 'tam':
						$cycle = 6*3600; // 6H cycle randomized (15=immortality)
						$title = 'Automaton <a href="chatcmd:///waypoint 1130 1530 795">'.ucfirst($boss).'</a>';
						$rtitl = ucfirst($boss);
						$perce = " [60% chance]";
						$rperc = " [60%]";
						$immor = 15*60;
						break;										
					case 'tara':
						$cycle = 9*3600;  // 9H30 tara cycle (30=immortality)
						$title = 'Camelot <a href="chatcmd:///waypoint 2092 3797 505">'.ucfirst($boss).'</a>';
						$rtitl = ucfirst($boss);
						$perce = " [99% sure]";
						$rperc = " [99%]";
						$immor = 30*60;
						break;
					case 'vizaresh':
						$cycle = 17*3600; // 17H07 viza cycle (7=immortality)
						$title = 'Gauntlet <a href="chatcmd:///waypoint 310 25 4328">'.ucfirst($boss).'</a>';
						$rtitl = ucfirst($boss);
						$perce = " [99% sure]";
						$rperc = " [99%]";
						$immor = 7*60;
						break;			
					case 'zaal':
						$cycle = 6*3600; // 6H cycle randomized (15=immortality)
						$title = 'Deity <a href="chatcmd:///waypoint 1730 1200 610">'.ucfirst($boss).'</a>';
						$rtitl = ucfirst($boss);
						$perce = " [75% chance]";
						$rperc = " [75%]";
						$immor = 15*60;
						break;																
					default:
						$cycle = 6*3600; // 6H default common cycle, assumed for any other
						$title = "Unknown ".ucfirst($boss); // no coordinates by default
						$rtitl = ucfirst($boss);
						$perce = " [no details]"; // unpredictable chance
						$rperc = " [xxx]";
						$immor = 15*60; // default 15 min immortality
						break;
				}
				if (time()<$last+$immor+30||(time()>$last+$immor+30+$cycle&&time()<$last+$immor+30+$cycle+$immor+30)) { // 30 sec kill margin
					if($relay) $updown = "up?";
					else $updown = "could be ##green##up##end##";
				} else {
					if($relay) $updown = "off";
					else $updown = "prolly ##red##down##end##";
				}
				if (time()-$last<172800) { // after 48h without spawn, boss event is prolly over
					$seenpop = $this->calcpop($last,0); // last pop strictly
					$seentime = $this->calctime($seenpop);
					$seen = $seentime['hour']."h".$seentime['min']."m";
					$nextpop = $this->calcpop($last+$immor+30,$cycle); // last kill cycled
					$nexttime = $this->calctime($nextpop);
					$next = $nexttime['hour']."h".$nexttime['min']."m";					
					if($relay) $inside .= $rtitl.'('.$dim.') '.$updown.' since '.substr($seen,0,-1).' pop in '.substr($next,0,-1).$rperc.". ";
					else $inside .= '<br>'.$title.' (RK'.$dim.') : '.$updown.' / seen '.$seen.' ago, may pop in '.$next.$perce;
					$total++;
				}
			}
			$inside .= '<br>';
		}
		if($relay) return $total." wb found. ".$inside;
		else return $total." world boss(es) currently found : ".$this->bot->core("tools")->make_blob("click to view", $inside);	
	}
	
	function calcpop($timer,$cycle)
	{
        $now = time();
        if($cycle>0) { while ($timer <= $now) { $timer = $timer + $cycle; }}
        if($cycle>0) $left = $timer - $now;
		else $left = $now-$timer;
		return $left;
    }
	
	function calctime($left)
	{
		if($left<0) $left = abs($left);
        $hour = floor($left/3600);
        $left = $left - ($hour*3600);
        $min = floor($left/60);
        $sec = $left - ($min*60);
        if ($sec < 10) { $sec = "0".$sec; }
        if ($hour < 10) { $hour = "0".$hour; }
        if ($min < 10) { $min = "0".$min; }
		$aret = array("hour" => $hour, "min" => $min, "sec" => $sec);
		return $aret;
    }		

	function show_buff()
	{
		$return = "";
		if($this->bot->core("settings")->get("Taraviza", "ApiUrl")!='') {
			$url = $this->bot->core("settings")->get("Taraviza", "ApiUrl")."/".$this->apiver."/"."gaubuffs";
			$content = $this->bot->core("tools")->get_site($url);	
			if (!($content instanceof BotError)) {
				if (strpos($content, '"faction":') !== false) {
					$buffs = json_decode($content);
					$now = time(); $faction = ""; $expires = 0;
					foreach($buffs as $buff) {
						if(($buff->faction=='clan'||$buff->faction=='omni')&&$buff->expires>$now) {
							$faction = $buff->faction;
							$expires = $buff->expires;
						} else {
							$return .= " No active sided buff detected for RK".$buff->dimension." ";
						}
						if($faction!=''&&$expires>$now) {
							$left = $expires - $now;
							$calctime = $this->calctime($left);
							$return .= " Currently RK".$buff->dimension." ".$faction." buff is up and expires in about ".$calctime['hour']."h".$calctime['min']."m ";						
						}
					}					
				} else {
					$return = "No buff info for now, may retry later on.";
				}
			} else {
				$return = "API couldn't be reached, so no data obtained.";
			}
		}
		return $return;
    }	
	
	function verif_tara()
	{
        $take = $this -> bot -> db -> select("SELECT * FROM tara");
        if (empty($take))
                $this -> bot -> db -> query("INSERT INTO tara (time) VALUES ('".time()."')");
    }

	function verif_viza()
	{
        $take = $this -> bot -> db -> select("SELECT * FROM viza");
        if (empty($take))
                $this -> bot -> db -> query("INSERT INTO viza (time) VALUES ('".time()."')");
    }

	function show_tara($from)
	{
		$timer = 0;		
        $this -> verif_tara();
        $take = $this -> bot -> db -> select("SELECT * FROM tara");
        foreach ($take as $line){ $timer = $line[0]; }
        $now = time();
        while ($timer <= $now) { $timer = $timer + $this->tcycle; }
		if ($now<($timer-$this->tcycle+1800)) $still = "should be up since ".floor(($now-($timer-$this->tcycle))/60)."m and then would";
		else $still = "should";
        $left = $timer - $now;
		$calctime = $this->calctime($left);
		$msg = "";
        if($from=="user") $msg = "Tarasque ".$still." pop in about ".$calctime['hour']."h".$calctime['min']."m";
		elseif($calctime['hour']=="00") $msg = $calctime['min'];
		return $msg;
    }

	function show_viza($from)
	{
		$timer = 0;
        $this -> verif_viza();
        $take = $this -> bot -> db -> select("SELECT * FROM viza");
        foreach ($take as $line){ $timer = $line[0]; }
        $now = time();
        while ($timer <= $now) { $timer = $timer + $this->vcycle; }
        $left = $timer - $now;
		$calctime = $this->calctime($left);
		$msg = "";
		if($from=="user") $msg = "Gauntlet should start in about ".$calctime['hour']."h".$calctime['min']."m";
		elseif($calctime['hour']=="00") $msg = $calctime['min'];
		return $msg;
    }

	function set_now($name, $channel)
	{
        $this -> bot -> db -> query("TRUNCATE TABLE tara");
        $this -> verif_tara();
        $this -> bot -> send_output($name, "Tarasque timer set in 9H", $channel);
    }

	function set_now2($name, $channel)
	{
        $this -> bot -> db -> query("TRUNCATE TABLE viza");
        $this -> verif_viza();
        $this -> bot -> send_output($name, "Gauntlet timer set in 19H", $channel);
    }

 	function set_tara($name, $oper, $msg, $channel)
	{
        $this -> verif_tara();
        $take = $this -> bot -> db -> select("SELECT * FROM tara");
        foreach ($take as $line){ $timer = $line[0]; }
        $orig = $timer;
        if ($oper == "add") {
        $timer = $timer + ($msg[1]*60);
        $this -> bot -> db -> query("UPDATE tara SET time='".$timer."' WHERE time='".$orig."'");
        $send = "+".$msg[1]." minute(s) added to Tarasque timer";
        } elseif ($oper == "rem") {
        $timer = $timer - ($msg[1]*60);
        $this -> bot -> db -> query("UPDATE tara SET time='".$timer."' WHERE time='".$orig."'");
        $send = "-".$msg[1]." minute(s) removed to Tarasque timer";
        } else {
        $send = "Tarasque Timer unchanged";
        }
        $this -> bot -> send_output($name, $send, $channel);
    }

 	function set_viza($name, $oper, $msg, $channel)
	{
        $this -> verif_viza();
        $take = $this -> bot -> db -> select("SELECT * FROM viza");
        foreach ($take as $line){ $timer = $line[0]; }
        $orig = $timer;
        if ($oper == "add") {
        $timer = $timer + ($msg[1]*60);
        $this -> bot -> db -> query("UPDATE viza SET time='".$timer."' WHERE time='".$orig."'");
        $send = "+".$msg[1]." minute(s) added to Gauntlet timer";
        } elseif ($oper == "rem") {
        $timer = $timer - ($msg[1]*60);
        $this -> bot -> db -> query("UPDATE viza SET time='".$timer."' WHERE time='".$orig."'");
        $send = "-".$msg[1]." minute(s) removed to Gauntlet timer";
        } else {
        $send = "Gauntlet Timer unchanged";
        }
        $this -> bot -> send_output($name, $send, $channel);
    }

}
?>
