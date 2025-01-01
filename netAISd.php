<?php
/* Демон.
Отдаёт данные netAIS как поток обычных данных AIS:
$ nc localhost 3800
$ telnet localhost 3800

Умеет также общаться по протоколу gpsd:
$ cgps localhost:3800
$ telnet localhost 3800
*/
ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
//ini_set('error_reporting', E_ALL & ~E_STRICT & ~E_DEPRECATED);
chdir(__DIR__); // задаем директорию выполнение скрипта

require('fcommon.php'); 	// 
require('params.php'); 	// 
$netAISJSONfilesDir = getAISdFilesNames($netAISJSONfilesDir); 	// определим имя и создадим каталог для данных netAIS
$netAISserverDataFileName = $netAISJSONfilesDir.'netAISserverData';

/*
$loopTime -- "pool mode", $sockWait --  "wait mode"
Правильно только pool mode, потому что в wait mode данные будут отдаваться со скоростью
чтения из сокета, и найдётся кто-нибудь, кто их будет читать с такой скоростью. Тогда демон
займёт весь процессор.
*/
$loopTime = 1000000; 	// microseconds, the time of one survey gpsd cycle is not less than, but not more, if possible.; цикл не должен быть быстрее, иначе он займёт весь процессор. Если нет переменной -- обязательно $sockWait
$sockWait = 0; 	// seconds, socket wait timeout. Must be 0 if $loopTime present. Else -- set "wait" mode. Должно быть 0, если есть $loopTime, т.е. -- pool mode. Иначе -- wait mode, по событиям чтения/записи сокетов
$waitLoops = 3; 	// time to wait for client handshake. If no -- flood.

if(IRun()) { 	// Я ли?
	echo "I'm already running, exiting.\n"; 
	return;
}

$greeting = '{"class":"VERSION","release":"netAISd_1.","rev":"stable","proto_major":1,"proto_minor":3}';
$SEEN_GPS = 0x01; $SEEN_AIS = 0x08;
$netAISdevice = array(
'class' => 'DEVICE',
'path' => 'netAISd',
'activated' => date('c'),
'flags' => $SEEN_AIS,
'stopbits' => 1
);
$aisData = array();

if(!$netAISdHost) $netAISdHost='localhost';
if(!$netAISdPort) $netAISdPort=3838;

$masterSock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if(!$masterSock) {
	echo "Failed to create socket by reason: " . socket_strerror(socket_last_error()) . "\n";
	return 1;
}
for($i=0;$i<100;$i++) {
	$res = @socket_bind($masterSock, $netAISdHost, $netAISdPort);
	if(!$res) {
		echo "Failed to binding to $netAISdHost:$netAISdPort by: " . socket_strerror(socket_last_error($masterSock)) . ", wait $i\r";
		sleep(1);
	}
	else break;
}
echo "\n";
if(!$res) {
	echo "Failed to binding to $netAISdHost:$netAISdPort by: " . socket_strerror(socket_last_error($masterSock)) . "\n";
	return 1;
}
$res = @socket_listen($masterSock, 10); 	// в очереди будет до  соединений
if(!$res) {
	echo "Failed listennig by: " . socket_strerror(socket_last_error($masterSock)) . "\n";
	return 1;
}

