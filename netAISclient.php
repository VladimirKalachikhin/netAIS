<?php
/* netAIS client
Ask netAIS server from params.php, send to self info, get other,
and put it to the gpsdAISd-like your own AIS info file.
GaladrielMap askAIS.php read this file and combine with original gpsdAISd file. So all
AIS targets are viewing.

Params: -sADDRESS.onion

*/
$path_parts = pathinfo(__FILE__); // определяем каталог скрипта
chdir($path_parts['dirname']); // задаем директорию выполнение скрипта

require_once('fGPSD.php'); // fGPSD.php, там есть переменные, которые должны быть глобальным, поэтому здесь
require_once('fcommon.php'); 	// 

$sleepTime = 5;
$greeting = '{"class":"VERSION","release":"netAISclient_1","rev":"5","proto_major":5,"proto_minor":1}'; 	// приветствие для gpsdPROXY
$SEEN_AIS = 0x08;
$netAISdevice = array(
'class' => 'DEVICE',
'path' => 'netAISclient',
'activated' => date('c'),
'flags' => $SEEN_AIS,
'stopbits' => 1
);
//$serverPath = '/netAISserver.php';
$serverPath = '/'; 	// ссылка названа index.php, и совместимость с SignalK версией. И вообще -- пусть имя сервера будет любым
require('params.php'); 	// 
$netAISJSONfilesDir = getAISdFilesNames($netAISJSONfilesDir); 	// определим имя и создадим каталог для данных netAIS

$options = getopt("s:");
//print_r($options); //
$netAISserverURI = @filter_var($options['s'],FILTER_SANITIZE_URL);
if(!$netAISserverURI) {
	echo "Require option:\n-sGroupServer.onion\n";
	return;
}

if(IRun($netAISserverURI)) { 	// Я ли?
	echo "I'm already running, exiting.\n"; 
	return;
}
$netAISJSONfileName = $netAISJSONfilesDir.$netAISserverURI;
if(substr($netAISserverURI,-6) == '.onion') $netAISserverURI .= $serverPath;
$spatialProvider = NULL; 	// строка идентификации источника координат

