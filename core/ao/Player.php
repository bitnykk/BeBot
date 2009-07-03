<?php
class Player
{
	//Game spesific variables
	private $uid = false;
	private $uname = false; //aka nickname
	private $firstname = false;
	private $lastname = false;
	private $breed = false;
	private $gender = false;
	private $level = false;
	private $profession = false;
	private $ai_level = false;
	private $organization = false;
	private $org_rank = false;
	
	//Bot spesific variables
	private $accesslevel = false;
	private $user_level = false;
	
	private $preferences = array();
	
	//When constructing a new player we need to have the bot handle so that the
	//class can look up certain variables automagically.
	
	public function __construct(&$bothandle, $data)
	{
		$this->bot = $bothandle;
		$this->error = new BotError($this->bot, get_class($this));
		
		foreach($data as $key=>$value)
		{
			$this->$key = $value;
		}
	}
	
	/*
		This function allows coders to use $player->uid instead of player->get_uid() when wanting to
		access a variable while still allowing the class to look up any values it has not already cached.
	*/
	public function _get($variable)
	{
		switch($variable)
		{
			case 'uid': case 'id':
				return ($this->get_uid());
				break;
			case 'uname': case 'nick': case 'nickname':
				return ($this->get_uname());
				break;
			case 'firstname':
			case 'lastname':
			case 'breed':
			case 'gender':
			case 'level':
			case 'profession':
			case 'ai_level':
			case 'ai_rank':
			case 'organization':
			case 'org_rank':
				return ($this->get_whois($variable));
				break;
			case 'pref': case 'preferences':
				return ($this->get_preferences($variable));
				break;
			default:
				$this->error->set("Unknown attribute '$variable'.");
				return $this->error;
				break;
			
		}
	}
	
	public function get_uid($uname)
	{
		//Make sure we have the uid at hand.
		if(!$this->uid)
		{
			$this->uid = $this->bot->core('player')->get_uid($uname);
			if($this->uid instanceof BotError)
			{
				//The uid could not be resolved.
				$this->error = $this->uid;
				$this->uid = false;
				return $this->error;
			}
		}
		return $this->uid;
	}
	
	public function get_uname($uid)
	{
		//Make sure we have the uname at hand.
		if(!$this->uname)
		{
			$this->uname = $this->bot->core('player')->get_uname($uid);
			if($this->uname instanceof BotError)
			{
				//The uid could not be resolved.
				$this->error = $this->uname;
				$this->uname = 'Unknown';
				return $this->error;
			}
		}
		return $this->uid;
	}
	
	public function get_whois($attribute)
	{
		//Make sure we have the attribute at hand.
		if(!$this->$attribute)
		{
			//Make sure we have a uname
			if(!$this->uname)
			{
				//If we don't have a uname already we should have an uid.
				$this->get_uname($this->uid);
			}
			$data = $this->bot->core('whois')->lookup($this->uname);
			foreach($data as $key=>$value)
			{
				$this->$key = $value;
			}
		}
		return ($this->$attribute);
	}
	
	//Lookup the preferences in the table if we haven't already done that.
	public function get_preferences($variable)
	{
		
	}
}
?>