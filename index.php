<?php
$path_parts = pathinfo(__FILE__); // определяем каталог скрипта
chdir($path_parts['dirname']); // задаем директорию выполнение скрипта

$version = ' v.1.5.10';
/*
1.5.8 restart clients via cron
1.5.2 work with SignalK
1.5.1 work via gpsdPROXY simultaneously with saved data to file
1.5.0 access by index.php, not by netAISserver.php. So it is possible .onion/?member... uri with common Apache2 config. Yes, for stupid NodeJS.
*/
require('fcommon.php'); 	// 
require('internationalisation.php'); 	// 
require('params.php'); 	// 
$netAISJSONfilesDir = getAISdFilesNames($netAISJSONfilesDir); 	// определим имя и создадим каталог для данных netAIS
$serversListFileName = 'server/serversList.csv'; 	// list of available servers

//echo $_SERVER['PHP_SELF'];
clearstatcache(TRUE,$selfStatusFileName); 	// from params.php
if(($selfStatusTimeOut !== 0) and ((time() - @filemtime($selfStatusFileName)) > $selfStatusTimeOut)) $status = array(); 	// статус протух
else $status = unserialize(@file_get_contents($selfStatusFileName)); 	// считаем файл состояния
if(!$status) {
	$status = array();
	$status['status']=15; 	// Navigational status 0 = under way using engine, 1 = at anchor, 2 = not under command, 3 = restricted maneuverability, 4 = constrained by her draught, 5 = moored, 6 = aground, 7 = engaged in fishing, 8 = under way sailing, 9 = reserved for future amendment of navigational status for ships carrying DG, HS, or MP, or IMO hazard or pollutant category C, high speed craft (HSC), 10 = reserved for future amendment of navigational status for ships carrying dangerous goods (DG), harmful substances (HS) or marine pollutants (MP), or IMO hazard or pollutant category A, wing in ground (WIG);11 = power-driven vessel towing astern (regional use), 12 = power-driven vessel pushing ahead or towing alongside (regional use); 13 = reserved for future use, 14 = AIS-SART (active), MOB-AIS, EPIRB-AIS 15 = undefined = default (also used by AIS-SART, MOB-AIS and EPIRB-AIS under test)
	$status['description']='';
	$_REQUEST['statusUpdated'] = 1;
}
//echo "_REQUEST <pre>";print_r($_REQUEST);echo "</pre><br>\n";
//echo "status <pre>";print_r($status);echo "</pre><br>\n";

// Сервер
// Определим наличие tor
//exec("netstat -an | grep LISTEN | grep $torPort",$psList); 	// exec будет ждать завершения
exec("netstat -an | grep $torPort",$psList); 	// exec будет ждать завершения
$torRun = strpos(implode("\n",$psList),'LISTEN');
//echo "torRun=$torRun; exec return <pre>";print_r($psList);echo "</pre><br>\n";
if(!$onion) @unlink('server/netAISserver.php'); 	// в конфиге не указан адрес скрытого сервиса -- сервер не может быть включен
// Возьмём список серверов: csv адрес,запущен,название, комментарий
$servers = array();
if (($handle = @fopen($serversListFileName, "r")) !== FALSE) {
	while (($server = fgetcsv($handle, 1000, ",")) !== FALSE) {
		if((!$server) or (count($server) < 4)) continue; 	// пустые и кривые строки
		if(!trim($server[0])) {
			$servers[] = $server; 	// строки - комментарии
			continue;
		}
		if(!$server[2]) $server[2] = parse_url($server[0], PHP_URL_HOST);
		$servers[$server[0]] = $server;
	}
	fclose($handle);
	//echo "<pre>"; print_r($servers); echo "</pre>\n";
}
// Определим включённость сервера
//clearstatcache(TRUE,'server/netAISserver.php');
//$serverOn = file_exists('server/netAISserver.php');
clearstatcache(TRUE,'server/index.php');
$serverOn = file_exists('server/index.php');