$vehicle = getSelfParms(); 	// базовая информация о себе
do {
	clearstatcache(TRUE,$selfStatusFileName); 	// from params.php
	if($selfStatusTimeOut and ((time() - filemtime($selfStatusFileName)) > $selfStatusTimeOut)) { 	// статус протух. Статус меняется в интерфейсе. Если его долго не дёргать (сутки по умолчанию) -- передача статуса прекращается. И приём, соответственно.
		error_log("exchange spatial info stopped by the inactive user reason");
		break;
	}

	$netAISdata = array();
	if(!updSelf($vehicle)) goto END;  	// запишем свежую информацию о себе, если там нет координат -- упс.
	//echo "vehicle: "; print_r($vehicle);
	$vehicleJSON = json_encode($vehicle);
	$uri = "$netAISserverURI?member=".urlencode($vehicleJSON);
	//echo $uri;
	// Отошлём всё серверу и получим свежее
	$ch = curl_init(); 	// tor не http proxy, а file_get_contents не умеет socs. Приходится через жопу. Ой, через cURL.
	curl_setopt($ch, CURLOPT_URL, $uri);
	curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME); 	// с разрешением имён через прокси
	curl_setopt($ch, CURLOPT_PROXY, "$torHost:$torPort");
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT,180);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	$netAISdata = curl_exec($ch);
	$respCode = curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
	//echo "curl_getinfo: "; print_r(curl_getinfo($ch));
	curl_close($ch);
	//echo "respCode=$respCode;\n";
	if($respCode != 200) {
		echo "\nNo connect to $netAISserverURI $torHost:$torPort\n";
		echo "Server return: $netAISdata\n";
		$netAISdata = array();
		goto END;
	}
	$netAISdata = json_decode($netAISdata,TRUE);
	if(!is_array($netAISdata)) {
		echo "\nError on connect to $netAISserverURI\n";
		echo "Server return: $netAISdata\n";
		$netAISdata = array();
		goto END;
	}
	//echo "Recieved data: "; print_r($netAISdata);
	// Там я тоже, поэтому удалим
	unset($netAISdata[$vehicle['mmsi']]); 
	//echo "Recieved without me: ";print_r($netAISdata);
	//echo "aisData from file: "; print_r($aisData);

	END:
	// Возьмём файл с целями netAIS
	//echo "netAISJSONfileName=$netAISJSONfileName;\n";
	clearstatcache(TRUE,$netAISJSONfileName);
	if(file_exists($netAISJSONfileName)) {
		$aisData = json_decode(file_get_contents($netAISJSONfileName),TRUE); 	// 
	}
	else {
		echo "no netAIS targets, $netAISJSONfileName don't exist, try to create new \n";
		$aisData = array();
	}
	//print_r($aisData);
	// Почистим общий файл от старых целей. Нормально это делает сервер, но связи с сервером может не быть
	$now = time();
	foreach($aisData as $veh => &$data) {
		if(($now-$data['timestamp'])>$noVehicleTimeout) unset($aisData[$veh]);
	}
	//echo "aisData before writing: <pre>"; print_r($aisData);echo "</pre>\n";
	// запишем свежее в общий файл
	foreach($netAISdata as $veh) {
		updAISdata($veh); 	
	}
	// зальём обратно
	if(strpos($spatialProvider,'gpsdPROXY')!==FALSE) { 	//
		//echo "\nОтдадим gpsdPROXY\n";
		do{
			$gpsdPROXYsock = createSocketClient($netAISgpsdHost,$netAISgpsdPort); 	// Соединение с gpsdPROXY
			if($gpsdPROXYsock === FALSE) { 	// клиент умер
				echo "\nFailed to connect to gpsdPROXY\n";
				break;
			}
			$msg = "?CONNECT;\n"; 	// ?CONNECT={"host":"","port":""};
			$res = socket_write($gpsdPROXYsock, $msg, strlen($msg)); 	// шлём команду подключиться к нам как к gpsd
			if($res === FALSE) { 	// клиент умер
				echo "\nFailed to write ?CONNECT to gpsdPROXY socket by: " . socket_strerror(socket_last_error($gpsdPROXYsock)) . "\n";
				break;
			}
			// handshaking as some gpsd
			$msg = "$greeting\n"; 	// 
			$res = socket_write($gpsdPROXYsock, $msg, strlen($msg)); 	// шлём приветствие
			if($res === FALSE) { 	// клиент умер
				echo "\nFailed to write VERSION to gpsdPROXY socket by: " . socket_strerror(socket_last_error($gpsdPROXYsock)) . "\n";
				break;
			}
			$buf = '';
			$buf = socket_read($gpsdPROXYsock, 2048, PHP_NORMAL_READ); 	// читаем WATCH, PHP_NORMAL_READ -- ждать \n
			if($buf === FALSE) { 	// клиент умер
				echo "\nFailed to read WATCH to gpsdPROXY socket by: " . socket_strerror(socket_last_error($gpsdPROXYsock)) . "\n";
				break;
			}
			$msg = json_encode(array('class' => 'DEVICES', 'devices' => array($netAISdevice)));
			$msg .= "\n";
			$res = socket_write($gpsdPROXYsock, $msg, strlen($msg)); 	// шлём DEVICES
			if($res === FALSE) { 	// клиент умер
				echo "\nFailed to write DEVICES to gpsdPROXY socket by: " . socket_strerror(socket_last_error($gpsdPROXYsock)) . "\n";
				break;
			}
			$msg = '{"class":"WATCH","enable":true,"json":true,"nmea":false,"raw":0,"scaled":true,"split24":true,"timing":false,"pps":false,"device":"'.$netAISdevice['path'].'","remote":"'.$netAISdevice['path'].'.php"}';
			$msg .= "\n";
			$res = socket_write($gpsdPROXYsock, $msg, strlen($msg)); 	// шлём WATCH
			if($res === FALSE) { 	// клиент умер
				echo "\nFailed to write WATCH to gpsdPROXY socket by: " . socket_strerror(socket_last_error($gpsdPROXYsock)) . "\n";
				break;
			}
			// handshaking success, will send data
			//echo "aisData before send to gpsdPROXY: <pre>"; print_r($aisData);echo "</pre>\n";
			$msg = json_encode(array('class'=>'netAIS','device'=>$netAISdevice['path'],'data'=>$aisData));
			$msg .= "\n";
			$res = socket_write($gpsdPROXYsock, $msg, strlen($msg)); 	// шлём данные
			socket_close($gpsdPROXYsock);
		}while(FALSE);
	}
	file_put_contents($netAISJSONfileName,json_encode($aisData)); 	// 
	@chmod($netAISJSONfileName,0666); 	// если файла не было
	clearstatcache(TRUE,$netAISJSONfileName);
	
	sleep($sleepTime);
} while(1);
unlink($netAISJSONfileName); 	// если netAIS выключен -- файл с целями должен быть удалён, иначе эти цели будут показываться вечно
return;