$socksRead = array(); $socksWrite = array(); $socksError = NULL; 	// массивы для изменивших состояние сокетов (с учётом, что они в socket_select() по ссылке, и NULL прямо указать нельзя)
$clients = array(); $messages = array();
$aisData = fileAISdata(); 	// данные AIS одни на всех, читать файл будем стараться реже
#print_r($aisData);
echo "Ready to connection from $netAISdHost:$netAISdPort\n";
$status = '';
do {
	$startTime = microtime(TRUE);
	$socksRead[] = $masterSock; 	// 
	#print_r($socksRead);
	$num_changed_sockets = socket_select($socksRead, $socksWrite, $socksError, $sockWait);
	#echo "\n $status \n";
	$socksReadCNT = @count($socksRead); $socksWriteCNT = @count($socksWrite);
	if ($num_changed_sockets === false) {
		echo "Error on sockets: " . socket_strerror(socket_last_error()) . "\n";
		break;
	}
	if(!$num_changed_sockets and !count($clients)) { 	// нет активных соединений и нет (когда-либо) подключенных клиентов. А может не быть соединений, но быть клиенты?
		echo "Waiting to inbound connection                                \n";
		$msgsock = socket_accept($masterSock); 	// ждём входящего соединения
		if(!$msgsock) {
			echo "Failed to accept incoming by: " . socket_strerror(socket_last_error($msgsock)) . "\n";
			continue;
		}
		$msg = $greeting;
		$clients[] = $msgsock;
		$n = array_search($msgsock,$clients);
		$messages[$n]['output'] = $msg; 	// 	
		$messages[$n]['loopCNT'] = 0; 	// 	счётчик оборотов на предмет выявления молчания клиента
		$socksWrite = $clients; 	// 	 Сейчас надо писать, а читать не надо
		echo "Connected!                                    \n";
		$status = 'Connected!';
		continue; 	// на следующем обороте проверится готовность нового клиента принять данные
	}

	// Читаем
	#echo "socksReadCNT=$socksReadCNT;\n";
	foreach($socksRead as $sock) {
		if($sock === $masterSock) {
			$msgsock = socket_accept($masterSock); 	// принимаем входящее соединение
			if(!$msgsock) {
				echo "\nFailed to accept incoming by: " . socket_strerror(socket_last_error($msgsock)) . "\n";
				continue;
			}
			$msg = $greeting;
			$clients[] = $msgsock;
			$n = array_search($msgsock,$clients);
			$messages[$n]['output'] = $msg; 	// 	
			$messages[$n]['loopCNT'] = 0; 	// 	счётчик оборотов на предмет выявления молчания клиента
			echo "New client connected!                                    \n";
			continue; 	// запросов ещё не было. Однако, на следующем обороте от него могут принять команду до отправки $greeting. Пофиг?
		}
		$sockKey = array_search($sock,$clients); 	// номер этого сокета в массивах клиентов
		#echo "\nClient sockKey=$sockKey;\n";
		$buf = @socket_read($sock, 2048, PHP_NORMAL_READ); 	// ждём команды
		#echo "\nbuf=$buf|\n";
		$status = 'socket_read';
		if($buf === FALSE) { 	// клиент умер
			echo "\nFailed to read data from client by: " . socket_strerror(socket_last_error($sock)) . "\n";
		    socket_close($sock);
		    unset($clients[$sockKey]);
		    unset($messages[$sockKey]);
		    continue;
		}
		if (!$buf = trim($buf)) {
			continue;
		}
		if($buf[0]!='?') continue; 	// это не команда
		// выделим команду и параметры
		list($command,$params) = explode('=',$buf);
		$command = trim(explode(';',substr($command,1))[0]); 	// не поддерживаем (пока?) нескольких команд за раз
		$params = trim($params);
		//echo "\nClient $sockKey| command=$command| params=$params|\n";
		if($params) $params = json_decode(substr($params,0,strrpos($params,'}')+1),TRUE);
		// Обработаем команду
		switch($command){
		case 'WATCH': 	// default: ?WATCH={"enable":true};
			if(!$params['device'] or $params['device'] == $netAISdevice['path']) { 	// команда адресована ко всем или именно к нам
				$messages[$sockKey]['WATCH'] = $params; 	// новая команда WATCH заменяет текущую
				$messages[$sockKey]['WATCH']['class'] = 'WATCH';
				$messages[$sockKey]['WATCH'] = array_reverse($messages[$sockKey]['WATCH']); 	// чтобы class было первым
				$messages[$sockKey]['loopCNT'] = FALSE; 	// клиент отозвался
			}
			break;
		case 'POLL':
			$messages[$sockKey]['POLL'] = TRUE;
			break;
		case 'BYE':
			echo "Close all connections. Bye!                              \n";
			break 3;
		}
		//echo "Client commands & data "; print_r($messages[$sockKey]);
	}

	// Действия по команде -- каждый оборот
	foreach($clients as $client => $socket) {
		// действия по ожиданию ответа клиента
		if($messages[$client]['loopCNT'] !== FALSE) { 	// 
			if($messages[$client]['loopCNT']<=$waitLoops){
				$messages[$client]['loopCNT'] += 1;
				continue;
			}
			$messages[$client]['output'] = getAISData($aisData,array(FALSE,TRUE));
			#print_r($messages[$client]['output']);
			continue;
		}
		// Действия по WATCH
		if($messages[$client]['WATCH']['enable'] === TRUE) { 	// первый WATCH, просьба включить
			//echo "\nClient $client| WATCH\n";
			if($messages[$client]['WATCH']['raw']) {
				unset($messages[$client]['WATCH']); 	// проигнорируем -- мы такого не умеем
				echo "\nUnsupported ask -- ignore\n";
				continue;
			}
			if(count($messages[$client]['WATCH']) == 2) { 	// там только class и enable -- это запрос для POLL
				$messages[$client]['WATCH']['POLL'] = 'ready'; 	//
			}
			else $messages[$client]['WATCH']['POLL'] = FALSE; 	//
			$messages[$client]['WATCH']['enable'] = 'enabling';
			// вернуть DEVICES
			$msg = array('class' => 'DEVICES', 'devices' => array($netAISdevice));
			$messages[$client]['output'] = json_encode($msg);
			//echo "Client commands & data after WATCH processing Client $client "; print_r($messages[$client]);
			//echo "Client commands & data after WATCH processing Client $client "; print_r($messages[$client]['WATCH']);echo " POLL: ";print_r($messages[$client]['POLL']);
		}
		elseif($messages[$client]['WATCH']['enable'] === 'close') { 	// просьба выключить
			socket_close($socket);
			unset($clients[$client]);
			unset($messages[$client]);
		}
		elseif($messages[$client]['WATCH']['enable'] === FALSE) { 	// просьба прекратить
			$messages[$client]['WATCH']['POLL'] = FALSE; 	//
			$messages[$client]['POLL'] = FALSE;
		}
		elseif($messages[$client]['WATCH']['enable'] == 'enabling') { 	// сейчас команды WATCH не было, но она была перед этим
			//echo "\nClient $client| First after WATCH\n";
			$messages[$client]['WATCH']['enable'] = 'enabled';
			// вернуть статус WATCH
			$msg = $messages[$client]['WATCH'];
			$msg['enable'] = TRUE; 	// gpsd иначе не понимает?
			unset($msg['POLL']);
			$messages[$client]['output'] = json_encode($msg);
			//echo "Client commands & data after first WATCH "; print_r($messages[$client]);
			//echo "Client commands & data after first WATCH, WATCH: "; print_r($messages[$client]['WATCH']);echo " POLL: ";print_r($messages[$client]['POLL']);
		}
		elseif($messages[$client]['WATCH']['enable'] == 'enabled') { 	// сейчас команды WATCH не было, но она была раньше
			if(!$messages[$client]['WATCH']['POLL'] == 'ready'){ 	// клиент хочет POLL -- ну вот пусть и спрашивает POLL
				//echo "\nClient $client| Seconds after WATCH, except POLL == ready\n";
				$messages[$client]['output'] = getAISData($aisData,array($messages[$client]['WATCH']['scaled'],$messages[$client]['WATCH']['nmea']));
				//echo "Client commands & data after WATCH'es "; print_r($messages[$client]);
				//echo "Client commands & data after WATCH'es "; print_r($messages[$client]['WATCH']);echo " POLL: ";print_r($messages[$client]['POLL']);
			}
		}
		// Действия по POLL
		if($messages[$client]['POLL']) {
			//echo "\nClient $client| POLL\n";
			if($messages[$client]['WATCH']['POLL'] == 'ready') {
				$messages[$client]['output'] = getAISData($aisData,array($messages[$client]['WATCH']['scaled'],$messages[$client]['WATCH']['nmea']));
			}
			$messages[$client]['POLL'] = FALSE;
			//echo "Client commands & data after POLL "; print_r($messages[$client]);
			//echo "Client commands & data after POLL "; print_r($messages[$client]['WATCH']);echo " POLL: ";print_r($messages[$client]['POLL']);
		}
		
	}

	// Пишем
	foreach($socksWrite as $sock) {
		if(!is_resource($sock)) continue; 	// сокет могли уже закрыть: например, он умер в процессе чтения
		$sockKey = array_search($sock,$clients); 	// номер этого сокета в массивах клиентов
		$msgs = $messages[$sockKey]['output'];
		if(!is_array($msgs) and ($msgs=trim($msgs))) $msgs = array(trim($msgs));
		if(!$msgs) continue;
		//echo "\nWriting for client $sockKey\n";
		foreach($msgs as $msg){
			//echo "\n$msg\n";
			$msg .= "\n";
			$res = socket_write($sock, $msg, strlen($msg));
			if($res === FALSE) { 	// клиент умер
				echo "\nFailed to write data to client by: " . socket_strerror(socket_last_error($sock)) . "\n";
				socket_close($sock);
				unset($clients[$sockKey]);
				unset($messages[$sockKey]);
				break;
			}
		}
		$messages[$sockKey]['output'] = NULL;
	}
	
	$socksRead = $clients;
	$socksWrite = $socksRead; 	// 	
	$cnt = count($clients);
	echo "Connected $cnt clients, ready $socksReadCNT read and $socksWriteCNT write sockets       \r";
	if($loopTime and ($cnt == $oldClientsCnt)) { 	// pool и количество клиентов за оборот не изменилось
		$sleepTime = $loopTime - (microtime(TRUE)-$startTime);
		if($sleepTime > 0) usleep($sleepTime); 	// ждём, если надо
		$aisData = fileAISdata(); 	// данные AIS одни на всех
	} 	// не ждём -- wait или нового клиента надо обслужить
	$oldClientsCnt = $cnt;
} while (true);
socket_close($masterSock);
foreach($clients as $socket) {
	socket_close($socket);
}


