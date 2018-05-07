<?php
/*
 * Plugin: checkpoint_time_differences
 * ~~~~~~~~~~~~~~~~~~~
 * » displays cp-time differences to a tracked record - either to pb or to a specific dedimania/local record
 *   
 *	 in the middle (instead of modescript_settings.xml <checkpoint_time>)
 *	 -> shows current cp-time difference when crossing a cp / finish
 *	 -> hidden 2 seconds after crossing cp / finish
 *	
 *	 at bottom (instead of checkpoints.xml <time_diff_widget> and its colorbar)
 *	 -> shows current cp-time difference when crossing and till next crossing a cp
 *	 -> shows also the kind and number of the tracked record
 *	 -> shows a colorbar
 *
 *	 at top
 *	 -> shows the cp-time differences of every crossed cp
 *
 *
 * » this code is inspired by undef's plugin.checkpoints.php and brakerb's plugin.checkpoint_records.php
 * ----------------------------------------------------------------------------------
 *
 * LICENSE: This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * ----------------------------------------------------------------------------------
 *
 */

	// Start the plugin
	$_PLUGIN = new PluginCpDiff();

/*
#///////////////////////////////////////////////////////////////////////#
#									#
#///////////////////////////////////////////////////////////////////////#
*/

class PluginCpDiff extends Plugin {
	//settings from xml-file
	public $settings;

	//number of total Cps (incl. finish)
	public $checkpointCount = 0;
	
	//spectatorLogin=>spectatedLogin
	public $specArray = array();	
	
	//login	=>local		= -1/0/#
	//	   	=>dedimania	= -1/0/# 
	public $tracking = array();
	
	//login => own = true / false
	//		=> recNum = #
	//		=> kind = dedimania / local / dedi as pb / local as pb / rec as pb
	public $trackingLabel = array();

	//login	=>[0] = 1.cp
	//		=>[1] = 2.cp etc
	public $trackedCheckpoints = array();
	public $curCheckpoints = array();
	
	public $curCpString = array();
	
	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function __construct () {

		// Describe the Plugin
		$this->setVersion('2.0.1');
		$this->setBuild('2018-05-07');
		$this->setAuthor('aca');
		$this->setCopyright('aca');
		$this->setDescription('displays cp-time differences to a tracked record - either to pb or to a specific dedimania/local record');

		// Add required dependencies
		$this->addDependence('PluginLocalRecords', Dependence::REQUIRED, '1.0.0', null);
		$this->addDependence('PluginDedimania', Dependence::REQUIRED, '1.0.0', null);
				

		// Register events to interact on
		$this->registerEvent('onSync', 'onSync');
		$this->registerEvent('onLoadingMap', 'onLoadingMap');
		$this->registerEvent('onBeginMap', 'onBeginMap');
		$this->registerEvent('onEndMap', 'onEndMap');
		
		$this->registerEvent('onPlayerConnect', 'onPlayerConnect');
		$this->registerEvent('onPlayerDisconnect', 'onPlayerDisconnect');
		
		$this->registerEvent('onPlayerCheckpoint', 'onPlayerCheckpoint');
		$this->registerEvent('onPlayerFinishLine', 'onPlayerFinishLine');
		
		$this->registerEvent('onPlayerInfoChanged', 'onPlayerInfoChanged');
		
		$this->registerEvent('onLocalRecord', 'onLocalRecord');
		$this->registerEvent('onDedimaniaRecord', 'onDedimaniaRecord');		
		
		$this->registerEvent('onDedimaniaRecordsLoaded', 'onDedimaniaRecordsLoaded');

		$this->registerChatCommand('lcps', 			'chat_lcps', 		'Sets local record checkpoints tracking', 	Player::PLAYERS);
		$this->registerChatCommand('dcps', 			'chat_dcps', 		'Sets dedimania record checkpoints tracking', 	Player::PLAYERS);
		$this->registerChatCommand('pbcps', 		'chat_pbcps', 		'Sets personalBest record checkpoints tracking', 	Player::PLAYERS);
		
	}

	/*
	#///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////#
	#																		EVENTS
	#///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////#
	*/
	
	public function onSync($aseco){
		// Read Configuration
		if (!$xml = $aseco->parser->xmlToArray('config/checkpoint_time_differences.xml', true, true)) {
			trigger_error('[CpDiff] Could not read/parse config file "config/checkpoint_time_differences.xml"!', E_USER_ERROR);
		}
		$this->settings = $xml['SETTINGS'];
		unset($xml);
		
	}
	
	public function onLoadingMap($aseco, $map){
		$this->checkpointCount = $map->nb_checkpoints;
	}
	
	public function onBeginMap($aseco, $uid) {
		//$aseco->console("onBeginMap");
		foreach ($aseco->server->players->player_list as $player){
			 //reset curCheckpoints-array
			$this->curCheckpoints[$player->login] = array();
			for($cp = 0; $cp < $this->checkpointCount; $cp++){
				$this->curCheckpoints[$player->login][] = -1;
			}

			$this->curCpString[$player->login] = implode(',', $this->curCheckpoints[$player->login]);
			//$aseco->console("$player->login: " .$this->curCpString[$player->login]);
			
			$this->refreshTrackedTime($player);
			if($player->getSpectatorStatus()){
				$this->onPlayerInfoChanged($aseco, $player->login);
			}
			else{
				$this->showTimeDiffWidgets($player->login);
			}
		}
	}	
	
	public function onDedimaniaRecordsLoaded($aseco, $records){
		//$aseco->console("onDedisLoaded");
		foreach($aseco->server->players->player_list as $player){
			$this->refreshTrackedTime($player);
			
			if($player->getSpectatorStatus()){
				$this->onPlayerInfoChanged($aseco, $player->login);
			}
			else{
				$this->showTimeDiffWidgets($player->login);
			}
		}		
	}
	
