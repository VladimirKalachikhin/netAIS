<?php
function getAISdFilesNames($path) {
$path = rtrim($path,'/');
//echo "path=$path;\n";
if(!$path) $path = 'data';
$dirName = pathinfo($path, PATHINFO_DIRNAME);
$fileName = pathinfo($path,PATHINFO_BASENAME);
//echo "dirName=$dirName; fileName=$fileName;\n";
if((!$dirName) OR ($dirName=='.')) {
	$dirName = sys_get_temp_dir()."/netAIS"; 	// права собственно на /tmp в системе могут быть замысловатыми
	@mkdir($dirName, 0777,true); 	// 
	@chmod($dirName,0777); 	// права будут только на каталог netAIS. Если он вложенный, то на предыдущие, созданные по true в mkdir, прав не будет. Тогда надо использовать umask.
	$path = $dirName."/".$fileName.'/';
}
else $path .= '/';
//echo "path=$path;\n";
return $path;
} // end function getAISdFilesNames
?>
