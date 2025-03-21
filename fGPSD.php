<?php
/*
askGPSD	получает информацию от gpsd (или gpsdPROXY) методом ?POLL;
getPosAndInfo	Собирает информацию с подключенных датчиков ГПС, etc. - что умеет gpsd или SignalK
getPosAndInfoFromGPSD
getPosAndInfoFromSignalK
*/

function askGPSD($host='localhost',$port=2947,$dataTypes=array('tpv')) {
/* Возвращает всю информацию классов gpsd TPV, AIS или и то и то в виде ассоциированного массива
если $host и $port указывают на реальный gpsd -- то AIS, разумеется, не будет, ибо POLL.
если же это gpsdPROXY -- AIS будет
$dataTypes - массив ключей ответа gpsd/gpsdPROXY, значения которых надо вернуть: array('tpv','ais','mob','collisions')
*/
$SEEN_GPS = 0x01; $SEEN_AIS = 0x08;
$requiredDevice = 0;	// какие устройства ы терминологии gpsd_json.5 ln 1355 должны быть в ответе gpsd
if(in_array('tpv',$dataTypes)) $requiredDevice = $requiredDevice | $SEEN_GPS;
if(in_array('ais',$dataTypes)) $requiredDevice = $requiredDevice | $SEEN_AIS;
global $spatialProvider;	// результат попытки обнаружения поставщика координат. Строка из приветствия gpsd, netAIS или строка signalk-server
//echo "\n\nНачали. host:$host:$port; dataType:";print_r($dataTypes);echo "\n";
$gpsd  = @stream_socket_client('tcp://'.$host.':'.$port,$errno,$errstr); // открыть сокет 
$res = @fwrite($gpsd, "\n\n"); 	// если там gpsdPROXY, он не пришлёт VERSION при открытии соединения
//echo "res=$res; ";var_dump($gpsd);echo "<br>\n";
if(($res === FALSE) or !$gpsd) return "no GPSD: $errstr";
//stream_set_blocking($gpsd,FALSE); 	// установим неблокирующий режим чтения Что-то с ним не так...
//echo "[askGPSD] Socket to gpsd opened, do handshaking\n";

$controlClasses = array('VERSION','DEVICES','DEVICE','WATCH');
$WATCHsend = FALSE; $POLLsend = FALSE;
do { 	// при каскадном соединении нескольких gpsd заголовков может быть много
	$zeroCount = 0;	// счётчик пустых строк
	do {	// крутиться до принятия строки или до 10 пустых строк
		$buf = fgets($gpsd); 
		//echo "<br>buf:<br>|".strtr($buf,"\r\n",'?!')."|<br>\n";
		if($buf === FALSE) { 	// gpsd умер
			@socket_close($gpsd);
			$msg = "Failed to read data from gpsd";
			echo "$msg<br>\n"; 
			return $msg;
		}
		$buf = trim($buf);
		if(!$buf) $zeroCount++;
	}while(!$buf and $zeroCount<10);
	$buf = json_decode($buf,TRUE);
	if($buf === null) { 	// прислали странное, это не gpsd?
	    @socket_close($gpsd);
		$msg = "Recieved not JSON. Is this gpsd?";
		echo "$msg<br>\n"; 
		return $msg;
	}
	//echo "<br>buf: ";echo "<pre>"; print_r($buf); echo "</pre>\n";
	switch($buf['class']){
	case 'VERSION': 	// можно получить от slave gpsd посде WATCH
		if(!$WATCHsend) { 	// команды WATCH ещё не посылали
			$res = fwrite($gpsd, '?WATCH={"enable":true};'."\n\n"); 	// велим демону включить устройства
			if($res === FALSE) { 	// gpsd умер
				socket_close($gpsd);
				$msg =  "Failed to send WATCH to gpsd: $errstr";
				echo "$msg<br>\n"; 
				return $msg;
			}
			$WATCHsend = TRUE;
			$spatialProvider = $buf['release'];
			//echo "Send TURN ON<br>\n";
		}
		break;
	case 'DEVICES': 	// соберём подключенные устройства со всех gpsd, включая slave
		//echo "Received DEVICES<br>\n"; //
		$devicePresent = array();
		foreach($buf["devices"] as $device) {
			if($device['flags']&$requiredDevice) $devicePresent[] = $device['path']; 	// список требуемых среди обнаруженных и понятых устройств.
		}
		break;
	case 'DEVICE': 	// здесь информация о подключенных slave gpsd, т.е., общая часть path в имени устройства. Полезно для опроса конкретного устройства, но нам не надо. 
		//echo "Received about slave DEVICE<br>\n"; //
		break;
	case 'WATCH': 	// 
		//echo "Received WATCH<br>\n"; //
		//print_r($gpsdWATCH); //
		if(!$POLLsend) { 	// к slave gpsd POLL не отсылают? Тогда шлём POLL после первого WATCH
			//echo "Sending POLL<br>\n";
			$res = fwrite($gpsd, '?POLL;'."\n\n"); 	// запросим данные
			if($res === FALSE) { 	// gpsd умер
				socket_close($gpsd);
				$msg =  "Failed to send POLL to gpsd: $errstr";
				echo "$msg<br>\n"; 
				return $msg;
			}
			$POLLsend = TRUE;
		}
		break;
	}
	
}while(!$buf or in_array($buf['class'],$controlClasses));
//echo "fGPSD.php [askGPSD] buf: ";echo "<pre>"; print_r($buf); echo "</pre>\n";

if(!$devicePresent) return 'no required devices present';
//echo "devicePresent: <pre>\n"; print_r($devicePresent); echo "</pre><br>\n";

@fwrite($gpsd, '?WATCH={"enable":false};'."\n"); 	// велим демону выключить устройства
fclose($gpsd);
//echo "Закрыт сокет\n";
if(!$buf['active']) return 'no any active devices';

// Собираем из того, что вернул gpsd то, что просили
$tpv = array();
foreach($dataTypes as $dataType){
	$tpv[$dataType] = $buf[$dataType];
};
//echo "Данные askGPSD <pre>"; print_r($tpv); echo "</pre>\n";
return $tpv;
} // end function askGPSD


