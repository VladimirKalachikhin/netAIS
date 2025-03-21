<?php
/* Демон. Непрерывно читает данные из файлов netAIS кадого подключенного к клиенту сервера.
Предназначен для показа целей netAIS в программах и на устройствах, не умеющих gpsdPROXY.
Например, OpenCPN.
Отдаёт данные netAIS как поток обычных данных AIS:
$ nc localhost 3900
$ telnet localhost 3900

Умеет также общаться по протоколу gpsd:
$ cgps localhost:3900
$ telnet localhost 3900
?DEVICES;
?WATCH={"enable":true};
?POLL;

?WATCH={"enable":true,"json":true}

Запуск: php netAISd.php
Запускается в index.php, если таковое указано в конфиге.
https://www.aggsoft.com/ais-decoder.htm
*/
ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
//ini_set('error_reporting', E_ALL & ~E_STRICT & ~E_DEPRECATED);
chdir(__DIR__); // задаем директорию выполнение скрипта

require('fcommon.php'); 	// 
require('params.php'); 	// 
$netAISJSONfilesDir = getAISdFilesNames($netAISJSONfilesDir); 	// определим имя и создадим каталог для данных netAIS, params.php
$serversListFileName = 'server/serversList.csv'; 	// list of available servers, именно здесь, потому что этот каталог не на временной файловой системе

/*
$loopTime -- "pool mode", $sockWait --  "wait mode"
Правильно только pool mode, потому что в wait mode данные будут отдаваться со скоростью
чтения из сокета, и найдётся кто-нибудь, кто их будет читать с такой скоростью. Тогда демон
займёт весь процессор.
Например, gpsd так и читает.
Поскольку регулировать скорость отдачи скоростью прихода новых данных невозможно - данные 
читаются из файла - то отдавать их через фиксированные промежутки времени -- единственный выход.
*/
if($netAISdPeriod){	// params.php
	$loopTime = 1000000*$netAISdPeriod; 	// microseconds, the time of one survey gpsd cycle is not less than, but not more, if possible.; цикл не должен быть быстрее, иначе он займёт весь процессор. Если нет переменной -- обязательно $sockWait
	$sockWait = 0; 	// seconds, socket wait timeout. Must be 0 if $loopTime present. Else -- set "wait" mode. Должно быть 0, если есть $loopTime, т.е. -- pool mode. Иначе -- wait mode, по событиям чтения/записи сокетов
}
else {
	$loopTime = 0;
	$sockWait = 5;
};
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
$metainfoSendedAt = 0;	// timestamp last metaifo sended
$metainfoSendEvery = 60*2;	// metaifo will be sended every sec.
$selfMOBfileName = $netAISJSONfilesDir.'/selfMOB'; 	//  array, 0 - Navigational status, 1 - Navigational status Text. место, где хранится состояние клиента

//if(!$netAISdHost) $netAISdHost='localhost';
if(!$netAISdHost) $netAISdHost='0.0.0.0';
if(!$netAISdPort) $netAISdPort=3900;

$masterSock = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if(!$masterSock) {
	echo "Failed to create socket by reason: " . socket_strerror(socket_last_error()) . "\n";
	return 1;
}
$res = socket_set_option($masterSock, SOL_SOCKET, SO_REUSEADDR, 1);	// чтобы можно было освободить ранее занятый адрес, не дожидаясь, пока его освободит система
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
//print_r($aisData);

echo "Ready to connection to $netAISdHost:$netAISdPort\n";
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
		//echo "\nbuf=$buf|\n";
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
			$messages[$client]['output'] = getAISData($aisData);	// Приводит данные к формату class "AIS" или сообщений NMEA AIS
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
			$messages[$client]['output'] = json_encode($msg, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
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
			$messages[$client]['output'] = json_encode($msg, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
			//echo "Client commands & data after first WATCH "; print_r($messages[$client]);
			//echo "Client commands & data after first WATCH, WATCH: "; print_r($messages[$client]['WATCH']);echo " POLL: ";print_r($messages[$client]['POLL']);
		}
		elseif($messages[$client]['WATCH']['enable'] == 'enabled') { 	// сейчас команды WATCH не было, но она была раньше
			if(!$messages[$client]['WATCH']['POLL'] == 'ready'){ 	// клиент хочет POLL -- ну вот пусть и спрашивает POLL
				//echo "\nClient $client| Seconds after WATCH, except POLL == ready\n";
				if($messages[$client]['WATCH']['nmea']) $messages[$client]['output'] = getAISData($aisData);	// Приводит данные к формату сообщений NMEA AIS
				else $messages[$client]['output'] = makeWATCH($aisData,$messages[$client]['WATCH']['scaled']);	// Приводит данные к формату gpsd class "AIS"
				//echo "Client commands & data after WATCH'es "; print_r($messages[$client]);
				//echo "Client commands & data after WATCH'es "; print_r($messages[$client]['WATCH']);echo " POLL: ";print_r($messages[$client]['POLL']);
			}
		}
		// Действия по POLL
		if($messages[$client]['POLL']) {
			//echo "\nClient $client| POLL                        \n";
			if($messages[$client]['WATCH']['POLL'] == 'ready') {
				if($messages[$client]['WATCH']['nmea']) $messages[$client]['output'] = getAISData($aisData);	// Приводит данные к формату сообщений NMEA AIS
				else $messages[$client]['output'] = makePOLL($aisData,$messages[$client]['WATCH']['scaled']);	// Приводит данные к формату gpsd class "AIS"
			}
			$messages[$client]['POLL'] = FALSE;
			//echo "\nClient commands & data after POLL "; print_r($messages[$client]);
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
	}; 	// не ждём -- wait или нового клиента надо обслужить
	$oldClientsCnt = $cnt;
} while (true);
socket_close($masterSock);
foreach($clients as $socket) {
	socket_close($socket);
};






