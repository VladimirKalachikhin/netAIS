<?php
function getAISdFilesNames() {
global $netAISJSONfileName;
if(!$netAISJSONfileName) $netAISJSONfileName = 'netaisJSONdata';
$dirName = pathinfo($netAISJSONfileName, PATHINFO_DIRNAME);
$fileName = pathinfo($netAISJSONfileName,PATHINFO_BASENAME);
if((!$dirName) OR ($dirName=='.')) {
	$dirName = sys_get_temp_dir()."/netAIS"; 	// права собственно на /tmp в системе могут быть замысловатыми
	@mkdir($dirName, 0777,true); 	// 
	@chmod($dirName,0777); 	// права будут только на каталог netAIS. Если он вложенный, то на предыдущие, созданные по true в mkdir, прав не будет. Тогда надо использовать umask.
	$netAISJSONfileName = $dirName."/".$fileName;
}

} // end function getAISdFilesNames
?>