function getPosAndInfo($host='',$port=NULL,$dataTypes=array('tpv')) { 
/* Собирает информацию с подключенных датчиков ГПС, etc. - что умеет gpsd или SignalK
*/
if(is_array($host)) { 	// спрашивать у SignalK
	//error_log("fGPSD.php getPosAndInfo: will ask spatial info from SignalK");
	$TPV = getPosAndInfoFromSignalK($host,$dataTypes);
}
elseif($host and $port) { 	// спрашивать у gpsd
	//error_log("fGPSD.php getPosAndInfo: will ask spatial info from gpsd");
	$TPV = getPosAndInfoFromGPSD($host,$port,$dataTypes);
	if(isset($TPV['error'])) {	// при этом $TPV['tpv']['error'] проверять не будем, чтобы ais и mob.
		$TPV = getPosAndInfoFromSignalK(NULL,$dataTypes);
	};
}
else { 	// попробуем найти SignalK
	$TPV = getPosAndInfoFromSignalK(NULL,$dataTypes);
};
return $TPV;
}; // end function getPosAndInfo


function getPosAndInfoFromGPSD($host='localhost',$port=2947,$dataTypes=array('tpv')) { 
/* Получает данные типа $dataTypes от gpsd
Возвращает плоский (без устройств) массив (объединяя информацию tpv от устройств оптимальным образом), 
с ключами $dataTypes
При неудаче -- массив с ключём 'error'
*/
//error_log("fGPSD.php getPosAndInfoFromGPSD: asking spatial info from gpsd");
$gpsdData = askGPSD($host,$port,$dataTypes);
//echo "<br>getPosAndInfoFromGPSD=<pre>"; print_r($gpsdData); echo "</pre>\n";
if(is_string($gpsdData)) {
    $gpsdData = array('error' => $gpsdData); 	// 
    return $gpsdData;
}
$tpv = array();
if($gpsdData['tpv']){
	foreach($gpsdData['tpv'] as $device) {
		if($device['time'])	$tpv[$device['time']] = $device; 	// с ключём - время
		else $tpv[] = $device; 	// с ключём  - целым.
	};
	krsort($tpv); 	// отсортируем по времени к прошлому
	$gpsdData['tpv'] = $tpv;
	$tpv = array();
	foreach($gpsdData['tpv'] as $device) {
		//echo "<br>device=<pre>"; print_r($device); echo "</pre>\n";
		if($device['mode'] == 3) { 	// последний по времени 3D fix - других координат не надо, но может быть информация от других устройств
			foreach($device as $key => $value){
				if($key == 'track') $key = 'course'; 	// course -- это "путевой угол", "путь", т.е. track
				$tpv[$key] = $value;
			};
		}
		else { 	// просмотрим остальные устройства
			foreach($device as $key => $value){
				if($key == 'track') $key = 'course';
				if(!isset($tpv[$key])) $tpv[$key] = $value;
			};
		};
	};
	if(!isset($tpv['lat']) or !isset($tpv['lon'])){ 	// координат нет, потому что не было ни одного готового устройства
		$tpv = array('error' => 'no fix from any devices'); 	// ничего нет, облом
	};
	$gpsdData['tpv'] = $tpv;
};
//echo "Получены данные getPosAndInfoFromGPSD <pre>"; print_r($gpsdData); echo "</pre>\n";
return $gpsdData;
} // end function getPosAndInfoFromGPSD


