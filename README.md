# netAIS [![License: CC BY-SA 4.0](https://img.shields.io/badge/License-CC%20BY--SA%204.0-lightgrey.svg)](https://creativecommons.org/licenses/by-sa/4.0/)

## v. 0.0

Exchange AIS-like messages via the Internet to watch position members of your private group. No need to a dedication server with a real IP address.  
Suitable for fishing, regatta and collective water recreation.  

![scheme](screenshots/art.png)   
Software use [TOR](torproject.org) as a communication environment, so it works smoothly via mobile internet and public wi-fi.  
Alpha-version works only with [GaladrielMap](http://galadrielmap.hs-yachten.at/) at now, sorry.

## Features
* Serving one private group.
* Membership in any number groups.
* English and Russian web-interface.

## Technical
Any of the software kit has a client and a server for one private group. The server must be configured as a TOR hidden service.  
You must get .onion address of this hidden service by anyway - by email, SMS or pigeon post, and configure the client with it.  
The client calls to the server with spatial and other info in AIS-like format. Server return info about all group members.  
This info puts to file and may be got asynchronously.  
Info is a JSON encoded array with MMSI keys and an array of data as value. The data are key-value pair as described in gpsd/www/AIVDM.adoc (if you have gpsd) and [e-Navigation Netherlands](http://www.e-navigation.nl/system-messages) site, except:

* The units of measurement are given in the human species
* The timestamp  is Unix timestamp

Also, this file format identical  [gpsdAISd](https://github.com/VladimirKalachikhin/gpsdAISd) file format.

## Compatibility
Linux. 

## Install&configure:
You must have a web server under Linux with php support and [TOR service](https://2019.www.torproject.org/docs/tor-manual.html.en).
Copy the project files to a web server directory and adjust paths in _params.php_.  
Set _write_ access to `data/` and `server/` directories for web server user (www-data?).
[Configure TOR hidden service](https://2019.www.torproject.org/docs/tor-onion-service.html.en) to `server/` directory if you are going to support a corporate group. It's no need if you want to be a group member only.  

### Vehicle info
The information abou you vehicle stored in _boatInfo.ini_ file. Fill it correctly.

## Web-interface
![screen](screenshots/s1.png)   
Web-interface allows you to control: 

* Open/close your private group (server On/Off) - the first section of the screen.
* Configure membership and start/stop watch on other groups - middle section.
* Set your own status and the message to bring - bottom section.

Web-interface optimised to mobile and/or e-Inc devices, old ones including.

## Thanks
* [Metrize Icons by Alessio Atzeni](https://icon-icons.com/pack/Metrize-Icons/1130) for icons.
## Support
You can get support for netAIS software for a beer [via PayPal](https://paypal.me/VladimirKalachikhin) or [YandexMoney](https://yasobe.ru/na/galadrielmap) at [galadrielmap@gmail.com](mailto:galadrielmap@gmail.com)  