  	public function onEndMap($aseco, $map) {
		$xml = '<manialink id="CheckpointWidgetsTopMiddleBottom"></manialink>';
		$aseco->sendManialink($xml, false, 0);
	} 
	

	public function onPlayerConnect($aseco, $player) {
		$showJoinInfo =((strtoupper($this->settings['JOIN_INFO'][0]['ENABLED'][0]) == 'TRUE') ? true : false);
				
		//set default cp-tracking (pb)
		$this->tracking[$player->login]['dedimania'] = -1;
		$this->tracking[$player->login]['local'] = -1;			

		//initialize curCheckpoints-array
		$this->curCheckpoints[$player->login] = array();
		for($cp = 0; $cp < $this->checkpointCount; $cp++){
			$this->curCheckpoints[$player->login][] = -1;
		}
		
		//on rebooting uaseco checkpointCount == 0
		if($this->checkpointCount > 0){
			$this->curCpString[$player->login] = implode(',', $this->curCheckpoints[$player->login]);
			$this->refreshTrackedTime($player);
			$this->showTimeDiffWidgets($player->login);
		}
	
		if($showJoinInfo){
			$message1 = "{#error}INFO{#server}» To compare to a specific dedimania-record use /dcps # (e.g. /dcps 1)";
			$message2 = "{#error}INFO{#server}» To compare to a specific local-record use /lcps # (e.g. /lcps 1)";
			$message3 = "{#error}INFO{#server}» Reset to default tracking (pb) by using /pbcps";
			
			$aseco->sendChatMessage($message1, $player->login);
			$aseco->sendChatMessage($message2, $player->login);
			$aseco->sendChatMessage($message3, $player->login);
		}
	}	
	
	
 	public function onPlayerDisconnect ($aseco, $player) {
		//clear from specArray
		if(isset($this->specArray[$player->login])){
			unset($this->specArray[$player->login]);
		}
		
		//clear from tracking-array
		unset($this->tracking[$player->login]);
		
		//clear from trackingLabel
		unset($this->trackingLabel[$player->login]);
		
		//clear from checkpoints-array
		unset($this->trackedCheckpoints[$player->login]);
		unset($this->curCheckpoints[$player->login]);
		unset($this->curCpString[$player->login]);

	}
	
	public function onPlayerCheckpoint($aseco, $params){
		$login = $params['login'];
		$time = 0;
		$cpNo = 1;
		
		if($aseco->server->maps->current->multi_lap === true){
			$time = (int)$params['lap_time'];
			$cpNo = (int)$params['checkpoint_in_lap'];
		}
		else{
			$time = (int)$params['race_time'];
			$cpNo = (int)$params['checkpoint_in_race'];
		}
		$this->curCheckpoints[$login][$cpNo -1] = $time;
		$this->curCpString[$login] = implode(',', $this->curCheckpoints[$login]);
	}
	
 	public function onPlayerFinishLine($aseco, $params) {
 		$login = $params['login'];
		$time = 0;
		$cpNo = $this->checkpointCount;
			
		if($aseco->server->maps->current->multi_lap === true){
			$time = (int)$params['lap_time'];
			$cpNo = (int)$params['checkpoint_in_lap'];	
		}
		else{
			$time = (int)$params['race_time'];
			$cpNo = (int)$params['checkpoint_in_race'];
		}
		
		$this->curCheckpoints[$login][$cpNo -1] = $time;
		$this->curCpString[$login] = implode(',', $this->curCheckpoints[$login]);
	} 
	
	
	public function onPlayerInfoChanged ($aseco, $login){
		//$aseco->console("onPlayerInfoChanged $login");
		$player = $aseco->server->players->getPlayerByLogin($login);
	
		//if status changed to spectator
		if($player->getSpectatorStatus()){
			//is a player spectated
			if($player->target_spectating != false){
				//set in specArray
				$this->specArray[$login] = $player->target_spectating;
				
				if($aseco->server->gamestate != Server::SCORE){
					//show instantly widgets of target
					$xml = $this->buildTimeDiffWidgets($player->target_spectating);
					$aseco->sendManialink($xml, $login, 0);
				}
			}
			else{
				$xml = '<manialink id="CheckpointWidgetsTopMiddleBottom"></manialink>';
				$aseco->sendManialink($xml, $login, 0);
			}
			
		}
		//if status changed from spectator to player
		else{
			if(isset($this->specArray[$login])){
				//unset in specArray
				unset($this->specArray[$login]);
			}
			if($aseco->server->gamestate != Server::SCORE){
				//show instantly widgets player himself
				$xml = $this->buildTimeDiffWidgets($login);
				$aseco->sendManialink($xml, $login, 0);	
			}
		}
	}
	
	public function onLocalRecord($aseco, $finish){
		foreach($aseco->server->players->player_list as $player){			
			$this->refreshTrackedTime($player);
			if(!$player->getSpectatorStatus()){
				$this->showTimeDiffWidgets($player->login);
			}
		}	
	}
	
	public function onDedimaniaRecord($aseco, $finish){
		foreach($aseco->server->players->player_list as $player){
			$this->refreshTrackedTime($player);
			if(!$player->getSpectatorStatus()){
				$this->showTimeDiffWidgets($player->login);
			}
		}
	}
	

	
	
	/*
	#///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////#
	#																		END EVENTS
	#///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////#
	*/

	
	
	/*
	#///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////#
	#																		CHATCOMMANDS
	#///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////#
	*/
	
