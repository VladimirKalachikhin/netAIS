<?php
/* netAIS server
*/
ini_set('error_reporting', E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
//ini_set('error_reporting', E_ALL & ~E_STRICT & ~E_DEPRECATED);
ob_start(); 	// попробуем перехватить любой вывод скрипта

$self = realpath(__FILE__); // определяем реальный каталог самого скрипта, не ссылки
$path_parts = pathinfo($self);
chdir($path_parts['dirname']); // задаем директорию выполнение скрипта

require('fcommon.php'); 	// 
require('params.php'); 	// 
$netAISJSONfilesDir = getAISdFilesNames($netAISJSONfilesDir); 	// определим имя и создадим каталог для данных netAIS
$netAISserverDataFileName = $netAISJSONfilesDir.'netAISserverData';	// сюда, потому что этот каталог нормально размещён в tmp
//echo "netAISserverDataFileName=$netAISserverDataFileName<br>\n";

// сведения от клиента о себе
// именно одного клиента, и одного MOB
// в принципе, можно было бы принимать прямо массив mmsi => array() в формате gpsdPROXY,
// но тогда каждый дурак мог бы прислать кучу корабликов
$member = json_decode(@$_REQUEST['member'],TRUE, 512, JSON_BIGINT_AS_STRING); 	// JSON_BIGINT_AS_STRING возможно поможет для строк -- числовых кодов. Не помогает.
//echo "member: <pre>"; print_r($member); echo "</pre>\n";
$mob = json_decode(@$_REQUEST['mob'],TRUE, 512, JSON_BIGINT_AS_STRING); 	// сведения от клиента о MOB
//echo "mob: <pre>"; print_r($mob); echo "</pre>\n";

clearstatcache(TRUE,$netAISserverDataFileName);
if(file_exists($netAISserverDataFileName)) {
	$aisData = json_decode(file_get_contents($netAISserverDataFileName),TRUE); 	// 
}
else {
	$aisData = array();
}
//echo "aisData before: <pre>"; print_r($aisData);echo "</pre\n>";

updAISdata($mob);	// запишем MOB обратившегося клиента в общий файл
//echo "aisData before with mob: <pre>"; print_r($aisData);echo "</pre\n>";

if((!@$member['lon'])or(!@$member['lat'])) {
	$aisData = 'Spatial info required, sorry.';
	http_response_code(400);
	goto OUT;
} 

$member['netAIS'] = TRUE;
updAISdata($member); 	// запишем обратившегося клиента в общий файл
//echo "aisData before clearing: <pre>"; print_r($aisData);echo "</pre\n>";

// Почистим общий файл от старых целей
$now = time();
foreach($aisData as $vehicle => &$data) {
	if(substr($vehicle,0,2)=='97') continue;	// всякие MOB и прочие alarm будут висеть вечно
	//echo "noVehicleTimeout=$noVehicleTimeout; данные протухли на ".($now-$data['timestamp'])." сек.<br>\n";
	if(($now-$data['timestamp'])>$noVehicleTimeout) unset($aisData[$vehicle]);
}
//echo "aisData: <pre>"; print_r($aisData);echo "</pre\n>";
file_put_contents($netAISserverDataFileName,json_encode($aisData,JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE));
clearstatcache(TRUE,$netAISserverDataFileName);

http_response_code(200);
OUT:
$aisData=json_encode($aisData,JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
ob_end_clean(); 			// очистим, если что попало в буфер
header('Content-Type: application/json;charset=utf-8;');
echo "$aisData \n";
return;


function updAISdata($vehicleInfo) {
/**/
global $aisData;
$vehicle = @$vehicleInfo['mmsi'];
if(!$vehicle) $vehicle = @$vehicleInfo['source'];
if(!$vehicle) return; 	// оно может быть пустое
foreach($vehicleInfo as $opt => $value) {
	$aisData[$vehicle][$opt] = $value; 	// 
};
}; // end function updAISdata

?>