$str = ""; 	// переменная часть сообщения в каждой секции
// Обработка запроса 
// вкл/выкл сервера
if($_REQUEST['stopServer']) { 	
	@unlink('server/netAISserver.php'); 	// 
	@unlink('server/index.php'); 	// 
	$str = $serverOffTXT;
	$serverOn = FALSE;
	if($servers[$onion]) $servers[$onion][1] = 0; 	// укажем, что клиент к своему серверу должен быть остановлен
	//echo "Server stopped<br>\n";
}
elseif($_REQUEST['startServer']) {
	@unlink('server/netAISserver.php'); 	// 
	@unlink('server/index.php'); 	// 
	$serverOn = FALSE;
	if($torRun and $onion) {
		$serverOn = serverStart();
		if($serverOn) {
			if(!$servers[$onion]) $servers[$onion] = array($onion,0,$onion,$myGroupNameTXT);
			$servers[$onion][1] = 1; 	// укажем, что клиент к своему серверу должен быть запущен
		}
		else $str = $serverErrTXT2;
	}
	else  $str = $serverErrTXT; 	// СБОЙ - не запущена служба tor или не сконфигурирован сервис onion.
	//echo "Server started<br>\n";
}
// редактор списка серверов
elseif($_REQUEST['editClient']) { 	
	//echo $_REQUEST['server'];
	$servers[$_REQUEST['server']][2] = $_REQUEST['serverName'];
	$servers[$_REQUEST['server']][3] = $_REQUEST['serverDescription'];
}
elseif($_REQUEST['delClient']) { 	
	
	if($_REQUEST['server'] <> $onion) { 	// удалить клиента к своему серверу нельзя
		killClient($_REQUEST['server']); 	// потому, что для удаляемой записи мог быть запущен клиент.
		unset($servers[$_REQUEST['server']]);
	}
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
	if($_REQUEST['server'] == $onion) { 	// указан клиент к своему серверу
		if(!$serverOn) { 	// сервер сейчас не запущен
			if($torRun and $onion) {
				$serverOn = serverStart();
				if(!$serverOn) $str = $serverErrTXT2;
			}
			else  {
				$str = $serverErrTXT; 	//  СБОЙ - не запущена служба tor или не сконфигурирован сервис onion.
				$servers[$onion][1] = 0; 	// 
			}
			//echo "Server started<br>\n";
		}
	}
}
// изменение статуса
elseif($_REQUEST['vehacleStatus'] or $_REQUEST['vehicleDescription'] or ($_REQUEST['vehacleStatus']=='0')) { 	// 
	//echo "vehacleStatus={$_REQUEST['vehacleStatus']}; vehicleDescription={$_REQUEST['vehicleDescription']};<br>\n";
	$status['status']=(int)$_REQUEST['vehacleStatus']; 	// 
	$status['description']=$_REQUEST['vehicleDescription'];
}
elseif($_REQUEST['destinationCommonName'] or ($_REQUEST['destinationCommonName'] === '') or $_REQUEST['destinationETA'] or ($_REQUEST['destinationETA']==='')) { 	// 
	//echo "destinationCommonName={$_REQUEST['destinationCommonName']}; destinationETA={$_REQUEST['destinationETA']};<br>\n";
	$status['destination']=$_REQUEST['destinationCommonName']; 	// 
	$status['eta']=$_REQUEST['destinationETA'];
}

if($_REQUEST) { 	// возможно, были изменения
	$handle = fopen($serversListFileName, "w"); 	// сохраним список серверов
	foreach($servers as $server){
		fputcsv($handle,$server);
	}
	fclose($handle);
	//echo "<pre>"; print_r($status); echo "</pre>\n";
	file_put_contents($selfStatusFileName,serialize($status)); 	// сохраним статус
}
//echo "<pre>"; print_r($status); echo "</pre>\n";

runClients(); 	// запустим\проверим клиентов для каждого сервера

if($serverOn) {
	$img = "src='img/serverRun.svg' alt='STOP'";
	$name = 'stopServer';
	if($torRun) { 	// команд не было, просто релоад, и обнаружилось, что tor умер
		if(!$str) $str = $serverOnTXT1.$onion.$serverOnTXT2;
	}
	else $str = $serverErrTXT1; 	// СБОЙ - не запущена служба tor
}
else { 
	$img = "src='img/off.svg' alt='START'";
	$name = 'startServer';
	if(!$str) $str = $serverOffTXT;
}
?>
<!DOCTYPE html >
<html lang="ru">
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
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);'>
<form id='server' 
style='padding:0.1rem;border:1px solid black;border-radius:5px;'
action='<?php echo $_SERVER['PHP_SELF'];?>'
>
	<table>
	<tr>
	<td style='width:100%;'><?php echo "$serverTXT $str";?></td>
	<td style='width:4rem;'>
		<button type=submit name="<?php echo $name ?>" value='1' style='margin:0rem;padding:0;'>
			<img <?php echo $img ?>  class='knob'>
		</button>
	</td>
	</tr>
	</table>
</form>

