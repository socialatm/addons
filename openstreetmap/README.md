# OpenStreetMap Plugin
by Mike Macgirvin and Klaus Weidenbach

This addon allows you to use OpenStreetMap for displaying locations.

## Requirements

To use this plugin you need a tile server that provides the map images.
OpenStreetMap data is free for everyone to use. Their tile servers are not.
Please take a look at their "Tile Usage Policy":
http://wiki.openstreetmap.org/wiki/Tile_usage_policy
You can run your own tile server or choose one from their list of public
tile servers: http://wiki.openstreetmap.org/wiki/TMS
Support the OpenStreetMap community and share the load.
The same counts for Nominatim, the reverse geocoding service, that will
translate place names to coordinates.
http://wiki.openstreetmap.org/wiki/Nominatim and their usage policy:
http://wiki.openstreetmap.org/wiki/Nominatim_usage_policy

## Configuration

Activate the plugin from your admin panel.

In the plugin settings page of your admin panel you can now configure:

* the *tmsserver* Tile Server (which map server to open if we have coordinates)
* the *nomserver* Nominatim Server (which server to use to look up coordinates
for place names)
* default *zoom* level
* if a *marker* should get shown on the map if we have coordinates

The Tile Server URL points to the tile server you want to use. Use the full URL,
with protocol (http/s) and trailing slash.
The Nominatim Server URL points to the reverse geocode service you want to use.
Use the full URL with protocol (http/s) and path.
You can configure the default zoom level on the map in the Default Zoom box.
1 will show the whole world and 18 is the highest zoom level available.
You can configure if a marker should be shown on the map

You can also use the CLI config utility for configuration:

    $ ./util/config openstreetmap tmsserver "http://www.openstreetmap.org/"
    $ ./util/config openstreetmap zoom 16

### Alternative Configuration

If you prefer to use a configuration file instead of the admin panel or the CLI
open the .htconfig.php file and add "openstreetmap" to the list of activated
addons.

    App::$config['system']['addon'] = "openstreetmap, ..."

You can configure the addon with these variables:

    App::$config['openstreetmap']['tmsserver'] = 'http://www.openstreetmap.org/';
    App::$config['openstreetmap']['nomserver'] = 'http://nominatim.openstreetmap.org/search.php';
    App::$config['openstreetmap']['zoom'] = '16';
    App::$config['openstreetmap']['marker'] = '0';

The *tmsserver* points to the tile server you want to use. Use the full URL,
with protocol (http/s) and trailing slash. You can configure the default zoom 
level on the map with *zoom*. 1 will show the whole world and 18 is the highest 
zoom level available. This can vary between tile servers.

## Usage

To add an embedded map centered on a set of coordinates, use the following in your posts and any other content supporting BBcode:

    [map=48.01234 9.4321]

To embed a map based on the name of a location, enter the text between the [map][/map] tag:

    [map]Sydney, Australia[/map]

## TODO

* Find a better way to handle location only items without coordinates
  * Use Nominatim in "Set your location" window for suggestion and to get
  coordinates to use
* Add OpenLayers (2-Clause BSD) or Leaflet (2-Clause BSD) for on-site displaying
of maps
  * Pick coordinates an location from a map in "Set your location"
  * Add views with markers on maps, etc.
* How to handle planets etc. locations? Shouldn't bug Nominatim with them.
