<?php
/* netAIS client
Daemon.
Ask netAIS server from params.php, send to self info, get other,
and put it to the gpsdAISd-like your own AIS info file.
GaladrielMap askAIS.php read this file and combine with original gpsdAISd file. So all
AIS targets are viewing.

Params: -sADDRESS.onion

*/
ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
//ini_set('error_reporting', E_ALL & ~E_STRICT & ~E_DEPRECATED);

chdir(__DIR__); // задаем директорию выполнение скрипта

require_once('fGPSD.php'); // fGPSD.php, там есть переменные, которые должны быть глобальным, поэтому здесь
require_once('fcommon.php'); 	// 

$sleepTime = 5;
$greeting = '{"class":"VERSION","release":"netAISclient","rev":"1","proto_major":5,"proto_minor":3}'; 	// приветствие для gpsdPROXY
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
/* Оказалось, что дочерний процесс тоже убивается при смерте родительского. Я полагал, 
что при & -- нет. Чтобы не убивался, нужно nohup command &
В результате при самоубийстве netAISclient по неактивности юзера будет убит gpsdPROXY, если 
он был запущен отсюда.
Но это фигня какая-то....
*/
// start gpsdPROXY
// запускаем только здесь, потому что в index.php запуск клиента кладётся в cron,
// и если что -- оно запустится и запустит $gpsdPROXYname здесь.
if($gpsdPROXYname){
	exec("$phpCLIexec $gpsdPROXYname > /dev/null 2>&1 &");
}
$vehicle = getSelfParms(); 	// базовая информация о себе: название, позывные, etc
$connected = FALSE;
do {
	clearstatcache(TRUE,$selfStatusFileName); 	// from params.php
	if($selfStatusTimeOut and ((time() - filemtime($selfStatusFileName)) > $selfStatusTimeOut)) { 	// статус протух. Статус меняется в интерфейсе. Если его долго не дёргать (сутки по умолчанию) -- передача статуса прекращается. И приём, соответственно.
		echo "exchange spatial info stopped by the inactive user reason\n";
		break;
	}

	$netAISdata = array();
	if(!updSelf($vehicle)) {  	// запишем свежую информацию о себе, если там нет координат -- упс.
		echo "\nFailed to update self info - no gpsd? Will wait... \n";
		/*
		if($gpsdPROXYname){	// start gpsdPROXY
			echo "try to start gpsdPROXY $phpCLIexec $gpsdPROXYname\n";
			exec("$phpCLIexec $gpsdPROXYname > /dev/null 2>&1 &");
			goto END;	// будем пытаться вечно запустить gpsdPROXY
		}
		else break;
		*/
		// А оно надо -- убиваться? Скорее всего, источник данных появится...
		//break;
		goto END;
	}
	//echo "vehicle: "; print_r($vehicle);
	$vehicleJSON = json_encode($vehicle);
	$uri = "$netAISserverURI?member=".urlencode($vehicleJSON);
	//echo $uri;
	// Отошлём всё серверу и получим свежее
	$ch = curl_init(); 	// tor не http proxy, а file_get_contents не умеет socs. Приходится через жопу. Ой, через cURL. С тех пор tor умеет http proxy.
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
		echo "\nNo connect to $netAISserverURI via $torHost:$torPort\n";
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
	//echo "aisData from file: "; print_r($aisData);
	// Почистим общий файл от старых целей. Нормально это делает сервер, но связи с сервером может не быть
	$now = time();
	foreach($aisData as $veh => &$data) {
		if(($now-$data['timestamp'])>$noVehicleTimeout) unset($aisData[$veh]);
	}
	// запишем свежее в общий файл
	foreach($netAISdata as $veh) {
		updAISdata($veh); 	
	}
	//echo "aisData before writing: <pre>"; print_r($aisData);echo "</pre>\n";
	// зальём обратно
	//echo "spatialProvider=$spatialProvider;\n";
	if(strpos($spatialProvider,'gpsdPROXY')!==FALSE) { 	//
		if(!$connected) {
			$gpsdPROXYsock = createSocketClient($netAISgpsdHost,$netAISgpsdPort); 	// Соединение с gpsdPROXY
			//echo "\ngpsdPROXYsock=$gpsdPROXYsock;\n"; var_dump($gpsdPROXYsock);
			if($gpsdPROXYsock === FALSE) { 	// клиент умер
				$connected = FALSE;
				echo "\nFailed to connect to gpsd, will wait. \n";
				/*
				if($gpsdPROXYname){	// start gpsdPROXY
					echo "try to connect to gpsdPROXY \n";
					exec("$phpCLIexec $gpsdPROXYname > /dev/null 2>&1 &");
					goto END;	// будем пытаться вечно запустить gpsdPROXY
				}
				else break;
				*/
				// А оно надо -- убиваться? Скорее всего, источник данных появится...
				//break;
				goto END;
			}
			$res = socket_write($gpsdPROXYsock, "\n\n", 2);	// gpsgPROXY не вернёт greeting, если не получит что-то. Ну, так получилось
			$buf = socket_read($gpsdPROXYsock, 2048, PHP_NORMAL_READ); 	// читаем VERSION, PHP_NORMAL_READ -- ждать \n
			//echo "buf: |$buf|\n";

			$msg = "?CONNECT;\n"; 	// ?CONNECT={"host":"","port":""};
			//echo "Send CONNECT\n";
			$res = socket_write($gpsdPROXYsock, $msg, strlen($msg)); 	// шлём команду подключиться к нам как к gpsd
			// handshaking as some gpsd
			$msg = "$greeting\n"; 	// 
			//echo "Send greeting\n";
			$res = socket_write($gpsdPROXYsock, $msg, strlen($msg)); 	// шлём приветствие
			$zeroCount = 0;	// счётчик пустых строк
			do{		// 
				$buf = socket_read($gpsdPROXYsock, 2048, PHP_NORMAL_READ); 	// PHP_NORMAL_READ -- ждать \n
				//echo "buf: |$buf|\n";
				if($buf === FALSE) {
					$connected = FALSE;
					echo "\nBroke socket $gpsdPROXYsock during handshaking \n";
					break;
				}
				if(!$buf=trim($buf)) {	// пустые строки
					$zeroCount++;
					continue;
				}
				if($buf[0]!='?') { 	// это не команда протокола gpsd
					$zeroCount++;
					continue;
				}
				$buf = rtrim(substr($buf,1),';');	// ? ;
				list($command,$params) = explode('=',$buf);
				$params = trim($params);
				//echo "\nClient command=$command; params=$params;\n";
				if($params) $params = json_decode($params,TRUE);
				switch($command){
				case 'WATCH':
					$msg = json_encode(array('class' => 'DEVICES', 'devices' => array($netAISdevice)));
					$msg .= "\n";
					//echo "Send DEVICES $msg \n";
					$res = socket_write($gpsdPROXYsock, $msg, strlen($msg)); 	// шлём DEVICES
	
					$msg = '{"class":"WATCH","enable":true,"json":true,"nmea":false,"raw":0,"scaled":true,"split24":true,"timing":false,"pps":false,"device":"'.$netAISdevice['path'].'","remote":"'.$netAISdevice['path'].'.php"}';
					$msg .= "\n";
					//echo "Send WATCH\n";
					$res = socket_write($gpsdPROXYsock, $msg, strlen($msg)); 	// шлём WATCH
					break;
				}
				$connected = TRUE;
				break;
			}while($zeroCount<10);
		}
		if($connected) {
			//echo "handshaking as gpsd success, will send data\n";
			//echo "aisData before send to gpsdPROXY: >"; print_r($aisData);
			$msg = json_encode(array('class'=>'netAIS','device'=>$netAISdevice['path'],'data'=>$aisData));
			$msg .= "\n";
			$res = @socket_write($gpsdPROXYsock, $msg, strlen($msg)); 	// шлём данные
			if($res === FALSE) { 	// клиент умер
				socket_close($gpsdPROXYsock);	// 
				$connected = FALSE;
				echo "\nFailed to write data to gpsdPROXY socket by: " . @socket_strerror(socket_last_error($gpsdPROXYsock)) . "\n";
				/*
				echo "try to start gpsdPROXY $phpCLIexec $gpsdPROXYname\n";
				exec("$phpCLIexec $gpsdPROXYname > /dev/null 2>&1 &");
				goto END;	// будем пытаться вечно запустить gpsdPROXY
				*/
				// А оно надо -- убиваться? Скорее всего, источник данных появится...
				//break;
				goto END;
			}
		}
	}
	file_put_contents($netAISJSONfileName,json_encode($aisData)); 	// собственно, заливание данных 
	@chmod($netAISJSONfileName,0666); 	// если файла не было
	clearstatcache(TRUE,$netAISJSONfileName);
	
	END:
	sleep($sleepTime);
} while(1);
@socket_close($gpsdPROXYsock);	// 
@unlink($netAISJSONfileName); 	// если netAIS выключен -- файл с целями должен быть удалён, иначе эти цели будут показываться вечно
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
//		case $phpCLIexec:	// авотхрен. В docker image  thecodingmachine/docker-images-php $phpCLIexec===php, но реально запускается /usr/bin/real_php
//			$run=TRUE;
//			break 3;
		default:
			if(strpos($w,'php')!==FALSE){
				$run=TRUE;
				break 3;
			}
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
	$status['status']=15; 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
	$status['description']='';
	$status['destination']=''; 	// 
	$status['eta']='';
	$status['safety_related_text']='';
}
//echo "status: <pre>"; print_r($status);echo "</pre>\n";
$vehicle['status'] = (int)$status['status']; 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
$vehicle['status_text'] = $status['description'];
$vehicle['destination'] = $status['destination'];
$vehicle['eta'] = $status['eta'];
$vehicle['safety_related_text'] = $status['safety_related_text'];
//echo "Координаты от $host:$netAISgpsdPort;\n";
$TPV = getPosAndInfo($host,$netAISgpsdPort); 	// fGPSD.php, там понимают массив в адресе
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
	if(!$vehicle['heading']) $vehicle['heading'] = $vehicle['course'];
	$vehicle['timestamp'] = time();
	return TRUE;
}
else {
	echo "Get TPV error:".$TPV['error']."\n";
	return FALSE;
}
} // end function updSelf

function updAISdata($vehicleInfo) {
/**/
global $aisData;
$vehicle = @$vehicleInfo['mmsi'];
if(!$vehicle) return; 	// оно может быть пустое
foreach($vehicleInfo as $opt => $value) {
	$aisData[$vehicle][$opt] = $value; 	// 
};
};	// end function updAISdata


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
echo "Connected to $host:$port \n";
return $sock;
} // end function createSocketClient

?>