function IRun($netAISserverURI) {
/**/
global $phpCLIexec;
$pid = getmypid();
//echo "pid=$pid\n";
//echo "ps -A w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)." -s$netAISserverURI'\n";
exec("ps -A w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)." -s$netAISserverURI'",$psList);
if(!$psList) exec("ps w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)." -s$netAISserverURI'",$psList); 	// for OpenWRT. For others -- let's hope so all run from one user
//print_r($psList); //
$run = FALSE;
foreach($psList as $str) {
	if(strpos($str,(string)$pid)!==FALSE) continue;
	$str = explode(' ',trim($str)); 	// массив слов
	foreach($str as $w) {
		switch($w){
		case 'watch':
		case 'ps':
		case 'grep':
		case 'sh':
		case 'bash': 	// если встретилось это слово -- это не та строка
			break 2;
		case $phpCLIexec:
			$run=TRUE;
			break 3;
		}
	}
}
return $run;
}


function getSelfParms() {
/**/
$vehicle = parse_ini_file('boatInfo.ini',FALSE,INI_SCANNER_TYPED);
if(!$vehicle['mmsi']) $vehicle['mmsi'] = str_pad(substr(crc32($vehicle['shipname']),0,9),9,'0'); 	// левый mmsi, похожий на настоящий -- для тупых, кому не всё равно (SignalK, к примеру)
return $vehicle;
}

function updSelf(&$vehicle) {
/**/
global $netAISgpsdHost,$netAISgpsdPort,$netAISsignalKhost,$selfStatusFileName,$selfStatusTimeOut; 	// from params.php
if($netAISgpsdHost) $host = $netAISgpsdHost;
else $host = $netAISsignalKhost;

clearstatcache(TRUE,$selfStatusFileName);
//echo "filemtime=".filemtime($selfStatusFileName)."; selfStatusTimeOut=$selfStatusTimeOut;\n";
if($selfStatusTimeOut and ((time() - filemtime($selfStatusFileName)) > $selfStatusTimeOut)) $status = array(); 	// статус протух
else $status = unserialize(@file_get_contents($selfStatusFileName)); 	// считаем файл состояния, которого может не быть
if(!$status) {
	$status = array();
	$status[0]=15; 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
	$status[1]='';
}
//echo "status: <pre>"; print_r($status);echo "</pre>\n";
$vehicle['status'] = (int)$status[0]; 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
$vehicle['status_text'] = $status[1];
$TPV = getPosAndInfo($host,$netAISgpsdPort); 	// fGPSD.php
//echo "TPV:";print_r($TPV);echo "\n";
if($TPV and (! isset($TPV['error']))) {
	$vehicle['speed'] = (float)$TPV['speed']; 	// SOG Speed over ground in m/sec
	if(($TPV['errX'] and $TPV['errX']<10) and ($TPV['errY'] and $TPV['errY']<10)) $accuracy = 1;
	else $accuracy = 0;
	$vehicle['accuracy'] = $accuracy; 	// Position accuracy The position accuracy (PA) flag should be determined in accordance with Table 50 1 = high (£ 10 m) 0 = low (>10 m) 0 = default
	$vehicle['lon'] = (float)$TPV['lon']; 	// Longitude in degrees
	$vehicle['lat'] = (float)$TPV['lat']; 	// Latitude in degrees
	$vehicle['course'] = (int)@$TPV['course']; 	// COG Course over ground in degrees ( 1/10 = (0-3 599). 3 600 (E10h) = not available = default. 3 601-4 095 should not be used)
	if(!$vehicle['course']) (int)$vehicle['course'] = $TPV['heading'];
	$vehicle['heading'] = $TPV['heading']; 	// True heading Degrees (0-359) (511 indicates not available = default)
	$vehicle['timestamp'] = time();
	return TRUE;
}
else echo "Get TPV error:".$TPV['error']."\n";
	return FALSE;
} // end function updSelf

function updAISdata($vehicleInfo) {
/**/
global $aisData;
$vehicle = @$vehicleInfo['mmsi'];
if(!$vehicle) return; 	// оно может быть пустое
foreach($vehicleInfo as $opt => $value) {
	$aisData[$vehicle][$opt] = $value; 	// 
}
}


function createSocketClient($host,$port){
/* создаёт сокет, соединенный с $host,$port на другом компьютере */
$sock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if(!$sock) {
	echo "Failed to create client socket by reason: " . socket_strerror(socket_last_error()) . "\n";
	return FALSE;
}
if(! @socket_connect($sock,$host,$port)){ 	// подключаемся к серверу
	echo "Failed to connect to remote server $host:$port by reason: " . socket_strerror(socket_last_error()) . "\n";
	return FALSE;
}
//echo "Connected to $host:$port!\n";
//$res = socket_write($socket, "\n");
return $sock;
} // end function createSocketClient

?>

