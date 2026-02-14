=== PropertyHive Radial Search ===
Contributors: PropertyHive,BIOSTALL
Tags: blm, propertyhive, property hive, property, real estate, software, estate agents, estate agent, property management
Requires at least: 3.8
Tested up to: 6.8.2
Stable tag: trunk
Version: 2.0.2
Homepage: https://wp-property-hive.com/addons/radial-search/

This add on for Property Hive allows users to perform radial searches based on their entered location.

== Description ==

This add on for Property Hive allows users to perform radial searches based on their entered location.

== Installation ==

= Special Requirements =

This add on requires the 'allow_url_fopen' PHP setting be enabled as it makes requests to a third party service to geocode locations entered to lat/lng co-ordinates.

= Manual installation =

The manual installation method involves downloading the Property Hive Radial Search Add-on plugin and uploading it to your webserver via your favourite FTP application. The WordPress codex contains [instructions on how to do this here](http://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

= Updating =

Updating should work like a charm; as always though, ensure you backup your site just in case.

== Changelog ==

= 2.0.2 =
* Corrected OSM geocoding requests from being blocked by passing in a 'Referer' header
* Allow radius to be passed through to similar properties shortcode as an attribute
* Only show 'Sort by distance' ordering option when a radius is being filtered too
* Stricter rules around when radius functionality should be executed on queries to prevent non-related SQL queries from being effected
* Declared support for WordPress 6.8.2

= 2.0.1 =
* New filter 'propertyhive_radial_search_ignore_address' to ignore address fields when a radius is searched
* Refinements to when queries are effected
* Add limit to subquery in case of duplicate lat_lng_post entries (though this should never happen)
* Declared support for WordPress 6.7.2

= 2.0.0 =
* Property Hive Pro compatibility, disabling the import functionality if a pro user but no valid license in place
* Move do_radius_actions action earlier in the process to impact REST API queries
* Remove radius related query stuff once finished
* Amends to when queries ran
* PHP 8.2 compatibility
* Declared support for WordPress 6.5.3

= 1.0.30 =
* Correction to recent support for new 'Search by location perimeter' option, ensuring it only effects the main query
* Declared support for WordPress 6.0.3

= 1.0.29 =
* Support for new 'Search by location perimeter' option (requires GEOS PHP extension - https://geophp.net/geos.html)

= 1.0.28 =
* Added ability to sort REST API requests by radius
* Declared support for WordPress 6.0.2

= 1.0.27 =
* Added support for new OSM geocoding when enabled
* Declared support for WordPress 5.9.3

= 1.0.26 =
* Broader support for London postcodes. Searching for WC2 for example will include properties in WC2E, whilst still excluding properties in WC22
* Allow address keyword search to find address fields separated by comma
* Declared support for WordPress 5.8.2

= 1.0.25 =
* Ensure wp_ph_radial_search_lat_lng_post table is filled upon installation should properties exist already
* Declared support for WordPress 5.7.2

= 1.0.24 =
* Only remove address keyword meta query if address keyword or location set
* Declared support for WordPress 5.7

= 1.0.23 =
* If setting selected to store applicant locations as freetype then also add ability to specify radius
* Declared support for WordPress 5.5.3

= 1.0.22 =
* Moved hooks that modify the query later in the order of execution to ensure it works with SEO friendly URLs
* Declared support for WordPress 5.4.1

= 1.0.21 =
* Added esc_sql() to queries executed manually

= 1.0.20 =
* Added ability to pass radius attribute through to property shortcodes. Needs to entered in conjunction with address_keyword attribute.
* Catered for postcodes being searched with no space
* Declared support for WordPress 5.3.2

= 1.0.19 =
* Added new filter 'propertyhive_radial_search_location_lookup' to customise location entered prior to Geocoding request. Useful when wanting to add bias to common town names
* Declared support for WordPress 5.3

= 1.0.18 =
* Activate new Geocoding API key setting under 'Property Hive > Settings > General > Map'. Used for when the main API key entered has a referer restriction applied and separate key required just for Geocoding requests.
* Use new Geocoding API key in requests if present, else fallback to original
* Declared support for WordPress 5.2.3

= 1.0.17 =
* Added new 'Current Location' option allowing people to find properties based on their current location
* Declared support for WordPress 5.2.2

= 1.0.16 =
* New filter 'propertyhive_address_fields_to_query' to allow specifying of which address fields to include when searching by keyword
* Remove country code if included in address_keyword search
* Added sanitization to user input
* Declared support for WordPress 5.0.3

= 1.0.15 =
* Corrected postcode search logic to match main app, specifically for when searching for only first part of postcode (e.g. N8)
* Added settings link to main plugin page

= 1.0.14 =
* Added new settings tab displaying information on cache and troubleshooting info
* Added ability to order by distance if address keyword is present
* Declared support for WordPress 4.9.8

= 1.0.13 =
* Added fix for when both Radial Search and Infinite Scroll add ons are used at same time
* Declared support for WordPress 4.9.7

= 1.0.12 =
* Ensure geocoding requests are done over HTTPS
* Append API Key to geocoding requests if one exists
* Append region to search query when making geocoding requests to try and reduce scenarios where zero results are returned
* Only do radius SQL query if lat/lng present to prevent SQL warning
* Declared support for WordPress 4.9.2

= 1.0.11 =
* Database query optimisations performed by adding index to database table. Speed improvements definitely noticeable when lots of properties
* Only store lat/lng in dedicated database table for on market properties. Waste of space storing them for properties not on the market
* Declared support for WordPress 4.8

= 1.0.10 =
* Remove street from address_keyword query when a radius is set. Searching for 'Tisbury' for example, would return properties on 'Tisbury Road'
* Take into account new exact/loose address keyword search setting
* Declared support for WordPress 4.7.4

= 1.0.9 =
* Fix query following recent update to Property Hive which includes reference number when searching for address
* Declared support for WordPress 4.7.3

= 1.0.8 =
* Pass default country as region when making geocoding requests

= 1.0.7 =
* Check for valid [license key](https://wp-property-hive.com/product/12-month-license-key/) before performing future updates
* Declared support for WordPress 4.7.1

= 1.0.6 =
* Don't assume property is being saved through WordPress when using save_post hook
* Declared suppport for WordPress 4.7

= 1.0.5 =
* Fix wrong default map center being shown when using Map Search Add On and having a radius set

= 1.0.4 =
* Use wp_remote_get() instead of simplexml_load_file() to make Google Geocoding API request

= 1.0.3 =
* Corrected address field names and included street in lookup
* Declared suppport for WordPress 4.6.1

= 1.0.2 =
* Add support for using the add on in conjunction with a location taxonomy dropdown

= 1.0.1 =
* Only perform query actions and filters when necessary. Was causing content on other pages to repeat

= 1.0.0 =
* First working release of the add on