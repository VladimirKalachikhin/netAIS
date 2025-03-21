<?php
ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
//ini_set('error_reporting', E_ALL & ~E_STRICT & ~E_DEPRECATED);
chdir(__DIR__); // задаем директорию выполнение скрипта
//echo $_SERVER['PHP_SELF'];

$version = ' v.2.0.0';
/*
1.5.8 restart clients via cron
1.5.2 work with SignalK
1.5.1 work via gpsdPROXY simultaneously with saved data to file
1.5.0 access by index.php, not by netAISserver.php. So it is possible .onion/?member... uri with common Apache2 config. Yes, for stupid NodeJS.

Имеется три сущности:
1) Собственное состояние
2) Клиенты к другим серверам, которые передают собственное состояние и получают чужое
3) Собственный сервер, который обменивается состояниями, включая, но не обязательно, собственное
*/
require('fcommon.php'); 	// 
require('params.php'); 	// 

// Интернационализация
// требуется, чтобы языки были перечислены в порядке убывания предпочтения
//$inStr = 'nb-NO,nb;q=0.9,no-NO;q=0.8,no;q=0.6,nn-NO;q=0.5,nn;q=0.4,en-US;q=0.3,en;q=0.1';
//$appLocales = array_map( function ($l) {return explode(';',$l)[0];},explode(',',$inStr));
$appLocales = array_map( function ($l) {return explode(';',$l)[0];},explode(',',$_SERVER['HTTP_ACCEPT_LANGUAGE']));
// Здесь игнорируются двойные локали (en-US), поэтому американскую локализацию сделать нельзя. Удмуртскую тоже.
$appLocales = array_unique(array_map( function ($l) {return strtolower(explode('-',$l)[0]);},$appLocales));
//echo "<pre>";print_r($appLocales);echo"</pre>";
foreach($appLocales as $appLocale){	// в порядке убывания предпочтения попробуем загрузить файл интернационализации
	$res = @include("internationalisation/$appLocale.php");
	if($res) break;
};
if(!$res) {
	$appLocale = 'en';
	@include("internationalisation/en.php");
}

$netAISJSONfilesDir = getAISdFilesNames($netAISJSONfilesDir); 	// определим имя и создадим каталог для данных netAIS
$serversListFileName = 'server/serversList.csv'; 	// list of available servers, именно здесь, потому что этот каталог не на временной файловой системе
// Это не в session для того, чтобы у всех юзеров были одни и те же данные.
$servers = getServersList();
$selfStatusFileName = 'server/selfStatus'; 	//  array, 0 - Navigational status, 1 - Navigational status Text. место, где хранится состояние клиента
clearstatcache(TRUE,$selfStatusFileName); 	// 
if(($selfStatusTimeOut !== 0) and ((time() - @filemtime($selfStatusFileName)) > $selfStatusTimeOut)) $status = array(); 	// статус протух
else $status = unserialize(@file_get_contents($selfStatusFileName)); 	// считаем файл состояния
if(!$status) {
	$status = array();
	$status['status']=15; 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
	$status['description']='';
	$_REQUEST['statusUpdated'] = 1;
};
$selfMOBfileName = 'server/selfMOB'; 	//  array, 0 - Navigational status, 1 - Navigational status Text. место, где хранится состояние клиента
$statusMOB = unserialize(@file_get_contents($selfMOBfileName)); 	// считаем файл MOB, которого может не быть
//echo "MOB:<pre>";print_r($statusMOB);echo "</pre><br>\n";
$selfVehicle = getSelfParms(); 	// базовая информация о себе: название, позывные, etc. Плоский список, аналошичный списку сведений AIS
/*
$greeting = '{"class":"VERSION","release":"netAISclient","rev":"1","proto_major":5,"proto_minor":3}'; 	// приветствие для gpsdPROXY
$SEEN_GPS = 0x01; $SEEN_AIS = 0x08;
$netAISdevice = array(
'class' => 'DEVICE',
'path' => 'netAISccontrol',
'activated' => date('c'),
'flags' => $SEEN_AIS,
'stopbits' => 1
);
*/
//echo "_REQUEST <pre>";print_r($_REQUEST);echo "</pre><br>\n";
//echo "status <pre>";print_r($status);echo "</pre><br>\n";