function getAISData($aisDates){
/* Приводит данные к формату сообщений NMEA AIS
$aisDates -- массив mmsi => aisData следующего формата:
Array:
(
    [123456789] => Array
        (
            [shipname] => Test SignalK vessel
            [mmsi] => 123456789
            [callsign] => SigKaSigKa
            [shiptype] => 37
            [shiptype_text] => Pleasure
            [draught] => 1.6
            [length] => 9
            [beam] => 3.5
            [netAIS] => 1
            [speed] => 2.2502777777778
            [lon] => 27.964333333333
            [lat] => 61.3779
            [course] => 204.91500004679
            [timestamp] => 1741520733
        )

    [972456789] => Array
        (
            [class] => MOB
            [status] => 1
            [points] => Array
                (
                    [0] => Array
                        (
                            [coordinates] => Array
                                (
                                    [0] => 27.899316666667
                                    [1] => 61.4912
                                )

                            [current] => 1
				            [mmsi] => 201246757
				            [safety_related_text] => 
                        )

                )

            [timestamp] => 1741365802
            [source] => 972456789
        )

)

Возвращает массив
*/
echo "[getAISData] aisDates:             "; print_r($aisDates); echo "\n";
$AISsentencies = array();
foreach($aisDates as $mmsi => $aisData){
	$sartmmsi = substr($mmsi,0,3);
	if($aisData['class']=='MOB'){	// это объект MOB в формате gpsdPROXY
		if(!$aisData['status']) continue;	// сообщения об отсутствии режима MOB посылать не будем
		// Надо из каждой точки в объекте MOB сделать плоский массив AIS MOB
		foreach($aisData['points'] as $point){
			if($point['mmsi']) $point['mmsi'] = '972'.substr($point['mmsi'],3);	// заменим каждую точку MOB объектом AIS SART
			else $point['mmsi'] = '972999999';
			$aisData1 = array(
				"mmsi" => $point['mmsi'],
				"status" => 14,
				"lon" => $point['coordinates'][0],
				"lat" => $point['coordinates'][1],
				"safety_related_text" => $point['safety_related_text'],
				"timestamp" => $aisData['timestamp']
			);
			if(!$aisData1["safety_related_text"]) $aisData1["safety_related_text"] = 'MOB ACTIVE';
			echo "[getAISData] AIS из MOB - aisData1:             "; print_r($aisData1); echo "\n";
			$aisData1 = toAISphrases($aisData1,'TPV','SART');	// а потом из массива AIS MOB сделать набор посылок AIS.
			$AISsentencies = array_merge($AISsentencies,$aisData1);
		};
	}
	elseif($sartmmsi=='972' or $sartmmsi=='974') {
		$aisData = toAISphrases($aisData,'TPV','SART');	// это одна точка AIS MOB
		$AISsentencies = array_merge($AISsentencies,$aisData);
	}
	else {
		$aisData = toAISphrases($aisData,'TPV','A');
		$AISsentencies = array_merge($AISsentencies,$aisData);
		if((time()-$metainfoSendedAt)>$metainfoSendEvery){
			$aisData = toAISphrases($aisData,'metainfo','A');
			$AISsentencies = array_merge($AISsentencies,$aisData);
			$metainfoSendedAt = time();
		};
	};
}
return $AISsentencies;
} // end function getAISData


