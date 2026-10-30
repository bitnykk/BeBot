<?php
/*
* AorRaid.php - for AOR raid context
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
$aorraid = new AorRaid($bot);
/*
The Class itself...
*/
class AorRaid extends BaseActiveModule
{

    function __construct(&$bot)
    {
        parent::__construct($bot, get_class($this));
		$this->register_event("cron", "1min");		
    }

    function command_handler($name, $msg, $origin)
    {
		// no command to support
	}

	function cron()
    {
		if($this->bot->checkaor&&!$this->bot->guildbot&&$this->bot->raidid>0) $this->aorraidjoin();		
		$this->unregister_event("cron", "1min");
    }

    function aorraidjoin()
    {
		$channelType = 0x8F;
		$teamId = $this->bot->raidid;
		$groupId =	pack("C", $channelType) . pack("V", $teamId);
		$status = 0;
        $packet = new AOChatPacket(
            "out",
            AOCP_GROUP_DATA_SET,
            array(
                $groupId,
                $status,
                "\0"
            )
        );
		$result = $this->bot->aoc->send_packet($packet);
		if($result) {
			$this->bot->log("AORRAID", "STATUS", "Joined Aor Raid Group");
		} else {
			$this->bot->log("AORRAID", "STATUS", "Failed to join Aor Raid");
		}
	}
	
}
?>
