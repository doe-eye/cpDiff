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
	
	//login=>curCheckpointId
	public $curCheckpointId = array();
	//login=>lastCheckpointId
	public $lastCheckpointId = array();
	
	/*
	#///////////////////////////////////////////////////////////////////////#
	#									#
	#///////////////////////////////////////////////////////////////////////#
	*/

	public function __construct () {

		// Describe the Plugin
		$this->setVersion('1.2');
		$this->setBuild('2018-04-24');
		$this->setAuthor('aca');
		$this->setCopyright('aca');
		$this->setDescription('displays cp-time differences to a tracked record - either to pb or to a specific dedimania/local record');

		// Add required dependencies
		$this->addDependence('PluginLocalRecords', Dependence::REQUIRED, '1.0.0', null);
		$this->addDependence('PluginDedimania', Dependence::REQUIRED, '1.0.0', null);
				

		// Register events to interact on
		$this->registerEvent('onSync', 'onSync');
		$this->registerEvent('onLoadingMap', 'onLoadingMap');
		$this->registerEvent('onEndMap', 'onEndMap');
		
		$this->registerEvent('onPlayerConnect', 'onPlayerConnect');
		$this->registerEvent('onPlayerDisconnect', 'onPlayerDisconnect');
		
		$this->registerEvent('onPlayerStartCountdown', 'onPlayerStartCountdown');
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
	
	public function onLoadingMap($aseco, $map) {
		$this->checkpointCount = $map->nb_checkpoints;
		
		foreach ($aseco->server->players->player_list as $player) {		
 			//reset curCheckpoints-array
			for($cp = 0; $cp < $this->checkpointCount; $cp++){
				$this->curCheckpoints[$player->login][$cp] = 0;
			}
			
			//reset curCheckpointId & lastCheckpointId
			$this->curCheckpointId[$player->login] = -1;
			$this->lastCheckpointId[$player->login] = -1;
			
			$this->refreshTrackedTime($player);
		} 	
	}
	

	
  	public function onEndMap($aseco, $map) {
		$aseco->console("onEndMap");
		$xml = '<manialink id="CheckpointWidgetsTopMiddleBottom"></manialink>';
		
		$aseco->sendManialink($xml, false, 0);
	} 
	

	public function onPlayerConnect($aseco, $player) {
		$showJoinInfo =((strtoupper($this->settings['JOIN_INFO'][0]['ENABLED'][0]) == 'TRUE') ? true : false);
		
		//refresh specArray if necessary
		if($player->is_spectator){			
			//is a player spectated
			if($player->target_spectating != false){
				$this->specArray[$player->login] = $player->target_spectating;
			}
		}
		
		//set default cp-tracking (pb)
		$this->tracking[$player->login]['dedimania'] = -1;
		$this->tracking[$player->login]['local'] = -1;			
		
		//initialize curCheckpoints-array
		for($cp = 0; $cp < $this->checkpointCount; $cp++){
			$this->curCheckpoints[$player->login][] = 0;
		}
		//initialize curCheckpointId / lastCheckpointId
		$this->curCheckpointId[$player->login] = -1;
		$this->lastCheckpointId[$player->login] = -1;
		
		$this->refreshTrackedTime($player);
		
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
		
		//clear from checkpoints-arrays
		unset($this->trackedCheckpoints[$player->login]);
		unset($this->curCheckpoints[$player->login]);
		unset($this->curCheckpointId[$player->login]);
		unset($this->lastCheckpointId[$player->login]);	
	}
	

	
	public function onPlayerStartCountdown ($aseco, $params) {
		$login = $params['login'];
		
		//reset curCheckpointId and lastCheckpointId
		$this->lastCheckpointId[$login] = $this->curCheckpointId[$login];
		$this->curCheckpointId[$login] = -1;
		
		$this->showTimeDiffWidgets($login, false);	
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
		$this->curCheckpointId[$login] = $cpNo -1;
		
		
		$this->showTimeDiffWidgets($login, true);
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
		$this->curCheckpointId[$login] = $cpNo -1;
		
		$this->showTimeDiffWidgets($login, true);
	} 
	
	
	public function onPlayerInfoChanged ($aseco, $login){
		$player = $aseco->server->players->getPlayerByLogin($login);
		
		//if status changed to spectator
		if($player->getSpectatorStatus()){
			//is a player spectated
			if($player->target_spectating != false){
				//set in specArray
				$this->specArray[$login] = $player->target_spectating;
				if($aseco->server->gamestate != Server::SCORE){
					//show instantly widgets of target
					$xml = $this->buildTimeDiffWidgets($player->target_spectating, false);
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
				$xml = $this->buildTimeDiffWidgets($login, false);
				$aseco->sendManialink($xml, $login, 0);	
			}
		}
	}
	
	public function onLocalRecord($aseco, $finish){
		foreach($aseco->server->players->player_list as $player){
			$this->refreshTrackedTime($player);
		}
	}
	
	public function onDedimaniaRecord($aseco, $finish){
		foreach ($aseco->server->players->player_list as $player){
			$this->refreshTrackedTime($player);
		}	
	}
	

	public function onDedimaniaRecordsLoaded($aseco, $records){
		foreach($aseco->server->players->player_list as $player){
			$this->refreshTrackedTime($player);
			$this->showTimeDiffWidgets($player->login, false);
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
		$this->showTimeDiffWidgets($login, false);
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
		$this->showTimeDiffWidgets($login, false);
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
		$this->showTimeDiffWidgets($login, false);
	
	}	
	/*
	#///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////#
	#																		END CHATCOMMANDS
	#///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////#
	*/
	
	private function getMiddleText($login){
		$txt = "";
		//start has no cpId
		if($this->curCheckpointId[$login] > -1){
			$txt = $this->getTimeDiffAtCp($this->curCheckpointId[$login], $login);
		}
		return $txt;
	}
	
	
	private function getBottomTrackingText($login){
		$curCpNo = $this->curCheckpointId[$login] + 1;
		
		$own = (($this->trackingLabel[$login]['own'] == true) ? 'own ' : '');
		$tracking = $own . $this->trackingLabel[$login]['recNum'] . ". " . $this->trackingLabel[$login]['kind'];		
		
		//at start and at finish
		if ($curCpNo == 0 || $curCpNo == $this->checkpointCount) {
			$tracking = $tracking ." ". $this->formatTime($this->trackedCheckpoints[$login][$this->checkpointCount - 1]);
		}
		//at cp
		else if ($curCpNo > 0 && $curCpNo < $this->checkpointCount) {
			$tracking = $tracking ." ". $this->formatTime($this->trackedCheckpoints[$login][$curCpNo - 1]);
		}
		
		return $tracking;	
	}
	
	
	private function getBottomText($login){
		$curCpNo = $this->curCheckpointId[$login] + 1;
		
		$bottomText = "";
		//at start
		if($curCpNo == 0) {
			$bottomText = "\$OSTART: ". $this->formatTime(0);
		}
		//at cp
		else if($curCpNo > 0 && $curCpNo < $this->checkpointCount) {
			$bottomText = "\$OCP" . $curCpNo . ": " . $this->getTimeDiffAtCp($this->curCheckpointId[$login], $login);
		}
		//at finish
		else if($curCpNo == $this->checkpointCount) {
			$bottomText = "\$OFINISH: ". $this->getTimeDiffAtCp($this->curCheckpointId[$login], $login);
		}		
		
		return $bottomText;
	}
	
	
	private function getTimeDiffAtCp($cpId, $login){
		$curCpTime = $this->curCheckpoints[$login][$cpId];

		//not yet driven through this cp (possible for topWidget)
		if($curCpTime == 0){
			return "";
		}
		
		//is a comparison possible
		if(count($this->trackedCheckpoints[$login]) == $this->checkpointCount && $this->trackedCheckpoints[$login][$cpId] != 0){
			$trackedCpTime = $this->trackedCheckpoints[$login][$cpId];
			
			$timeDifference = $trackedCpTime - $curCpTime;
			$textColor = "\$".$this->settings['TEXTCOLORS'][0]['TIME_IMPROVED'][0]."-";
			
			if ($timeDifference < 0) {
				$timeDifference = $timeDifference * (-1);
				$textColor = "\$".$this->settings['TEXTCOLORS'][0]['TIME_WORSE'][0]."+";
			}
			else if($timeDifference == 0) {
				$textColor = "\$".$this->settings['TEXTCOLORS'][0]['TIME_EQUAL'][0];
			}
			
			return $textColor . $this->formatTime($timeDifference);	
		}
		//no comparison -> show currently driven time
		else{
			return $this->formatTime($curCpTime);
		}		
	}
	
	private function formatTime($ms) {
		$minutes = intval($ms / 60000);
		$seconds = intval(($ms % 60000 )/ 1000);
		$tseconds = ($ms % 60000) % 1000;

		if($minutes > 0){
			$res = sprintf('%02d:%02d.%03d', $minutes, $seconds, $tseconds);
		}
		else if($seconds > 0){
			$res = sprintf('%2d.%03d', $seconds, $tseconds);
		}
		else{
			$res = sprintf('%d.%03d', $seconds, $tseconds);
		}
		return $res;
	}
	
	
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
		
		//placeholder for initializing $this->trackedCheckpoints[$login] (when no comparison possible)
		$tmp = array();
		for($i = 0; $i < $this->checkpointCount; $i++){
			$tmp[] = 0;
		}
		
		//dedimania records available & tracked
		if(isset($aseco->plugins['PluginDedimania']) && isset($aseco->plugins['PluginDedimania']->db['Map']) && isset($aseco->plugins['PluginDedimania']->db['Map']['Records']) && !empty($aseco->plugins['PluginDedimania']->db['Map']['Records']) && $this->tracking[$login]['dedimania'] != -1){
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
			
			//no own records
			if($lscore == 0 && $dscore == 0){
				$this->trackedCheckpoints[$login] = $tmp;
				
				//set trackingLabel
				$this->trackingLabel[$login]['recNum'] = 0;
				$this->trackingLabel[$login]['kind'] = 'pb record';
				$this->trackingLabel[$login]['own'] = false;	
			}			
			
			//take dedi-rec
			else if($dscore != 0 && $dscore <= $lscore || $lscore == 0){
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
		//local or dedi tracked but no rec available
		else{
			$this->trackedCheckpoints[$login] = $tmp;
			
			//local or dedi tracked?
			$kind = (($this->tracking[$login]['local'] == -1) ? 'dedimania' : 'local');
			
			//set trackingLabel
			$this->trackingLabel[$login]['recNum'] = 0;
			$this->trackingLabel[$login]['kind'] = $kind. ' records to track';
			$this->trackingLabel[$login]['own'] = false;
		}
	}
	
	private function showTimeDiffWidgets($login, $showMiddle){
		global $aseco;
		$xml = $this->buildTimeDiffWidgets($login, $showMiddle);
		
		$aseco->sendManialink($xml, $login, 0);
		
		//also for spectators
		foreach ($this->specArray as $spectator => $spectated){
			if($spectated == $login){
				$aseco->sendManialink($xml, $spectator, 0);
			}
		}	
	}
	
	
	
	private function buildTimeDiffWidgets($login, $showMiddle){
		global $aseco;
		$cp_times = implode(',', $this->trackedCheckpoints[$login]);
		$multilapmap = (($aseco->server->maps->current->multi_lap == true) ? 'True' : 'False');
 		$hideMiddle = (($showMiddle == true) ? 'False' : 'True');
		
		$improved	= $this->settings['TEXTCOLORS'][0]['TIME_IMPROVED'][0];
		$equal		= $this->settings['TEXTCOLORS'][0]['TIME_EQUAL'][0];
		$worse 		= $this->settings['TEXTCOLORS'][0]['TIME_WORSE'][0];

		$colorbarEnabled = ((strtoupper($this->settings['COLORBAR'][0]['ENABLED'][0]) == 'TRUE') ? 'True' : 'False');
		$middleEnabled = ((strtoupper($this->settings['WIDGET_MIDDLE'][0]['ENABLED'][0]) == 'TRUE') ? 'True' : 'False');
		
$maniascript = <<<EOL
<script><!--
 /*
 * ==================================
 * Function:	<cpWidgets_ShowMiddle_Colorbar> @ plugin.cpDiff.php
 * Author:	aca
 * License:	GPLv3
 * ==================================
 */
#Include "TextLib" as TextLib

main() {
	
	declare CMlFrame FrameCheckpointTimeDiffMiddle	<=> (Page.GetFirstChild("CheckpointTimeDiffMiddle") as CMlFrame);
	declare CMlQuad QuadColorbar			<=> (Page.GetFirstChild("ColorbarBottom") as CMlQuad);
	
	declare Integer TotalCheckpoints		= {$this->checkpointCount};		// Incl. Finish
	declare Boolean MultilapMap				= {$multilapmap};
	declare Integer[] BestCheckpointTimes	= [{$cp_times}];

	
	//login of driving player
	declare Text PlayerPlayingLogin			= "{$login}";
	
	//Player who is driving						player to whom widget is shown
	declare PlayerPlaying					<=> InputPlayer;
	
	declare Boolean HideMiddle				= {$hideMiddle};
	declare Boolean ColorbarEnabled			= {$colorbarEnabled};
	declare Boolean MiddleEnabled			= {$middleEnabled};
	
	
	declare Integer CurrentCheckpoint		= 0;
	declare Integer CurrentLapCheckpoint	= 0;
	declare Integer CurrentRaceTime 		= 0;
	declare Integer TimeDifference			= 0;	
	
	declare ColorBarColors = [
		"Improved"	=> TextLib::ToColor("{$improved}"),
		"Equal"		=> TextLib::ToColor("{$equal}"),
		"Worse"		=> TextLib::ToColor("{$worse}")
	];
	
	QuadColorbar.RelativeRotation		= 180.0;
	QuadColorbar.Opacity			= 0.75;
	
	if(InputPlayer != Null){
		//player to whom widget is shown is Spectator -> set PlayerPlaying to actually driving player (Spectated)
		if(InputPlayer.IsSpawned == False){
			foreach(Player in Players){
				if(Player.User.Login == PlayerPlayingLogin){
					PlayerPlaying <=> Player;
					break;
				}
			}	
		}
		
		//fetch CurrentCheckpoint and CurrentRaceTime	
		CurrentCheckpoint = PlayerPlaying.CurRace.Checkpoints.count; //count of crossed cps since initial start	
		CurrentRaceTime = PlayerPlaying.CurCheckpointRaceTime; //time since initial start
		
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

		//calculate TimeDifference & set Colorbar
		if (CurrentRaceTime > 0) {
			if (BestCheckpointTimes.existskey(CurrentCheckpoint - 1) && BestCheckpointTimes[CurrentCheckpoint - 1] != 0){
				TimeDifference = (BestCheckpointTimes[CurrentCheckpoint - 1] - CurrentRaceTime);
				
				if (TimeDifference < 0) {
					QuadColorbar.Colorize = ColorBarColors["Worse"];
					if(ColorbarEnabled == True){
						QuadColorbar.Visible = True;
					}
				}
				else if (TimeDifference == 0) {
					QuadColorbar.Colorize = ColorBarColors["Equal"];
					if(ColorbarEnabled == True){
						QuadColorbar.Visible = True;
					}
				}
				else{
					QuadColorbar.Colorize = ColorBarColors["Improved"];
					if(ColorbarEnabled == True){
						QuadColorbar.Visible = True;
					}
				}
			}
		}
		else{
			QuadColorbar.Visible = False;
		}
	}
	
	//for TimeDiffWidgetMiddle
	if(HideMiddle == True){
		FrameCheckpointTimeDiffMiddle.Hide();
	}
	else{
		if(MiddleEnabled == True){
			FrameCheckpointTimeDiffMiddle.Show();
			//show for 2 seconds
			sleep(2000);
		}
		FrameCheckpointTimeDiffMiddle.Hide();
	} 
		
}
--></script>
EOL;

	
		$xml = '<manialink id="CheckpointWidgetsTopMiddleBottom" name="CheckpointWidgetsTopMiddleBottom" version="3">';
		
		//TimeDiffWidget top
		$posXtop = (int)$this->settings['WIDGET_TOP'][0]['POS_X'][0];
		$posYtop = (int)$this->settings['WIDGET_TOP'][0]['POS_Y'][0];
		$numCols = (int)$this->settings['WIDGET_TOP'][0]['NUM_COLS'][0];
		$bgTop = $this->settings['WIDGET_TOP'][0]['BACKGROUND_COLOR'][0];
		$bgTop_al = $this->settings['WIDGET_TOP'][0]['BACKGROUND_COLOR_ACTIVE_LAST'][0];
		$cpNoColor = $this->settings['WIDGET_TOP'][0]['CPNO_COLOR'][0];
		$cpTimeColorTop = $this->settings['WIDGET_TOP'][0]['CP_TIME_COLOR'][0];
		$maxRows = $this->settings['WIDGET_TOP'][0]['MAX_ROWS'][0];
		$topEnabled = ((strtoupper($this->settings['WIDGET_TOP'][0]['ENABLED'][0]) == 'TRUE') ? true : false);
		
		$rowCount = $this->checkpointCount / $numCols;
		
		//only show, when widget is enabled in xml and when not more rows than indicated in xml
		if($topEnabled && $rowCount <= $maxRows){
			$column = 0;
			$cp = 0;
			while($cp < $this->checkpointCount){		
				$xml .= '<frame pos="'. ($posXtop + 22 * $column).' '.$posYtop.'" z-index="0">';
				$xml .= '<quad pos="0 0" z-index="0.01" size="20 5" bgcolor="'. (($this->curCheckpointId[$login] == $cp || ($this->lastCheckpointId[$login] == $cp && $this->lastCheckpointId[$login] > $this->curCheckpointId[$login])) ? $bgTop_al : $bgTop) .'" valign="center"/>';	
				$xml .= '<label pos="2 0" z-index="0.02" size="8 3.75" textsize="2" scale="0.8" text="' .(($cp + 1 == $this->checkpointCount)? "Fin: " : "Cp".($cp + 1).": ").'" valign="center" textcolor="'.$cpNoColor.'"/>';
				$xml .= '<label pos="10 0" z-index="0.02" size="10 3.75" textsize="2" textcolor="'.$cpTimeColorTop.'" text="'.$this->getTimeDiffAtCp($cp, $login).'" scale="0.8" valign="center" />';
				$xml .= '</frame>';
				
				$column++;
				if($column == $numCols){
					$posYtop -= 6;
					$column = 0;
				}
				$cp++;
			}
		}

		
		//TimeDiffWidget middle
		$bgMiddle = $this->settings['WIDGET_MIDDLE'][0]['BACKGROUND_COLOR'][0];
		$posXmiddle = (int)$this->settings['WIDGET_MIDDLE'][0]['POS_X'][0];
		$posYmiddle = (int)$this->settings['WIDGET_MIDDLE'][0]['POS_Y'][0];
		$cpTimeColorMiddle = $this->settings['WIDGET_MIDDLE'][0]['CP_TIME_COLOR'][0];
		
		$xml .= '<frame pos="'.$posXmiddle.' ' .$posYmiddle. '" z-index="0" id="CheckpointTimeDiffMiddle">';
		$xml .= '<quad pos="0 30" z-index="0.01" size="25 5" bgcolor="'. $bgMiddle .'" halign="center" valign="center"/>';
		$xml .= '<label pos="0 30"  z-index="0.02" size="50 3.75" textsize="2" scale="0.8" halign="center" valign="center" textprefix="$T" textcolor="'.$cpTimeColorMiddle.'" text="'.$this->getMiddleText($login).'" id ="LabelCheckpointTimeDiffMiddle"/>';
		$xml .= '</frame>';
		
		
		
		//colorbar
 		$xml .= '<frame pos="175 -90" z-index="-40">';
		$xml .= '<quad pos="0 0" z-index="0" size="350 28.125" style="BgsPlayerCard" substyle="BgRacePlayerLine" id="ColorbarBottom" hidden="true"/>';
		$xml .= '</frame>'; 

		//TimeDiffWidget bottom
		$bottomEnabled = ((strtoupper($this->settings['WIDGET_BOTTOM'][0]['ENABLED'][0]) == 'TRUE') ? true : false);
		$posXbottom = (int)$this->settings['WIDGET_BOTTOM'][0]['POS_X'][0];
		$posYbottom = (int)$this->settings['WIDGET_BOTTOM'][0]['POS_Y'][0];
		$bgBottom = $this->settings['WIDGET_BOTTOM'][0]['BACKGROUND_COLOR'][0];
		$trTxtColor = $this->settings['WIDGET_BOTTOM'][0]['TRACKING_TEXT_COLOR'][0];
		$cpTimeColorBottom = $this->settings['WIDGET_BOTTOM'][0]['CP_TIME_COLOR'][0];
		
		if($bottomEnabled){
			$xml .= '<frame pos="'.$posXbottom.' ' .$posYbottom. '" z-index="0" id="CheckpointTimeDiffBottom">';
			$xml .= '<quad pos="0 0" z-index="0.01" size="40 7.5" bgcolor="'.$bgBottom.'"/>';
			$xml .= '<label pos="20 -1.21875" z-index="0.02" size="50 3.75" textsize="2" scale="0.8" halign="center" textprefix="$T" textcolor="'.$cpTimeColorBottom.'" text="'.$this->getBottomText($login).'" id ="LabelCheckpointTimeDiffBottom"/>';
			$xml .= '<label pos="20 -4.6875" z-index="0.02" size="50 2.625" textsize="1" scale="0.8" halign="center" textprefix="$T" textcolor="'.$trTxtColor.'" text="'.$this->getBottomTrackingText($login).'" id="LabelTracking"/>';
			$xml .= '</frame>';		
		}
		$xml .= $maniascript;
		$xml .= '</manialink>';
		
		return $xml;	
	}

	
}

?>