function makeWATCH($aisData,$scaled){
/*
Приводит данные к формату потока gpsd "WATCH", но неправильному: с AIS и MOB, но без tpv.
При этом class AIS тоже не соответствует документации - он в формате gpsdPROXY
MOB тоже в формате gpsdPROXY.
Возвращение единиц к дурацким -- по значению $scaled, для AIS, но не для MOB. MOB всегда в нормальных единицах.
Возвращает массив объектов AIS и MOB

gpsdPROXY возвращает AIS в следующем виде:
{
	"class":"AIS",
	"ais":{
		"123456789":{
			"shipname":"Test SignalK vessel","mmsi":"123456789","callsign":"SigKaSigKa","shiptype":37,"shiptype_text":"Pleasure","draught":1.6,"length":9,"beam":3.5,"netAIS":true,"lon":28.378433333333334,"lat":61.28268333333333,"timestamp":1741873877,"speed":2.2269444444444444,"course":358.6240000818845
		}
	}
}

gpsdPROXY возвращает ALARM в следующем виде:
{
	"class":"ALARM",
	"alarms":{
		"collisions":[],
		"MOB":{
			"class":"MOB","status":true,"points":[{"coordinates":[28.185217,61.442033],"current":true,"mmsi":"201246757","safety_related_text":""}],"timestamp":1741875560,"source":"972246757"
		}
	}
}

*/
if($scaled) $aisData = deMes($aisData);
$AIS = array(
	"class" => "AIS",
	"scaled" => $scaled,
	"ais" => array(),
);
$ALARM = array(
	"class" => "ALARM",
	"alarms" => array("MOB" => array()),
);
foreach($aisData as $mmsi => $Data){
	if($Data['class'] == 'MOB') $ALARM['alarms']['MOB'][] = $Data;
	else $AIS['ais'][] = $Data;
};

return array($AIS,$ALARM);
}; // end function toGPSDphrases

function makePOLL($aisData,$scaled){
/*
Приводит данные к формату gpsd class "POLL", но неправильному: с AIS и MOB, но без tpv
$aisDates -- массив mmsi => aisData следующего формата:
Array:
(
    [123456789] => Array
        (
            [shipname] => Test SignalK vessel
            [mmsi] => 123456789
            [callsign] => SigKaSigKa
            [shiptype] => 37
            [shiptype_text] => Pleasure
            [draught] => 1.6
            [length] => 9
            [beam] => 3.5
            [netAIS] => 1
            [speed] => 2.2502777777778
            [lon] => 27.964333333333
            [lat] => 61.3779
            [course] => 204.91500004679
            [timestamp] => 1741520733
        )

    [972456789] => Array
        (
            [class] => MOB
            [status] => 1
            [points] => Array
                (
                    [0] => Array
                        (
                            [coordinates] => Array
                                (
                                    [0] => 27.899316666667
                                    [1] => 61.4912
                                )

                            [current] => 1
				            [mmsi] => 201246757
				            [safety_related_text] => 
                        )

                )

            [timestamp] => 1741365802
            [source] => 972456789
        )

)

Возвращает объект POLL
Возвращение единиц к дурацким -- по значению $scaled
*/
if($scaled) $aisData = deMes($aisData);
$POLL = array(	// данные для передачи клиенту как POLL, в формате gpsd
	"class" => "POLL",
	"time" => time(),
	"active" => 0,
	"tpv" => array(),
	"sky" => array(),	// обязательно по спецификации, пусто
	"ais" => array(),
	"mob" => array(),
);
foreach($aisData as $mmsi => $Data){
	if($Data['class'] == 'MOB') $POLL['mob'][] = $Data;
	else $POLL['ais'][] = $Data;
};
return $POLL;
}; // end function toGPSDphrases

function deMes($aisData){
/* приводит единицы измерения в $aisData из gpsd scaled в gpsd AIS
*/
//echo "[deMes] aisData:"; print_r($aisData); echo "\n";
foreach($aisData as $mmsi => &$Data){
	if($Data['class'] == 'MOB') continue;	// MOB вне документации gpsd, и потому - всегда в нормальных единицах
	// приведём единицы обратно к дурацким
	if($Data['speed']) $Data['speed'] = ($Data['speed']*60*60)/1852; 	// в узлах
	if($Data['speed'] and ($Data['type']!='27')) $Data['speed'] *= 10; 	// в 1/10 узла
	if($Data['lon']) $Data['lon'] = ($Data['lon']*60)*10000; 	// 1/10000th of a minute of arc
	if(Data['lat']) $Data['lat'] = ($Data['lat']*60)*10000; 	// 1/10000th of a minute of arc
	if((int)$Data['type']==27) {
		$Data['lon'] /= 1000; 	// в 1/10 минуты 
		$Data['lat'] /= 1000; 	// в 1/10 минуты
	}
	// скорость поворота восстаналивать не будем за отсутствием
	if($Data['course'] and ($Data['type']!='27')) $Data['course'] = $Data['course']*10; 	// COG Course over ground in degrees ( 1/10 = (0-3599)
	if($Data['draught']) $Data['draught'] = $Data['draught']*10; 	// Maximum present static draught In m ( 1/10 m в сообщениях 5,24. Но в сообщениях 6,8 осадка -- в сантиметрах!!!
	if($Data['length']) $Data['length'] = $Data['length']*10; 	// 
	if($Data['beam']) $Data['beam'] = $Data['beam']*10; 	// 
};
return $aisData;
} // end function deMes



/////////////////////////////////////////////////////////////////////////////////////////////
// Эти функции - из gigitraffic/fAIS.php
/////////////////////////////////////////////////////////////////////////////////////////////