function getAISData($aisDates,$flags=array(FALSE,FALSE)){
/* Приводит данные к формату class "AIS" или сообщений NMEA AIS
$aisDates -- массив mmsi => aisData
Возвращение единиц к дурацким -- по значению $flags[0]
ВОзвращать поток NMEA AIS -- $flags[1]
Возвращает массив
*/
$AISsentencies = array();
foreach($aisDates as $mmsi => $aisData){
	$aisData = toAISphrases($aisData,$flags); 	// массив сообщений AIS, но в нормальных единицаъ измерения
	$AISsentencies = array_merge($AISsentencies,$aisData);
}
return $AISsentencies;
} // end function getAISData

function toAISphrases($aisData,$flags=array(FALSE,FALSE)){
/* Делает набор посылок AIS из данных AIS.
$aisData -- данные одного судна в нормальных единицах измерения
Возвращает массив строк AIS -- JSON или NMEA
*/
global $netAISdevice; 	// AISformat.php
$AISformat = array(
'18' => array(
	'MessageID' => str_pad(decbin(18), 6, '0', STR_PAD_LEFT),	// 6 bits Identifier for Message 18; always 18
	'Repeatindicator' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT),	// 2 Repeat indicator 0 = default; 3 = do not repeat any more
	'mmsi' => array('num',30,1),	// str_pad(decbin($mmsi), 30, '0', STR_PAD_LEFT);// 30 bits User ID  	MMSI number
	'Spare' => str_pad(decbin(0), 8, '0', STR_PAD_LEFT), //8 Not used. Should be set to zero. Reserved for future use
	'speed' => array('num',10,1),	// str_pad(decbin($speed), 10, '0', STR_PAD_LEFT) 10 SOG Speed over ground
	'PositionAccuracy' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT),	// 1
	'lon' => array('lon'),	// $ais->mk_ais_lon($lon) 28
	'lat' => array('lat'),	// $ais->mk_ais_lat($lat) 27
	'course' => array('num',12,1),	// str_pad(decbin($course), 12, '0', STR_PAD_LEFT) 12 COG Course over ground in 1/10= (0-3599)
	'heading' => array('num',9,1),	// str_pad(decbin($heading), 9, '0', STR_PAD_LEFT) 9 True heading Degrees (0-359) (511 indicates not available = default)
	'timestamp' => str_pad(decbin(0), 6, '0', STR_PAD_LEFT),	// 6 UTC second when the report was generated (0-59 or 60 if time stamp is not available, which should also be the default value or 62 if electronic position fixing system operates in estimated (dead reckoning) mode or 61 if positioning system is in manual input mode or 63 if the positioning system is inoperative) 
	'Spare1' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT), //2 Not used. Should be set to zero. Reserved for future use
	'Class_B_unit_flag' => str_pad(decbin(1), 1, '0', STR_PAD_LEFT), // 1 0 = Class B SOTDMA unit 1 = Class B “CS” unit
	'Class_B_display_flag' => str_pad(decbin(1), 1, '0', STR_PAD_LEFT), // 1 0 = No display available; not capable of displaying Message 12 and 14 1 = Equipped with integrated display displaying Message 12 and 14
	'Class_B_DSC_flag' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT), // 1 0 = Not equipped with DSC function 1 = Equipped with DSC function (dedicated or time-shared)
	'Class_B_band_flag' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT), // 1 0 = Capable of operating over the upper 525 kHz band of the marine band 1 = Capable of operating over the whole marine band (irrelevant if “Class B Message 22 flag” is 0)
	'Class_B_Message_22_flag' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT), // 1 0 = No frequency management via Message 22, operating on AIS 1, AIS 2 only 1 = Frequency management via Message 22 )
	'Mode_flag' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT), // 1 0 = Station operating in autonomous and continuous mode = default 1 = Station operating in assigned mode
	'RAIM_flag' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT), // 1 RAIM (Receiver autonomous integrity monitoring) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use
	'Communication_state_selector_flag' => str_pad(decbin(1), 1, '0', STR_PAD_LEFT), // 1 0 = SOTDMA communication state follows 1 = ITDMA communication state follows       (always “1” for Class-B “CS”)
	'Communication_state' => '1100000000000000110' // 19 SOTDMA communication state (see § 3.3.7.2.1, Annex 2), if communication state selector flag is set to 0, or ITDMA communication state (see § 3.3.7.3.2, Annex 2), if communication state selector flag is set to 1 Because Class B “CS” does not use any Communication State information, this field should be filled with the following value: 1100000000000000110
	),