	public function chat_lcps ($aseco, $login, $chat_command, $chat_parameter) {
		if (!$player = $aseco->server->players->getPlayerByLogin($login)) {
			return;
		}

		// Check for relay server
		if ($aseco->server->isrelay) {
			$message = "{#server}» {#error}Command unavailable on relay server!";
			$aseco->sendChatMessage($message, $player->login);
			return;
		}	
	
		$this->setCpTracking($player, 'local' ,$chat_parameter);
		$this->refreshTrackedTime($player);
		if(!$player->getSpectatorStatus()){
			$this->showTimeDiffWidgets($login);
		}
	}

	public function chat_dcps ($aseco, $login, $chat_command, $chat_parameter) {
		if (!$player = $aseco->server->players->getPlayerByLogin($login)) {
			return;
		}

		// Check for relay server
		if ($aseco->server->isrelay) {
			$message = "{#server}» {#error}Command unavailable on relay server!";
			$aseco->sendChatMessage($message, $player->login);
			return;
		}	
	
		$this->setCpTracking($player, 'dedimania' ,$chat_parameter);
		$this->refreshTrackedTime($player);
		if(!$player->getSpectatorStatus()){
			$this->showTimeDiffWidgets($login);
		}
	}

	public function chat_pbcps ($aseco, $login, $chat_command, $chat_parameter) {
		if (!$player = $aseco->server->players->getPlayerByLogin($login)) {
			return;
		}

		// Check for relay server
		if ($aseco->server->isrelay) {
			$message = "{#server}» {#error}Command unavailable on relay server!";
			$aseco->sendChatMessage($message, $player->login);
			return;
		}	
		
		$this->setCpTracking($player, 'pb', 'pb');	
		$this->refreshTrackedTime($player);
		if(!$player->getSpectatorStatus()){
			$this->showTimeDiffWidgets($login);
		}
	
	}	
	/*
	#///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////#
	#																		END CHATCOMMANDS
	#///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////#
	*/
	
	

	
	private function setCpTracking($player, $kindOfRec, $param){
		global $aseco;
		$this->tracking[$player->login]['local'] = -1;
		$this->tracking[$player->login]['dedimania'] = -1;
		
		//for personalBest cp-tracking
		if($kindOfRec == 'pb'){
			$message = "{#server}» Checkpoints tracking on $kindOfRec record turned {#highlite}ON";
		}
		
		//for local or dedimania cp-tracking
		else if($param == ''){//tracking own/last rec
			$this->tracking[$player->login][$kindOfRec] = 0;
			$message = "{#server}» Checkpoints tracking on $kindOfRec records turned {#highlite}ON {#server}(your own or the last $kindOfRec record)";
		}
		else if(is_numeric($param) && $param > 0){//tracking specific rec-#
			$this->tracking[$player->login][$kindOfRec] = intval($param);
			$message = "{#server}» Checkpoints tracking on $kindOfRec record {#highlite}No. $param {#server}(or the last available $kindOfRec record)";
		}
		else {
			$message = "{#server}» {#error}No such ".$kindOfRec.' record {#highlite}$I'. $param ." {#server} tracking set to default (pb)";
		}
				
		$aseco->sendChatMessage($message, $player->login);
	}
	
	
	