function toAISphrases($vesselData,$aisDataClass,$AISformat="A"){
/* Делает набор посылок AIS из данных AIS.
$vesselData -- данные одного судна в нормальных единицах измерения, массив:
Array(
	[mmsi] => 244690470
	[status] => 15
	[status_text] => Not defined
	[accuracy] => 0
	[lon] => 5.675295
	[lat] => 52.848757
	[course] => 0
	[heading] => 
	[maneuver] => 0
	...
)
$aisDataClass - это либо 'TPV', либо 'metainfo', т.е., какой набор данных из $vesselData готовить.
$AISformat - тип передатчика AIS, Class A, Class B или SART - буй EPIRB, буй MOB, или сообщение об опасности
Возвращает массив строк AIS NMEA
*/
$AISformatA = array(
'TPV' => array(
	'1' => array(
		'MessageID' => str_pad(decbin(1), 6, '0', STR_PAD_LEFT),	// 6 bits Identifier for Message 1; always 1
		'Repeatindicator' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT),	// 2 Repeat indicator 0 = default; 3 = do not repeat any more
		'mmsi' => array('num',30,1),	// 30 bits User ID  	MMSI number
		'status' => array('num',4,1,15),	// 
		'turn' => array('num',8,1,128),
		//'turn' => str_pad(decbin(128), 8, '0', STR_PAD_LEFT),	// not available
		'speed' => array('num',10,((60*60)/1852)*10,1023),	// str_pad(decbin($speed), 10, '0', STR_PAD_LEFT) 10 SOG Speed over ground
		'accuracy' => array('num',1,1,0),
		'lon' => array('lon'),	// 
		'lat' => array('lat'),	// 
		'course' => array('num',12,10,3600),	// str_pad(decbin($course), 12, '0', STR_PAD_LEFT) 12 COG Course over ground in 1/10= (0-3599)
		//'course' => decbin(3600),
		'heading' => array('num',9,1,511),	// str_pad(decbin($heading), 9, '0', STR_PAD_LEFT) 9 True heading Degrees (0-359) (511 indicates not available = default)
		// всегда актуальные данные?
		'timestamp' => str_pad(decbin(0), 6, '0', STR_PAD_LEFT),	// 6 UTC second when the report was generated (0-59 or 60 if time stamp is not available, which should also be the default value or 62 if electronic position fixing system operates in estimated (dead reckoning) mode or 61 if positioning system is in manual input mode or 63 if the positioning system is inoperative) 
		'maneuver' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT),	// 0
		'Spare' => str_pad(decbin(0), 8, '0', STR_PAD_LEFT), //8 Not used. Should be set to zero. Reserved for future use
		'raim' => array('num',1,1,0), // 1 RAIM (Receiver autonomous integrity monitoring) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use
		'Communication_state' => '1100000000000000110' // 19 SOTDMA communication state (see § 3.3.7.2.1, Annex 2), if communication state selector flag is set to 0, or ITDMA communication state (see § 3.3.7.3.2, Annex 2), if communication state selector flag is set to 1 Because Class B “CS” does not use any Communication State information, this field should be filled with the following value: 1100000000000000110
	)
),
'metainfo' => array(
	'5' => array(
		'MessageID' => str_pad(decbin(5), 6, '0', STR_PAD_LEFT),	// 6 bits Identifier for Message 18; always 18
		'Repeatindicator' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT),	// 2 Repeat indicator 0 = default; 3 = do not repeat any more
		'mmsi' => array('num',30,1),	// str_pad(decbin($mmsi), 30, '0', STR_PAD_LEFT);// 30 bits User ID  	MMSI number
		'ais_version' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT),	// 0
		'imo' => array('num',30,1,0),
		'callsign' => array('str',7), 	// $ais->char2bin($callsign, 7) 42 Call sign of the MMSI-registered vessel. 7 x 6 bit ASCII characters,
		'shipname' => array('str',20), 	// $ais->char2bin('ASIAN JADE', 20); 	// 120 Name of the MMSI-registered vessel. Maximum 20 characters 6-bit ASCII, 
		'shiptype' => array('num',8,1,0), // str_pad(decbin($shiptype), 8, '0', STR_PAD_LEFT);//8 Type of ship and cargo type
		'to_bow' => array('num',9,1,0), 	// str_pad(decbin($to_bow), 9, '0', STR_PAD_LEFT);// Dimension to Bow Meters
		'to_stern' => array('num',9,1,0), 	// str_pad(decbin($to_stern), 9, '0', STR_PAD_LEFT);// Dimension to Stern Meters
		'to_port' => array('num',6,1,0), 	// str_pad(decbin($to_port), 6, '0', STR_PAD_LEFT);// Dimension to Port Meters
		'to_starboard' => array('num',6,1,0), 	// str_pad(decbin($to_starboard), 6, '0', STR_PAD_LEFT);// Dimension to Starboard Meters
		'TypeOfElectronicPositionFixingDevice' => str_pad(decbin(6), 4, '0', STR_PAD_LEFT),	// 0
		'eta' => array('num',20,1,2460),	//eta мы никак не изменяем это поле при получении, и это число
		'draught' => array('num',8,10,0),	//
		'destination' => array('str',20),	//
		'dte' => array('num',1,1,1),	//
		'Spare' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT), //1 Not used. Should be set to zero. Reserved for future use
	)
)
);
$AISformatB = array(
'TPV' => array(
	'18' => array(
		'MessageID' => str_pad(decbin(18), 6, '0', STR_PAD_LEFT),	// 6 bits Identifier for Message 18; always 18
		'Repeatindicator' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT),	// 2 Repeat indicator 0 = default; 3 = do not repeat any more
		'mmsi' => array('num',30,1),	// str_pad(decbin($mmsi), 30, '0', STR_PAD_LEFT);// 30 bits User ID  	MMSI number
		'Spare' => str_pad(decbin(0), 8, '0', STR_PAD_LEFT), //8 Not used. Should be set to zero. Reserved for future use
		'speed' => array('num',10,((60*60)/1852)*10,1023),	// 10 SOG Speed over ground
		//'PositionAccuracy' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT),	// 1
		'accuracy' => array('num',1,1,0),
		'lon' => array('lon'),	// $ais->mk_ais_lon($lon) 28
		'lat' => array('lat'),	// $ais->mk_ais_lat($lat) 27
		'course' => array('num',12,10,3600),	// 12 COG Course over ground in 1/10= (0-3599)
		'heading' => array('num',9,1,511),	// str_pad(decbin($heading), 9, '0', STR_PAD_LEFT) 9 True heading Degrees (0-359) (511 indicates not available = default)
		'timestamp' => str_pad(decbin(0), 6, '0', STR_PAD_LEFT),	// 6 UTC second when the report was generated (0-59 or 60 if time stamp is not available, which should also be the default value or 62 if electronic position fixing system operates in estimated (dead reckoning) mode or 61 if positioning system is in manual input mode or 63 if the positioning system is inoperative) 
		'Spare1' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT), //2 Not used. Should be set to zero. Reserved for future use
		'Class_B_unit_flag' => str_pad(decbin(1), 1, '0', STR_PAD_LEFT), // 1 0 = Class B SOTDMA unit 1 = Class B “CS” unit
		'Class_B_display_flag' => str_pad(decbin(1), 1, '0', STR_PAD_LEFT), // 1 0 = No display available; not capable of displaying Message 12 and 14 1 = Equipped with integrated display displaying Message 12 and 14
		'Class_B_DSC_flag' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT), // 1 0 = Not equipped with DSC function 1 = Equipped with DSC function (dedicated or time-shared)
		'Class_B_band_flag' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT), // 1 0 = Capable of operating over the upper 525 kHz band of the marine band 1 = Capable of operating over the whole marine band (irrelevant if “Class B Message 22 flag” is 0)
		'Class_B_Message_22_flag' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT), // 1 0 = No frequency management via Message 22, operating on AIS 1, AIS 2 only 1 = Frequency management via Message 22 )
		'Mode_flag' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT), // 1 0 = Station operating in autonomous and continuous mode = default 1 = Station operating in assigned mode
		//'RAIM_flag' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT), // 1 RAIM (Receiver autonomous integrity monitoring) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use
		'raim' => array('num',1,1,0), // 1 RAIM (Receiver autonomous integrity monitoring) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use
		'Communication_state_selector_flag' => str_pad(decbin(1), 1, '0', STR_PAD_LEFT), // 1 0 = SOTDMA communication state follows 1 = ITDMA communication state follows       (always “1” for Class-B “CS”)
		'Communication_state' => '1100000000000000110' // 19 SOTDMA communication state (see § 3.3.7.2.1, Annex 2), if communication state selector flag is set to 0, or ITDMA communication state (see § 3.3.7.3.2, Annex 2), if communication state selector flag is set to 1 Because Class B “CS” does not use any Communication State information, this field should be filled with the following value: 1100000000000000110
	),
	'27' => array( // Хотя у нас в Class_B_unit_flag указано 1, что означает CS. Class A and Class B "SO" shipborne mobile equipment outside base station coverage
		'MessageID' => str_pad(decbin(27), 6, '0', STR_PAD_LEFT),	// 6 bits Identifier for Message 18; always 18
		'Repeatindicator' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT),	// 2 Repeat indicator 0 = default; 3 = do not repeat any more
		'mmsi' => array('num',30,1),	// str_pad(decbin($mmsi), 30, '0', STR_PAD_LEFT);// 30 bits User ID  	MMSI number
		'PositionAccuracy' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT),	// 1
		'RAIM_flag' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT), // 1 RAIM (Receiver autonomous integrity monitoring) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use
		'status' => array('num',4,1,15),	// str_pad(decbin($status), 1, '0', STR_PAD_LEFT) 4
		'lon' => array('lon10'),	// $ais->mk_ais_lon($lon)/1000 18 Longitude in 1/10 min!!!!!
		'lat' => array('lat10'),	// $ais->mk_ais_lat($lat)/1000 17 Latitude in 1/10 min
		'speed' => array('num',6,(60*60)/1852,63),	// str_pad(decbin($speed/10), 6, '0', STR_PAD_LEFT) 6 SOG В узлах!!! Speed over ground
		'course' => array('num',9,1,511),	// str_pad(decbin($course/10), 9, '0', STR_PAD_LEFT) 9 COG Course over ground in degrees
		'Position_latency' => str_pad(decbin(1), 1, '0', STR_PAD_LEFT), // 1 0 = Reported position latency is less than 5 seconds; 1 = Reported position latency is greater than 5 seconds = default
		'Spare' => str_pad(decbin(0), 1, '0', STR_PAD_LEFT) //1 Not used. Should be set to zero. Reserved for future use
	)
),
'metainfo' => array(
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
		'shiptype' => array('num',8,1,0), // str_pad(decbin($shiptype), 8, '0', STR_PAD_LEFT);//8 Type of ship and cargo type
		'VendorID' => char2bin('', 7), 	// 42 Unique identification of the Unit by a number as defined by the manufacturer (option; “@@@@@@@” = not available = default)
		'callsign' => array('str',7), 	// $ais->char2bin($callsign, 7) 42 Call sign of the MMSI-registered vessel. 7 x 6 bit ASCII characters,
		'to_bow' => array('num',9,1,0), 	// str_pad(decbin($to_bow), 9, '0', STR_PAD_LEFT);// Dimension to Bow Meters
		'to_stern' => array('num',9,1,0), 	// str_pad(decbin($to_stern), 9, '0', STR_PAD_LEFT);// Dimension to Stern Meters
		'to_port' => array('num',6,1,0), 	// str_pad(decbin($to_port), 6, '0', STR_PAD_LEFT);// Dimension to Port Meters
		'to_starboard' => array('num',6,1,0), 	// str_pad(decbin($to_starboard), 6, '0', STR_PAD_LEFT);// Dimension to Starboard Meters
		'epfd' => array('num',4,1,0), //str_pad(decbin($epfd), 4, '0', STR_PAD_LEFT) // 4 Position Fix Type 0 = Undefined (default); 1 = GPS, 2 = GLONASS, 3 = combined GPS/GLONASS, 4 = Loran-C, 5 = Chayka, 6 = integrated navigation system, 7 = surveyed; 8 = Galileo, 9-14 = not used, 15 = internal GNSS
		'Spare' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT) //2 Not used. Should be set to zero. Reserved for future use
	)
)
);
// SART будет один для A и B, хотя в B сообщение должно быть короче - вплоть до только 1 слота. Другой разницы нет.
$AISformatSART = array(
'TPV' => array(
	'1' => array(
		'MessageID' => str_pad(decbin(1), 6, '0', STR_PAD_LEFT),	// 6 bits Identifier for Message
		'Repeatindicator' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT),	// 2 Repeat indicator 0 = default; 3 = do not repeat any more
		'mmsi' => array('num',30,1),	// 30 bits User ID  	MMSI number
		'status' => array('num',4,1,15),	// 
		'turn' => array('num',8,1,128),
		'speed' => array('num',10,((60*60)/1852)*10,1023),	// str_pad(decbin($speed), 10, '0', STR_PAD_LEFT) 10 SOG Speed over ground
		'accuracy' => array('num',1,1,0),
		'lon' => array('lon'),	// 
		'lat' => array('lat'),	// 
		'course' => array('num',12,10,3600),	// str_pad(decbin($course), 12, '0', STR_PAD_LEFT) 12 COG Course over ground in 1/10= (0-3599)
		'heading' => array('num',9,1,511),	// str_pad(decbin($heading), 9, '0', STR_PAD_LEFT) 9 True heading Degrees (0-359) (511 indicates not available = default)
		'timestamp' => str_pad(decbin(0), 6, '0', STR_PAD_LEFT),	// 6 UTC second when the report was generated (0-59 or 60 if time stamp is not available, which should also be the default value or 62 if electronic position fixing system operates in estimated (dead reckoning) mode or 61 if positioning system is in manual input mode or 63 if the positioning system is inoperative) 
		'maneuver' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT),	// 0
		'Spare' => str_pad(decbin(0), 8, '0', STR_PAD_LEFT), //8 Not used. Should be set to zero. Reserved for future use
		'raim' => array('num',1,1,0), // 1 RAIM (Receiver autonomous integrity monitoring) flag of electronic position fixing device; 0 = RAIM not in use = default; 1 = RAIM in use
		'Communication_state' => '1100000000000000110' // 19 SOTDMA communication state (see § 3.3.7.2.1, Annex 2), if communication state selector flag is set to 0, or ITDMA communication state (see § 3.3.7.3.2, Annex 2), if communication state selector flag is set to 1 Because Class B “CS” does not use any Communication State information, this field should be filled with the following value: 1100000000000000110
	),
	'14' => array(
		'MessageID' => str_pad(decbin(14), 6, '0', STR_PAD_LEFT),	// 6 bits Identifier for Message
		'Repeatindicator' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT),	// 2 Repeat indicator 0 = default; 3 = do not repeat any more
		'mmsi' => array('num',30,1),	// 30 bits User ID  	MMSI number
		'Spare' => str_pad(decbin(0), 2, '0', STR_PAD_LEFT), //2 Not used. Should be set to zero. Reserved for future use
		'safety_related_text' => array('str',161)	// Occupies up to 3 slots, or up to 5 slots when able to use FATDMA reservations. For Class B “SO” mobile AIS stations the length of the message should not exceed 3 slots. For Class B “CS” mobile AIS stations the length of the message should not exceed 1 slot. Number of slots:Maximum 6-bit ASCII characters 1:16, 2:53, 3:90, 4:128, 5:161
	)
)
);