// Сервер
// Указание, каким образом предполагается обращаться к вашему серверу.
// Нужно только для проверки наличия транспорта на компьютере.
// Specifies how your server to be accessed.
// It is only necessary to check the availability of transport on the computer.
$selfTransport = array('Yggdrasil');
//$selfTransport = array();
$selfTransport = array_fill_keys($selfTransport,false);	// 
if($torHost) $selfTransport['TOR']=true;	// params.php
if(isset($selfTransport['TOR'])) $selfTransport['TOR'] = checkTOR();
if(isset($selfTransport['Yggdrasil'])) $selfTransport['Yggdrasil'] = checkYgg();
//echo "selfTransport:<pre>"; print_r($selfTransport); echo "</pre>\n";

// Обработка запроса 
$str = ""; 	// переменная часть сообщения в каждой секции
// вкл/выкл собственного сервера
if($_REQUEST['stopServer']) { 	
	@unlink('server/index.php'); 	// 
	$serverOn = FALSE;
	if(isset($servers[$selfServer])) $servers[$selfServer][1] = 0; 	// укажем, что клиент к своему серверу должен быть остановлен
	//echo "Server stopped<br>\n";
}
elseif($_REQUEST['startServer']) {
	@unlink('server/index.php'); 	// 
	$serverOn = serverStart();	// сервер запустим в любом случае, потому что мы не знаем, с каким транспортом кроме указанных он работает
	if(!$serverOn) $str = $serverErrTXT;
	if(!$servers[$selfServer]) $servers[$selfServer] = array($selfServer,0,$myGroupNameTXT,'');
	//echo "Server started<br>\n";
}
// редактор списка серверов групп, в которых участвуем:
elseif($_REQUEST['editClient']) { 	
	//echo $_REQUEST['server'];
	$servers[$_REQUEST['server']][2] = $_REQUEST['serverName'];
	$servers[$_REQUEST['server']][3] = $_REQUEST['serverDescription'];
}
elseif($_REQUEST['delClient']) { 	
	if($_REQUEST['server'] != $selfServer) { 	// удалить клиента к своему серверу нельзя
		killClient($_REQUEST['server']); 	// потому, что для удаляемой записи мог быть запущен клиент.
		unset($servers[$_REQUEST['server']]);
	};
}
elseif($_REQUEST['addClient']) {
	$serverURL = filter_input(INPUT_GET, 'server', FILTER_SANITIZE_URL);
	if(!$serverURL) return;
	$servers[$serverURL][0] = $serverURL;
	$servers[$serverURL][1] = 0;
	$servers[$serverURL][2] = $_REQUEST['serverName'];
	$servers[$serverURL][3] = $_REQUEST['serverDescription'];
}
// запуск/остановка клиента
elseif($_REQUEST['stopClient']) { 	// собственно запуск/остановка происходит в runClients() каждую перезагрузку
	$servers[$_REQUEST['server']][1] = 0;
}
elseif($_REQUEST['startClient']) { 	// 
	$servers[$_REQUEST['server']][1] = 1;
	if($_REQUEST['server'] == $selfServer) { 	// указан клиент к своему серверу
		if(!$serverOn) { 	// сервер сейчас не запущен
			$serverOn = serverStart();
			if(!$serverOn) $str = $serverErrTXT;
			//echo "Server started<br>\n";
		};
	};
}
// изменение статуса
elseif($_REQUEST['criminalAlert']){
	$status['status']=14; 	// 
	$status['description']=$AISstatus14criminalTXT;
	$status['safety_related_text']='A criminal attack!';
}
elseif($_REQUEST['fireAlert']){
	$status['status']=14; 	// 
	$status['description']=$AISstatus14fireTXT;
	$status['safety_related_text']="There's a fire on board!";
}
elseif($_REQUEST['medicalAlert']){
	$status['status']=14; 	// 
	$status['description']=$AISstatus14medicalTXT;
	$status['safety_related_text']='We have a medical emergency!';
}
elseif($_REQUEST['wreckAlert']){
	$status['status']=14; 	// 
	$status['description']=$AISstatus14wreckTXT;
	$status['safety_related_text']='Our vessel is sinking!';
}
elseif($_REQUEST['mobAlert']){
	if($statusMOB["status"]){	// экран же не обновляется, и к моменту нажатия кнопки режим MOB может быть уже прекращён. Не следует здесь начинать его снова.
		// Подпишем точку сообщением из своего статуса.
		// А надо ли это?
		foreach($statusMOB['points'] as &$point){
			if($point['mmsi'] != $selfVehicle['mmsi']) continue;
			if($point['current']) {	// подпишем только текушую точку
				$point['safety_related_text'] = $status['description'];
				break;
			};
		};
		$statusMOB['timestamp'] = time();	// собственно, основное: обновим метку времени, чтобы все клиенты перерисовали этот MOB у себя.
		//echo "MOB alert!:<pre>";print_r($statusMOB);echo "</pre><br>\n";
		file_put_contents($selfMOBfileName,serialize($statusMOB)); 	// сохраним статус MOB
		clearstatcache(TRUE,$selfMOBfileName); 	//
	};
}
elseif($_REQUEST['vehacleStatus'] or $_REQUEST['vehicleDescription'] or ($_REQUEST['vehacleStatus']=='0')) { 	// 
	//echo "vehacleStatus={$_REQUEST['vehacleStatus']}; vehicleDescription={$_REQUEST['vehicleDescription']};<br>\n";
	$status['status']=(int)$_REQUEST['vehacleStatus']; 	// 
	$status['description']=$_REQUEST['vehicleDescription'];
	$status['safety_related_text']=null;
}
elseif($_REQUEST['destinationCommonName'] or ($_REQUEST['destinationCommonName'] === '') or $_REQUEST['destinationETA'] or ($_REQUEST['destinationETA']==='')) { 	// 
	//echo "destinationCommonName={$_REQUEST['destinationCommonName']}; destinationETA={$_REQUEST['destinationETA']};<br>\n";
	$status['destination']=$_REQUEST['destinationCommonName']; 	// 
	$status['eta']=$_REQUEST['destinationETA'];
}; // конец обработки запроса

