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
	$path = $dirName."/".$fileName.'/';
	$umask = umask(0); 	// сменим на 0777 и запомним текущую
	@mkdir($path, 0777,true); 	// 
	@chmod($path,0777); 	// права будут только на каталог netAIS. Если он вложенный, то на предыдущие, созданные по true в mkdir, прав не будет. Тогда надо использовать umask.
	umask($umask); 	// 	Вернём. Зачем? Но umask глобальна вообще для всех юзеров веб-сервера
}
else $path .= '/';
//echo "path=$path;\n";
return $path;
} // end function getAISdFilesNames
?>