switch($AISformat){
case "B":
	$AISformat = $AISformatB;
	break;
case "SART":
	$AISformat = $AISformatSART;
	break;
case "A":
default:
	$AISformat = $AISformatA;
};
$AISsentencies = array();
foreach($AISformat[$aisDataClass] as $type => $format){
	//echo "type=$type;\n\n";
	$aisSent = getNMEAsent($vesselData,substr($type,0,2),$format);
	//echo "aisSent=$aisSent;\n";
	$AISsentencies[] = $aisSent;
	//if($vesselData['turn'] and ($vesselData['turn']!=-128)) {
	//	file_put_contents('digitrafficAIS.log',$aisSent,FILE_APPEND);
	//	echo "aisData['turn']={$vesselData['turn']};\n";
	//}
}
//if(!$vesselData['to_bow']) file_put_contents('digitrafficAIS.log',$AISsentencies,FILE_APPEND);

return $AISsentencies;
} // end function toAISphrases

function getNMEAsent($vesselData,$type,$format) {
/* Возвращает строку -- выражение NMEA AIS типа $type 
$format: array('num',bits,multiplicator,default)
$format: array('str',bits)
*/ 
if(@$vesselData['shipname']) $vesselData['shipname'] = strtoupper(rus2translit($vesselData['shipname']));
if(!is_numeric($vesselData['mmsi'])) $vesselData['mmsi'] = str_pad(substr(crc32($vesselData['mmsi']),0,9),9,'0');
//echo "vesselData['mmsi']={$vesselData['mmsi']}\n";
$vesselData['type'] = $type;

//print_r($vesselData);
$aisSent = '';
//echo "type=$type; format:"; print_r($format); //echo "aisData:"; print_r($vesselData);

foreach($format as $key => $field){	// каждое поле, требуемое в посылке данного типа

	//if($key=='turn') echo "[getNMEAsent] {$vesselData['mmsi']} vesselData[$key]={$vesselData[$key]};\n";

	if(is_array($field)) {

		//if($key=='eta') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n";
		//if($key=='destination') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n";
		//if($key=='course') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n";
		//if($key=='heading') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n\n";
		//if($key=='shiptype') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n\n";
		//if($key=='status') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n\n";
		//if($vesselData['mmsi']=='230985490'){
			//if($key=='course') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n";
			//if($key=='heading') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n";
			//if($key=='lon') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n";
			//if($key=='lat') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n";
			//if($key=='length') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n";
			//if($key=='beam') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n";
			//if($key=='to_bow') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n";
			//if($key=='to_stern') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n";
			//if($key=='to_port') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n";
			//if($key=='to_starboard') echo "[getNMEAsent] aisData[$key]={$vesselData[$key]};\n\n";
		//}

		switch($field[0]){
		case 'num': 	// число
			//$field = str_pad(substr(decbin(round($vesselData[$key]*$field[2])),-$field[1]), $field[1], '0', STR_PAD_LEFT);
			if(isset($vesselData[$key]))	$field = str_pad(substr(decbin(round((int)$vesselData[$key]*$field[2])),-$field[1]), $field[1], '0', STR_PAD_LEFT);
			else $field = str_pad(decbin($field[3]), $field[1], '0', STR_PAD_LEFT);
			break;
		case 'str': 	// строка
			//echo "aisData[$key]={$vesselData[$key]};\n";
			if(isset($vesselData[$key]))	$field = char2bin($vesselData[$key], $field[1]);
			else $field = char2bin(str_repeat('@',20), $field[1]);
			break;
		case 'lon': 	// долгота в 1/10000 градуса
			$field = str_pad(decbin(mk_ais_lon(@$vesselData[$key])), 28, '0', STR_PAD_LEFT);
			//echo "lon=$field\n";
			break;
		case 'lat': 	// широта в 1/10000 градуса
			$field = str_pad(decbin(mk_ais_lat(@$vesselData[$key])), 27, '0', STR_PAD_LEFT);
			//echo "lat=$field\n";
			break;
		case 'lon10': 	// долгота в 1/10 градуса
			$field = str_pad(decbin(mk_ais_lon(@$vesselData[$key],10)), 18, '0', STR_PAD_LEFT);
			//echo "lon10=$field\n";
			break;
		case 'lat10': 	// широта в 1/10 градуса
			$field = str_pad(decbin(mk_ais_lat(@$vesselData[$key],10)), 17, '0', STR_PAD_LEFT);
			//echo "lat10=$field\n";
			break;
		};
	};
	//else echo "$key: $field;\n\n";
	$aisSent .= $field;
};

$aisSent = mk_ais($aisSent);
return $aisSent;
}; // end function getNMEAsent


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
if(($lat === null) or ($lat === false)) $lat = 91;
if ($lat<0.0) {
	$lat = -$lat;
	$neg=true;
}
else $neg=false;
$latd = 0x00000000;
$latd = intval($lat * 60.0*$mes);
if ($neg==true) {
	$latd = ~$latd;
	$latd+=1;
	$latd &= 0x07FFFFFF;
}
//echo "[mk_ais_lat] lat=$lat; latd=$latd;\n";
return $latd;
};	// end function mk_ais_lat