'24A' => array(
	'MessageID' => str_pad(decbin(24), 6, '0', STR_PAD_LEFT), 	// 6 bits Identifier for Message 24; always 24
	'Repeatindicator' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT), 	// 2 Repeat indicator 0 = default; 3 = do not repeat any more
	'mmsi' => array('num',30,1), 	// str_pad(decbin($mmsi), 30, '0', STR_PAD_LEFT);// 30 bits User ID  	MMSI number
	'Part number' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT), 	// 2 Part number  always 0 for Part A
	'shipname' => array('str',20) 	// $ais->char2bin('ASIAN JADE', 20); 	// 120 Name of the MMSI-registered vessel. Maximum 20 characters 6-bit ASCII, 
),
'24B' => array(
	'MessageID' => str_pad(decbin(24), 6, '0', STR_PAD_LEFT), 	// 6 bits Identifier for Message 24; always 24
	'Repeatindicator' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT), 	// 2 Repeat indicator 0 = default; 3 = do not repeat any more
	'mmsi' => array('num',30,1), 	// str_pad(decbin($mmsi), 30, '0', STR_PAD_LEFT);// 30 bits User ID  	MMSI number
	'Part number' => str_pad(decbin(1), 2, '0', STR_PAD_LEFT), 	// 2 Part number  always 1 for Part B
	'shiptype' => array('num',8,1), // str_pad(decbin($shiptype), 8, '0', STR_PAD_LEFT);//8 Type of ship and cargo type
	'VendorID' => char2bin('', 7), 	// 42 Unique identification of the Unit by a number as defined by the manufacturer (option; “@@@@@@@” = not available = default)
	'callsign' => array('str',7), 	// $ais->char2bin($callsign, 7) 42 Call sign of the MMSI-registered vessel. 7 x 6 bit ASCII characters,
	'to_bow' => array('num',9,1), 	// str_pad(decbin($to_bow), 9, '0', STR_PAD_LEFT);// Dimension to Bow Meters
	'to_stern' => array('num',9,1), 	// str_pad(decbin($to_stern), 9, '0', STR_PAD_LEFT);// Dimension to Stern Meters
	'to_port' => array('num',6,1), 	// str_pad(decbin($to_port), 6, '0', STR_PAD_LEFT);// Dimension to Port Meters
	'to_starboard' => array('num',6,1), 	// str_pad(decbin($to_starboard), 6, '0', STR_PAD_LEFT);// Dimension to Starboard Meters
	'epfd' => array('num',4,1), //str_pad(decbin($epfd), 4, '0', STR_PAD_LEFT) // 4 Position Fix Type 0 = Undefined (default); 1 = GPS, 2 = GLONASS, 3 = combined GPS/GLONASS, 4 = Loran-C, 5 = Chayka, 6 = integrated navigation system, 7 = surveyed; 8 = Galileo, 9-14 = not used, 15 = internal GNSS
	'Spare' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT) //2 Not used. Should be set to zero. Reserved for future use
),
'27' => array( // Хотя у нас в Class_B_unit_flag указано 1, что означает CS. Class A and Class B "SO" shipborne mobile equipment outside base station coverage
	'MessageID' => str_pad(decbin(27), 6, '0', STR_PAD_LEFT),	// 6 bits Identifier for Message 18; always 18
	'Repeatindicator' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT),	// 2 Repeat indicator 0 = default; 3 = do not repeat any more
	'mmsi' => array('num',30,1),	// str_pad(decbin($mmsi), 30, '0', STR_PAD_LEFT);// 30 bits User ID  	MMSI number
	'PositionAccuracy' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT),	// 1
	'RAIM_flag' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT), // 1 RAIM (Receiver autonomous integrity monitoring) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use
	'status' => array('num',4,1),	// str_pad(decbin($status), 1, '0', STR_PAD_LEFT) 4
	'lon' => array('lon10'),	// $ais->mk_ais_lon($lon)/1000 18 Longitude in 1/10 min!!!!!
	'lat' => array('lat10'),	// $ais->mk_ais_lat($lat)/1000 17 Latitude in 1/10 min
	'speed' => array('num',6,0.1),	// str_pad(decbin($speed/10), 6, '0', STR_PAD_LEFT) 6 SOG В узлах!!! Speed over ground
	'course' => array('num',9,0.1),	// str_pad(decbin($course/10), 9, '0', STR_PAD_LEFT) 9 COG Course over ground in degrees
	'Position_latency' => str_pad(decbin(1), 1, '0', STR_PAD_LEFT), // 1 0 = Reported position latency is less than 5 seconds; 1 = Reported position latency is greater than 5 seconds = default
	'Spare' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT) //1 Not used. Should be set to zero. Reserved for future use
)
);
$addAISformat = array(
'18' => array(
),
'24A' => array(
),
'24B' => array(
'shiptype_text',
'epfd_text',
'imo',
'vin'
),
'27' => array( 
'status_text'
)
);

