<?php
/*
 * Extended security module for BeBot.
 * This module allows flexible security groups like "all omni 210+ are GUESTs".
 * Written by Alreadythere (RK2).
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
/* TABLE STRUCTURE:
 * There are two kinds of flexible security groups: AND connected and OR connected ones.
 * The kind of group is defined by the field name of 'join', with op '&&' for AND and '||' for OR.
 * There must be exactly one 'join' entry per flexible group! 'join' entries don't need any compareto values.
 * Any other field name is used to compare with the returned values of the whois query on the user.
 *
 * A flexible security group is ALWAYS just an extension of an existing security group defined by the Security module.
 * The access level is linked via the GID entries.
 */
$flexiblesecurity_core = new FlexibleSecurity_Core($bot);
class FlexibleSecurity_Core extends BasePassiveModule
{
    private $cache; // saves the highest access level defined by all flexible groups for a player.
    private $querynames;
	var $enabled;

    function __construct(&$bot)
    {
        parent::__construct($bot, get_class($this));
        $this->bot->db->query(
            "CREATE TABLE IF NOT EXISTS " . $this->bot->db->define_tablename("security_flexible", "true") . " (
					id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
					gid INT(10) unsigned NOT NULL,
					field ENUM('join', 'level', 'profession', 'faction', 'rank_id', 'org_id', 'at_id', 'channel'),
					op ENUM('=', '<', '<=', '>', '>=', '!=', '&&', '||'),
					compareto VARCHAR(100) NOT NULL DEFAULT ''
				)"
        );
        $this->register_module("flexible_security");
        $this->register_event("cron", "6hour");
        $this->cache = array();
        $this->querynames = array(
            'level' => 'level',
            'profession' => 'profession',
            'faction' => 'faction',
            'rank_id' => 'org_rank_id',
            'org_id' => 'org_id',
            'at_id' => 'defender_rank_id'
        );
        $this->update_table();
        $this->enabled = false;
        $this->check_enable();
    }


    function update_table()
    {
        if ($this->bot->core("settings")
            ->exists("FlexibleSecurity", "SchemaVersion")
        ) {
            $this->bot->db->set_version(
                "security_flexible",
                $this->bot
                    ->core("settings")->get("FlexibleSecurity", "SchemaVersion")
            );
            $this->bot->core("settings")
                ->del("FlexibleSecurity", "SchemaVersion");
        }
        if ($this->bot->db->get_version("security_flexible") >= 3) {
            return;
        }
        switch ($this->bot->db->get_version("security_flexible")) {
            case 1:
				$col = $this->bot->db->select("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '#___security_flexible' AND COLUMN_NAME = 'id'");
				if(count($col)==0) {
					$this->bot->db->update_table(
						"security_flexible",
						"id",
						"add",
						"ALTER TABLE #___security_flexible ADD `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST"
					);
				}
				$col = $this->bot->db->select("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '#___security_flexible' AND COLUMN_NAME = 'condition'");
				if(count($col)==1) {			
					$this->bot->db->update_table(
						"security_flexible",
						"condition",
						"modify",
						"ALTER TABLE #___security_flexible CHANGE `condition` `op` ENUM( '=', '<', '<=', '>', '>=', '!=', '&&', '||' )"
					);
				}
            case 2:
                $this->bot->db->update_table(
                    "security_flexible",
                    "field",
                    "modify",
                    "ALTER TABLE #___security_flexible MODIFY `field` ENUM('join', 'level', 'profession', 'faction', 'rank_id', 'org_id', 'at_id', 'channel')"
                );
        }
        $this->bot->db->set_version("security_flexible", 3);
    }


    // Clean cache periodically to react to changed in whois cache
    function cron()
    {
        $this->clear_cache();
    }


    // Clears the cache and checks if we need to enable flexible security, should be called everytime a flexible group gets modified in any way.
    // This means changes in the access levels via security GUI too!
    function clear_cache()
    {
        $this->cache = array();
        $this->check_enable();
        // Clear cached security infos for mains in security module:
        $this->bot->core("security")->cache_mgr("del", "maincache", 0);
    }


    function check_enable()
    {
        $result = $this->bot->db->select("SELECT * FROM #___security_flexible WHERE field = 'join'");
        $this->enabled = !empty($result);
    }


    // Returns the highest access level $player has due to flexible security groups if higher then $highest. Returns $highest otherwise.
    function flexible_group_access($player, $highest, $channel = null)
    {
        // If we have no rules active, save time, memory and resources.
        if (!$this->enabled) {
            return $highest;
        }
        // Make sure $player is always ucfirst-strtolower:
        $player = $this->bot->core('tools')->sanitize_player($player);
        // Check if cached, then compare $highest with cached access level
        $cache_key = $player . "|" . ($channel === null ? "*" : strtolower($channel));
        if (isset($this->cache[$cache_key])) {
            if ($this->cache[$cache_key] > $highest) {
                $highest = $this->cache[$cache_key];
            }
            return $highest;
        }
        // Not in cache, get all flexible security groups with a higher access level then $highest (no sense to check for lower)
        $groups = $this->bot->db->select(
            "SELECT t1.gid, t1.access_level, t2.op FROM #___security_groups AS t1," . " #___security_flexible AS t2 WHERE t1.access_level > " . $highest . " AND t1.gid = t2.gid"
            . " AND t2.field = 'join' ORDER BY access_level DESC"
        );
        // No groups with higher access level? just return $highest again
        if (empty($groups)) {
            return $highest;
        }
        // Go through the groups in descending order of access levels
        foreach ($groups as $group) {
            $gid = $group[0];
            $acl = $group[1];
            if ($group[2] == '||') {
                $groupkind = 'OR';
            } else {
                $groupkind = 'AND';
            }
            // Now get the other fields of the rules
            $rules = $this->bot->db->select(
                "SELECT field, op, compareto FROM" . " #___security_flexible WHERE gid = " . $gid . " AND field != 'join'"
            );
            // if we got rules build the query string
            if (!empty($rules)) {
                $requires_whois = false;
                foreach ($rules as $rule) {
                    if (strtolower($rule[0]) != 'channel') {
                        $requires_whois = true;
                        break;
                    }
                }
                if ($requires_whois) {
                    $this->bot->core("whois")->lookup($player);
                }
                $wherestring = "";
                $channel_rules = array();
                $rulecount = 0;
                foreach ($rules as $rule) {
                    if (strtolower($rule[0]) != 'channel') {
                        $rulecount++;
                    }
                }
                $count = 0;
                foreach ($rules as $rule) {
                    // handle faction = all or faction != all cases
                    if (strtolower($rule[0]) == 'channel') {
                        $expected = strtolower($rule[2]);
                        $actual = $channel === null ? null : strtolower($channel);
                        $channel_rules[] = $actual !== null
                            && (($rule[1] == '=' && $actual == $expected)
                                || ($rule[1] == '!=' && $actual != $expected));
                        continue;
                    }
                    $count++;
                    if (strtolower($rule[0]) == 'faction' && strtolower($rule[2]) == 'all') {
                        if ($rule[1] == '=') {
                            $op = "OR";
                        } else {
                            $op = "AND";
                        }
                        $wherestring .= " (faction " . $rule[1] . " 'omni' " . $op . " faction ";
                        $wherestring .= $rule[1] . " 'clan' " . $op . " faction " . $rule[1];
                        $wherestring .= " 'neutral') ";
                    } else {
                        $wherestring .= " " . $this->querynames[$rule[0]] . " " . $rule[1];
                        $wherestring .= " '" . $rule[2] . "'";
                    }
                    if ($count < $rulecount) {
                        $wherestring .= " " . $groupkind;
                    }
                }
                $channel_match = empty($channel_rules)
                    ? null
                    : ($groupkind == 'AND' ? !in_array(false, $channel_rules, true) : in_array(true, $channel_rules, true));
                $player_match = false;
                if ($wherestring != "") {
                    $ret = $this->bot->db->select(
                        "SELECT nickname FROM #___whois WHERE nickname = '" . $player . "' AND (" . $wherestring . ")"
                    );
                    $player_match = !empty($ret);
                }
                $matches = $groupkind == 'AND'
                    ? (($channel_match === null || $channel_match) && ($wherestring == "" || $player_match))
                    : (($channel_match === true) || $player_match);
                // If the player satisfies the group, cache result and return it
                if ($matches) {
                    $this->cache[$cache_key] = $acl;
                    return $acl;
                }
            }
        }
        // Nothing higher found, cache this as result and return $highest
        $this->cache[$cache_key] = $highest;
        return $highest;
    }
}

?>