function getPosAndInfoFromSignalK($server=array(),$dataTypes=array('tpv')) { 
/* Получает данные типа $dataTypes от SignalK 
Возвращает массив с ключами $dataTypes
При неудаче -- массив с ключём 'error'

Серверы SignslK через какое-то время перестают быть видимыми через zeroconf, хотя, вроде, работают.
Поэтому обнаружение их никак не гарантируется.
*/
global $spatialProvider;
//error_log("fGPSD.php getPosAndInfoFromSignalK: asking spatial info from SignalK");
$serversDirName = sys_get_temp_dir().'/signalK';
$serversName = $serversDirName.'/signalKservers';

if(!file_exists($serversDirName)){ 	// один file_exists быстрей, чем два mkdir и chmod
	mkdir($serversDirName, 0777,true); 	// 
	chmod($serversDirName,0777); 	// права будут только на каталог netAIS. Если он вложенный, то на предыдущие, созданные по true в mkdir, прав не будет. Тогда надо использовать umask.
}
$findServers = null;
if((time()-@filemtime($serversName))>60){
	@unlink($serversName);
}
else {
	$findServers = unserialize(@file_get_contents($serversName));
	$serversCount = @count($findServers);
}
//echo "Read findServers:<pre>"; print_r($findServers); echo "/<pre>\n";

if(!$findServers) {
	if($server) { 	// указали SignalK явно
		$self = json_decode(file_get_contents("http://{$server[0]}:{$server[1]}/signalk/v1/api/self"),TRUE);
		if(substr($self,0,8)=='vessels.') {
			$self = substr($self,9);
			$findServers = array();
			$findServers[$self] = array(	'host' => $server[0], 
									'port' => $server[1],
									'self' => $self
								);
		};
	}
	else {
		//error_log("fGPSD.php getPosAndInfoFromSignalK: search SignalK services");
		$ret = exec('avahi-browse --terminate --resolve --parsable --no-db-lookup _signalk-http._tcp',$signalkDiscovery);
		//error_log("fGPSD.php getPosAndInfoFromSignalK: search SignalK services result: $ret\n");
		//echo"fGPSD.php getPosAndInfoFromSignalK: search SignalK services result: $ret;<br>\n";
		if($ret) {
			$findServers = array();
			foreach($signalkDiscovery as $l){
				if($l[0] != '=') continue;
				$server = array();
				$signalkDiscovery = explode(';',$l);
				$server['host'] = $signalkDiscovery[7];
				$server['port'] = $signalkDiscovery[8];
				$self = explode(' ',$signalkDiscovery[9]);
				foreach($self as $l1){
					if(substr($l1,1,5) == 'self=') { 	// там кавычки
						$selfStr = substr(trim($l1,'"'),5);
						break;
					};
				};
				$server['self'] = $selfStr;
				$findServers[$selfStr] = $server;
			};
		}
		else {
			$self = json_decode(@file_get_contents("http://localhost:3000/signalk/v1/api/self"),TRUE);
			if(substr($self,0,8)=='vessels.') {
				$self = substr($self,9);
				$findServers = array();
				$findServers[$self] = array('host' => $server[0], 
										'port' => $server[1],
										'self' => $self
									);
			};
		};
	};
};
if(!$findServers) {
	$TPV = array('error' => 'no any Signal K resources found'); 	// ничего нет, облом
	return $TPV;
};
//error_log("fGPSD.php getPosAndInfoFromSignalK: SignalK services found!");
//echo "findServers <pre>"; print_r($findServers); echo "</pre><br>\n";
// Серверы обнаружены
$spatialProvider = 'signalk-server';
$TPV = array(); 
$spatialInfo = array();
if(in_array('tpv',$dataTypes)){
	foreach($findServers as $serverID => $server){
		$signalkDiscovery = json_decode(file_get_contents("http://{$server['host']}:{$server['port']}/signalk"),TRUE);
		if(! $signalkDiscovery) { 	// нет сервера, нет связи, и т.п.
			unset($findServers[$serverID]);
			continue;
		}
		//print_r($http_response_header);
		//print_r($signalkDiscovery);
		$APIurl = $signalkDiscovery['endpoints']['v1']['signalk-http'];
		$vessel = json_decode(file_get_contents($APIurl."vessels/{$server['self']}"),TRUE);
		$position = $vessel['navigation'];
		//print_r($position);
		if(! $position) { 	// нет такого ресурса
			unset($findServers[$serverID]);
			continue;
		}
		$timestamp = strtotime($position['position']['timestamp']);
		$TPV = array('time' => $timestamp);
		if($position['position']['value']['longitude'] and $position['position']['value']['latitude']) {
			$TPV['lon'] = $position['position']['value']['longitude']; 	// долгота
			$TPV['lat'] = $position['position']['value']['latitude']; 	// широта
		}
		if($position['courseOverGroundTrue']['value']) $TPV['course'] = $position['courseOverGroundTrue']['value']*180/M_PI; 	// путевой угол, путь, исходно -- в радианах
		if($position['speedOverGround']['value']) $TPV['speed'] = $position['speedOverGround']['value']; 	// скорость m/sec
		if($position['headingTrue']['value']) $TPV['heading'] = $position['headingTrue']['value']*180/M_PI; 	//  курс, исходно -- в радианах
		//echo date(DATE_RFC2822,$timestamp).' '.$position['position']['timestamp'];
		if($vessel['environment']['depth']['belowSurface']) $TPV['depth'] = $vessel['environment']['depth']['belowSurface']['value'];
		elseif($vessel['environment']['depth']['belowTransducer']) $TPV['depth'] = $vessel['environment']['depth']['belowTransducer']['value'];
		$spatialInfo[$timestamp] = $TPV;
	};
	krsort($spatialInfo); 	// отсортируем по времени к прошлому
	$TPV = array();
	$i = 0;
	foreach($spatialInfo as $device){
		if(!$i) $i++;
		if($i) {
			foreach($device as $key => $value){
				if(!isset($TPV[$key])) $TPV[$key] = $value;
			};
		}
		else {
			foreach($device as $key => $value){
				$TPV[$key] = $value;
			};
		};
	};
	$spatialInfo = array();
	$spatialInfo['tpv'] = $TPV;
};
	
if(in_array('ais',$dataTypes)){
	$TPV = array(); 
	foreach($findServers as $serverID => $server){
		$signalkDiscovery = json_decode(file_get_contents("http://{$server['host']}:{$server['port']}/signalk"),TRUE);
		if(! $signalkDiscovery) { 	// нет сервера, нет связи, и т.п.
			unset($findServers[$serverID]);
			continue;
		};
		//print_r($http_response_header);
		//echo "server <pre>"; print_r($server); echo "</pre><br>\n";
		//echo "signalkDiscovery<pre>"; print_r($signalkDiscovery); echo "</pre><br>\n";
		$APIurl = $signalkDiscovery['endpoints']['v1']['signalk-http'];
		//echo "APIurl=$APIurl;<br>\n";
		$vessels = json_decode(file_get_contents($APIurl."vessels/"),TRUE);
		$vesselSelf = json_decode(file_get_contents($APIurl."vessels/{$server['self']}"),TRUE);
		//echo "vessels <pre>"; print_r($vessels); echo "</pre><br>\n";
		foreach($vessels as $vesselID => $vessel){
			//echo "vessel $vesselID<pre>"; print_r($vessel); echo "</pre><br>\n";
			if($vesselID == $server['self']) continue;
			//echo "vessel $vesselID<pre>"; print_r($vessel); echo "</pre><br>\n";
			$TPV[$vesselID]['mmsi'] = $vessel['mmsi'];
			if($vessel['uuid']) $TPV[$vesselID]['uuid'] = $vessel['uuid'];
			if($vessel['name']['value']) $TPV[$vesselID]['shipname'] = $vessel['name']['value'];
			if($vessel['registrations']['imo']['value']) $TPV[$vesselID]['imo'] = $vessel['registrations']['imo']['value'];
			if($vessel['communication']['value']['callsignVhf']) $TPV[$vesselID]['callsign'] = $vessel['communication']['value']['callsignVhf'];
			if($vessel['navigation']['courseOverGroundTrue']['value']) $TPV[$vesselID]['course'] = $vessel['navigation']['courseOverGroundTrue']['value']*180/M_PI;
			if($vessel['navigation']['destination']['commonName']['value']) $TPV[$vesselID]['destination'] = $vessel['navigation']['destination']['commonName']['value'];
			if($vessel['navigation']['destination']['eta']['value']) $TPV[$vesselID]['eta'] = $vessel['navigation']['destination']['eta']['value'];
			if($vessel['navigation']['headingTrue']['value']) $TPV[$vesselID]['heading'] = $vessel['navigation']['headingTrue']['value']*180/M_PI;
			if($vessel['navigation']['position']['value']['longitude']) $TPV[$vesselID]['lon'] = $vessel['navigation']['position']['value']['longitude'];
			if($vessel['navigation']['position']['value']['latitude']) $TPV[$vesselID]['lat'] = $vessel['navigation']['position']['value']['latitude'];
			if($vessel['navigation']['maneuver']['value']) $TPV[$vesselID]['maneuver'] = $vessel['navigation']['maneuver']['value'];
			if($vessel['navigation']['speedOverGround']['value']) $TPV[$vesselID]['speed'] = $vessel['navigation']['speedOverGround']['value'];
			if($vessel['navigation']['state']['value']) $TPV[$vesselID]['status'] = $vessel['navigation']['state']['value'];
			if($vessel['navigation']['datetime']['value']) $TPV[$vesselID]['timestamp'] = strtotime($vessel['navigation']['datetime']['value']);
			if($vessel['design']['aisShipType']['value']['name']) $TPV[$vesselID]['ais_version'] = $vessel['design']['aisShipType']['value']['name'];
			if($vessel['design']['draft']['value']['maximum']) $TPV[$vesselID]['draught'] = $vessel['design']['draft']['value']['maximum'];
			if($vessel['design']['length']['value']['overall']) $TPV[$vesselID]['length'] = $vessel['design']['length']['value']['overall'];
			if($vessel['design']['beam']['value'])$TPV[$vesselID]['beam'] = $vessel['design']['beam']['value'];
			if($vessel['communication']['value']['netAIS'])$TPV[$vesselID]['netAIS'] = TRUE;
			//echo "TPV[$vesselID]<pre>"; print_r($TPV[$vesselID]); echo "</pre><br>\n";
		};
	};
	$spatialInfo['ais'] = $TPV;
};

//echo "Write findServers:"; print_r($findServers); echo "\n";
if($serversCount != count($findServers)){
	file_put_contents($serversName,serialize($findServers));
	@chmod($serversName,0666); 	// если файла не было
}
//echo "spatialInfo<pre>"; print_r($spatialInfo); echo "</pre><br>\n";
return $spatialInfo;
} // end function getPosAndInfoFromSignalK


?>