list($scaled,$onNMEA) = $flags;
$AISsentencies = array();
foreach($AISformat as $type => $format){
	if($onNMEA) {
		//echo "type=$type;\n";
		$aisSent = getNMEAsent($aisData,substr($type,0,2),$format,$flags);
	}
	else {
		$aisSent = array();
		$aisSent['class'] = 'AIS';
		$aisSent['device'] = $netAISdevice['path'];
		$aisSent['type'] = substr($type,0,2);
		foreach($format as $key => $field){
			if(!is_array($field)) continue;
			$aisSent[$key] = $aisData[$key];
		}
		$format = $addAISformat[$type]; 	// нестандартные поля
		foreach($format as $key){
			$aisSent[$key] = $aisData[$key];
		}
		$aisSent = deMes($aisSent,$flags); 	// переведём единицы измерения, они зависят от типа
		$aisSent = json_encode($aisSent);
	}
	$AISsentencies[] = $aisSent;
}
return $AISsentencies;
} // end function toAISphrases

function getNMEAsent($aisData,$type,$format,$flags=array(FALSE,FALSE)) {
/* Возвращает строку -- выражение NMEA AIS типа $format 
*/ 
if(!is_numeric($aisData['mmsi'])) $aisData['mmsi'] = str_pad(substr(crc32($aisData['mmsi']),0,9),9,'0');
//echo "aisData['mmsi']={$aisData['mmsi']}\n";
$aisData['shipname'] = strtoupper(rus2translit($aisData['shipname']));
$aisData['type'] = $type;
$aisData = deMes($aisData,$flags); 	// переведём единицы измерения, они зависят от типа
//print_r($aisData);
$aisSent = '';
foreach($format as $key => $field){
	//echo "$key:$field\n";
	if(is_array($field)) {
		//echo "aisData[$key]={$aisData[$key]};\n";
		switch($field[0]){
		case 'num': 	// число
			$field = str_pad(decbin(round($aisData[$key]*$field[2])), $field[1], '0', STR_PAD_LEFT);
			break;
		case 'str': 	// строка
			//echo "aisData[$key]={$aisData[$key]};\n";
			$field = char2bin($aisData[$key], $field[1]);
			//echo "str=$field\n";
			break;
		case 'lon': 	// долгота в 1/10000 градуса
			$field = str_pad(decbin(mk_ais_lon($aisData[$key])), 28, '0', STR_PAD_LEFT);
			//echo "lon=$field\n";
			break;
		case 'lat': 	// широта в 1/10000 градуса
			$field = str_pad(decbin(mk_ais_lat($aisData[$key])), 27, '0', STR_PAD_LEFT);
			//echo "lat=$field\n";
			break;
		case 'lon10': 	// долгота в 1/10 градуса
			$field = str_pad(decbin(mk_ais_lon($aisData[$key],10)), 18, '0', STR_PAD_LEFT);
			//echo "lon10=$field\n";
			break;
		case 'lat10': 	// широта в 1/10 градуса
			$field = str_pad(decbin(mk_ais_lat($aisData[$key],10)), 17, '0', STR_PAD_LEFT);
			//echo "lat10=$field\n";
			break;
		}
		//echo "$field\n\n";
	}
	//else echo "$key: $field;\n\n";
	$aisSent .= $field;
}
$aisSent = mk_ais($aisSent);
return $aisSent;
} // end function getNMEAsent

