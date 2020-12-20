<?php
if(strpos($_SERVER['HTTP_ACCEPT_LANGUAGE'],'ru')===FALSE) { 	// клиент - нерусский
//if(TRUE) { 	// 
	$title = 'netAIS control panel';
	$myGroupNameTXT = 'My group';
	
	$serverTXT = 'You netAIS group:';
	$serverOffTXT = ' closed';
	$serverOnTXT1 = " open, address: <input type='text' value='";
	$serverOnTXT2 = "' size='17' style='font-size:110%;'>";
	$serverErrTXT = ' ERR - TOR service not found or onion resource not configure.';
	$serverErrTXT1 = ' ERR - TOR service not found';
	
	$serverPlaceholderTXT = 'Required! .onion address';
	$serverNamePlaceholderTXT = 'Clear name';
	$serverDescrPlaceholderTXT = 'Short description';

	$AISstatusTXT = array(
	0=>'under way using engine',
	1=>'at anchor',
	2=>'not under command',
	3=>'restricted maneuverability',
	4=>'constrained by her draught',
	5=>'moored',
	6=>'aground',
	7=>'engaged in fishing',
	8=>'under way sailing',
	11=>'power-driven vessel towing astern',
	12=>'power-driven vessel pushing ahead or towing alongside',
	15=>'undefined'
	);
	$vehicleDescrPlaceholderTXT = 'status description';
}
else {
	$title = 'Управление netAIS';
	$myGroupNameTXT = 'Моя группа';
	
	$serverTXT = 'Своя группа netAIS:';
	$serverOffTXT = ' не запущена';
	$serverOnTXT1 = " работает, адрес: <input type='text' value='";
	$serverOnTXT2 = "' size='17' style='font-size:110%;'>";
	$serverErrTXT = ' СБОЙ - не запущена служба tor или не сконфигурирован сервис onion.';
	$serverErrTXT1 = ' СБОЙ - не запущена служба tor';
	
	$serverPlaceholderTXT = 'Нужно! .onion адрес';
	$serverNamePlaceholderTXT = 'Понятное наименование';
	$serverDescrPlaceholderTXT = 'Краткое описание';
	
	$AISstatusTXT = array(
	0=>'Двигаюсь под мотором',
	1=>'На якоре',
	2=>'Без экипажа',
	3=>'Ограничен в манёвре',
	4=>'Ограничен осадкой',
	5=>'Ошвартован',
	6=>'На мели',
	7=>'Занят ловлей рыбы',
	8=>'Двигаюсь под парусом',
	11=>'Тяну буксир',
	12=>'Толкаю состав или буксирую под бортом',
	15=>'неопределённое'
	);
	$vehicleDescrPlaceholderTXT = 'Описание состояния';
}
?>
