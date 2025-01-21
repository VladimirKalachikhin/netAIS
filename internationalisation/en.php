<?php
$title = 'netAIS control panel';
$myGroupNameTXT = 'My group';

$serverTXT = 'You netAIS group:';
$serverOffTXT = ' closed';
$serverOnTXT1 = " open, address: <input type='text' value='";
$serverOnTXT2 = "' size='22' style='font-size:110%;'>";
$serverErrTXT = ' ERR - TOR service not found or onion resource not configure.';
$serverErrTXT1 = ' ERR - TOR service not found';
$serverErrTXT2 = ' ERR - unknown. Rights?';

$serverPlaceholderTXT = 'Required! .onion address';
$serverNamePlaceholderTXT = 'Clear name';
$serverDescrPlaceholderTXT = 'Short description';

$vehicleDestinationPlaceholderTXT = 'Destination common name';
$vehicleETAplaceholderTXT = 'Estimated time of arrival';

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
14=>'I need help',
15=>'undefined'
);
$AISstatus14criminalTXT = 'A criminal attack!';
$AISstatus14fireTXT = "There's a fire on board!";
$AISstatus14medicalTXT = 'We have a medical emergency!';
$AISstatus14wreckTXT = 'Our vessel is sinking!';
$AISstatus14mobTXT = 'The man is overboard!';
$vehicleDescrPlaceholderTXT = 'status description';
?>