	private function refreshTrackedTime($player){
		global $aseco;
		$login = $player->login;
		//$aseco->console("refreshTrackedTime $login");
		
		//placeholder for initializing $this->trackedCheckpoints[$login] (when no comparison possible)
		$tmp = array();
		for($i = 0; $i < $this->checkpointCount; $i++){
			$tmp[] = 0;
		}
		
		//dedimania records available & tracked
		if(isset($aseco->plugins['PluginDedimania']) && isset($aseco->plugins['PluginDedimania']->db['Map'])  && isset($aseco->plugins['PluginDedimania']->db['Map']['Records']) && !empty($aseco->plugins['PluginDedimania']->db['Map']['Records']) && $this->tracking[$login]['dedimania'] != -1){
			//$aseco->console("$login : dedi tracked");
			$no = 0;
			$record = null;
			
			if($this->tracking[$login]['dedimania'] == 0){
				// Search for own/last record
				while($no < count($aseco->plugins['PluginDedimania']->db['Map']['Records'])){
					$record = $aseco->plugins['PluginDedimania']->db['Map']['Records'][$no++];
					if($record['Login'] == $login) {
						break;
					}
				}
			}		
			else if($this->tracking[$login]['dedimania'] > 0){
				// If specific record unavailable, use last one
				$no = $this->tracking[$login]['dedimania'];
				if($no > count($aseco->plugins['PluginDedimania']->db['Map']['Records'])){
					$no = count($aseco->plugins['PluginDedimania']->db['Map']['Records']);
				}
				$record = $aseco->plugins['PluginDedimania']->db['Map']['Records'][$no - 1];				
			}	

			if(!is_array($record['Checks'])){
				$record['Checks'] = explode(',', $record['Checks']);	
			}
			
			// Check for valid checkpoints and refresh trackedCheckpoints
			if(!empty($record['Checks']) && $record['Best'] == end($record['Checks']) && count($record['Checks']) == $this->checkpointCount){
				$this->trackedCheckpoints[$login] = $record['Checks'];
			}
			else{
				$aseco->sendChatMessage("{#server}» Dedimania-Record by {#highlite}".$record['Login']."{#server} seems to be invalid.");
				$aseco->sendChatMessage("{#server}» Choose another record to compare to", $login);
			}

			//set trackingLabel
			$this->trackingLabel[$login]['recNum'] = $no;
			$this->trackingLabel[$login]['kind'] = 'dedimania record';
			
			//own record
			if($record['Login'] == $login){
				$this->trackingLabel[$login]['own'] = true;
			}
			//other than own 
			else{
				$this->trackingLabel[$login]['own'] = false;
			}
		}
		//local records available & tracked
		else if(isset($aseco->plugins['PluginLocalRecords']) && $aseco->plugins['PluginLocalRecords']->records->count() > 0 && $this->tracking[$login]['local'] != -1){
			//$aseco->console("$login : local tracked");
			$no = 0;
			$record = null;
			
			if($this->tracking[$login]['local'] == 0){
				// Search for own/last record
				while($no < $aseco->plugins['PluginLocalRecords']->records->count()){
					$record = $aseco->plugins['PluginLocalRecords']->records->getRecord($no++);
					if($record->player->login == $login) {
						break;
					}
				}
			}		
			else if($this->tracking[$login]['local'] > 0){
				// If specific record unavailable, use last one
				$no = $this->tracking[$login]['local'];
				if($no > $aseco->plugins['PluginLocalRecords']->records->count()){
					$no = $aseco->plugins['PluginLocalRecords']->records->count();
				}
				$record = $aseco->plugins['PluginLocalRecords']->records->getRecord($no - 1);				
			}	

			// Check for valid checkpoints and refresh trackedCheckpoints
			if(!empty($record->checkpoints) && $record->score == end($record->checkpoints) && count($record->checkpoints) == $this->checkpointCount){
				$this->trackedCheckpoints[$login] = $record->checkpoints;
			}
			else{
				$aseco->sendChatMessage("{#server}» Local-Record by {#highlite}".$record->player->login."{#server} seems to be invalid.");	
				$aseco->sendChatMessage("{#server}» Choose another record to compare to", $login);
			}

			//set trackingLabel
			$this->trackingLabel[$login]['recNum'] = $no;
			$this->trackingLabel[$login]['kind'] = 'local record';
			//own record
			if($record->player->login == $login){
				$this->trackingLabel[$login]['own'] = true;
			}
			//other than own 
			else{
				$this->trackingLabel[$login]['own'] = false;
			}
		}			
		//pb tracked
		else if(isset($aseco->plugins['PluginDedimania']) && isset($aseco->plugins['PluginDedimania']->db['Map']) && isset($aseco->plugins['PluginDedimania']->db['Map']['Records']) && isset($aseco->plugins['PluginLocalRecords']) && $this->tracking[$login]['local'] == -1 && $this->tracking[$login]['dedimania'] == -1){
			//$aseco->console("$login : pb tracked");
			$lno = 0;
			$lrecord = null;
			$lscore = 0;
			//search for lokal record
			while($lno < $aseco->plugins['PluginLocalRecords']->records->count()){
				$lrecord = $aseco->plugins['PluginLocalRecords']->records->getRecord($lno++);
				if($lrecord->player->login == $login){
					$lscore = (int)$lrecord->score;
					break;
				}
			}

			//$aseco->dump($aseco->plugins['PluginDedimania']->db['Map']['UId']);
			$dno = 0;
			$drecord = null;
			$dscore = 0;
			//search for dedimania record
			if(!empty($aseco->plugins['PluginDedimania']->db['Map']['Records'])){
				while($dno < count($aseco->plugins['PluginDedimania']->db['Map']['Records'])){
					$drecord = $aseco->plugins['PluginDedimania']->db['Map']['Records'][$dno++];
					if($drecord['Login'] == $login){
						$dscore = (int)$drecord['Best'];
						break;
					}
				}
			}
			
			//$aseco->console("$login lscore: $lscore dscore: $dscore");
			
			//no own records
			if($lscore == 0 && $dscore == 0){
				//$aseco->console("$login : pb-> nothing to track");
				$this->trackedCheckpoints[$login] = $tmp;
				
				//set trackingLabel
				$this->trackingLabel[$login]['recNum'] = 0;
				$this->trackingLabel[$login]['kind'] = 'pb record';
				$this->trackingLabel[$login]['own'] = false;	
			}			
			
			//take dedi-rec
			else if($dscore != 0 && $dscore <= $lscore || $lscore == 0){
				//$aseco->console("$login : pb-> take-dedi");
				if(!is_array($drecord['Checks'])){
					$drecord['Checks'] = explode(',', $drecord['Checks']);	
				}
				// Check for valid checkpoints and refresh trackedCheckpoints
				if(!empty($drecord['Checks']) && $drecord['Best'] == end($drecord['Checks']) && count($drecord['Checks']) == $this->checkpointCount){
					$this->trackedCheckpoints[$login] = $drecord['Checks'];
				}
				else{
					$aseco->sendChatMessage("{#server}» Dedimania-Record by {#highlite}".$drecord['Login']."{#server} seems to be invalid.");
					$aseco->sendChatMessage("{#server}» Choose another record to compare to", $login);
				}
				
				//set trackingLabel
				$this->trackingLabel[$login]['recNum'] = $dno;
				$this->trackingLabel[$login]['kind'] = 'dedi as pb record';
				$this->trackingLabel[$login]['own'] = true;
			}
			//take local-rec
			else if($lscore != 0 && $lscore < $dscore || $dscore == 0){
				//$aseco->console("$login : pb-> take-local");
				// Check for valid checkpoints and refresh trackedCheckpoints
				if(!empty($lrecord->checkpoints) && $lrecord->score == end($lrecord->checkpoints) && count($lrecord->checkpoints) == $this->checkpointCount){
					$this->trackedCheckpoints[$login] = $lrecord->checkpoints;
				}
				else{
					$aseco->sendChatMessage("{#server}» Local-Record by {#highlite}".$lrecord->player->login."{#server} seems to be invalid.");	
					$aseco->sendChatMessage("{#server}» Choose another record to compare to", $login);
				}
				
				//set trackingLabel
				$this->trackingLabel[$login]['recNum'] = $lno;
				$this->trackingLabel[$login]['kind'] = 'local as pb record';
				$this->trackingLabel[$login]['own'] = true;				
			}

		}
		//local or dedi tracked but no rec available || records not yet loaded
		else{
			//$aseco->console("$login : no rec available");
			$this->trackedCheckpoints[$login] = $tmp;
			
			//local or dedi tracked?
			$kind = (($this->tracking[$login]['local'] == -1) ? 'dedimania' : 'local');
			
			//set trackingLabel
			$this->trackingLabel[$login]['recNum'] = 0;
			$this->trackingLabel[$login]['kind'] = $kind. ' records to track';
			$this->trackingLabel[$login]['own'] = false;
		}
	}
	