if($_REQUEST) { 	// возможно, были изменения. Это, типа, псевдосессия, но одна на всех, чтобы у всех юыли одинаковые данные.
	$handle = fopen($serversListFileName, "w"); 	// сохраним список серверов
	foreach($servers as $server){
		fputcsv($handle,$server);
	}
	fclose($handle);
	//echo "<pre>"; print_r($status); echo "</pre>\n";
	file_put_contents($selfStatusFileName,serialize($status)); 	// сохраним статус
}
//echo "status: <pre>"; print_r($status); echo "</pre>\n";
//echo "servers: <pre>"; print_r($servers); echo "</pre>\n";

runClients(); 	// запустим\проверим клиентов для каждого сервера групп, в которых участвуем

// Определим включённость сервера
clearstatcache(TRUE,'server/index.php');
$serverOn = file_exists('server/index.php');
if($serverOn) {
	$buttonImg = "src='img/serverRun.svg' alt='STOP'";
	$buttonName = 'stopServer';
	$serverTXT .= " $serverOnTXT";
}
else { 
	$buttonImg = "src='img/off.svg' alt='START'";
	$buttonName = 'startServer';
	$serverTXT .= " $serverOffTXT";
};

?>
<!DOCTYPE html >
<html>
<head>
	<link href="common.css" rel="stylesheet" type="text/css">
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta http-equiv="Content-Script-Type" content="text/javascript">
	<?php //if($_REQUEST) echo "<meta http-equiv='refresh' content='0; url={$_SERVER['PHP_SELF']}'>"; /*чтобы очистить строку браузера от данных формы*/?>
	<title><?php echo "$title $version";?></title>
</head>
<body style="margin:0; padding:0;">
<?php /* ?>
<div id='infoBox' style='font-size: 90%; position: absolute;'>
</div>
<script>
//alert(window.outerWidth+' '+window.outerHeight);
//infoBox.innerText='width: '+window.outerWidth+' height: '+window.outerHeight;
infoBox.innerText='width: '+window.innerWidth+' height: '+window.innerHeight;
</script>
<?php */ ?>
<div style = '
	width:95%;
	margin:0; padding:0;
<?php // ?>
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
<?php // ?>
	'
>
	<form id='server' style='padding:0.1rem;border:1px solid black;border-radius:5px;' action='<?php echo $_SERVER['PHP_SELF'];?>'>
		<table>
			<tr>
				<td style='width:100%;'><?php 
