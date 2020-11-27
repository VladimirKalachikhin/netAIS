<?php
/* netAIS server
*/
ob_start(); 	// попробуем перехватить любой вывод скрипта

$self = realpath(__FILE__); // определяем реальный каталог самого скрипта, не ссылки
$path_parts = pathinfo($self);
chdir($path_parts['dirname']); // задаем директорию выполнение скрипта

require('params.php'); 	// 
getAISdFilesNames();
//echo "netAISserverDataFileName=$netAISserverDataFileName<br>\n";

$member = json_decode(@$_REQUEST['member'],TRUE, 512, JSON_BIGINT_AS_STRING); 	// сведения от клиента, JSON_BIGINT_AS_STRING возможно поможет для строк -- числовых кодов. Не помогает.
//echo "member: <pre>"; print_r($member); echo "</pre>\n";

if((!@$member['lon'])or(!@$member['lat'])) {
	$aisData = 'Spatial info required, sorry.';
	goto OUT;
} 

$member['netAIS'] = TRUE;

clearstatcache(TRUE,$netAISserverDataFileName);
if(file_exists($netAISserverDataFileName)) {
	$aisData = json_decode(file_get_contents($netAISserverDataFileName),TRUE); 	// 
}
else {
	$aisData = array();
}
//echo "aisData: <pre>"; print_r($aisData);echo "</pre\n>";

updAISdata($member); 	// запишем обратившегося клиента в общий файл
// Почистим общий файл от старых целей
$now = time();
foreach($aisData as $vehicle => &$data) {
	if(($now-$data['timestamp'])>$noVehicleTimeout) unset($aisData[$vehicle]);
}
//echo "aisData: <pre>"; print_r($aisData);echo "</pre\n>";
file_put_contents($netAISserverDataFileName,json_encode($aisData));
clearstatcache(TRUE,$netAISserverDataFileName);

OUT:
$aisData=json_encode($aisData);
ob_end_clean(); 			// очистим, если что попало в буфер
header('Content-Type: application/json;charset=utf-8;');
echo "$aisData \n";
return;


function updAISdata($vehicleInfo) {
/**/
global $aisData;
$vehicle = @$vehicleInfo['mmsi'];
if(!$vehicle) return; 	// оно может быть пустое
foreach($vehicleInfo as $opt => $value) {
	$aisData[$vehicle][$opt] = $value; 	// 
}
}

function getAISdFilesNames() {
global $netAISserverDataFileName;
if(!$netAISserverDataFileName) $netAISserverDataFileName = 'netAISserverData';
$dirName = pathinfo($netAISserverDataFileName, PATHINFO_DIRNAME);
$fileName = pathinfo($netAISserverDataFileName,PATHINFO_BASENAME);
if((!$dirName) OR ($dirName=='.')) {
	$dirName = sys_get_temp_dir()."/netAIS"; 	// права собственно на /tmp в системе могут быть замысловатыми
	@mkdir($dirName, 0777,true); 	// 
	@chmod($dirName,0777); 	// права будут только на каталог netAIS. Если он вложенный, то на предыдущие, созданные по true в mkdir, прав не будет. Тогда надо использовать umask.
	$netAISserverDataFileName = $dirName."/".$fileName;
}
}

?>

