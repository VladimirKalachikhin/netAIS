<?php
$noVehicleTimeout = 600; 	// seconds, time of continuous absence of the vessel in AIS, when reached - is deleted from the data. "when a ship is moored or at anchor, the position message is only broadcast every 180 seconds;"

// Пути и параметры Paths
// client
$netAISJSONfileName = 'netaisJSONdata'; 	// recommended on "portable" os - such as OpenWRT. файл данных AIS, такой же, как у gpsdAISd. Туда добавляются цели от netAIS
//$netAISJSONfileName = '/home/www-data/netAIS/data/netaisJSONdata'; 	// recommended on "full futured" os, such as Ubuntu. файл данных AIS, такой же, как у gpsdAISd. Туда добавляются цели от netAIS
$selfStatusFileName = 'data/selfStatus'; 	//  array, 0 - Navigational status, 1 - Navigational status Text. место, где хранится состояние клиента
$selfStatusTimeOut = 60*60*24; 	// in sec, one day. If no change this time - exit
$serversListFileName = 'data/serversList.csv'; 	// list of available servers
// server
$netAISserverDataFileName = 'netAISserverData'; 	// recommended on "portable" os - such as OpenWRT. файл, куда будем складывать цели netAIS, аналогично файлу $aisJSONfileName. Фактически, это одна сессия для всех клиентов.
//$netAISserverDataFileName = '/home/www-data/netAIS/data/netAISserverData'; 	// recommended on "full futured" os, such as Ubuntu. файл данных AIS, такой же, как у gpsdAISd. Туда добавляются цели от netAIS
// tor hidden service address
// config you http server correctly to run tor hidden service!
//$onion = 'axwiptv2ewxmcv24.onion'; 	// Server onion address. If not - no server run. This is a content of /var/lib/tor/hidden_service_netAIS/hostname file
$onion = ''; 	// Server onion address. If not - no server run. This is a content of /var/lib/tor/hidden_service_netAIS/hostname file
$torPort = 9050; 	// from torrc, default 9050

$phpCLIexec = 'php'; 	// php-cli executed name on your OS
$gpsdHost = 'localhost';
$gpsdPort = 2947;
?>