echo "$serverTXT $str";
if($serverOn){
	if(isset($selfTransport['TOR']) and $selfTransport['TOR']===false) {// Если указано, что транспорт - TOR, но не удалась проверка наличия TOR.
		echo "<br><span style='font-size:75%;'>$torErrTXT</span>\n";
	};
	if(isset($selfTransport['Yggdrasil']) and $selfTransport['Yggdrasil']===false) {// Если указано, что транспорт - Yggdrasil, но не удалась проверка наличия Yggdrasil.
		echo "<br><span style='font-size:75%;'>$yggdrasilErrTXT</span\n";
	};
};
									?>
				</td>
				<td style='width:4rem;'>
					<button type=submit name="<?php echo $buttonName ?>" value='1' style='margin:0rem;padding:0;'>
						<img <?php echo $buttonImg ?>  class='knob'>
					</button>
				</td>
			</tr>
		</table>
	</form>
	<div id='client' style='width:100%;height:53vh;margin:0.5rem 0 0.5rem 0;border:1px solid black;border-radius:5px;'>
		<div style='height:65%;overflow:auto;padding:0.5rem;'>
		<?php
foreach($servers as $url => $server) {	// список подключенных групп
	if(is_int($url)) continue; 	// строки - комментарии
	$disable = false;
	if(strrpos($url,'onion')!==false){
		if(!$selfTransport['TOR']) $disable = $torErrTXT;
	}
	elseif((strpos($url,'[2')!==false) or (strpos($url,'[3')!==false)){
		if(!$selfTransport['Yggdrasil']) $disable = $yggdrasilErrTXT;
	};
		?>
			<form action='<?php echo $_SERVER['PHP_SELF'];?>' style='margin:0.5rem 0 0.5rem 0;'>
				<input type='hidden' name='server' value='<?php echo $server[0] ?>'>
				<table><tr>
					<td>
						<?php if($server[1]) { ?>
						<button type='submit' name="stopClient" value='1' style='margin:0;padding:0;'>
							<img src="img/clientRun.svg" alt="STOP"  class='knob'>
						</button>
						<?php } else { ?>
						<button type=submit name="startClient" value='1' style='margin:0;padding:0;'>
							<img src="img/off.svg" alt="START"  class='knob'>
						</button>
						<?php }; ?>
					</td>
					<td>
						<input type='text' name='serverName' size='17' value='<?php echo htmlentities($server[2],ENT_QUOTES); ?>' disabled style='font-size:90%;'>
					</td>
					<td style='width:100%'>
						<textarea name='serverDescription' rows=2 disabled style='width:100%;font-size:75%;'><?php 
if($disable) echo htmlentities($disable,ENT_QUOTES);
else echo htmlentities($server[3],ENT_QUOTES); 
						?></textarea>
					</td>
					<td>
						<button type='button' name='editClient' value='1' style='margin:0;padding:0;'
							onclick='
								//console.log(this);
								const form = this.closest("form");
								// новая кнопка "удалить"
<?php if($server[0] != $selfServer){	// удалить клиента к своему серверу нельзя 
?>
								let but = form.querySelector("button"); 	// первый button в форме
								but.firstElementChild.src="img/del.svg"; 	// сменим картинку
								but.name = "delClient";
<?php }; ?>
								// включить поля ввода
								form.querySelector("input[type=text]").disabled = false;
								form.querySelector("textarea").disabled = false;
								// новая кнопка "сохранить изменения"
								event.preventDefault(); 	// submit не сработает
								this.type="submit"; 	// сменим тип
								this.firstElementChild.src="img/ok.svg"; 	// сменим картинку
								this.onclick=null;
							'
						>
							<img src="img/edit.svg" alt="EDIT" class='knob'>
						</button>
					</td>
				</tr></table>
			</form>
		<?php
};	// конец списка подключенных групп
		?>
		</div>
		<form action='<?php echo $_SERVER['PHP_SELF'];?>' style='padding:0.5rem 0 0.5rem 0;'>
			<table>
				<tr>
					<td>
						<input type='text' name='server' placeholder='<?php echo $serverPlaceholderTXT ?>' size='17' style='font-size:90%;'>
					</td>
					<td>
						<input type='text' name='serverName' placeholder='<?php echo $serverNamePlaceholderTXT ?>' size='17' style='font-size:90%;'>
					</td>
					<td style='width:100%'>
						<textarea name='serverDescription' placeholder='<?php echo $serverDescrPlaceholderTXT ?>' rows=2 style='width:99%;font-size:75%;padding:0.5rem;'></textarea>
					</td>
					<td>
						<button type=submit name="addClient" value='1' style='margin:0;padding:0;'>
							<img src="img/add.svg" alt="EDIT" class='knob'>
						</button>
					</td>
				</tr>
			</table>
		</form>
	</div>
	<form  action='<?php echo $_SERVER['PHP_SELF'];?>' style='width:100%;margin:0.5rem 0 0.5rem 0;border:1px solid black;border-radius:5px;text-align:center;'>
		<button type=submit name="criminalAlert" value='1' style='margin:1rem;padding:0;width:15%;'>
			<img src="img/robbery.png" alt="Criminal alert!" class='knob'>
		</button>
		<button type=submit name="fireAlert" value='1' style='margin:1rem;padding:0;width:15%;'>
			<img src="img/fire.png" alt="Fire alert!" class='knob'>
		</button>
		<button type=submit name="medicalAlert" value='1' style='margin:1rem;padding:0;width:15%;'>
			<img src="img/medical.png" alt="AID alert!" class='knob'>
		</button>
		<button type=submit name="wreckAlert" value='1' style='margin:1rem;padding:0;width:15%;'>
			<img src="img/shipwreck_danger.png" alt="Ship wreck alert!" class='knob'>
		</button>
		<button type=submit name="mobAlert" value='1' style='<?php if(!$statusMOB["status"]) echo 'display:none;';?>margin:1rem;padding:0;width:15%;'>
			<img src="img/mob_marker.png" alt="The man is overboard!" class='knob'>
		</button>
	</form>
	<div style='width:100%;border:1px solid black;border-radius:5px;'>
		<form  action='<?php echo $_SERVER['PHP_SELF'];?>' id='destination' style='margin:0.5rem 0 0.5rem 0;padding:0.5rem;width:47%;float:right';>
			<input type='text' name='destinationCommonName' onchange="this.form.submit()" placeholder='<?php echo $vehicleDestinationPlaceholderTXT; ?>' size='17' style='font-size:120%;width:97%;margin:0.5rem' value='<?php echo $status['destination'];?>'><br>
			<input type='datetime-local' name='destinationETA' onchange="this.form.submit()" placeholder='<?php echo $vehicleETAplaceholderTXT; ?>' size='17' style='font-size:120%;width:97%;margin:0.5rem' value='<?php echo $status['eta'];?>'>
		</form>
		<form  action='<?php echo $_SERVER['PHP_SELF'];?>' id='status' style='margin:0.5rem;width:47%;'>
		<!--0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use);  15 = undefined = default -->
		<!--0 = Двигаюсь под мотором, 1 = На якоре, 2 = Без экипажа, 3 = Ограничен в манёвре, 4 = Ограничен осадкой, 5 = Ошвартован, 6 = На мели, 7 = Занят ловлей рыбы, 8 = Двигаюсь под парусом, 11 = Тяну буксир (regional use), 12 = Толкаю состав или буксирую под бортом (regional use);  15 = неопределённое = default -->
			<select name='vehacleStatus' onchange="this.form.submit()" size='1' style='width:100%;font-size:150%;text-align: center;'>
				<option value='0' <?php if($status['status'] == 0) echo "selected=1";?> ><?php echo $AISstatusTXT[0]; ?></option>
				<option value='1' <?php if($status['status'] == 1) echo "selected=1";?> ><?php echo $AISstatusTXT[1]; ?></option>
				<option value='2' <?php if($status['status'] == 2) echo "selected=1";?> ><?php echo $AISstatusTXT[2]; ?></option>
				<option value='3' <?php if($status['status'] == 3) echo "selected=1";?> ><?php echo $AISstatusTXT[3]; ?></option>
				<option value='4' <?php if($status['status'] == 4) echo "selected=1";?> ><?php echo $AISstatusTXT[4]; ?></option>
				<option value='5' <?php if($status['status'] == 5) echo "selected=1";?> ><?php echo $AISstatusTXT[5]; ?></option>
				<option value='6' <?php if($status['status'] == 6) echo "selected=1";?> ><?php echo $AISstatusTXT[6]; ?></option>
				<option value='7' <?php if($status['status'] == 7) echo "selected=1";?> ><?php echo $AISstatusTXT[7]; ?></option>
				<option value='8' <?php if($status['status'] == 8) echo "selected=1";?> ><?php echo $AISstatusTXT[8]; ?></option>
				<option value='11' <?php if($status['status'] == 11) echo "selected=1";?> ><?php echo $AISstatusTXT[11]; ?></option>
				<option value='12' <?php if($status['status'] == 12) echo "selected=1";?> ><?php echo $AISstatusTXT[12]; ?></option>
				<option value='14' <?php if($status['status'] == 14) echo "selected=1";?> ><?php echo $AISstatusTXT[14]; ?></option>
				<option value='15' <?php if($status['status'] == 15) echo "selected=1";?> ><?php echo $AISstatusTXT[15]; ?></option>
			</select><br>
			<textarea name='vehicleDescription' onchange="this.form.submit()" placeholder='<?php echo $vehicleDescrPlaceholderTXT; ?>' rows=3 style='width:98%;font-size:75%;margin:0.5rem 0;padding:0.5rem;'>