<div id='client'
style='width:100%;height:53vh;margin:0.5rem 0 0.5rem 0;border:1px solid black;border-radius:5px;'
>
<div style='height:65%;overflow:auto;padding:0.5rem;'>
<?php
//echo "torRun=$torRun;<br>";
if(!$torRun) echo $serverErrTXT1; 	// СБОЙ - не запущена служба tor
else {
	foreach($servers as $url => $server) {
		if(is_int($url)) continue; 	// строки - комментарии
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
			<?php } ?>
		</td>
		<td>
			<input type='text' name='serverName' size='17' value='<?php echo htmlentities($server[2],ENT_QUOTES); ?>' disabled style='font-size:90%;'>
		</td>
		<td style='width:100%'>
			<textarea name='serverDescription' rows=2 disabled style='width:100%;font-size:75%;'>
<?php echo htmlentities($server[3],ENT_QUOTES); ?></textarea>
		</td>
		<td>
			<button type='button' name='editClient' value='1' style='margin:0;padding:0;'
			onclick='
				//console.log(this);
				const form = this.closest("form");
				// новая кнопка "удалить"
				let but = form.querySelector("button"); 	// первый button в форме
				but.firstElementChild.src="img/del.svg"; 	// сменим картинку
				but.name = "delClient";
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
	}
}
?>
</div>
<form action='<?php echo $_SERVER['PHP_SELF'];?>' style='padding:0.5rem 0 0.5rem 0;'>
<table><tr>
	<td>
		<input type='text' name='server' placeholder='<?php echo $serverPlaceholderTXT ?>' size='17' style='font-size:90%;'>
	</td>
	<td>
		<input type='text' name='serverName' placeholder='<?php echo $serverNamePlaceholderTXT ?>' size='17' style='font-size:90%;'>
	</td>
	<td style='width:100%'>
		<textarea name='serverDescription' placeholder='<?php echo $serverDescrPlaceholderTXT ?>' rows=2 style='width:99%;font-size:75%;padding:0.5rem;'>
</textarea>
	</td>
	<td>
		<button type=submit name="addClient" value='1' style='margin:0;padding:0;'>
			<img src="img/add.svg" alt="EDIT" class='knob'>
		</button>
	</td>
</tr></table>
</form>
</div>
<?php ?>
<div 
style='width:100%;border:1px solid black;border-radius:5px;'
>
<form  action='<?php echo $_SERVER['PHP_SELF'];?>' id='destination'
style='margin:0.5rem 0 0.5rem 0;padding:0.5rem;width:47%;float:right';
>
	<input type='text' name='destinationCommonName' onchange="this.form.submit()" placeholder='<?php echo $vehicleDestinationPlaceholderTXT; ?>' size='17' style='font-size:120%;width:97%;margin:0.5rem' value='<?php echo $status['destination'];?>'><br>
	<input type='datetime-local' name='destinationETA' onchange="this.form.submit()" placeholder='<?php echo $vehicleETAplaceholderTXT; ?>' size='17' style='font-size:120%;width:97%;margin:0.5rem' value='<?php echo $status['eta'];?>'>
</form>
<form  action='<?php echo $_SERVER['PHP_SELF'];?>' id='status'
style='margin:0.5rem;width:47%;'
>
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
	if($server[1]) { 	// запустим, он проверяет сам, запущен ли
		//echo "Запускаем netAISclient для сервера {$server[2]}<br>\n";
		exec("$phpCLIexec netAISclient.php -s$uri > /dev/null 2>&1 & echo $!",$psList); 	// exec не будет ждать завершения: & - daemonise; echo $! - return daemon's PID
		//echo "[runClients] exec return <pre>";print_r($psList);echo "</pre><br>\n";	// что характерно, какой-то PID будет всегда, и мы не узнаем, запустился клиент или нет.
		$oneClientRun += 1;
		// Запустим сервер сообщений AIS для тупых
		if($netAISdHost) { 	// он проверяет сам, запущен ли
			exec("$phpCLIexec netAISd.php > /dev/null 2>&1 & echo $!",$psList); 	// exec не будет ждать завершения: & - daemonise; echo $! - return daemon's PID
		}
		exec("crontab -l | grep -v '".$phpCLIexec.'netAISclient.php -s'.$uri."'  | crontab -"); 	// удалим запуск клиента из cron
		exec('(crontab -l ; echo "* * * * * '.$phpCLIexec.'netAISclient.php -s'.$uri.'") | crontab - '); 	// добавим запуск клиента в cron, каждую минуту
	}
	else { 	// убъём
		killClient($uri);
		$netAISJSONfileName = $netAISJSONfilesDir.$uri;
		@unlink($netAISJSONfileName); 	// если netAIS выключен -- файл с целями должен быть удалён, иначе эти цели будут показываться вечно
		$oneClientRun -= 1;
		exec("crontab -l | grep -v '".$phpCLIexec.'netAISclient.php -s'.$uri."'  | crontab -"); 	// удалим запуск клиента из cron
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
exec("ps -A w | grep '$uri'",$psList);
if(!$psList) exec("ps w | grep '$uri'",$psList); 	// for OpenWRT. For others -- let's hope so all run from one user
//echo "res=$res ps w | grep '$uri':<pre>"; print_r($psList); echo "</pre><br>\n";
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
@unlink('netAISserver.php');
@unlink('index.php');
symlink('../netAISserver.php',$serverName);
chdir('..');
//echo readlink("server/$serverName");
// Определим включённость сервера
clearstatcache(TRUE,"server/$serverName");
$serverOn = file_exists("server/$serverName");
return $serverOn;
} // end function serverStart
?>