function deMes($aisData,$flags){
/* приводит единицы измерения в одном сообщении AIS $aisData к принятым в AIS
Может вызываться для любых сообщений, поэтому if
*/
list($scaled,$onNMEA) = $flags;
//echo "scaled=$scaled; onNMEA=$onNMEA;\n";
//print_r($aisData);
if($aisData['speed']) $aisData['speed'] = ($aisData['speed']*60*60)/1852; 	// в узлах
if(!$scaled) {
	// приведём единицы обратно к дурацким
	if($aisData['speed'] and ($aisData['type']!='27')) $aisData['speed'] *= 10; 	// в 1/10 узла
	if(!$onNMEA) { 	// в $onNMEA координаты переводятся в phpais
		if($aisData['lon']) $aisData['lon'] = ($aisData['lon']*60)*10000; 	// 1/10000th of a minute of arc
		if($aisData['lat']) $aisData['lat'] = ($aisData['lat']*60)*10000; 	// 1/10000th of a minute of arc
		if((int)$aisData['type']==27) {
			$aisData['lon'] /= 1000; 	// в 1/10 минуты 
			$aisData['lat'] /= 1000; 	// в 1/10 минуты
		}
	}
	// скорость поворота восстаналивать не будем за отсутствием
	if($aisData['course'] and ($aisData['type']!='27')) $aisData['course'] = $aisData['course']*10; 	// COG Course over ground in degrees ( 1/10 = (0-3599)
	if($aisData['draught']) $aisData['draught'] = $aisData['draught']*10; 	// Maximum present static draught In m ( 1/10 m в сообщениях 5,24. Но в сообщениях 6,8 осадка -- в сантиметрах!!!
	if($aisData['length']) $aisData['length'] = $aisData['length']*10; 	// 
	if($aisData['beam']) $aisData['beam'] = $aisData['beam']*10; 	// 
}
return $aisData;
} // end function deMes