<?php echo $status['description'];?></textarea>
		</form>
	</div>
</div>
</body>
</html>

<?php



function runClients() {
/* для каждого url в $servers организует периодический запуск клиента */
global $servers,$phpCLIexec,$netAISdHost,$netAISdPort,$netAISJSONfilesDir;
$oneClientRun = 0;
foreach($servers as $uri => $server) {
	if(is_int($url)) continue; 	// строки - комментарии
	// Проверим, есть ли требуемый транспорт
	if(strrpos($server[0],'onion')!==false){
		if(!isset($selfTransport['TOR'])) $selfTransport['TOR'] = checkTOR();
		if(!$selfTransport['TOR']) $server[1] = false;
	}
	elseif((strpos($url,'[2')!==false) or (strpos($url,'[3')!==false)){
		if(!isset($selfTransport['Yggdrasil'])) $selfTransport['Yggdrasil'] = checkYgg();
		if(!$selfTransport['Yggdrasil']) $server[1] = false;
	};
	//echo "[runClients] selfTransport:<pre>"; print_r($selfTransport); echo "</pre>\n";
	if($server[1]) { 	// запустим, он проверяет сам, запущен ли
		//echo "Запускаем netAISclient для сервера {$server[2]}<br>\n";
		exec("$phpCLIexec netAISclient.php -s$uri > /dev/null 2>&1 & echo $!",$psList); 	// exec не будет ждать завершения: & - daemonise; echo $! - return daemon's PID
		//echo "[runClients] exec return <pre>";print_r($psList);echo "</pre><br>\n";	// что характерно, какой-то PID будет всегда, и мы не узнаем, запустился клиент или нет.
		$oneClientRun += 1;
		// Запустим сервер сообщений AIS для тупых
		if($netAISdHost) { 	// он проверяет сам, запущен ли
			exec("$phpCLIexec netAISd.php > /dev/null 2>&1 & echo $!",$psList); 	// exec не будет ждать завершения: & - daemonise; echo $! - return daemon's PID
			//echo "netAISd запущен, PID:"; print_r($psList);
		}
		exec("crontab -l | grep -v '".$phpCLIexec.' netAISclient.php -s'.$uri."'  | crontab -"); 	// удалим запуск клиента из cron
		exec('(crontab -l ; echo "* * * * * '.$phpCLIexec.' netAISclient.php -s'.$uri.'") | crontab - '); 	// добавим запуск клиента в cron, каждую минуту
	}
	else { 	// убъём
		killClient($uri);
		$netAISJSONfileName = $netAISJSONfilesDir.base64_encode($uri);
		//echo "netAISJSONfileName=$netAISJSONfileName;<br>\n";
		@unlink($netAISJSONfileName); 	// если netAIS выключен -- файл с целями должен быть удалён, иначе эти цели будут показываться вечно
		$oneClientRun -= 1;
		exec("crontab -l | grep -v '".$phpCLIexec.' netAISclient.php -s'.$uri."'  | crontab -"); 	// удалим запуск клиента из cron
	}
}
//echo "oneClientRun=$oneClientRun;<br>\n";
if($netAISdHost and ($oneClientRun < 1)) { 	// остановим сервер сообщений AIS для тупых
	//echo "Шлём ?BYE в $netAISdHost $netAISdPort <br>\n";
	exec("echo ?BYE | nc $netAISdHost $netAISdPort > /dev/null 2>&1 &");
}
} // end function runClients