function mk_ais_lon($lon,$mes=10000) {
/* Делает AIS представление долготы
долгота -- в десятичных градусах 
Результат -- в десятитысячных минуты при умолчальном значении $mes
для сообщения № 27 $mes должна быть равна 10 -- результат в десятых минуты

результат надо кодировать в строку бит также, как и другие числа
*/
//$lon = 103.851;
if(($lon === null) or ($lon === false)) $lon = 181;
if ($lon<0.0) {
	$lon = -$lon;
	$neg=true;
}
else $neg=false;
$lond = 0x00000000;
$lond = intval($lon * 60.0*$mes);
if ($neg==true) {
	$lond = ~$lond;
	$lond+=1;
	$lond &= 0x0FFFFFFF;
}
//echo "[mk_ais_lon] lat=$lon; latd=$lond;\n";
return $lond;
}; // end function mk_ais_lon

function mk_ais_rot($rot){
/* rate of turn */

} // end function mk_ais_rat

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
$_a = str_split($name);	// в PHP до 8.2.0 из пустой строки делается массив с одним значением. ПОсле -- пустой массив
if ($len) {
	foreach ($_a as $_1) {
		if (isset($ais_chars[$_1])) $dec = $ais_chars[$_1];
		else $dec = 0;
		$bin = str_pad(decbin( $dec ), 6, '0', STR_PAD_LEFT);
		$rv .= $bin;
		//echo "$_1 $dec ($bin)<br/>";
	}
}
return $rv.$pad;
}; // end function char2bin

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
}; // end function mk_ais
/////////////////////////////////////////////////////////////////////////////////////////////


