<?php
/* netAIS client
Daemon.

Params: -sADDRESS.transport

Клиент к серверу, указанному в параметрах. Получает от сервера состояние подключенных к этому серверу
клиентов, и отдаёт ему своё.
Своё состояние клиент получает от какого-то источника: gpsd, gpsdPROXY, SignalK, etc.
Состояние подключенных к указанному серверу клиентов сторонний юзер может получить из файла,
куда всё записывается, кроме того, оноотдаётся в gpsdPROXY шизоидной коммандой CONNECT.
*/
ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
//ini_set('error_reporting', E_ALL & ~E_STRICT & ~E_DEPRECATED);

chdir(__DIR__); // задаем директорию выполнение скрипта

require_once('fcommon.php'); 	// 
require_once('fGPSD.php'); // fGPSD.php, там есть переменные, которые должны быть глобальным, поэтому здесь
require_once('params.php'); 	// 

$selfStatusFileName = 'server/selfStatus'; 	//  array, 0 - Navigational status, 1 - Navigational status Text. место, где хранится состояние клиента
$selfMOBfileName = $netAISJSONfilesDir.'/selfMOB'; 	//

$greeting = '{"class":"VERSION","release":"netAISclient","rev":"1","proto_major":5,"proto_minor":3}'; 	// приветствие для gpsdPROXY
$SEEN_GPS = 0x01; $SEEN_AIS = 0x08;
$netAISdevice = array(
'class' => 'DEVICE',
'path' => 'netAISclient',
'activated' => date('c'),
'flags' => $SEEN_AIS,
'stopbits' => 1
);
require('params.php'); 	// 
$netAISJSONfilesDir = getAISdFilesNames($netAISJSONfilesDir); 	// определим имя и создадим каталог для данных netAIS

$options = getopt("s:");
//print_r($options); //
$netAISserverURI = @filter_var($options['s'],FILTER_SANITIZE_URL);
if(!$netAISserverURI) {
	echo "Require option:\n-sGroupServer.url\n";
	return;
}