function fileAISdata(){
/* Читает файл данных AIS*/
global $netAISserverDataFileName;
// Возьмём файл с целями netAIS
//echo "netAISserverDataFileName=$netAISserverDataFileName;\n";
clearstatcache(TRUE,$netAISserverDataFileName);
if(file_exists($netAISserverDataFileName)) {
	$aisData = json_decode(file_get_contents($netAISserverDataFileName),TRUE); 	// 
}
else {
	echo "\n$netAISserverDataFileName don't exist \n";
	$aisData = array();
}
//echo "aisData from file: "; print_r($aisData);
return $aisData;
} // end function fileAISdata

function rus2translit($string) {
$converter = array(
'а' => 'a',   'б' => 'b',   'в' => 'v',
'г' => 'g',   'д' => 'd',   'е' => 'e',
'ё' => 'e',   'ж' => 'zh',  'з' => 'z',
'и' => 'i',   'й' => 'y',   'к' => 'k',
'л' => 'l',   'м' => 'm',   'н' => 'n',
'о' => 'o',   'п' => 'p',   'р' => 'r',
'с' => 's',   'т' => 't',   'у' => 'u',
'ф' => 'f',   'х' => 'h',   'ц' => 'c',
'ч' => 'ch',  'ш' => 'sh',  'щ' => 'sch',
'ь' => '\'',  'ы' => 'y',   'ъ' => '\'',
'э' => 'e',   'ю' => 'yu',  'я' => 'ya',

'А' => 'A',   'Б' => 'B',   'В' => 'V',
'Г' => 'G',   'Д' => 'D',   'Е' => 'E',
'Ё' => 'E',   'Ж' => 'Zh',  'З' => 'Z',
'И' => 'I',   'Й' => 'Y',   'К' => 'K',
'Л' => 'L',   'М' => 'M',   'Н' => 'N',
'О' => 'O',   'П' => 'P',   'Р' => 'R',
'С' => 'S',   'Т' => 'T',   'У' => 'U',
'Ф' => 'F',   'Х' => 'H',   'Ц' => 'C',
'Ч' => 'Ch',  'Ш' => 'Sh',  'Щ' => 'Sch',
'Ь' => '\'',  'Ы' => 'Y',   'Ъ' => '\'',
'Э' => 'E',   'Ю' => 'Yu',  'Я' => 'Ya'
);
return strtr($string, $converter);
}