function fileAISdata(){
/* Читает файлы данных AIS
Из файлоа каждого включенного имеющегося сервера групп.
*/
global $netAISJSONfilesDir,$selfMOBfileName;
$aisData = array();
// Возьмём список серверов: csv адрес,используется,название, комментарий
$servers = getServersList();
foreach($servers as $server){
	if(!$server[1]) continue;	// не используется
	$aisData1 = array();
	$netAISserverDataFileName = $netAISJSONfilesDir.base64_encode($server[0]);
	//echo "netAISserverDataFileName=$netAISserverDataFileName;\n";
	clearstatcache(TRUE,$netAISserverDataFileName);
	if(file_exists($netAISserverDataFileName)) {
		$aisData1 = json_decode(file_get_contents($netAISserverDataFileName),TRUE); 	// 
	}
	else echo "netAIS data file $netAISserverDataFileName don't exist \n";
	//echo "aisData from file $netAISserverDataFileName: "; print_r($aisData1);
	// Нельзя объединить массивы штатными средствами PHP, потому что тогда строка цифр в ключе
	// станет числом. Древняя багофича. А оно нам не надо.
	foreach($aisData1 as $mmsi => $data){
		$mmsi = (string)$mmsi;
		if($aisData1[$mmsi]['timestamp']>$aisData[$mmsi]['timestamp']){	// если от другого сервера свежее. 
			foreach($data as $key => $value){	// Но, может быть, не полнее
				$aisData[$mmsi][$key] = $aisData1[$mmsi][$key];
			};
		};
	};
};
// Свой режим MOB
clearstatcache(TRUE,$selfMOBfileName); 	//
$statusMOB = unserialize(@file_get_contents($selfMOBfileName)); 	// считаем файл MOB, которого может не быть
if($statusMOB and $statusMOB['status']){
	$aisData[$statusMOB['source']] = $statusMOB;
};

return $aisData;
}; // end function fileAISdata


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
}; 	// end function rus2translit

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



?>