if(IRun($netAISserverURI)) { 	// Я ли?
	echo "I'm already running, exiting.\n"; 
	return;
}
$netAISJSONfileName = $netAISJSONfilesDir.base64_encode($netAISserverURI);
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
$vehicle = getSelfParms(); 	// базовая информация о себе: название, позывные, etc. Плоский список, аналошичный списку сведений AIS
// $statusMOB сперва пусто, потом приходит информация о MOB.
// Тогда в updSelf() $statusMOB читается из файла, и, если пришедшее свежее,
// $statusMOB обновляется пришедшим и записывается в файл.
// После этого непустой $statusMOB отдаётся удалённому серверу
$statusMOB = array();	// сведения о своём MOB в формате объекта MOB gpsdPROXY
$connected = FALSE;
$gpsdPROXYsock = null;
$lastTimestamp = 0;
$MOBsendedToServer = false;	// флаг, что MOB был послан на сервер этого клиента. Позволяет перепослать MOB, если связь с сервером потеряна.
do {
	clearstatcache(TRUE,$selfStatusFileName); 	//
	if($selfStatusTimeOut and ((time() - filemtime($selfStatusFileName)) > $selfStatusTimeOut)) { 	// статус протух. Статус меняется в интерфейсе. Если его долго не дёргать (сутки по умолчанию) -- передача статуса прекращается. И приём, соответственно.
		echo "exchange spatial info stopped by the inactive user reason\n";
		break;
	}

	$netAISdata = array();// Информация, пришедня с сервера, массив mmsi => массив значений AIS.
	// запишем свежую информацию о себе, если там нет координат -- упс.
	// Оно берёт данные от gpsd, SignalK или от VenusOS, и закрывает соединение.
	if(!updSelf()) {  	// изменяет $vehicle
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
	};
	//echo "vehicle to send: "; print_r($vehicle);
	//echo "statusMOB to send: "; print_r($statusMOB);
	$vehicleJSON = json_encode($vehicle,JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
	$moburl = '';
	if($statusMOB){	// имеется свежая информация о своём MOB, её надо передать на сервер.
		//echo "statusMOB to send: "; print_r($statusMOB);
		$statusMOBJSON = json_encode($statusMOB,JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
		$moburl = '&mob='.urlencode($statusMOBJSON);
	};
	$uri = "$netAISserverURI?member=".urlencode($vehicleJSON).$moburl;
	//echo "uri=\n$uri\n";
	// Отошлём всё серверу и получим свежее.
	// На сервер отправляется плоский список сведений AIS одного судна - себя.
	// Кроме того, на сервер могут отправляться данные своего MOB, если updSelf получил таковые:
	// В виде нескольких объектов AIS SART MOB?
	// или в виде одного объекта MOB gpsdPROXY? - !
	// Штатному серверу всё равно.
	// Однако, мы получаем информацию MOB от gpsdPROXY в его же формате, логично в нём же и отдавать
	$ch = curl_init(); 	// tor не http proxy, а file_get_contents не умеет socs. Приходится через жопу. Ой, через cURL. С тех пор tor умеет http proxy.
	curl_setopt($ch, CURLOPT_URL, $uri);
	if(strrpos($uri,'onion')!==false){
		curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME); 	// с разрешением имён через прокси
		curl_setopt($ch, CURLOPT_PROXY, "$torHost:$torPort");
	};
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT,180);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	$netAISdata = curl_exec($ch);	// отсылаем свои и получаем чужие данные netAIS от указанного сервера
	$respCode = curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
	//echo "curl_getinfo: "; print_r(curl_getinfo($ch));
	curl_close($ch);
	//echo "respCode=$respCode; netAISdata=$netAISdata;\n";
	if($respCode != 200) {
		echo "\nNo connect to $netAISserverURI";
		if(strrpos($uri,'onion')!==false) echo " via $torHost:$torPort";
		echo "\n";
		echo "Server return: $netAISdata\n";
		$netAISdata = array();
		$MOBsendedToServer = false;
		goto END;
	}
	$MOBsendedToServer = true;
	$netAISdata = json_decode($netAISdata,TRUE);
	if(!is_array($netAISdata)) {
		echo "\nError on parse JSON from $netAISserverURI\n";
		$netAISdata = array();
		goto END;
	}
	//echo "Recieved data: "; print_r($netAISdata);
	// В полученных данных есть и мои координаты и мой MOB, поэтому удалим их
	unset($netAISdata[$vehicle['mmsi']]); 
	unset($netAISdata['972'.substr($vehicle['mmsi'],3)]); 
	//echo "Recieved without me: ";print_r($netAISdata);
	// От сервера приходит файл с целями AIS: список array('mmsi' => array(плоский список сведений AIS))
	// Кроме того, это может быть MOB, в виде
	// некскольких объектов AIS SART MOB?
	// или одного объекта MOB gpsdPROXY?
	// Поскольку мы сами отсылаем информацию MOB в формате объекта MOB gpsdPROXY,
	// то будем только в нём же и принимать.

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
	foreach($aisData as $veh => $data) {
		if($data['class' == 'MOB']) continue;
		if(($now-$data['timestamp'])>$noVehicleTimeout) unset($aisData[$veh]);
	}
	// запишем свежее в общий файл
	foreach($netAISdata as $veh) {
		updAISdata($veh); 	
	}
	//echo "aisData before writing: <pre>"; print_r($aisData);echo "</pre>\n";
	// Таким образом, $aisData - актуальный файл с целями netAIS в формате 
	// список array('mmsi' => array(плоский список сведений AIS)),
	// плюс сведения о чужих точках MOB в формате array('972mmsi' => array(объекта MOB gpsdPROXY))
	// зальём обратно
	//echo "spatialProvider=$spatialProvider;\n";
	// если у нас есть gpsdPROXY, то мы можем отдать ему данные через сокет, а не через файл.
	if(strpos($spatialProvider,'gpsdPROXY')!==FALSE) {
		//echo "aisData before send to gpsdPROXY: >"; print_r($aisData);
		$connected = uploadTogpsdPROXY($connected);	// fCommon.php
		if(!$connected) goto END;
	};
	// собственно, заливание данных. Из этого файла пусть все берут, хотя gpsdPROXY мы выше отдали сами.
	file_put_contents($netAISJSONfileName,json_encode($aisData),JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE); 	
	@chmod($netAISJSONfileName,0666); 	// если файла не было
	clearstatcache(TRUE,$netAISJSONfileName);
	
	END:
	sleep($poolInterval);
} while(true);
@socket_close($gpsdPROXYsock);	// 
@unlink($netAISJSONfileName); 	// если netAIS выключен -- файл с целями должен быть удалён, иначе эти цели будут показываться вечно
return;