function IRun() {
/**/
global $phpCLIexec;
$pid = getmypid();
//echo "pid=$pid\n";
exec("ps -A w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)."'",$psList);
if(!$psList) exec("ps w | grep '".pathinfo(__FILE__,PATHINFO_BASENAME)."'",$psList); 	// for OpenWRT. For others -- let's hope so all run from one user
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


// This functions pick up from https://github.com/ais-one/phpais
// Copyright 2014 Aaron Gong Hsien-Joen <aaronjxz@gmail.com>

function mk_ais_lat($lat,$mes=10000) {
/* Делает AIS представление широты
широта -- в десятичных градусах 
Результат -- в десятитысячных минуты при умолчальном значении $mes
для сообщения № 27 $mes должна быть равна 10 -- результат в десятых минуты

результат надо кодировать в строку бит также, как и другие числа
*/
//$lat = 1.2569;
if ($lat<0.0) {
	$lat = -$lat;
	$neg=true;
}
else $neg=false;
$latd = 0x00000000;
$latd = intval ($lat * 60.0*$mes);
if ($neg==true) {
	$latd = ~$latd;
	$latd+=1;
	$latd &= 0x07FFFFFF;
}
return $latd;
}

function mk_ais_lon($lon,$mes=10000) {
/* Делает AIS представление долготы
широта -- в десятичных градусах 
Результат -- в десятитысячных минуты при умолчальном значении $mes
для сообщения № 27 $mes должна быть равна 10 -- результат в десятых минуты

результат надо кодировать в строку бит также, как и другие числа
*/
//$lon = 103.851;
if ($lon<0.0) {
	$lon = -$lon;
	$neg=true;
}
else $neg=false;
$lond = 0x00000000;
$lond = intval ($lon * 60.0*$mes);
if ($neg==true) {
	$lond = ~$lond;
	$lond+=1;
	$lond &= 0x0FFFFFFF;
}
return $lond;
}

function char2bin($name, $max_len) {
/* Кодирование строк.
В разных полях строки разной длины, и здесь дополняются @
 */
$len = strlen($name);
if ($len > $max_len) $name = substr($name,0,$max_len);
if ($len < $max_len) $pad = str_repeat('0', ($max_len - $len) * 6);
else $pad = '';
$rv = '';
$ais_chars = array(
	'@'=>0, 'A'=>1, 'B'=>2, 'C'=>3, 'D'=>4, 'E'=>5, 'F'=>6, 'G'=>7, 'H'=>8, 'I'=>9,
	'J'=>10, 'K'=>11, 'L'=>12, 'M'=>13, 'N'=>14, 'O'=>15, 'P'=>16, 'Q'=>17, 'R'=>18, 'S'=>19,
	'T'=>20, 'U'=>21, 'V'=>22, 'W'=>23, 'X'=>24, 'Y'=>25, 'Z'=>26, '['=>27, '\\'=>28, ']'=>29,
	'^'=>30, '_'=>31, ' '=>32, '!'=>33, '\"'=>34, '#'=>35, '$'=>36, '%'=>37, '&'=>38, '\''=>39,
	'('=>40, ')'=>41, '*'=>42, '+'=>43, ','=>44, '-'=>45, '.'=>46, '/'=>47, '0'=>48, '1'=>49,
	'2'=>50, '3'=>51, '4'=>52, '5'=>53, '6'=>54, '7'=>55, '8'=>56, '9'=>57, ':'=>58, ';'=>59,
	'<'=>60, '='=>61, '>'=>62, '?'=>63
);
$_a = str_split($name);
if ($_a) foreach ($_a as $_1) {
	if (isset($ais_chars[$_1])) $dec = $ais_chars[$_1];
	else $dec = 0;
	$bin = str_pad(decbin( $dec ), 6, '0', STR_PAD_LEFT);
	$rv .= $bin;
	//echo "$_1 $dec ($bin)<br/>";
}
return $rv.$pad;
}

function mk_ais($_enc, $_part=1,$_total=1,$_seq='',$_ch='A') {
/* Здесь только формирование самого сообщения: кодирование и контрольная сумма
Содержательная часть в виде строки бит передаётся сюда уже готовой
$_enc строка бит всех полей сообщения
$_seq	sequential message ID for multi-sentence messages
*/
$len_bit = strlen($_enc);
$rem6 = $len_bit % 6;
$pad6_len = 0;
if ($rem6) $pad6_len = 6 - $rem6;
//echo  $pad6_len.'<br>';
$_enc .= str_repeat("0", $pad6_len); // pad the text...
$len_enc = strlen($_enc) / 6;
//echo $_enc.' '.$len_enc.'<br/>';

$itu = '';

for ($i=0; $i<$len_enc; $i++) {
	$offset = $i * 6;
	$dec = bindec(substr($_enc,$offset,6));
	if ($dec < 40) $dec += 48;
	else $dec += 56;
	//echo chr($dec)." $dec<br/>";
	$itu .= chr($dec);
}

// add checksum
$chksum = 0;
$itu = "AIVDM,$_part,$_total,$_seq,$_ch,".$itu.",0";

$len_itu = strlen($itu);
for ($i=0; $i<$len_itu; $i++) {
	$chksum ^= ord( $itu[$i] );
}

$hex_arr = array('0','1','2','3','4','5','6','7','8','9','A','B','C','D','E','F');
$lsb = $chksum & 0x0F;
if ($lsb >=0 && $lsb <= 15 ) $lsbc = $hex_arr[$lsb];
else $lsbc = '0';
$msb = (($chksum & 0xF0) >> 4) & 0x0F;
if ($msb >=0 && $msb <= 15 ) $msbc = $hex_arr[$msb];
else $msbc = '0';

$itu = '!'.$itu."*{$msbc}{$lsbc}\r\n";
return $itu;
}

?>
