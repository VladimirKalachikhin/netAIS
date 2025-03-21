<?php
function getAISdFilesNames($path) {
$path = rtrim($path,'/');
//echo "path=$path;\n";
if(!$path) $path = 'data';
$dirName = pathinfo($path, PATHINFO_DIRNAME);
$fileName = pathinfo($path,PATHINFO_BASENAME);
//echo "[getAISdFilesNames] dirName=$dirName; fileName=$fileName;\n";
if((!$dirName) OR ($dirName=='.')) {
	$dirName = sys_get_temp_dir()."/netAIS"; 	// права собственно на /tmp в системе могут быть замысловатыми
	$path = $dirName."/".$fileName.'/';
	$umask = umask(0); 	// сменим на 0777 и запомним текущую
	@mkdir($path, 0777,true); 	// 
	@chmod($path,0777); 	// права будут только на каталог netAIS. Если он вложенный, то на предыдущие, созданные по true в mkdir, прав не будет. Тогда надо использовать umask.
	umask($umask); 	// 	Вернём. Зачем? Но umask глобальна вообще для всех юзеров веб-сервера
}
else $path .= '/';
//echo "[getAISdFilesNames] path=$path;\n";
return $path;
} // end function getAISdFilesNames

function getServersList(){
/* Возвращает список чужих серверов, т.е., конфигураций для запуска своих клиентов */
global $serversListFileName;

// Возьмём список серверов: csv адрес,запущен,название, комментарий
$servers = array();
if (($handle = @fopen($serversListFileName, "r")) !== FALSE) {
	while (($server = fgetcsv($handle, 1000, ",")) !== FALSE) {
		if((!$server) or (count($server) < 4)) continue; 	// пустые и кривые строки
		if(!trim($server[0])) {
			$servers[] = $server; 	// строки - комментарии
			continue;
		};
		if(!$server[2]) $server[2] = parse_url($server[0], PHP_URL_HOST);
		$servers[$server[0]] = $server;
	};
	fclose($handle);
	//echo "[getServersList] servers:<pre>"; print_r($servers); echo "</pre>\n";
};
return $servers;
}; // end function getServersList

function getSelfParms() {
/**/
$vehicle = parse_ini_file('boatInfo.ini',FALSE,INI_SCANNER_TYPED);
if(!$vehicle['mmsi']) $vehicle['mmsi'] = str_pad(substr(crc32($vehicle['shipname']),0,9),9,'0'); 	// левый mmsi, похожий на настоящий -- для тупых, кому не всё равно (SignalK, к примеру)
return $vehicle;
}; // end function getSelfParms

function uploadTogpsdPROXY($connected){
/**/
global $netAISgpsdHost,$netAISgpsdPort,$gpsdPROXYsock,$netAISdevice,$greeting,$aisData;

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
		return $connected;
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
			$msg = json_encode(array('class' => 'DEVICES', 'devices' => array($netAISdevice)),JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
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
};
if($connected) {
	//echo "handshaking as gpsd success, will send data\n";
	// Посылаем $aisData в gpsdPROXY.
	//echo "[uploadTogpsdPROXY] aisData before send to gpsdPROXY: >"; print_r($aisData);
	$msg = json_encode(array('class'=>'netAIS','device'=>$netAISdevice['path'],'data'=>$aisData),JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
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
		return $connected;
	};
};
return $connected;
}; // end function uploadTogpsdPROXY
?>