function killClient($uri) {
global $phpCLIexec; 	// from params.php
// Казлы из PHP ниасилили разбор адреса .onion в функции parse_url
// Поэтому здесь костыли для выделения собственно адреса для дальнейшего убивания процесса.
// Я знаю о существовании pkill, но на OpenWRT его нет(?)
if(substr($uri,0,4) != 'http') $uri = 'http://'.$uri;
//echo 'host=<pre>';print_r(parse_url($uri)); echo ";</pre>\n";
//echo 'host='.parse_url($uri, PHP_URL_HOST).";<br>\n";
//echo 'host=<pre>';print_r(pathinfo($uri)); echo ";</pre>\n";
$uri = trim(parse_url($uri, PHP_URL_HOST),'[]');

exec("ps -A w | grep '$uri'",$psList);
if(!$psList) exec("ps w | grep '$uri'",$psList); 	// for OpenWRT. For others -- let's hope so all run from one user
//echo "[killClient] exec uri=$uri;<br>"; //echo "<pre>"; print_r($psList); echo "</pre><br>\n";
foreach($psList as $str) {
	$str = explode(' ',trim($str)); 	// массив слов
	$pid = $str[0];
	foreach($str as $w) {
		switch($w){
		case 'watch':
		case 'ps':
		case 'grep':
		case 'sh':
		case 'bash': 	// если встретилось это слово -- это не та строка
			break 2;
		case $phpCLIexec:
			//echo "Убиваем процесс $pid\n";
			$ret = exec("kill $pid"); 	// exec будет ждать завершения
			break 3;
		}
	}
}
} // end function killClient