function IRun($netAISserverURI) {
/**/
global $phpCLIexec;
$pid = getmypid();
//echo "pid=$pid\n";
//echo "ps -A w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)." \-s".str_replace(array('[',']'),array('\[','\]'),$netAISserverURI)."'\n";
exec("ps -A w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)." \-s".str_replace(array('[',']'),array('\[','\]'),$netAISserverURI)."'",$psList);
if(!$psList) exec("ps w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)." -s".str_replace(array('[',']'),array('\[','\]'),$netAISserverURI)."'",$psList); 	// for OpenWRT. For others -- let's hope so all run from one user
//echo "[IRun] psList:"; print_r($psList); echo "\n";
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
};
//echo "[IRun] run=$run\n";
return $run;
}


function updSelf() {
/**/
global $vehicle,$statusMOB,$netAISgpsdHost,$netAISgpsdPort,$netAISsignalKhost,$selfStatusFileName,$selfStatusTimeOut,$selfMOBfileName,$lastTimestamp,$MOBsendedToServer; 	// from params.php
if($netAISgpsdHost) $host = $netAISgpsdHost;
else $host = $netAISsignalKhost;

// $status - собственное текущее состояние, как указано в web-интерфейсе: Navigational status и прочее.
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

// $vehicle - текущая информация о себе как о цели AIS, включая координаты
$vehicle['status'] = (int)$status['status']; 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
$vehicle['status_text'] = $status['description'];
$vehicle['destination'] = $status['destination'];
$vehicle['eta'] = $status['eta'];
$vehicle['safety_related_text'] = $status['safety_related_text'];
//echo "Координаты от $host:$netAISgpsdPort;\n";
$spatialInfo = getPosAndInfo($host,$netAISgpsdPort,array('tpv','mob')); 	// fGPSD.php, там понимают массив в адресе
//echo "[updSelf] spatialInfo:";print_r($spatialInfo);echo "\n";
if(isset($TPV['error'])) {
	echo "Get spatialInfo error:".$spatialInfo['error']."\n";
	return FALSE;
};
if($spatialInfo['tpv']){
	$TPV = $spatialInfo['tpv'];
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
	$vehicle['timestamp'] = $TPV['timestamp'];
	if(!$vehicle['timestamp']) $vehicle['timestamp'] = time();
};
if($spatialInfo['mob']){
	$statusMOB = unserialize(@file_get_contents($selfMOBfileName)); 	// считаем файл MOB, которого может не быть
	//echo "[updSelf] spatialInfo MOB:";print_r($spatialInfo['mob']);echo "\n";
	//echo "[updSelf] statusMOB:"; print_r($statusMOB); echo "\n";
	//echo "[updSelf] statusMOB['timestamp']={$statusMOB['timestamp']}; spatialInfo['mob']['timestamp']={$spatialInfo['mob']['timestamp']}; lastTimestamp=$lastTimestamp;\n";
	if($statusMOB['timestamp']<$spatialInfo['mob']['timestamp']){	// имеется свежее
		$statusMOB = $spatialInfo['mob'];	// там же нет ничего интересного, такого, что не пришло сейчас?
		if(!$statusMOB['source']) $statusMOB['source'] = '972'.substr($vehicle['mmsi'],3);
		file_put_contents($selfMOBfileName,serialize($statusMOB)); 	// сохраним статус MOB
		@chmod($netAISJSONfileName,0666);	// это один файл на всех
		$lastTimestamp = $statusMOB['timestamp'];
	}
	// Таким образом изменение MOB от пришедщих данных или web-интерфейса будет
	// послано на сервер только один раз.
	elseif($statusMOB['timestamp']==$lastTimestamp) {	// т.е., не имеется свежей информации о своём MOB
		file_put_contents($selfMOBfileName,serialize($statusMOB)); 	// сохраним статус MOB - вдруг он другой со старой отметкой времени?
		@chmod($netAISJSONfileName,0666);	// это один файл на всех
		// укажем, что ничего нового нет
		// Это сделано для того, чтобы другие могли прекратить у себя чужой MOB, по крайней мере,
		// до его изменения.
		// Однако, если связи с сервером нет, а тем временем возник MOB - на сервер он не передастся.
		if($MOBsendedToServer) $statusMOB = array();	
		// если старая информация о MOB совсем старая, вообще удалим файл с этой информацией.
		// Потому что информация о MOB могла просто перестать передаваться, как это бывает с AIS SART.
		if($statusMOB['timestamp']>(time()-60*60*24)){
			$statusMOB = array();
			unlink($selfMOBfileName);	
		};
	}
	//else $statusMOB = array();	// т.е., не имеется свежей информации о своём MOB
	else $lastTimestamp = $statusMOB['timestamp'];	
};
return TRUE;
}; // end function updSelf

function updAISdata($vehicleInfo) {
/*
global $vehicle - это собственное судно в формате AIS
*/
global $aisData,$vehicle;
$mmsi = @$vehicleInfo['mmsi'];
//echo "[updAISdata] mmsi=$mmsi; vehicleInfo:"; print_r($vehicleInfo); echo "\n";
if($mmsi){	// это обычное судно
	foreach($vehicleInfo as $opt => $value) {
		$aisData[$mmsi][$opt] = $value; 	// 
	};
}
else {	
	// это сообщение MOB, причём не свое, а от другого судна. Однако, это может быть
	// чужое сообщение MOB, инициированное нашим сообщением MOB
	$mmsi = @$vehicleInfo['source'];
	if(!$mmsi) return; 	// оно может быть пустое
	// Возможно, там есть информация о нас же откуда-то со стороны
	$info = array();
	//echo "[updAISdata] aisData[$mmsi]:"; print_r($aisData[$mmsi]); echo "\n";
	foreach($vehicleInfo as $opt => $value) {
		//echo "[updAISdata] opt=$opt; value:"; print_r($value); echo "\n";
		if($opt == 'points'){
			foreach($value as $i => $point){
				if($point['mmsi'] == $vehicle['mmsi']) unset($value[$i]);	// если эта точка поставлена нами - проигнорируем её, вне зависимости от отметки времени.
			};
		};
		$aisData[$mmsi][$opt] = $value; 	// 
	};
	// После удаления своих точек из объекта MOB других точек там не оказалось, поэтому
	// просто удаляем этот объект MOB.
	if(!count($aisData[$mmsi]['points'])) unset($aisData[$mmsi]);
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

