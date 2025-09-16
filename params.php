<?php
// Период обновления сведений, сек. С этим интервалом опрашивается каждая из
// подключенных групп на предмет новых данных.
$poolInterval = 5;	// Information update period, sec. At this interval, each of the connected groups is polled for new data.

// Время, пока данные о судне считаются актуальными, если они не обновляются.
$noVehicleTimeout = 600; 	// seconds, time of continuous absence of the vessel in netAIS, when reached - is deleted from the data. "when a ship is moored or at anchor, the position message is only broadcast every 180 seconds;"

// Если не менять статус за это время -- приём netAIS выключается. Ибо юзер может забыть про netAIS и транслировать неверную информацию.
//$selfStatusTimeOut = 60*60*24; 	// in sec, one day. If no changes by this time - recieve netAIS sets off. If -- 0 - don't check. 
$selfStatusTimeOut = 0;

// Пути и параметры Paths
// client
// каталог для файлов данных AIS. Туда добавляются цели от netAIS, свой файл для каждой группы, и данные сервера
// если имя каталога указано без пути, он будет размещён в /tmp, что рекомендовано для "упрощённых" операционных систем типа OpenWRT
// если указан полный путь -- каталог буде размещён где указано. Это рекомендовано для "полнофункциональных" операционных систем для избежания проблем с доступом к файлам в /tmp других процессов
// directory for AIS data
// if without path this is placed in /tmp. It is recommended on "portable" os - such as OpenWRT.
// with the path - placed by path. Recommended on "full futured" os, such as Ubuntu, to avoid files access troubles from other processes.
//$netAISJSONfilesDir = 'data/'; 	// 
$netAISJSONfilesDir = '/home/www-data/netAIS/data/'; 	// 

// Подключение к tor proxy. Нужно для членов групп и для держателя собственной группы, если
// общение осуществляется через tor.
// Если общение идёт через локальную сеть, yggdrasil или реальный Internet - указывать не надо.
// Tor proxy connection. This is necessary if you are a client of a group
// that communicates through a TOR, or you yourself are the owner of such a group. 
$torHost = 'localhost';
$torPort = 9050; 	// from torrc, default 9050

// Адрес сервера вашей приватной группы в локальной сети. Нужен, чтобы автоматически
// включить вас самих в вашу же приватную группу. Можно не указывать, и включить себя в свою
// группу руками.
// The server address of your private group on the local network. It is needed to automatically
// include yourself in your own private group. You can leave it out and include yourself in your
// group with your own hands.
// Если вы не держите собственную группу - указывать не нужно.
// If you don't keep your own group, you don't need to specify it.
//$selfServer = 'localhost:8888';
$selfServer = 'localhost/netAIS/server/';

// Пояснительный текст к серверу приватной группы. В основном для того, чтобы указать адреса,
// по которым отвечает сервер. Потому что адрес TOR запомнить невозможно, а как получить его -
// можно сразу и не вспомнить.
// Clarification text to the private group's server. Basically, in order to specify the addresses
// that the server responds to. Because it is impossible to remember the TOR address,
// and you may not immediately remember how to get it.
// Скорее всего, адрес сервиса TOR можно получить как-то так:
// Most likely, the address of the TOR service can be obtained somehow like this:
// # cat /var/lib/tor/hidden_service_netAIS/hostname
// Адрес yggdrasil получается так:
// The Yggdrasil address can be obtained by this:
// # yggdrasilctl getself
// Никогда не выставляйте в публичную сеть, будь то Yggdrasil или Internet, интерфейс
// управления netAIS /netAIS/index.html!
// Конфигурируйте ваш веб-сервер так, чтобы адрес /netAIS/index.html был доступен только
// в локальной сети!
// Never expose the netAIS management interface /netAIS/index.html to a public network, such as Yggdrasil or the Internet!
// Configure your web server to the address /netAIS/index.html is only available on the local network!
$selfServerMemoTXT = '
Например:
Общедоступный демо-сервер netAIS отвечает по следующим адресам:
For example:
The publicly accessible netAIS demo server can be accessed at the following addresses:
eqavt5cdur7vbzoejquiwviok4tfexy32sggxdxujm75uiljqi5g27ad.onion
http://[200:6be:9cfb:6551:8e3d:cd06:a928:85a]/netAIS/ 
';

// Сервер-ретранслятор своих (клиентских) данных netAIS в формате потока NMEA или gpsd json.
// Нужен для показа данных netAIS в сторонних программах и на картплотерах.
// AIS flow daemon. netAIS feed by "gpsd://" or NMEA protocol.
// Use this for apps other than GaladrielMap, such as OpenCPN or iron chartplotter.
// Если не указано - не запускается.
//$netAISdHost='localhost'; 	// comment to disable. 
//$netAISdHost='192.168.10.10';
//$netAISdHost='0.0.0.0';
$netAISdPort=3900;
// Отсылать данные netAIS с интервалом в указанное количество секунд.
// Если указать 0 или не указать, данные будут отсылаться так часто, как их сможет читать клиент.
// В этом случае, например, gpsd будет читать данные непрерывно, с такой скоростью, которую позволит производительность компьютера.
$netAISdPeriod=5;	// send netAIS data every sec. If 0 or null - send it by client read. On this case, the gpsd will read data continuously.

// Источник информации о себе
// Self data source
$netAISgpsdHost = 'localhost';
//$netAISgpsdPort = 2947;	//	gpsd
$netAISgpsdPort = 3838; 	// gpsdPROXY

// если используется gpsdPROXY, и он нигде не запускается отдельно, укажите здесь полное имя для его запуска:
// If you use gpsdPROXY, and no start it separately, place full filename here to start it:
$gpsdPROXYname = '../gpsdPROXY/gpsdPROXY.php';

// Signal K
//$netAISsignalKhost = array(['localhost',3000]);

// system
$phpCLIexec = 'php'; 	// php-cli executed name on your OS
//$phpCLIexec = '/usr/bin/php-cli'; 	// php-cli executed name on your OS
?>