	private function showTimeDiffWidgets($login){
		global $aseco;
		$xml = $this->buildTimeDiffWidgets($login);
		
		$aseco->sendManialink($xml, $login, 0);
		
		//also for spectators
		foreach ($this->specArray as $spectator => $spectated){
			if($spectated == $login){
				$aseco->sendManialink($xml, $spectator, 0);
			}
		}	
	}
	
	
	
	private function buildTimeDiffWidgets($login){
		global $aseco;
		//$aseco->console("buildTimeDiffWidgets $login");
		$trackedCpTimes = implode(',', $this->trackedCheckpoints[$login]);		
		$multilapmap = (($aseco->server->maps->current->multi_lap == true) ? 'True' : 'False');
		
		$improved	= $this->settings['TEXTCOLORS'][0]['TIME_IMPROVED'][0];
		$equal		= $this->settings['TEXTCOLORS'][0]['TIME_EQUAL'][0];
		$worse 		= $this->settings['TEXTCOLORS'][0]['TIME_WORSE'][0];

		//top-widget
		$topEnabled = ((strtoupper($this->settings['WIDGET_TOP'][0]['ENABLED'][0]) == 'TRUE') ? 'True' : 'False');
		$bgTop = $this->settings['WIDGET_TOP'][0]['BACKGROUND_COLOR'][0];
		$bgTop_al = $this->settings['WIDGET_TOP'][0]['BACKGROUND_COLOR_ACTIVE_LAST'][0];
		$numCols = (int)$this->settings['WIDGET_TOP'][0]['NUM_COLS'][0];
		$maxRows = $this->settings['WIDGET_TOP'][0]['MAX_ROWS'][0];
		
		//middle-widget
		$middleEnabled = ((strtoupper($this->settings['WIDGET_MIDDLE'][0]['ENABLED'][0]) == 'TRUE') ? 'True' : 'False');
		$middleShowTime = (int)$this->settings['WIDGET_MIDDLE'][0]['SHOW_TIME'][0];
		
		//bottom-widget
		$own = (($this->trackingLabel[$login]['own'] == true) ? 'own ' : '');
		$tracking = $own . $this->trackingLabel[$login]['recNum'] . ". " . $this->trackingLabel[$login]['kind'];
		$bottomEnabled = ((strtoupper($this->settings['WIDGET_BOTTOM'][0]['ENABLED'][0]) == 'TRUE') ? 'True' : 'False');
		
		$colorbarEnabled = ((strtoupper($this->settings['COLORBAR'][0]['ENABLED'][0]) == 'TRUE') ? 'True' : 'False');
		
		
$maniascript = <<<EOL
<script><!--
 /*
 * ==================================
 * Function:	<Time-Diff-Widgets_Top_Middle_Bottom> @ plugin.checkpoint_time_differences.php
 * Author:	aca
 * License:	GPLv3
 * ==================================
 */
#Include "TextLib" as TextLib
#Include "MathLib" as MathLib

Text FormatTime (Integer MwTime) {
	declare Text FormatedTime = "0:00.000";

	if (MwTime > 0) {
		FormatedTime = TextLib::TimeToText(MwTime, True) ^ MwTime % 10;
	}
	return FormatedTime;
}

Text TimeToTextDiff (Integer _Time) {
	declare InputTime	= MathLib::Abs(_Time);
	declare Seconds		= (InputTime / 1000) % 60;
	declare Minutes		= (InputTime / 60000) % 60;
	declare Hours		= (InputTime / 3600000);

	declare Time = "";
	if (Hours > 0) {
		Time = Hours ^":"^ TextLib::FormatInteger(Minutes, 2) ^":"^ TextLib::FormatInteger(Seconds, 2);
	}
	else if (Minutes > 0) {
		Time = Minutes ^":"^ TextLib::FormatInteger(Seconds, 2);
	}
	else {
		Time = ""^ Seconds;
	}
	Time ^= "."^ TextLib::FormatInteger(InputTime % 1000, 3);

	if (Time != "") {
		return ""^ Time;
	}
	return "0.000";
}

main(){
	declare Integer TotalCheckpoints = {$this->checkpointCount};
	
	while(!PageIsVisible || InputPlayer == Null || TotalCheckpoints == 0){
		yield;
		continue;
	}
	
	
//top-widget
	declare Boolean TopEnabled = {$topEnabled};
	declare CMlFrame[] CpTimeFramesTop;
	declare Integer CP = 0;
	while(CP < TotalCheckpoints){
		CpTimeFramesTop.add((Page.GetFirstChild("FrameCheckpointTimeDiffTop" ^ CP) as CMlFrame));
		CP += 1;
	}		
	declare CMlLabel[] CpTimeDiffLabelsTop;
	CP = 0;
	while(CP < TotalCheckpoints){
		CpTimeDiffLabelsTop.add((Page.GetFirstChild("LabelTopCheckpoint" ^ CP) as CMlLabel));		
		CP += 1;
	}	
	declare CMlQuad[] CpQuadsTop;
	CP = 0;
	while(CP < TotalCheckpoints){
		CpQuadsTop.add((Page.GetFirstChild("QuadTopCheckpoint" ^ CP) as CMlQuad));
		CP += 1;
	}

	declare TopColors = [
		"BgTop"		=> TextLib::ToColor("{$bgTop}"),
		"BgTop_al"	=> TextLib::ToColor("{$bgTop_al}")
	];	
	
	declare Real NumCols = 0.0 + {$numCols};
	declare Real TotalCheckpointsReal = 0.0 + {$this->checkpointCount};
	declare Real MaxRows = 0.0 + {$maxRows};
	declare Real RowCount = TotalCheckpointsReal / NumCols;

	//only show top-widget when enabled and when it has not more rows than indicated in the xml-file
	if(TopEnabled == False || RowCount > MaxRows){
		declare Integer Counter = 0;
		while(Counter < TotalCheckpoints){
			CpTimeFramesTop[Counter].Hide();
			Counter += 1;
		}
	}
	else{
		declare Integer Counter = 0;
		while(Counter < TotalCheckpoints){
			CpQuadsTop[Counter].Opacity = 0.65;
			CpQuadsTop[Counter].BgColor = TopColors["BgTop"];
			Counter += 1;
		}
	}
		
//middle-widget
	declare Boolean MiddleEnabled = {$middleEnabled};
	declare Integer MiddleShowTime = {$middleShowTime};
	declare CMlFrame FrameCheckpointTimeDiffMiddle	<=> (Page.GetFirstChild("FrameCheckpointTimeDiffMiddle") as CMlFrame);
	declare CMlLabel LabelCheckpointTimeDiffMiddle	<=> (Page.GetFirstChild("LabelCheckpointTimeDiffMiddle") as CMlLabel);
	FrameCheckpointTimeDiffMiddle.Hide();

//bottom-widget
	declare Boolean BottomEnabled = {$bottomEnabled};
	declare CMlFrame FrameCheckpointTimeDiffBottom	<=> (Page.GetFirstChild("FrameCheckpointTimeDiffBottom") as CMlFrame);
	declare CMlLabel LabelCheckpointTimeDiffBottom	<=> (Page.GetFirstChild("LabelCheckpointTimeDiffBottom") as CMlLabel);
	declare CMlLabel LabelTracking					<=> (Page.GetFirstChild("LabelTracking") as CMlLabel);	
	declare Text TrackingText = "{$tracking}";
	
	if(BottomEnabled == False){
		FrameCheckpointTimeDiffBottom.Hide();
	}
	
//colorbar
	declare Boolean ColorbarEnabled	= {$colorbarEnabled};
	declare CMlQuad QuadColorbar	<=> (Page.GetFirstChild("ColorbarBottom") as CMlQuad);
	declare ColorBarColors = [
		"Improved"	=> TextLib::ToColor("{$improved}"),
		"Equal"		=> TextLib::ToColor("{$equal}"),
		"Worse"		=> TextLib::ToColor("{$worse}")
	];

	if(ColorbarEnabled == True){
		QuadColorbar.RelativeRotation = 180.0;
		QuadColorbar.Opacity = 0.75;
	}
	else{
		QuadColorbar.Visible = False;
	}	

	
	declare Integer CurrentCheckpoint		= 0;
	declare Integer CurrentLapCheckpoint	= 0;
	declare Integer CurrentRaceTime 		= 0;
	declare Integer TimeDifference			= 0;	

	declare Text TextColor					= "";
	declare TimeDiffColors = [
		"Improved"	=> "\${$improved}",
		"Equal"		=> "\${$equal}",
		"Worse"		=> "\${$worse}"
	];

//other declarations
	declare Boolean MultilapMap				 = {$multilapmap};
	//player who's widget shall be shown
	declare Text PlayerPlayingLogin			 = "{$login}";	
	declare PlayerPlaying 					<=> InputPlayer;
	declare Integer[] TrackedCheckpointTimes = [{$trackedCpTimes}];
	declare Integer[][Text] PlayersCurrentCheckpoints = Integer[][Text];
	PlayersCurrentCheckpoints[PlayerPlayingLogin] = [{$this->curCpString[$login]}];

	
	//for faking Event onPlayerCheckpoint
	CP = 0;
	declare Integer MiddleShowEnd = 0;

	//flag for entering
	declare Boolean Initial = True;
	
	//for checking, if the Player, who's widget shall be shown, is still connected
	declare Boolean IsConnected = True;

	
//player to whom widget is shown is Spectator -> set PlayerPlaying to actually driving player (Spectated)
	if(InputPlayer.IsSpawned == False){
		foreach(Player in Players){
			if(Player.User.Login == PlayerPlayingLogin){
				PlayerPlaying <=> Player;
				break;
			}
		}	
	}
	
	
	
	
//********************************************************************** main loop ******************************************************
	while(True){
		yield;	
		
	//check if Player, who's widget shall be shown, is still connected
		if(InputPlayer.IsSpawned == False){
			IsConnected = False;
			foreach(Player in Players){
				if(Player.User.Login == PlayerPlayingLogin){
					IsConnected = True;
					break;
				}
			}
		}
		if(IsConnected == False){
			continue;
		}
		
	//fetch CurrentCheckpoint and CurrentRaceTime	
		CurrentCheckpoint = PlayerPlaying.CurRace.Checkpoints.count; //count of crossed cps since first start	
		CurrentRaceTime = PlayerPlaying.CurCheckpointRaceTime; //time since first start
		if (MultilapMap == True) {
			CurrentLapCheckpoint = CurrentCheckpoint - (PlayerPlaying.CurrentNbLaps * TotalCheckpoints); //count of crossed cps since last start
			
			//at finish
			if(CurrentCheckpoint > 0 && CurrentLapCheckpoint == 0){
				CurrentCheckpoint = TotalCheckpoints;
				if(PlayerPlaying.CurrentNbLaps > 1){
					//take away time driven in previous finish
					CurrentRaceTime -= PlayerPlaying.CurRace.Checkpoints[(TotalCheckpoints * (PlayerPlaying.CurrentNbLaps -1)) -1];		
				}	
			}
			//at cps
			else{
				CurrentCheckpoint = CurrentLapCheckpoint;
				CurrentRaceTime = PlayerPlaying.CurCheckpointLapTime; //time since last start
			}
		}
		
	//when just entered loop	
		if(Initial == True){
			CP = CurrentCheckpoint;
			Initial = False;


		//fill top-widget
			declare Integer Int = 0;
			declare Integer TimeDiff = 0;
			declare Integer CpTime = -1;
			declare Text Tcolor = "";
			//log("InputPlayer: " ^InputPlayer.User.Login ^ "PlayerPlaying: " ^PlayerPlayingLogin ^" PlayerPlayingCurCPs: " ^PlayersCurrentCheckpoints[PlayerPlayingLogin]);
			while(Int < TotalCheckpoints){
				CpTime = PlayersCurrentCheckpoints[PlayerPlayingLogin][Int];				
				if(CpTime == -1){
					break;
				}
				else{
					if(TrackedCheckpointTimes[Int] > 0){
						TimeDiff = TrackedCheckpointTimes[Int] - CpTime;						
						if (TimeDiff < 0) {
							Tcolor = TimeDiffColors["Worse"] ^"+";
						}
						else if (TimeDiff == 0) {
							Tcolor = TimeDiffColors["Equal"];
						}
						else{
							Tcolor = TimeDiffColors["Improved"] ^"-";
						}
					}
					else{
						TimeDiff = CpTime;
					}
					CpTimeDiffLabelsTop[Int].Value = Tcolor ^ TimeToTextDiff(MathLib::Abs(TimeDiff));
				}
				Int += 1;
			}
		}
		
		
	//calculate TimeDifference, set Colorbar and TextColor
		if (CurrentRaceTime > 0) {
			//comparison possible
			if (TrackedCheckpointTimes.existskey(CurrentCheckpoint - 1) && TrackedCheckpointTimes[CurrentCheckpoint - 1] != 0){
				TimeDifference = (TrackedCheckpointTimes[CurrentCheckpoint - 1] - CurrentRaceTime);
				
				if (TimeDifference < 0) {
					TextColor = TimeDiffColors["Worse"] ^"+";
					QuadColorbar.Colorize = ColorBarColors["Worse"];
					if(ColorbarEnabled == True){
						QuadColorbar.Visible = True;
					}
				}
				else if (TimeDifference == 0) {
					TextColor = TimeDiffColors["Equal"];
					QuadColorbar.Colorize = ColorBarColors["Equal"];
					if(ColorbarEnabled == True){
						QuadColorbar.Visible = True;
					}
				}
				else{
					TextColor = TimeDiffColors["Improved"] ^"-";
					QuadColorbar.Colorize = ColorBarColors["Improved"];
					if(ColorbarEnabled == True){
						QuadColorbar.Visible = True;
					}
				}
			}
			//no comparison
			else{
				TimeDifference = CurrentRaceTime;
			}
		}
		else{
			QuadColorbar.Visible = False;
		}	
		
	// Change BottomLabels
		//at start
		if (CurrentCheckpoint == 0){
			CP = 0;
			LabelCheckpointTimeDiffBottom.Value = "\$OSTART: "^ TimeToTextDiff(0);
			LabelTracking.Value = TrackingText ^" "^ FormatTime(TrackedCheckpointTimes[TotalCheckpoints - 1]);
		}
		//at cp
		else if (CurrentCheckpoint > 0 && CurrentCheckpoint < TotalCheckpoints) {
			LabelCheckpointTimeDiffBottom.Value = "\$OCP"^ CurrentCheckpoint ^": "^ TextColor ^ TimeToTextDiff(MathLib::Abs(TimeDifference));
			LabelTracking.Value = TrackingText ^" "^ FormatTime(TrackedCheckpointTimes[CurrentCheckpoint - 1]);
		}
		//at finish
		else if (CurrentCheckpoint == TotalCheckpoints){
			LabelCheckpointTimeDiffBottom.Value = "\$OFINISH: "^ TextColor ^ TimeToTextDiff(MathLib::Abs(TimeDifference));
			LabelTracking.Value = TrackingText ^" "^ FormatTime(TrackedCheckpointTimes[TrackedCheckpointTimes.count - 1]);
		}
		
		
	//fake Event onCheckpoint
		if(CurrentCheckpoint > CP || (CurrentCheckpoint == 1 && CP == TotalCheckpoints)){
			CP = CurrentCheckpoint;
			MiddleShowEnd = CurrentTime + MiddleShowTime;
			PlayersCurrentCheckpoints[PlayerPlayingLogin][CurrentCheckpoint -1] = CurrentRaceTime;
			
			//set Top-Quads BackgroundColor
			for(I, 0, TotalCheckpoints -1){
				CpQuadsTop[I].BgColor = TopColors["BgTop"];
			}
			//highlight current Top-Quad
			CpQuadsTop[CurrentCheckpoint -1].BgColor = TopColors["BgTop_al"];
		}
		
	//fill current TopLabel (none for start)
		if(CurrentCheckpoint > 0){
			CpTimeDiffLabelsTop[CurrentCheckpoint -1].Value = TextColor ^ TimeToTextDiff(MathLib::Abs(TimeDifference));
		}

		
	//show & hide TimeDiffWidget-Middle
		if(MiddleEnabled == True){
			LabelCheckpointTimeDiffMiddle.Value = "\$O"^ TextColor ^ TimeToTextDiff(MathLib::Abs(TimeDifference));
			if(CurrentTime < MiddleShowEnd && CurrentCheckpoint != 0){
				FrameCheckpointTimeDiffMiddle.Show();
			}
			else{
				FrameCheckpointTimeDiffMiddle.Hide();
			}
		}
	}
//********************************************************************** end main loop ******************************************************		
}
--></script>
EOL;

	
		$xml = '<manialink id="CheckpointWidgetsTopMiddleBottom" name="CheckpointWidgetsTopMiddleBottom" version="3">';
		
		
		//TimeDiffWidget top
		$posXtop = (int)$this->settings['WIDGET_TOP'][0]['POS_X'][0];
		$posYtop = (int)$this->settings['WIDGET_TOP'][0]['POS_Y'][0];
		$cpNoColor = $this->settings['WIDGET_TOP'][0]['CPNO_COLOR'][0];
		$cpTimeColorTop = $this->settings['WIDGET_TOP'][0]['CP_TIME_COLOR'][0];

		$column = 0;
		$cp = 0;
		while($cp < $this->checkpointCount){		
			$xml .= '<frame pos="'. ($posXtop + 22 * $column).' '.$posYtop.'" z-index="0" id="FrameCheckpointTimeDiffTop'.$cp.'">';
			$xml .= '<quad pos="0 0" z-index="0.01" size="20 5" valign="center" id="QuadTopCheckpoint'.$cp.'"/>';	
			$xml .= '<label pos="2 0" z-index="0.02" size="8 3.75" textsize="2" scale="0.8" text="' .(($cp + 1 == $this->checkpointCount)? "Fin: " : "Cp".($cp + 1).": ").'" valign="center" textcolor="'.$cpNoColor.'" />';
			$xml .= '<label pos="10 0" z-index="0.02" size="10 3.75" textsize="2" textcolor="'.$cpTimeColorTop.'" text="" scale="0.8" valign="center" id="LabelTopCheckpoint'.$cp.'"/>';
			$xml .= '</frame>';
			
			$column++;
			if($column == $numCols){
				$posYtop -= 6;
				$column = 0;
			}
			$cp++;
		}

		
		//TimeDiffWidget middle
		$bgMiddle = $this->settings['WIDGET_MIDDLE'][0]['BACKGROUND_COLOR'][0];
		$posXmiddle = (int)$this->settings['WIDGET_MIDDLE'][0]['POS_X'][0];
		$posYmiddle = (int)$this->settings['WIDGET_MIDDLE'][0]['POS_Y'][0];
		$cpTimeColorMiddle = $this->settings['WIDGET_MIDDLE'][0]['CP_TIME_COLOR'][0];
		
		$xml .= '<frame pos="'.$posXmiddle.' ' .$posYmiddle. '" z-index="0" id="FrameCheckpointTimeDiffMiddle">';
		$xml .= '<quad pos="0 30" z-index="0.01" size="25 5" bgcolor="'. $bgMiddle .'" halign="center" valign="center"/>';
		$xml .= '<label pos="0 30"  z-index="0.02" size="50 3.75" textsize="2" scale="0.8" halign="center" valign="center" textprefix="$T" textcolor="'.$cpTimeColorMiddle.'" text="" id ="LabelCheckpointTimeDiffMiddle"/>';
		$xml .= '</frame>';
		
		
		
		//colorbar
 		$xml .= '<frame pos="175 -90" z-index="-40">';
		$xml .= '<quad pos="0 0" z-index="0" size="350 28.125" style="BgsPlayerCard" substyle="BgRacePlayerLine" id="ColorbarBottom" hidden="true"/>';
		$xml .= '</frame>'; 

		//TimeDiffWidget bottom
		$posXbottom = (int)$this->settings['WIDGET_BOTTOM'][0]['POS_X'][0];
		$posYbottom = (int)$this->settings['WIDGET_BOTTOM'][0]['POS_Y'][0];
		$bgBottom = $this->settings['WIDGET_BOTTOM'][0]['BACKGROUND_COLOR'][0];
		$trTxtColor = $this->settings['WIDGET_BOTTOM'][0]['TRACKING_TEXT_COLOR'][0];
		$cpTimeColorBottom = $this->settings['WIDGET_BOTTOM'][0]['CP_TIME_COLOR'][0];
		
		$xml .= '<frame pos="'.$posXbottom.' ' .$posYbottom. '" z-index="0" id="FrameCheckpointTimeDiffBottom">';
		$xml .= '<quad pos="0 0" z-index="0.01" size="40 7.5" bgcolor="'.$bgBottom.'"/>';
		$xml .= '<label pos="20 -1.21875" z-index="0.02" size="50 3.75" textsize="2" scale="0.8" halign="center" textprefix="$T" textcolor="'.$cpTimeColorBottom.'" text="" id ="LabelCheckpointTimeDiffBottom"/>';
		$xml .= '<label pos="20 -4.6875" z-index="0.02" size="50 2.625" textsize="1" scale="0.8" halign="center" textprefix="$T" textcolor="'.$trTxtColor.'" text="" id="LabelTracking"/>';
		$xml .= '</frame>';
		
		$xml .= $maniascript;
		$xml .= '</manialink>';
		
		return $xml;

	
	}
	
}
?>