function serverStart(){
//exec('ln -sr netAISserver.php server/netAISserver.php'); 	// symlink() не умеет относительные ссылки, и нужен полный путь
//echo readlink('server/netAISserver.php');
// но, однако, busybox не умеет ln -sr, поэтому создаём относительную ссылку через жопу:
//$serverName = 'netAISserver.php';
$serverName = 'index.php';
chdir('server');
@unlink('netAISserver.php'); 	// для совместимости со старыми версиями. Теперь это называется server/index.php
@unlink('index.php');
symlink('../netAISserver.php',$serverName);
chdir('..');
//echo readlink("server/$serverName");
// Определим включённость сервера
clearstatcache(TRUE,"server/$serverName");
$serverOn = file_exists("server/$serverName");
return $serverOn;
} // end function serverStart

function checkTOR(){
/* Определим наличие tor */
global $torPort;	// params.php
//exec("netstat -an | grep LISTEN | grep $torPort",$psList); 	// exec будет ждать завершения
exec("netstat -an | grep $torPort",$psList); 	// exec будет ждать завершения
$torRun = strpos(implode("\n",$psList),'LISTEN');
//echo "torRun=$torRun; exec return <pre>";print_r($psList);echo "</pre><br>\n";
if($torRun===false) return false;
else return true;
}; // end function checkTOR

/*
function checkYgg(){
// For PHP >= 7.3, or: ip -6 addr | grep -oP '(?<=inet6\s)([a-f0-9:]+)(?=/)' 
$ygg = false;
foreach(net_get_interfaces() as $intName => $interface){	// ищем свой адрес Yggdrasil
	if(substr($intName,0,3)!='tun') continue;	//	интерфейс должен быть туннель
	if(!$interface['up']) continue;	// интерфейс должен быть поднят
	foreach($interface['unicast'] as $addr){
		if(substr($addr['address'],0,3)=='201'){	// собственный адрес Yggdrasil
			$ygg = true;
			break 2;
		};
	};
};
return $ygg;
}; // end function checkYgg()
*/
function checkYgg(){
// For PHP < 7.3 
//ip -6 addr | grep -oP "(?<=inet6\s)([a-f0-9:]+)(?=/)"
$ygg = false;
exec('ip -6 addr | grep -oP "(?<=inet6\s)([a-f0-9:]+)(?=/)"',$interfaces);
//echo "[checkYgg] interfaces:<pre>"; print_r($interfaces); echo "</pre>\n";
foreach($interfaces as $addr){
	// собственный адрес Yggdrasil. Они теперь ваще просто с 2 начинаются, а во внутренней сети - с 3.
	if($addr[0]=='2' or $addr[0]=='3'){	
		$ygg = true;
		break;
	};
};
return $ygg;
}; // end function checkYgg()
?>

