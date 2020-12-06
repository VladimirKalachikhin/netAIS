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

$sleepTime = 5;
$serverPath = '/netAISserver.php';
require('fcommon.php'); 	// 
require('params.php'); 	// 
getAISdFilesNames();

$options = getopt("s:");
//print_r($options); //
$netAISserverURI = filter_var($options['s'],FILTER_SANITIZE_URL);
if(!$netAISserverURI) {
	echo "Option:\n-sGroupServer.onion\n";
}

if(IRun($netAISserverURI)) { 	// Я ли?
	echo "I'm already running, exiting.\n"; 
	return;
}
if(substr($netAISserverURI,-6) == '.onion') $netAISserverURI .= $serverPath;

$vehicle = getSelfParms(); 	// базовая информация о себе
do {
	clearstatcache(TRUE,$selfStatusFileName); 	// from params.php
	if((time() - filemtime($selfStatusFileName)) > $selfStatusTimeOut) break; 	// статус протух

	updSelf($vehicle); 	// запишем свежую информацию о себе
	//echo "vehicle: "; print_r($vehicle);
	$vehicleJSON = json_encode($vehicle);
	$uri = "$netAISserverURI?member=".urlencode($vehicleJSON);
	//echo $uri;
	// Отошлём всё серверу и получим свежее
	$ch = curl_init(); 	// tor не http proxy, а file_get_contents не умеет socs. Приходится через жопу. Ой, через cURL.
	curl_setopt($ch, CURLOPT_URL, $uri);
	curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME); 	// с разрешением имён через прокси
	curl_setopt($ch, CURLOPT_PROXY, "127.0.0.1:$torPort");
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT,30);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	$netAISdata = curl_exec($ch);
	$respCode = curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
	//echo "curl_getinfo: "; print_r(curl_getinfo($ch));
	curl_close($ch);
	//echo "respCode=$respCode;\n";
	if($respCode != 200) {
		echo "No connect to $netAISserverURI\n";
		echo "Server return: $netAISdata\n";
		$netAISdata = array();
		goto END;
	}
	$netAISdata = json_decode($netAISdata,TRUE);
	if(!is_array($netAISdata)) {
		echo "Error on connect to $netAISserverURI\n";
		echo "Server return: $netAISdata\n";
		$netAISdata = array();
		goto END;
	}
	//echo "Recieved data: "; print_r($netAISdata);
	// Там я тоже, поэтому удалим
	unset($netAISdata[$vehicle['mmsi']]); 
	//echo "Recieved without me: ";print_r($netAISdata);

	END:
	// Возьмём файл с целями netAIS
	//echo "netAISJSONfileName=$netAISJSONfileName;\n";
	clearstatcache(TRUE,$netAISJSONfileName);
	if(file_exists($netAISJSONfileName)) {
		$aisData = json_decode(file_get_contents($netAISJSONfileName),TRUE); 	// 
	}
	else {
		echo "netAISJSONfileName don't exist \n";
		$aisData = array();
	}
	//echo "aisData from file: "; print_r($aisData);
	// запишем свежее в общий файл
	foreach($netAISdata as $veh) {
		updAISdata($veh); 	
	}
	//print_r($aisData);
	// Почистим общий файл от старых целей. Нормально это делает сервер, но связи с сервером может не быть
	$now = time();
	foreach($aisData as $veh => &$data) {
		if(($now-$data['timestamp'])>$noVehicleTimeout) unset($aisData[$veh]);
	}
	//echo "aisData before writing: <pre>"; print_r($aisData);echo "</pre>\n";
	// зальём обратно
	file_put_contents($netAISJSONfileName,json_encode($aisData)); 	// 
	@chmod($netAISJSONfileName,0666); 	// если файла не было
	clearstatcache(TRUE,$netAISJSONfileName);
	
	sleep($sleepTime);
} while(1);
return;


function IRun($netAISserverURI) {
/**/
global $phpCLIexec;
$pid = getmypid();
//echo "pid=$pid\n";
//echo "ps -A w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)." -s$netAISserverURI'\n";
exec("ps -A w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)." -s$netAISserverURI'",$psList);
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
if(!$vehicle['mmsi']) $vehicle['mmsi'] = $vehicle['shipname'];
return $vehicle;
}

function updSelf(&$vehicle) {
/**/
global $netAISgpsdHost,$netAISgpsdPort,$selfStatusFileName,$selfStatusTimeOut; 	// from params.php
if(!$netAISgpsdHost) $netAISgpsdHost = 'localhost';
if(!$netAISgpsdPort) $netAISgpsdPort = 2947;

clearstatcache(TRUE,$selfStatusFileName);
//echo "filemtime=".filemtime($selfStatusFileName)."; selfStatusTimeOut=$selfStatusTimeOut;\n";
if((time() - filemtime($selfStatusFileName)) > $selfStatusTimeOut) $status = array(); 	// статус протух
else $status = unserialize(file_get_contents($selfStatusFileName)); 	// считаем файл состояния
if(!$status) {
	$status = array();
	$status[0]=15; 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
	$status[1]='';
}
//echo "status: <pre>"; print_r($status);echo "</pre>\n";
$vehicle['status'] = (int)$status[0]; 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
$vehicle['status_text'] = $status[1];
$TPV = getPosAndInfo($netAISgpsdHost,$netAISgpsdPort); 	// fGPSD.php
//print_r($TPV);
if(! isset($TPV['error'])) {
	$vehicle['speed'] = (float)$TPV['velocity']; 	// SOG Speed over ground in m/sec
	if(($TPV['errX'] and $TPV['errX']<10) and ($TPV['errY'] and $TPV['errY']<10)) $accuracy = 1;
	else $accuracy = 0;
	$vehicle['accuracy'] = $accuracy; 	// Position accuracy The position accuracy (PA) flag should be determined in accordance with Table 50 1 = high (£ 10 m) 0 = low (>10 m) 0 = default
	$vehicle['lon'] = (float)$TPV['lon']; 	// Longitude in degrees
	$vehicle['lat'] = (float)$TPV['lat']; 	// Latitude in degrees
	$vehicle['course'] = (int)$TPV['course']; 	// COG Course over ground in degrees ( 1/10 = (0-3 599). 3 600 (E10h) = not available = default. 3 601-4 095 should not be used)
	if(!$vehicle['course']) (int)$vehicle['course'] = $TPV['heading'];
	$vehicle['heading'] = $TPV['heading']; 	// True heading Degrees (0-359) (511 indicates not available = default)
	$vehicle['timestamp'] = time();
}
else echo "Get TPV error:".$TPV['error']."\n";
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

?>

