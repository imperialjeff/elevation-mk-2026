=== PropertyHive Property Import ===
Contributors: PropertyHive,BIOSTALL
Tags: property import, property hive, propertyhive, blm import property, expertagent, vebra, alto, dezrez, jupix, real estate, software, estate agents, estate agent, property management
Requires at least: 5.3
Tested up to: 6.9
Stable tag: trunk
Version: 3.0.28
Homepage: https://wp-property-hive.com/addons/property-import/

This add on for Property Hive imports properties from another source into WordPress

== Description ==

This add on for Property Hive imports properties from another source into WordPress

== Installation ==

= Manual installation =

The manual installation method involves downloading the Property Hive Property Import plugin and uploading it to your webserver via your favourite FTP application. The WordPress codex contains [instructions on how to do this here](http://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

Once installed and activated, you can access the import tool by navigating to 'Property Hive > Import Properties' from within Wordpress.

= Updating =

Updating should work like a charm; as always though, ensure you backup your site just in case.

== Changelog ==

= 3.0.28 =
* Updated parsing of properties in Dezrez JSON imports to always take limit into account if one is set in advanced settings
* Updated 'Need Help' link in field rules settings area to point to new doc
* Updated Reapit flood data so values sent as 'false' weren't ignored
* Corrected missing 'Replace enquiry button' setting in Street import settings lost during the v3 update
* Corrected issue with full description not importing when mapped in field rules
* Corrected issue with media modified date not being set
* Corrected modified date in Rex imports to ensure 'only import updated properties' setting works as it should

= 3.0.27 =
* Updated passwords, API keys and other sensitive information to be masked in main imports table
* Updated CSV imports to skip properties if no property ID unique field is found
* Updated CSV imports to not continue parsing if non-200 response found. If a 404 page or similar was found it would parse and import that
* Updated summary description to use 'Summary' field in AgentOS imports
* Corrected issue in Reapit Foundations meaning embed requests were removed wrongly
* Corrected Reapit Foundations lettings status name
* Corrected issue with importing property type from Kato API
* Corrected removal functionality in XML and CSV

= 3.0.26 =
* Added support for virtual tours in XML and CSV imports with the addition of a new section under the 'Media' settings tab
* Added better validation surrounding checking for invalid JSON when parsing Street response
* Added filters to change how EPC filetype validation works. By default we'll check that an EPC URL isn't linking to a website before downloading
* Updated efficiency of Jupix imports when 'Only import updated properties' is ticked
* Corrected fatal error being thrown when no price provided in Street imports
* Corrected undefined variable in Street format
* Corrected issue with brochure and EPC media settings not saving for XML and CSV imports

= 3.0.25 =
* Added support back in for importing REAXML local files
* Added User-Agent header to OSM geocoding requests to reduce risk of requests failing
* Updated Street imports to ignore scheduled properties with a 'Publish After' date set in the future
* Updated recent changes to Agency Pilot API full description import to ignore additional info with a missing header or text
* Updated Rex imports with improved logic around when 'Only import updated properties' is ticked
* Corrected issue with setting primary office ID in Rentman XML imports
* Declared support for WordPress version 6.9

= 3.0.24 =
* Updated Agency Pilot API imports to import additional info into the full description when provided
* Updated field rules to handle fields of type array, instead of just strings. 
* Updated field rules so if a JSONPath query is entered it handles all results should multiple results be returned
* Corrected issue with Inmobalia XML imports

= 3.0.23 =
* Added additional debugging in RTDF format when invalid JSON received
* Updated imports table so a frequency doesn't show for realtime formats where it's not relevant
* Updated cron to ignore real-time formats. Previously these would be included and just produce empty logs

= 3.0.22 =
* Added additional information to logs when a 304 header code is received from Alto. This is when there have been no properties updated since the last time an import ran but this was unclear and felt like something had gone wrong.
* Updated media queue to handle empty or invalid files. Previously these would remain in the queue and attempt to get processed each time it ran
* Updated 'Vebra' to 'Alto' in error message
* Corrected a warning from being logged when Alto property XML is empty due to no properties to process
* Corrected 'Creation of dynamic property' PHP error

= 3.0.21 =
* Added more debugging to RTDF
* Added 'Received Data' in logs table when viewing logs for an RTDF import
* Updated Street imports to ensure brochures sent in the property_urls node are also imported
* Updated creating of RTDF rewrite rules to exclude deleted imports
* Corrected issue with property types not being assigned correctly from Agency Pilot API imports due to term ID not being cast to an integer
* Corrected issue with properties being duplicated from Rentman imports
* Corrected missing parameter in 'propertyhive_property_imported' action in Gnomen import

= 3.0.20 =
* Added new actions at start and end of import_media() function
* Updated recent 'propertyhive_kato_xml_descriptions' filter so units table can also be reordered in Kato XML description
* Disabled ability to set a property limit in Alto format due to the way in which their API works
* Corrected bedrooms field in Pixxi feed
* Corrected issue with negotiators not importing in formats that provide a negotiator
* Corrected recent 'propertyhive_property_import_stop_if_no_properties' so it also continues to remove properties if enabled
* Corrected real-time status progress indicator for Loop imports
* Corrected Arthur authorisation issue

= 3.0.19 =
* Updated logic surrounding importing brochures in AgencyPilot API format. The URL doesn't always change when the brochure is updated so also look at the property's DateUpdated
* Updated encoding of title and summary description when inserting/updating as some CRMs (i.e MRI Thesaurus) send data in non-UTF8 encoding which, when containing a special character, would cause it to fail
* Corrected floorplans not always importing from AgentOS imports. Looks like there is two fields that floorplans can be sent in so now cate for both
* Corrected issue where no property logged against log entries from Arthur imports
* Corrected issue where properties could get duplicated from Arthur imports

= 3.0.18 =
* Added support for utilities data from Jupix if present
* Added "propertyhive_property_imported" actions to unit import in Arthur
* Added support for brochures sent in 'additionalMedia' from Street
* Corrected commercial property types and commercial rent frequency in 10ninety

= 3.0.17 =
* Added new filter 'propertyhive_kato_xml_descriptions' to change order of descriptions imported
* Corrected lettings specific descriptions not importing from Street

= 3.0.16 =
* Run Muven summary descriptions through html_entity_decode() to handle formatting being sent encoded
* Added Rentman to list of formats where processed file can be downloaded from the logs
* Handle imagecreatefrompng() PHP functionality not existing when creating EPC graph automatically from ratings
* Corrected issue with Rentman parsing of properties

= 3.0.15 =
* Corrected parsing of Rentman XML files

= 3.0.14 =
* Updated main imports table to only relevant for Expert Agent imports based on data source
* Corrected issue with FTP connections thinking it wasn't connected when no directory specified
* Corrected issue with images not importing in Kato XML format

= 3.0.13 =
* Ensured Arthur authorisation details are retained when saving import details so you don't need to keep re-authorising
* Corrected issue with properties not getting assigned to commercial department in Loop format

= 3.0.12 =
* Added new filter 'propertyhive_property_import_format_details' to allow additional details to be written to imports table
* Corrected issue with flooplans and EPC not imports in Dezrez JSON format
* Corrected potential for duplicate EPC's to be imported in Dezrez JSON format when provided in two different parts of the data

= 3.0.11 =
* Updated importing from another Property Hive website to only import active departments
* Updated importing from another Property Hive website to support commercial properties
* Corrected a difference in the way the 'exact hours' frequency works between v2 and v3

= 3.0.10 =
* Added support for brochures sent as 'links' in Rex format
* Added more logging surrounding formats that use FTP connections
* Updated Kato API URLs following deprecation of old URLs at the end of October 2025
* Corrected Thesaurus FTP passive setting name to ensure FTP passive mode works when enabled
* Corrected issue with importing images when sent via FTP in formats like BLM

= 3.0.9 =
* Added ability to import from another site running Property Hive
* Corrected importing EPCs in SME Professional JSON format
* Corrected importing price qualifier in BDP format

= 3.0.8 =
* Catered for additional unmappedAttribute type fields in Reapit feed when detecting commercial properties
* Corrected EPCs not importing in MRI XML format
* Corrected MRI Thesaurus imports from not running

= 3.0.7 =
* Added filter 'propertyhive_property_import_flush_cache_at_end' to control whether cache is flushed or not when an import finishes
* Improved migration of availabilities when updating v2 to v3
* Improved availability mapping across all formats
* Corrected issue with 'particulars_url' field not importing in Kato XML format
* Corrected issue with Dezrez JSON imports losing availability

= 3.0.6 =
* Added filter 'propertyhive_property_import_stop_if_no_properties' to disable failsafe whereby imports stop if no properties are found
* Added extra security to the file that allows download of files sent locally via FTP
* Added extra security to start/pause toggle by using a nonce
* Added additional encoding to images sent in ExpertAgent format to handle them containing brackets
* Correct Kato XML brochure imports 

= 3.0.5 =
* Corrected Arthur authentication issue
* Corrected issue with properties being assigned to wrong agent when using the Property Portal add on and two agents having the same branch code
* Remove unnecessary duplicate function call

= 3.0.4 =
* Corrected default RTDF endpoints when creating a new import
* Corrected inconsistencies in the 'Every 15/Fifteen minutes' cron schedule
* Flush rewrite rules when saving a real-time feed

= 3.0.3 =
* Added more helpful logging in Reapit Foundations logs when import isn't runniung because the app isn't installed
* Changed wording on Plugins page from 'Settings' to 'Manage Imports'
* Corrected issue with Rex 'Test Details' functionality

= 3.0.2 =
* Corrected an anomoly with Alto feeds whereby in a particular scenario, properties could get removed in error

= 3.0.1 =
* Corrected issue with Alto imports not running following latest release
* Remove 'Test Details' button from Alto imports due to the way their authentication tokens work
* Remove unused function

= 3.0.0 =
* Completely rewritten from the ground up
* Updated main imports table UI allowing you to now filter by format and sort the table
* Updated create/edit import UI to be easier to navigate
* Updated settings with some being moved to a top-level as opposed to being set on a per-import basis. These will be migrated accordingly upon update
* Added a real-time progress indicator to imports
* Added ability to import generic XML and CSV files
* Added new 'Field Rules' section in import settings to create custom import logic through UI rather than requiring custom PHP snippets
* Added ability to search logs
* Added ability to kill imports as they're running
* Added ability to clone existing imports
* Added ability to run imports individually
* Added ability to put a limit on the number of properties and images imported
* Added 'Test Details' to all formats where applicable as you're setting up the import to test the details work there and then
* Added 'Only import updated properties' option to all formats where applicable

= 2.1.13 =
* Added support for Veco Plus
* Cater for SME changing the image URL format and appending a query string
* Pass import ID through to Loop API actions
* Declared support for WordPress version 6.8

= 2.1.12 =
* Dezrez have added rate limiting to their API. Add sleeps to prevent us hitting that. This might result in imports taking longer, especially the first one.
* Check for non-200 HTTP responses from Dezrez API and log accordingly
* Import lease years remaining from Apex27
* Cater for additional virtual tour fields in Gnomen
* Correct price qualifier and tenure field name for Domus imports
* Correct full descriptions importing for Rex commercial properties

= 2.1.11 =
* Added support for importing properties from AgestaNET
* Added support for importing properties from Getrix
* Added support for commercial properties from Rex

= 2.1.10 =
* Added support for importing properties from Apimo
* Added support for importing properties from Inmoweb
* Added support for importing properties from Inmovilla
* Added support for importing properties from CASAFAR
* Added support for importing properties from InfoCasa
* Import office in Rex format
* Don't run description from Thesaurus through strip_tags()
* Manage access tokens expiring in Reapit Foundation import and renew accordingly

= 2.1.9 =
* Set negotiator in Dezrez JSON imports if matching negotiator found
* Set post date and modified date on properties imported from Reapit based on dates provided in data
* Pick up Rex imports from where they last fell over when applicable
* Use correct field for bedrooms in eGO Real Estate feed
* Ensure property address is written to logs when adding missing type in Apex27 format

= 2.1.8 =
* New filters to specify how custom departments relate to Property Hive departments from ExpertAgent import
* Also set POA to 'yes' when qualifier is 'POA' from Vebra Alto feeds
* Improved logging for WebEDGE imports
* Added filter 'propertyhive_property_import_media_filename' to customise filenames in Street format. To be applied to all
* Corrected issue with mappings not getting saved and overwritten in Street format due to conflicting variable name
* Declared support for WordPress version 6.7.2

= 2.1.7 =
* Added preliminary support for ReSales Online API
* Cater for updated Street types and styles
* Updated Pixxi format to support an API key
* When an alert is displayed showing properties have a missing type or status, display these properties in the modal for easier investigation
* When displaying the import data on the property record, also show the date that that data was received
* Added filter to hide 'Edit Mappings' link
* Corrected flood defenses variable in Street format
* Corrected new build fields and import floorplans in Pixxi format
* Corrected negotiators not updating in some formats
* Remove '_view_statistics' meta field when comparing and logging meta fields before and after values

= 2.1.6 =
* Correct splash screen when White Label plugin in use
* Import floorplans from Kyero format
* Cater for Content-Type being array when checking EPC from Street is website
* Cater for differing description field names in ReSales Online XML feed
* New filter to customise Kato XML units table total
* Pass $post_id and $property to Kato XML tables columns filter as new parameters

= 2.1.5 =
* New splash screen when no imports exist
* Import ground rent, service charge and lease remaining from Loop
* Don't try and import EPC from Street if website URL provided
* Extend media cron to not run if anything has happened in 10 minutes
* Variable correction in SME Professional format

= 2.1.4 =
* Import deposit amount from Street
* Import sale by, qualifier and tenure for commercial properties from Loop
* Corrections to material information in Apex27 imports
* Ensure media modified date is in correct format in Reapit Foundations to fix processing of media in background queue
* New filter to disable the setting of 'Featured' on properties based on data in CRM. Useful for having manual control over which properties are featured
* Declared support for WordPress version 6.7.1

= 2.1.3 =
* Add utilities material information to Reapit Foundations format
* Updated Loop property type default values
* Add filters to Kato XML units table so it columns/data can be customised
* Only include fitted info in Kato description if fitted is true
* New filter to specify whether properties get put on market by default
* New actions when import is started, paused and deleted
* Fix showing wrong Reapit Foundations and Street selected statuses on main imports table

= 2.1.2 =
* Paginate Dezrez Rezi JSON imports to support more than 999 properties
* Catered for images sent with a watermark from Kato
* Added commercial support to Juvo XML format
* Declared support for WordPress version 6.7

= 2.1.1 =
* Corrected error in Arthur format following recent logs overhaul
* Import accessibility from Loop
* Cater for 'ads' broadband type in Loop format

= 2.1.0 =
* New Troubleshooting Wizard tool
* Main imports table UI refactored
* Logs UI updated to now be in tables with better structured data
* Alert system introduced to main imports table whereby it will flag any missing statuses, critical errors, and more
* When adding an import allow searching by format
* When adding an import there is a new 'Test Details' button allowing you to check details are valid before continuing
* When adding an import there is a direct link to the CRM-specific docs 
* Added statuses as a setting to Street and Reapit Foundations
* Database optimisations, adding indexes to logs tables. Note that any existing logs will be removed to allow us to do this safely
* Output CRM ID in list of properties and allow for searching by this
* Improved auto-mapping of custom fields, now taking into account different case and spaces
* Improved mapping screen table heading labels and added link to customise them in settings area
* Hyperlinked various text in tooltips
* Ensure entire message is logged when a fatal error occurs
* Better errors logged when Dezrez JSON is valid but empty
* Ensure Street Book Viewing Elementor widget works when used in WpResidence theme
* Added basic wp-env setup for development
* Catered for 'commercialEpc' as photo type from Reapit Foundations
* Added parking and outside space mapping to Loop
* Added cafe and restaurant to list of Loop commercial types
* Removed 'Communal Garden' from list of Loop parking options
* Added material information B&C to Loop v2 API
* Correction to XML2U format when importing images

= 2.0.22 =
* Added preliminary support for importing properties from AgentBox
* Added preliminary support for importing properties in the XML2U format
* Added support for importing material info from Apex27
* Break long logs into chunks so they don't get cut off anymore
* Look at varying address field names in Inmobalia XML imports
* Look at type, style AND unmappedAttributes when getting Reapit types to map
* Import price qualifier for commercial properties in Reapit imports
* Don't continue to geocode addresses from Rex if one of them is denied
* Cater for 'poa' being the price qualifier in Agency Pilot REST API
* Import caption for floorplans sent in Kato XML
* Import brochure title as post excerpt for use in button labels in Kato XML
* Catch errors first in Street format before checking for pagination and log accordingly
* Added filter 'propertyhive_property_import_remove_properties_in_php' to do remove logic in PHP in Reapit Foundations. For large imports where select query was failing due to memory limits
* Added fallback for download_url() so it works on wp.com hosted sites where the WordPress download_url() function is disabled
* Correction to obtaining just IDs in agent query
* Tweaks to recently added eGO format
* Only declare shutdown functions if not already exists
* Added more logging in Gnomen format surrounding invalid XML
* Added filter 'propertyhive_reaxml_statuses_imported' to allow sold properties to be imported from REAXML
* Corrected field used for Material Information in Street imports
* Added Sale By mapping to Loop imports
* Added steps to stop Muven imports getting into infinite loop
* Corrected  'user_information' in RTDF enquiry requests to be object insead of collection
* Corrected date formats in RTDF enquiry format to match specification (d-m-Y)

= 2.0.21 =
* Support added for importing properties in the eGO Real Estate XML format
* Added support for commercial mappings to Dezrez JSON format
* Use new is_commercial flag in Street feed to determine commercial props
* Cater for comma-delimited branch names being entered in SME branch mapping
* Fire media queue at start of import to reduce time properties spend with no images
* Import 'highlights' field from Rex if no features
* Import Material Info parts B&C from Street
* Import Material Info parts B&C from Acquaint
* Import price qualifier in WebEDGE imports
* Set post_excerpt on media when downloaded in background
* Import EPCs in Arthur format
* Don't continue to perform geocoding requests if one is denied in Kyero
* Support for Property Portal add on when requesting RTDF enquiries
* Declared support for WordPress version 6.6.2

= 2.0.20 =
* Cater for missing/invalid price in Kyero imports. Previously it would throw a PHP error
* Increase number of images imported from MRI XML from 30 to 99 after they've increased the number of images included their end
* Use OID as ref number in AgentOS imports if no global reference set
* Use price_numerical field from Muven and set currency
* Added more debugging to Dezrez JSON logs when JSON can't be parsed
* Ensure withdrawn/sold/let/draft properties aren't importing from WebEdge
* Added mapping support for new non-traditional tenure from Alto by Vebra
* Declared support for WordPress version 6.6.1

= 2.0.19 =
* Add more info to error logs if VaultEA request fails
* Commercial status and type mappings enabled in Apex27 imports
* Don't continue processing 10ninety feed if no properties
* Import dimensions for commercial properties in RTDF imports
* Continue where a previously failed import fell over in Jet/Reapit
* Import properties in the new homes department in Jet/Reapit utilising the GetNewHomesProperties method

= 2.0.18 =
* Added support for material information parts B&C from Vebra Alto by upgrading to use v13 of their API
* Use id as reference number if no agent reference is present in Vebra Alto imports
* Correct issue with Loop media modified dates being rounded as they're provided with milliseconds which would result in media being downloaded every time
* Correct commercial descriptions imported via RTDF format
* Cater for exact hours not being entered as exact hours (i.e. 8:00)
* Add timeout to individual property requests in MRI format
* Cater for images with .asp or .php extensions in Kyero format
* Cater for sub property type field 'en' in Kyero imports
* Move downloading of Kyero file to process file for better logging if an issue occurs whilst obtaining the file
* Correct agent mapping in Inmobalia format
* Declared support for WordPress version 6.5.5

= 2.0.17 =
* Corrected issue with Kato image imports
* Import brochures from Muven

= 2.0.16 =
* Added support for importing Inmobalia XML files
* Added 'only import updated properties' option in Rex format
* Import 'formatted_dimensions' field from Rex format when importing room information
* Pass width parameter in image URLs obtained from Kato to prevent huge images. Defaults to 1150px but can be customised using the 'propertyhive_agentsinsight_image_width' filter.
* Support for new AgentOS property type field
* Corrected typo in Rentman bullet point field name
* Pass $import_id through to pre-import hooks for all formats
* Declared support for WordPress version 6.5.4

= 2.0.15 =
* Cater for NULL summary descriptions from Loop throwing database error
* Check for errors in response from Loop and handle accordingly
* Add clarification on Reapit Foundation API charges
* Encode media URLs and use https for files imported from ExpertAgent
* Set currency on RTDF lettings properties
* Support for virtualtour2 field in Acquaint XML format
* Import deposit in Acquaint XML format
* Add rent and price columns to Kato units table in XML feed
* Correct decimal places in Alto feed for ground rent and service charge
* Improve REX price imports when extracting the price from a string

= 2.0.14 =
* Enhancements to Reapit Foundations format whereby we'll now query properties by status in requests, as opposed to filtering them afterwards. Should reduce number of API requests
* More logging added to Reapit Foundations surrounding the requests made
* Added support for the Property Portal add on to Loop format
* Import council tax band and other material info from Acquaint XML
* Added filters so RTDF mandtory fields can be customised
* When validating RTDF mandatory fields, only check for empty string. Allow '0' as some fields use 0 to mean 'Not Specified'
* Declared support for WordPress version 6.5.3

= 2.0.13 =
* Import material info part A details from Street
* Import deposit from Reapit Foundations
* Correction to how displayAsUnderOffer works in Loop feeds
* New actions added before and after cron runs
* Write to log when geocoding request is being performed

= 2.0.12 =
* Added support for importing properties via the Kato API
* Added support for importing properties in the thinkSPAIN XML format
* Catered for image URLs with query string in Kyero imports

= 2.0.11 =
* Extract price from 'price_advertise_as' field in Rex format
* Import tenure and material information from MRI
* Add support for 'imported_ids' and 'import_id' as new shortcode attributes
* Cater for image URL's with query strings in BLM format
* New filter 'propertyhive_street_departments' applied to Street department requests should you wish to only import sales or lettings properties
* Add price qualifier mapping to Dezrez commercial properties
* Extend ReSales Online timeout limit to 360 to support larger XML files
* Cater for media with .ASP extension in media queue cron
* Fix featured, floor area decimal points, support multiple property types and features in Caldes format
* Declared support for WordPress version 6.5.2

= 2.0.10 =
* Added ability to only import updated properties in Kyero imports
* Added material info to Jupix import when applicable (requires '&version=3.0' to be appended to the URL)
* Filters added to Reapit Foundations API details (client ID and client secret) so it could be used with a private app
* PHP8 compatibility with regards to setting Domus featured properties
* Added 'letAgreed' to default list of Loop lettings statuses
* Correction to importing let date available from Muven
* Added 'propertyhive_property_import_queued_media_imported' action to media cron when media has finished importing for a property
* Always ensure media cron is queued
* Output 'only import updated properties' setting on main imports table

= 2.0.9 =
* Added council tax band to Jupix imports (requires '&version=3.0' in URL entered into import settings)
* Import brochure descriptions from Rex
* Import EPC's into correct section from Rex
* Set properties as 'Let' when necessary in Rex format
* New default status mappings for 'Sold' and 'Let' in Rex settings
* Correct Rex field name: system_listing_state

= 2.0.8 =
* Added support for importing in the RE/MAX format
* Cater for non royal mail address in VaultEA feeds. Overseas feeds seem to have the address structured differently
* Import let date available from Muven
* Import let available date from ExpertAgent
* Cater for missing or empty price in EA imports so round() function doesn't throw a PHP warning
* Increase timeouts in Arthur requests
* Just use the property ID as the filename when importing brochures from Reapit. Previously we would use the strapline field which could be long or invalid as a filename
* Import EPC's from Dezrez as documents, regardless of type
* Use new 'instructed date' field in Street imports as created date

= 2.0.7 =
* Import brochures sent as 'additional media' from Street, only as storing brochures as URLs
* Improvements to setting of post create/modified date in Street imports
* Added commercial support to SME Professional XML import
* Trim Rex username and password entered to avoid issues with rogue spaces being copied
* Corrected rent frequency in Agency Pilot API import
* Corrected issue with Jupix imports getting stuck on last property
* Corrected commercial descriptions importing from Apex27

= 2.0.6 =
* Added support for importing properties from Pixxi CRM
* Added filter 'propertyhive_property_import_include_deleted_in_filter' to exclude deleted imports from admin property filter
* Set virtual tour label in Rex format
* Added Let Agreed as default lettings status in Muven format
* Added House and Studio Flat to list of default Muven property types
* Imported 'address_4' from SME Professional into County field

= 2.0.5 =
* Updated Loop image URLs to pass width as parameter (i.e. ?width=1150) instead of using -big.jpg which will be removed
* Added support for 'VirtualTours' node in Apex27 feed
* Continue Jupix imports from where they get to last if the previous one fell over

= 2.0.4 =
* Added support for comma-delimited branch mapping in Vebra Alto format
* Refined support for comma-delimited branch codes in APex27 format
* Import EPCs files if present in Apex27 format
* Added filter 'propertyhive_loop_endpoints' to add more Loop v2 endpoints (i.e. including sold properties)
* Added filter 'propertyhive_webedge_off_market_statuses to specify WebEdge off market statuses
* Added support for residential properties from AgencyPilot
* Take earliest available date from units in Arthur if not set on property
* Declared support for WordPress version 6.4.3

= 2.0.3 =
* Auto generate EPC charts from Jupix format if no EPC provided but EPC values are
* Fallback to use imperial dimensions for rooms if no others provided in Vebra Alto format
* Only match first found negotiator in Kato import

= 2.0.2 =
* Allow branch IDs to be comma-delimited when setting up branch mappings for Street format. Caters for scenario where 2 separate branches in Street should be mapped to one office in Property Hive
* Don't continue if errors found in Street response
* Don't continue if no pagination element found in Street response as should always be present
* Use wp_remote_get() to obtain Gnomen XML files instead of simplexml_load_file()
* Defaut remove action to 'Remove All Media Except The First Image'. A common issue is regarding disk usage so starting with this asa the default should help long term

= 2.0.1 =
* Existing filter 'propertyhive_street_image_size' also applied to physical image downloads so size of image imported from Street can be changed
* Continue Street imports from where they get to last if the previous one fell over
* New 'propertyhive_street_headers' filter to customise headers sent in requests to Street
* Use original LettingsTenancy ID in AgentOS imports as unique ID instead of propertyID. The former is used when downloaded brochures
* Cater for price being empty in MRI XML format so round() doesn't throw a fatal error in PHP8 when price doesn't exist
* Don't retry geocoding requests in MRI XML format if it gets denied once
* Correct the variable set when geocoding fails in BLM format
* Correct the wording in error in BLM format when importing EPC
* If response from Gnomen request is WP_Error then log this accordingly
* Declared support for WordPress version 6.4.2

= 2.0.0 =
* Property Hive Pro compatibility, disabling the import functionality if a pro user but no valid license in place
* Include counts of statuses in Reapit Foundations logs
* Continue Alto import where it got to last if it falls over
* Cater for 'sold' system_listing_status in Rex feed
* Change to Reapit wording now that they will be doing invoicing
* Add next/prev log links to top as well as bottom and change page title
* Take into account filter when displaying message about no logs
* Added duration to logs. Makes it clearer to see how long an import took, and whether it even finished at all
* Turn off reapit cost calculator following recent fee calculation changes
* Store if an import is a media import or not and use accordingly. Previously, some formats would look at the last date that an import ran when deciding which propertis to import and would wrongly be looking at media imports
* Rebrand agentsinsight to Kato
* Make 'Available Area' translatable in Kato/agentsinsight format
* Also cater for marketing_text_{6-10} fields from Kato/agentsinsight
* Correct default statuses in Apex27 XML format
* POA support in Apex27 XML format
* Cater for fields being sent as a string 'true' or 'false' in RTDF import instead of a boolean
* Use correct post_id variable when generating EPC for Street so they get assigned to the correct property
* Include sold and Let statuses in Muven format. Still should only send properties that have been selected to be sent.
* Don't set post_date_gmt when importing in RTDF format. When in BST this caused issues
* Also check for lat/lng fields as well as latitude/longitude when importing coordinates from Arthur as field names seems to alternate
* Declared support for WordPress version 6.4.1

= 1.3.32 =
* Store Vebra Alto token in database as opposed to in txt file. For some reason all of a sudden lots of sites experienced issues errors regarding writing to file
* Don't process deleted webedge imports
* Don't remove line breaks from full description from Apex27

= 1.3.31 =
* Use correct field for display address in Loop V2 format
* Use updated address fields in VaultEA format
* Support more EPC media type IDs when importing in RTDF format
* Write error to log if files can't be opened during Alto import
* Allow for fields MEDIA_IMAGE_70-99 for additional images in BLM format
* BLM errors updated to be clearer where they originate from
* Update script to reference HTTPS URL
* Declared support for WordPress version 6.3.1

= 1.3.30 =
* Added commercial availability mapping to Street format
* Import reference number from Loop V2 API
* Also import tours with 'tour' or '360' in the URL in Thesaurus format
* Added ability to view media in the queue
* Added ability to delete media in queue
* Passed importID to BLM pre filters
* Include properties with any status when checking if imported already in BLM

= 1.3.29 =
* Don't return blank warnings and errors in RTDF response
* Import available date in Street format

= 1.3.28 =
* Added support for ExpertAgent imports from local directory
* Added ability to set base URL in BDP format
* Changed WordPress required version to 5.3+
* Catered for displayAsUnderOffer field in Loop V2 format
* Added default mapping for soldSTC status received in Loop v2 format
* Import commercial_rental properties as lettings in Rex format
* Put root node back in in RTDF XML responses

= 1.3.27 =
* Remove root node from RTDF responses if response format is XML
* Added more debugging to Reapit Foundations format
* Added more debugging to BDP format if invalid JSON response received

= 1.3.26 =
* Remove GMT date from Alto imports as date provided is in Europe/London. Was causing propereties to be scheduled
* Only do remove process in Gnomen if properties contained something
* Import council tax band in ExpertAgent format
* Don't remove line breaks from description in RTDF format
* Cater for properties that are sales and lettings in VaultEA format

= 1.3.25 =
* Support for lettings properties in Resales Online format
* Added filter 'propertyhive_rtdf_property_due_import' in RTDF format to amend property data pre import
* Set bathrooms and virtual tour URL in Domus format
* Corrected PHP error at end of download media cron
* Correct post_date imported for Street properties

= 1.3.24 =
* Added support for commercial department to Loop V2 format
* Show total pages in logs when Street feed runs to aid with debugging
* Output any errors to the logs returned by the Street format
* Add more debugging to Arthur format if JSON can't be parsed
* Import deposit and MaterialInformation from ExpertAgent if provided
* Tweaks to response sent in RTDF requests
* Import 'Rooms' from RTDF format if present
* Added tip to enable background media queueing for RTDF format
* Check 'application/x-zip-compressed' content type when importing remote BLM
* Added hooks allowing ability to add own import frequencies

= 1.3.23 =
* Ensure only one 'featured' meta key is created for Loop v1 format to prevent database getting clogged with empty meta keys
* Allow for setting of multiple property types in Reapit Foundations
* Also look at 'modified' field when deciding whether to download media in Reapit Foundations. Previously we would just look at URL and suspect these don't always change when an image gets replaced
* Corrected issue with commercial properties with sellingAndLetting marketing status going off market when 'Only updated updated properties' option is ticked in Reapit Foundations format
* Use different icon for Street viewing Elementor widget
* Trim slashes from Street Base API URL entered
* Declared support for WordPress version 6.2.2

= 1.3.22 =
* Overhaul regarding dates and times, ensuring all dates are stored in UTC and that the timezone is taken into account across the add on
* Added support for mapping parking and outside spaces to Street format
* Use get_the_ID() instead of get_the_id(). PHP case-insensitive but good practice

= 1.3.21 =
* Added support for commercial properties that are sale and rent in Reapit Foundations
* Show the import format in the notice at top of a property record to aid with debugging when someone has multiple formats setup
* Corrected issue with the 'Exact Hours' frequency running every five minutes, along with tooltip about ensuring the correct timezone is set
* Correct issue with not being able to untick Muven only updated setting
* Show deleted imports in 'Added Method' property filter

= 1.3.20 =
* Cater for rent frequency coming through as 'annually' in Reapit Foundations
* Set POA accordingly for rental properties from Reapit Foundations
* Allow changing of endpoints and add support for getbranchemails endpoint in Rightmove Real-Time Feed format
* Don't request properties with status 'let' from Street. Now they have a separate 'let_agreed' status we should no longer need this
* Show any errors returned from Street in the logs such as invalid API key. Previously it would look like it was working but just not returning any properties
* Request properties with certain statuses such as Sold STC from Muven with filter. We believe by default only available properties are sent
* Prevent PHP error in Muven format when no price sent

= 1.3.19 =
* Add commercial support to Street format
* Fix typo when importing service charge from Reapit Foundations
* Import commercial tenure (properly this time) from Reapit Foundations
* Fix issue with furnished from Gnomen
* Tweak to field name used to import brochures in Gnomen
* Do a check to ensure deposit isn't empty in Rex Format

= 1.3.18 =
* UI tweak to main import screen: Button labels changed, icons added, removed 'Status' column and added new 'Edit Mappings' button to jump straight to custom field mappings step
* Ensure post ID in the logs is a link to jump straight to that property
* Display approximate Reapit Foundations API usage for the current month. This is an approximation and relates to the current site/domain only
* Also look at 'unmappedAttributes' field when importing property type from Reapit Foundations. Sometimes they seem to use this for property type instead of the original field
* Calculated and imported leasehold years remaining in Reapit Foundations format
* Don't import max internal/external area as 0 in Reapit Foundations when not applicable to prevent size showing as 3,000 - 0 sq ft
* Support for additional virtual tours in Street format when sent in the 'property_urls' part of the data
* Added support for council tax band to 10ninety
* Added support for commercial to EstatesIT XML format
* Ensure txt and XML files downloaded from Alto during the authentication and data gathering phase contain the import ID so are completely unique. Issue experienced where multiple Alto imports were running and there was crossover between the data imported
* Set reference number field to the property ID in SME Professional formats
* Pass import ID to Kyero hooks as additional parameter. This will eventually be rolled out to all import formats
* Declared support for WordPress version 6.2

= 1.3.17 =
* Support for commercial properties from Reapit Foundations. It will look at the 'type' field and see if it a commercial type to decide this
* Remove query string from image and floorplan URLs in Apex27 format

= 1.3.16 =
* Pass 'User-Agent' header in Street requests when applicable. This allows them to determine which media should be returned
* Apex27 format updates: commercial support, furnished and deposit
* Import town, county and try to map location from Muven format

= 1.3.15 =
* Added initial support for Apex27 XML feed
* Added support for price qualifier to Agency Pilot REST API format
* Added support for mapping property type with property style in Street format
* Added new filter 'propertyhive_property_import_street_import_report_url' to ignore EPC URL sent by Street and just use ratings. Sometimes they would link to a HTML webpage that we can't import as a PDF
* Import parkingSpaces from Street. We don't use have a field for this or use it but someone might want to write a hook to import this
* Ensure price_actual is set in RTDF imports
* Import Material information in Reapit Foundations format
* Tweak to 10ninety URL structure to use PROPERTY_REF instead of AGENT_REF
* Decode HTML in Muven descriptions as they sent it encoded meaning HTML would show on the frontend
* Correct features not importing in Muven format
* Correct support for mapping price qualifiers in Gnomen format
* Correct parameter names used in VirtualNeg format
* Set reference number in Veco format to WebID field
* Cater for both ID or name when matching branches from Veco
* Rename Property Finder UAE to include myCRM

= 1.3.14 =
* Rename Clarks Computers to Muven
* Cater for 0 results being returned from Muven to prevent infinite loop
* Import material information in Muven format
* Correction to when RTDF data sent in JSON
* Cater for token header being lowercase in Alto token responses
* Added support for property portal add on to Kyero format
* Added support for property portal to RTDF format
* Added 'propertyhive_property_import_reapit_foundations_photo_types' filter so Reapit Foundations photo types can be amended
* Change permalink structure for new 10ninety properties
* Import EPC name from agentsinsight*
* Use wp_remote_get instead of cURL in Dezrez JSON

= 1.3.13 =
* Added support for Offr API
* Set negotiator in agentsinsight* format if matching user found

= 1.3.12 =
* Added support for Property Finder UAE XML Feed
* Added price qualifier to agentsinsight* feed
* Log number of API Requests made in Reapit Foundations imports so we can report on these in future
* Import floor area size from VaultEA
* Import council tax band in BLM regardless of whether leashold or not
* Added pause after every Google geocoding request to avoid hitting rate limits
* Corrected weird issue with return from geocoding request causing future gecoding requests to not be made

= 1.3.11 =
* Added ability for Street API base URL to be customised with the addition of a new setting
* Process VaultEA API requests in chunks of 10 with 1 second pause between chunks to avoid rate threshold. Includes filters to customise the number of request per pause, and the pause duration
* Added support for VaultEA commercialLeasePrice field when property is sale and rent
* Cater for Vebra Alto media URL's being sent with querystring. Having seen a migration take place from Jupix to Alto the image URL's in this case had a '?v=' parameter appended to media URLs
* Import reference number in RTDF format
* Corrected issues in RTDF import when requests send in XML format and blank XML nodes provided
* Support for boolean values when converting array to XML in RTDF response

= 1.3.10 =
* Store data received in RTDF request and display in logs when applicable if an error occurred
* Improve FTP handling in Thesaurus format where FTP has timed out
* Improve logging for failed VaultEA imports where no items are returned
* Cater for Reapit SOAP API reference migration where they'll be changing unique IDs from jet_* to rps_*

= 1.3.09 =
* Support for XML in RTDF format
* Ensure folder is created for remote BLM when accessing BLM direct. Previously it would only do it if the remote file was a ZIP
* Request let_agreed status in Street import

= 1.3.08 =
* Don't continue to process Street feed if no properties found as all properties would get removed if an error occurred whilst obtaining data
* Execute actual import cron every 5 minutes with filter 'propertyhive_property_import_cron_frequency' to customise
* Support multiple parking in CSV format
* Import council tax band in CSV format

= 1.3.07 =
* Added ability to import custom data in request from VaultEA using new 'propertyhive_property_import_vaultea_custom' filter
* Expand on tooltip about properties coming off market action and the fact this only effects properties removed going forward
* Catered for features being a string in Gnomen

= 1.3.06 =
* Added new format: Rightmove Real-Time Datafeed (RTDF) allowing properties to be pushed in real-time
* Updated version of Vebra Alto API used from v11 to v12 to ensure new Material Information data is imported (council tax, leasehold info etc)
* Ensured commercial tenures map and get set correctly in Agency Pilot API format
* Declared support for WordPress version 6.1.1

= 1.3.05 =
* Look at media modified date/time when deciding whether or not to re-import media from Reapit/JET format. Previously it would just look at URL which doesn't appear to change even when the media has been replaced
* Import deposit from MRI XML format
* Correct issue with media descriptions causing error when importing in MRI XML format
* Added support for Material info to AgentOS format
* Added support for commercial property in Utili format. There is no specific commercial category so look at property type to determine this
* Declared support for WordPress version 6.1

= 1.3.04 =
* Added land for sale as new endpoint in vaultEA format
* Look for 'featured' tag in Street import and set property as featured accordingly
* Ensure remote BLM feeds are processed in their own folder
* Ensure brochures from Reapit Foundations import in background queue due to PHP extension

= 1.3.03 =
* Added support for V2 of the Loop API with the addition of a new format
* Added support for multiple locations and property types in CSV format
* Also set ALLOW_UNFILTERED_UPLOADS contant in background media queue
* Declared support for WordPress version 6.0.3

= 1.3.02 =
* Use correct/improved fields for price and rent in AgentInsights feed
* Add 'district' to list of fields checked when auto-mapping location in Loop
* Set title on media and hopefully fix issue with EPCs in VaultEA format due to length of EPC URL provided
* MRI XML updates: Council tax, tenure/qualifier fix, leasehold, EPC
* Output JSON in log if invalid data provided in Arthur format to aid with debugging

= 1.3.01 =
* Ensure 'ALLOW_UNFILTERED_UPLOADS' constant is set when imports run. URLs provided with querystrings where causing WP to throw a security warning
* Remove query string from media filenames imported from Street after they changed the format of their URLs
* Use 'large' photo when importing photos as URLs from Street, with 'propertyhive_street_image_size' filter to override
* Don't set featured in Rex as they don't send it so might've be set manually
* Import deposit, available date and council tax band from SME JSON

= 1.3.0 =
* Cater for properties listed as 'For Sale and To Let' in Street format
* Users now must agree to terms about Reapit Foundations charges before imports will run
* Added ability to make multiple requests in Reapit Foundations format with new 'propertyhive_reapit_foundations_json_properties_requests' filter
* Trim whitespace from API details entered for Utili format
* Set post_title on imported attachments in Reapit/JET format
* Set post_title on imported attachments in Reapit Foundations format
* Further improvements to remote BLM to reduce the risk of duplicates should imports time out

= 1.2.99 =
* Generate EPC graph from ratings in Street format if no EPC graph URL is provided
* Added WP_Error message to log if response fails in Rex format
* Ensure all BLMs are deleted when using remote BLM option. Issues were encountered when multiple remote BLM feeds we in place.
* Added support for Property Portal add on to MRI Thesaurus format
* Added support for Property Portal add on to Rezi format
* Only import properties from Rezi with ApprovedForMarketingWebsite flag
* Declared support for WordPress version 6.0.2

= 1.2.98 =
* Added new Street Book Viewing Link Elementor widget when a Street import is in place
* Correct building name typo in Agency Pilot REST API format
* Correct importing bedrooms etc from Gnomen by casting to right data type
* Set create/modify date when importing properties from Gnomen

= 1.2.97 =
* Import tenure from Street
* Add filter 'propertyhive_street_api_base_url' so Street base URL can be changed
* Add filter 'propertyhive_reapit_foundations_json_negotiators_on_every_request' to allow negotiators to be requested every Reapit impor

= 1.2.96 =
* Import tenure, deposit and material info where present from Veco
* Import material info where present in BLM format
* Add filter "propertyhive_agency_pilot_api_request_body" to modify request sent in Agency Pilot REST API format
* Declared support for WordPress version 6.0.1

= 1.2.95 =
* Import brochures in Reapit Foundations format
* Import council tax band from Veco
* Corrected full description not importing from VirtualNeg
* VaultEA improvements - Import published photos only, better commercial support
* Added support for portal add on in Gnomen format

= 1.2.94 =
* Optimisations to Reapit Foundations format to reduce API calls
* Support the importing of EPCs from Rex
* Cater for Property Portal add on in SME format where branch sent as empty
* Added support for Property Portal add on to Acquaint format

= 1.2.93 =
* Added filter 'propertyhive_sme_professional_json_branch_ids' to customise branch IDs/filenames in SME JSON format
* Exclude sold properties from VaultEA requests
* Support for differing timezone formats when checking updated from Rezi
* Correct letting status not importing from Street format

= 1.2.92 =
* Added support for viewing URL button replacement from Street
* Added price qualifier mapping to commercial properties in Reapit/JET format
* Added support for portal add on to Street format
* Added support for portal add on to Reapit/JET format
* Added support for portal add on to SME Professional JSON format
* Corrected usage of datecreated in BDP format
* Declared support for WordPress version 6.0

= 1.2.91 =
* Support for importing properties from BDP
* Added ability to specify exact hours at which imports are executed. Note: Due to the way in which WP crons are executed this might not be exactly on the hour
* Loop office mapping set to use API key as office identifier
* Don't skip properties if only_updated setting not enabled in BLM imports
* Corrected typo in room dimensions imported rom VaultEA

= 1.2.90 =
* Include under offer properties when requesting properties from Street
* Added support for new OSM geocoding when enabled

= 1.2.89 =
* Import council tax band from Dezrez JSON format
* Import rooms from VaultEA format
* Import different displayaddress based on which one is set in Vault
* Fix sales status not mapping in Street format

= 1.2.88 =
* Corrections to VaultEA format, including display address, images, floorplans and features
* PHP8 compatibility fix for Street format where no features are present
* Use a different field for status in Street format
* Don't import featured field from Foundations as not sent as a field
* Declared support for WordPress version 5.9.3

= 1.2.87 =
* Increased timeout to Rex requests
* Add 'propertyhive_blm_import_prevent_geocoding' filter to turn off geocoding in BLM format
* Improved location mapping in BLM import, allowing for multiple location terms matching one address part, and if multiple are matched, if the parent location name is also matched, use only that one
* Import media captions as captions in WP from Reapit / Jet format

= 1.2.86 =
* Initial support for VaultEA format
* Support for rent frequency in Rex format
* Trim any additional full stops from rent in AI format
* Declared support for WordPress version 5.9.2

= 1.2.85 =
* Don't remove new lines from SME Professional JSON feed

= 1.2.84 =
* Added filter 'propertyhive_reapit_foundations_json_properties_url_parameters' so Reapit Foundations API request parameters can be customised
* Ensure imports aren't executed when main cron is ran via WP-CLI
* Added support for upcoming council tax band field to formats where we appear to get this information

= 1.2.83 =
* Ensure captions set accordingly for media imported from Reapit Foundations API
* Ensure currency is set for lettings properties from Reapit Foundations API
* Import coordinates in Loop format if present
* Use display address field if present in Rex format
* Escape Rex details containing < and breaking main import page
* Declared support for WordPress version 5.9.1

= 1.2.82 =
* Ensure Rezi feeds are only processed if parsing stage completely successfully with valid responses obtained
* Split Rezi feed into separate API requests for sales and lettings properties with new 'propertyhive_dezrez_json_api_calls' filter

= 1.2.81 =
* Added support for office mapping to agentsinsight* XML format
* Added support for Property Portal add on to agentsinsight* XML format
* Added support for Property Portal add on to AgencyPilot API format
* PHP8 compatilibity tweak to Jupix format

= 1.2.80 =
* Support for commercial properties in AgentOS format with new filter 'propertyhive_letmc_json_commercial_property_types' to specify what property types should classify a property as commercial
* Add any missing availabilities to right section in Reapit/JET format based on whether using old or new status mapping

= 1.2.79 =
* Further tweaks and corrections to floor/unit table appended to agentsinsight* descriptions
* Declared support for WordPress version 5.9

= 1.2.78 =
* Change the way in which images are obtained from Reapit Foundations to reduce number of API endpoint calls

= 1.2.77 =
* Corrected issue with floor/unit table appended to agentsinsight* descriptions

= 1.2.76 =
* Added ability in Vebra format to start from particular record
* Added new Vebra v11 letings statuses to list of default commercial availabilities

= 1.2.75 =
* Import virtual tour labels sent in Vebra Alto XML API format

= 1.2.74 =
* Added filter 'propertyhive_sme_professional_json_departments' to allow only certain departments to be imported from SME JSON
* Import features from Reapit Foundations
* Ensure properties flagged as 'external' in Reapit Foundations aren't imported
* Re-download brochure in agentsinsight* format if property updated date is different as there is no URL change when the brochure is replaced

= 1.2.73 =
* Increase the timeout on requests for properties in the Reapit Foundations format

= 1.2.72 =
* Include land when importing properties from Street

= 1.2.71 =
* PHP8 compatibility updates
* Added 'Only Import Updated Properties' option to Street JSON import
* Added 'Only Import Updated Properties' option to Agency Pilot JSON import
* Don't strip new lines from imported full description in Arthur format
* When deleting an import, remove any sensitive feed details from database
* Declared support for WordPress version 5.8.3

= 1.2.70 =
* Correction to recent public address override feature added Street format

= 1.2.69 =
* Added auto-location mapping to SME Professional XML format
* Use public address override field in Street format is present

= 1.2.68 =
* Moved filter in property list allowing filter by import to its own dropdown instead of using marketing status

= 1.2.67 =
* Added support for parking in Reapit / JET SOAP API format
* Added support for parking in MRI XML format
* Added emphasis on using 'Only import updated properties' in Reapit Foundations format

= 1.2.66 =
* Added support for EPCs in PropertyAdd XML format
* Additional support for brochures in Gnomen format
* Added support for 'featured' in Gnomen format
* Added calls to save_post hook when removing properties

= 1.2.65 =
* Move obtaining of 10ninety XML into class to provide improved debugging/logging should obtaining the XML fail
* Changed obtaining of 10ninety XML to use wp_remote_get() instead of trying to manually do fallbacks

= 1.2.64 =
* Import MaxSize field in Reapit SOAP API and use accordingly for commercial properties
* Corrected storage of price_actual field when importing from Kyero

= 1.2.63 =
* Corrections to Reapit Foundations format including fixing images and negotiator not importing and only importing certain statuses
* Execute imports in a random order to ensure if multiple imports are setup and one fails that it doesn't always stop the subsequent imports from running
* Allow token to be passed in query string when running Vebra feed manually to speed up debugging. Previously would have to wait an hour for existing token to expire

= 1.2.62 =
* Added price qualifier mapping to commercial for Vebra API import
* Added facility for CSV imports to remove old properties in the form of a new setting
* Declared support for WordPress version 5.8.2

= 1.2.61 =
* Include Vebra custom_location node in list of fields check when trying to auto-assign location
* Imported created date for properties imported from Street
* Added support for virtual tours in Street format
* Added support for additionalMedia in Street format. These will go into brochures within Property Hive
* Added new Vebra lettings statuses introduced in v11 of their API to mapping stage
* Correct unit variable name passed in agentsinsight XML hook

= 1.2.60 =
* Update version number in API URLs used by Agency Pilot

= 1.2.59 =
* Import available date in Dezrez REZI JSON format
* Look for field called virtual_tour when importing from Gnomen
* Check existing properties with both publish and future status when importing from BLM. Aimed at getting around timezone issues

= 1.2.58 =
- Refactor Dezrez XML import
* Improve logic surrounding 'Only Import Updated Properties' in Dezrez XML format
* Add new filter 'propertyhive_dezrez_xml_api_calls' to customise parameters sent in API requests in Dezrez XML format

= 1.2.57 =
* Tweaks to Reapit Foundations integration following the requirement to have on Property Hive app that all clients use instead of each client having their own developer account as originally advised
* Prevent featured getting overwritten in MRI XML format when set manually
* Look for property_area field as well as area in Gnomen feed
* When changing setting to store media as URLs, remove that type of media from the media download queue

= 1.2.56 =
* Added support for Clarks Computers XML format
* Changed Dezrez XML requests to be done over HTTPS

= 1.2.55 =
* Updated Vebra API to use v11
* PHP8 compatbility in Dezrez XML format
* 'Fitted' information appended to description imported from AgentsInsight*
* Floors/units table appended to description imported from AgentsInsight*

= 1.2.54 =
* Added filter 'propertyhive_database_id_mappings_vebra_api_xml' to customise departments imported from Vebra
* Added filter 'propertyhive_property_import_keep_logs_days' to change how long logs are kept before being automatically deleted

= 1.2.53 =
* Import Let Agreed properties from Street

= 1.2.52 =
* Added filters 'propertyhive_let_mc_requests_per_chunk' and 'propertyhive_let_mc_sleep_seconds' to change AgentOS API request rate and pause duration
* Added support for a vtour field in Gnomen format
* Don't empty post_content when updating properties as this was causing issues with the data bridges

= 1.2.51 =
* Added pagination to Street format to cater for when more than 250 properties exist
* Added more information to logs when BLM has invalid number of fields
* Tweak to department assignment in ExpertAgent format

= 1.2.50 =
* Added branch mappings to Street format
* Added support for currencies in Rex format setting currency to country of property provided

= 1.2.49 =
* Allow comma-delimited list of branch IDs to be used in Reapit formats
* Corrected issue with args passed through to Street requests
* Allow Rex API URL to be customised to support non-UK API requests

= 1.2.48 =
* Added support for lettings properties in Loop format
* Catered for differing XML structure for images in Gnomen format

= 1.2.47 =
* Added initial support for Reapit Foundations platform
* Correct typo in Street rent field name
* In media background processing cron, only unlink files once all media for a property has been processed. Attempts to get around potential issue with cron timing out midway through importing images for a property
* Added filter 'propertyhive_blm_import_address_to_geocode' to change the address used for geocoding requests
* Declare support for WordPress version 5.8

= 1.2.46 =
* Street format to use price_pcm field for lettings properties if it exists

= 1.2.45 =
* Rex format to only include properties with publish_to_external set
* Rex format to add pagination to get around 100 property limit in requests
* Added support for brochures to Street format

= 1.2.44 =
* Added support for new Roby AI CSV
* Display different error if empty data in Loop format
* Set negotiator in Gnomen format if matching user with agent_name found
* Correct property ID used in logging when geocoding request fails in Gnomen

= 1.2.43 =
* Added filter 'propertyhive_gnomen_commercial_category_id' to change Gnomen commercial category ID
* Use description2 field provided by EstatesIT as part of full description

= 1.2.42 =
* Import parking and outside space in EstateIT XML format based on matchflag
* Import deposit in EstateIT XML format
* Support virtual tours in Rentman XML format
* Change log when error occurs in media queue cron to help differentiate where the issue arose

= 1.2.41 =
* Include overseas address fields when auto-assigning locations in BLM
* Currency used in BLM imports to match that of country. Previously it was hardcoded to GBP

= 1.2.40 =
* Import mixed room dimensions in Vebra format instead of just metric
* Import additional/disclaimer text in Domus format and append to end of full description
* Corrected rooms not importing in Domus format

= 1.2.39 =
* Catered for Yearly rent frequency coming through in Arthur format
* Catered for Quarterly rent frequency coming through in Arthur format
* Corrected issue with floorplans not importing in Domus format

= 1.2.38 =
* Catered for reception rooms field in Reapit feed being 'Receptions' instead of 'ReceptionRooms'
* Add new action 'propertyhive_property_imported' to all formats. A similar hook existed already but it was format specific.
* Declare support for WordPress version 5.7.2

= 1.2.37 =
* Acquaint XML format to set currency based on country
* Arthur format to support commercial when 'Units Only' structure selected

= 1.2.36 =
* Added support for differing Gnomen format, looking at whether there is a key in the URL to determine which format to use

= 1.2.35 =
* Added notification if it appears imports aren't running automatically
* Added link to documentation to existing notification about an import that's potentially fallen over
* Added more explanation to logs when a 401 response is received from a Vebra Alto import
* Don't keep making failed geocoding requests in Juvo format
* Corrections to Street format now that we have someone using it
* Altered chunk check to ignore 0 and prevent a modulo by zero error in Juvo format. Needs to be rolled out across all formats
* Declare support for WordPress version 5.7.1

= 1.2.34 =
* Corrected issue with properties not getting assigned the correct department in new SME JSON format
* Added support for 'Daily' as a rent frequency in the Arthur format

= 1.2.33 =
* Added support for SME Professional JSON format

= 1.2.32 =
* Correct properties not going in as PCM in Agency Pilot API format
* Split out sales and lettings availabilities in Reapit format

= 1.2.31 =
* Added support for commercial properties in 10ninety XML format
* Filter applied to all SoapClient initiations in Reapit format

= 1.2.30 =
* Added support for mapping branches in Acquaint XML format. It uses the XML filename as each branch will have their own feed
* stripslashes() from Vebra password as entering a password containing a slash would result in the details not working
* Declare support for WordPress version 5.7

= 1.2.29 =
* Added support for commercial properties to Acquaint format
* Added compatibility to 10ninety XML format for Property Portal add on
* Correct issue with properties not mapping to a branch in Vebra format when Property Portal add on is being used
* Shorten length of log when error occurs during queued media import

= 1.2.28 =
* Added support for importing units in agentsinsight* format. Use filter 'propertyhive_import_agentsinsight_units'
* Specify timeout limit in AgentOS GET requests
* Catered for let_agreed field in Rex format
* Tweaks to MRI rooms import
* Added the facility to give multiple imports a custom name to help distiguish between them. Will only appear when more than one import is in place. Aimed at sites/portals that might have multiple imports running of the same format
* Added support to SME format for Property Portal add on
* When mappings are changed and 'Only import updated properties' setting is selected, show a warning telling the user they may need to do a fresh full import
* Only prepend opening XML tag if one doesn't exist in Gnomen format
* Declare support for WordPress version 5.6.2

= 1.2.27 =
* Trim whitespace from media URL's in Kyero format after seeing a third party wrap URLs with a space causing media imports to fail
* Amend how property data is constructed when parsing data from AgentOS
* Declare support for WordPress version 5.6.1

= 1.2.26 =
* Added support for sales feed from SME Professional
* Added support for overseas feeds in BLM format
* Correct issue with properties not being drafted or deleted when necessary in Vebra format

= 1.2.25 =
* Add option to only import updated properties in 10ninety format
* Don't remove line breaks in room descriptions for Rex
* Add line breaks between descriptionfull and rooms if both exist in Acquaint format
* If the expected data from MRI is inside another outer node, move down a level to get the correct data

= 1.2.24 =
* Add post_date to show date created for Thesaurus import
* Correct Juvo media imports when storing media files
* Add Alto to references of Vebra to make it clear that Alto is supported

= 1.2.23 =
* Check for null description when inserting media to be queued
* Tweak check for residential lettings properties in Rex format

= 1.2.22 =
* BLM remote format support to accept URL to ZIP file
* Filter BooksterHQ properties by type to ensure only properties import

= 1.2.21 =
* Import dimensions in commercial descriptions in Vebra format
* Further tweak to last update regarding Arthur imports stopping if no images in JSON

= 1.2.20 =
* Improved error logging for formats that obtain data via FTP
* Don't stop running Arthur import completely if images JSON is empty
* Import long_description as full description if no rooms or extras in MRI format

= 1.2.19 =
* Support for second virtual tours and captions in Reapit format
* Don't keep making geocoding requests in Jupix format if previously failed due to REQUEST_DENIED error. It just causes the import to hang for about 3 seconds each time a failed geocoding request is done
* Cater for priceTo being 0 in Agency Pilot JSON format to stop giving weird price formatting like £100,000-£0
* Remove unit ref from unit display address in Arthur format as done above and caused duplication
* Declare support for WordPress version 5.6

= 1.2.18 =
* Correct issue with Vebra format not removing properties when using the 'Only import updated properties' setting
* Ensure branchID is set on a property in AgentOS format to ensure office mapping works
* Correct media queue not working correctly on Acquaint format due to date format
* Add prompt to try changing FTP Passive option if gettnig file via FTP fails
* Add filter 'propertyhive_agency_pilot_json_properties_parameters' in Agency Pilot JSON format so parameters used when querying properties can be customised

= 1.2.17 =
* Import image description from Arthur format
* Added support for commercial properties to MRI XML format
* Allow mapping of multiple branches in MRI XML format by using comma-delimited list of branch codes
* Only run media background processing cron when applicable
* Number of queued media items displayed more accurately represents number of actual media items that will be processed
* Tweaks to media background processing structure to try and prevent recurring 'function already declared' PHP error

= 1.2.16 =
* Correct issue with EPC's not being queued when background media processing enabled
* Added option to ExpertAgent XML format to only import updated properties
* Remove _property_import_data meta key from being checked when comparing before and after meta values

= 1.2.15 =
* Correct POA not being set in Agency Pilot API format
* Correct rent frequency being hardcoded to PA in Agency Pilot API format
* Ensure prices and rents are to 2 decimal places in Agency Pilot API format
* Import vebra's propertyid attribute as additional meta data for use with Property File add on

= 1.2.14 =
* Log the exact data received for each property and make this available to view on the property record
* Added ability to filter properties by which import they came from
* Added support for Property Portal add on in Vebra format
* Set pagination on BooksterHQ requests to overcome default 20 limit
* Allow Reapit/Jet to be run for specific departments by adding new 'propertyhive_property_import_jet_departments' filter 
* Import unit virtual tours on top-level property in Arthur format
* Correct undefined error when saving agents when certain departments not active
* Trim whitespace from API details entered for Arthur format

= 1.2.13 =
* Added support for BooksterHQ format
* Added support for Street format
* Set post date of property to date created received in Utili format
* Correct media not importing correctly from Juvo format when storing media as URLs
* When used in conjunction with our Property Portal add on, add the ability to specify which branch is associated with which import
* Added documentation link to main plugins page
* Added support for virtual tours to Arthur format
* Correct issue with media cron not running when ran by itself
* Correct issue with latitude not importing when doing CSV import
* Added missing message to Vebra logs when photos are queued
* Remove background media queueing from beta
* Declare support for WordPress version 5.5.3

= 1.2.12 =
* Added support for tenure mapping to MRI format
* Added support for price qualifier mapping to MRI format
* Imported lat and lng in MRI fomat
* Imported 'extras' in MRI format, appending them to the full description
* Corrected issue with properties not getting POA set accordingly in MRI format
* Changed Vebra format so getting 'updated properties only' gets all within last 24 hours instead of just the last hour. This should get around any issues with timezones, BST, or rare occurence when a property ges updated in Vebra as the import is running
* If garden node is passed as 'yes' in the Decorus data record it as the corresponding Outside Space value with same name
* Catered for rent being a range in agentsinsight* format so rent from and to fields are set accordingly
* Corrected rent frequency for commercial properties in Dezrez JSON format
* Renamed recently added fatal error related functions to avoid potential clashes
* Renamed media cron add_log() function so it doesn't clash with main class

= 1.2.11 =
* Added option to MRI XML format to only import updated properties
* Ensure features and rooms are imported in MRI XML format
* Extend Veco virtual tour lookup to include the word 'tour' when looking in the URL fields to determine if a URL is a virtual tour

= 1.2.10 =
* Correct rent frequency for commercial properties in Dezrez XML format
* Add support for mapping commercial property types in Dezrez XML format
* If the Loop format is being used add a prompt about exporting enquiries to Loop including link to add on
* If the Arthur Online format is being used add a prompt about exporting enquiries to Arthur Online including link to add on

= 1.2.09 =
* Ensure only property.xml XML file is processed in Decorus format
* Ensure WP_Error is returned when calling wp_update_post()
* Correct issue features not resetting in agentinsight* format

= 1.2.08 =
* Track fatal errors and log them accordingly in an effort to aid with support when improts bomb out with very little error information
* Ensure modified images are imported in ExpertAgent format when replaced with different file but using same filename
* When mapping branches in Jupix format cater for name also and comma-delimited list
* Import formats now ordered correctly in dropdown regardless of case

= 1.2.07 =
* Correct typo in AgentOS format resulting in town/city not getting imported
* Set timeout in Veco request
* Cater for null summary descriptions in Veco format to prevent properties not getting imported
* Correct typo resulting in Veco Matterport virtual tours not importing
* Declare support for WordPress version 5.5.1

= 1.2.06 =
* Added new option to new Caldes format for accessing the XML via remote FTP

= 1.2.05 =
* Added support for Caldes Software XML format
* Remove .00 from floor areas received in BLM format
* Import floor area unit for commercial properties in BLM format
* Added log when response from Vebra when getting token isn't expected
* Correction to 'Only Import Updated' option in Dezrez JSON format as it was skipping a property if it fell over on one
* Set POA in Agency Pilot API format accordingly
* Correct postcode field in Agency Pilot REST API format

= 1.2.04 =
* Look at UPDATE_DATE in BLM when checking if brochure URLs need importing again
* Add a log entry when REAXML file is processed containing URL to download the XML to aid with debugging

= 1.2.03 =
* Import features from agentsinsight* format
* Clear down queued media when an import is deleted
* Clear down queued media when a queued media feature is disabled
* Run logs through htmlentities() so any HTML/XML within a log doesn't break the output

= 1.2.02 =
* Added new import structure setting to Arthur format allowing importing of units only
* Improved UI surrounding selecting the import format changing it to a dropdown instead of a long list
* Removed .00 from prices received in BLM format
* Corrected floor area not importing in BLM
* Check 'URLs' node in Veco format to see if they contain a virtual tour

= 1.2.01 =
* Added support for Virtualneg XML
* Use portal address in arthur field if present
* Correct create/update date in Arthur format for properties
* Import virtual tours in Thesaurus format
* Declare support for WordPress version 5.5

= 1.2.0 =
* Added new concept of processing media in a separate background queue. Being released in BETA. This aims to get around issues with imports timing out, on of the most common support issues we see.

= 1.1.82 =
* Catered for MRI XML format having media sent in different format
* Import available date from AgentOS
* Set timeout on requests to Arthur to try and avoid requests timing out and hitting the default 5 second limit

= 1.1.81 =
* Added new option to Arthur format to specify only top-level property should be imported with no units
* Import virtualtour node send in Gnomen XML format

= 1.1.80 =
* Add filter to each format allowing customisation of address fields to check when auto-mapping property to location
* Geocoding requests to be over HTTPS in AgentOS format

= 1.1.79 =
* Updates to Gnomen format including support for commercial properties, brochures, EPCs and Virtual Tours
* Run EPC URLs sent by MRI through html_entity_decode() function

= 1.1.78 =
* Import virtual tours in Dezrez JSON format that are hosted on Rezi servers instead of being on YouTube etc
* Added more debugging to AgentOS logs when issue arises obtaining or parsing responses

= 1.1.77 =
* Change field being used as unique identifier in REX format
* Assign properties to negotiator based on name in REX format
* Extend limit on number of properties received in REX format
* Don't include withdrawn properties in REX format

= 1.1.76 =
* Added parking and outside space as mapped fields in SME Professional format
* Use 'suppress_filters' when removing properties from Arthur format to ensure units are included

= 1.1.75 =
* Import availability in AgentOS format
* Ensure property types can be mapped in Kyero format
* Set featured in Agency Pilot API format
* Correctly categorise brochures and EPCs in Agency Pilot API format
* Import all features from SME format

= 1.1.74 =
* Correct size from/to and units in agentsinsight* format
* Import URL set in particulars_url field as brochure when present in agentsinsight* format
* Reduce number of requests made per minute in AgentOS format to prevent hitting strict throttling limits. In future might need to look at adding a 'Only import updated properties' option (assuming dates are sent)
* Don't keep doing geocoding requests if one is denied in agentOS format
* Added filter to AgentOS format (propertyhive_agentos_json_properties_due_import) to filter properties pre-import

= 1.1.73 =
* Ignore properties in Acquaint XML format that have 'feedto' set to 'none'

= 1.1.72 =
* Cater for lat/lngs being zero as well as empty strings when deciding whether to do geocoding fallback to obtain co-ordinates

= 1.1.71 =
* Corrected address fields imported in Dezrez XML format ensuring house number is imported and putting town into the town field in Hive
* Corrected issue with thumbnail image being imported in MRI format
* Corrected issue with floorplans not importing in MRI format
* Declare support for WordPress version 5.4.2

= 1.1.70 =
* Corrected issue with new MRI format not making request
* Corrected wrong floor area unit being imported from Agency Pilot API

= 1.1.69 =
* Added support for MRI XML format
* Split out sales and lettings availabilities in Dezrez JSON format

= 1.1.68 =
* Added support for AgentOS/LetMC API format
* Tweaked address fields imported in 10ninety format

= 1.1.67 =
* Added code to do redirects from mailouts from third party software. For Jupix this will look for the format http://website-url.com?profileID={property-id-in-jupix} and for all other formats the following can be used: http://website-url.com?imported_id={property-id-in-software}
* Tweaks to Rex format regarding display address and summary description

= 1.1.66 =
* Added next and previous buttons to logs to allow quickly cycling through them
* Added ability to force import to run by adding &force=1 to manual execution. Reduces the need to wait 5 minutes when we know it's definitely fallen over or when debugging
* Added support for new epcgraph field in EstatesIT format
* Catered for ampersands in data when importing CSVs for fields where there's a list of possible values

= 1.1.65 =
* Added support for Decorus / Landlord Manager XML feed

= 1.1.64 =
* Updated Arthur format to only import properties that have available or under offer units
* Catered for properties from Arthur with no units

= 1.1.63 =
* Availabilities split out for formats that share statuses across departments
* Added ability to update properties in CSV using existing post ID
* Only update from CSV when field set as to not overwrite existing data
* Price qualifiers changed to not be case-sensitive in Vebra format. Testing with the ability to roll this out to all taxonomies across all formats in future

= 1.1.62 =
* Added support for Rex Software format
* Added new setting to Arthur format to specify if units should imported as their own properties
* Added floor area fields (albeit blank) to Dezrez formats for commercial properties so they at least appear in search results (due to floor area being the default sort order)
* Added new filters to Jupix image (propertyhive_jupix_image_url) and floorplan (propertyhive_jupix_floorplan_url) URLs. Useful if wanting to use a different size of image than large
* Declare support for WordPress version 5.4.1

= 1.1.61 =
* Added filters to Dezrez import formats to allow customisation of which property types are classed as commercial and should therefore put properties in the commercial department

= 1.1.60 =
* Added support for virtual tours to Loop format
* Catered for more than 100 properties in Aruthur Online results
* Don't process Acquaint data if feed couldn't be obtained/parsed
* Assigned properties to commercial department accordingly in Dezrez XML format when propertyTpye is commercial
* Set default URL for Loop format when setting up a new import
* Check more fields when auto-matching location in agentsinsight* format
* Declare support for WordPress version 5.4

= 1.1.59 =
* Catered for when no currency provided in Kyero XML format by using currency of country instead

= 1.1.58 =
* Renamed Thesaurus to Thesaurus / MRI
* Used lat/lng from Thesaurus geocode.file when available instead of making geocoding requests

= 1.1.57 =
* Added support for agentsinsight* XML
* First pass at automatically adding custom field mappings (mainly property type and availability) that don't exist yet to all formats to save having to go through logs to find which ones don't exist

= 1.1.56 =
* Specified timeout on Agency Pilot API requests to stop it timing out after the default 5 seconds
* Passed options and token through to Agency Pilot API pre-import hooks

= 1.1.55 =
* Corrected mapping of location in Agency Pilot REST API format
* Logged Vebra data in database to add some sort of debugging. At some point we'll a) roll this out to all formats and b) make it accessible on frontend

= 1.1.54 =
* Take into account child-parent locations relationships when validating and importing CSV files

= 1.1.53 =
* Updated list of property types in Dezrez format that determine whether a property should be assigned to commercial department

= 1.1.52 =
* Correction regarding date formatting in Vebra format when working out date to get changed properties from
* Correction regarding checking if EPC's imported from BLM previously or not. Catered for scenario where MEDIA_IMAGE_60 doesn't exist and only MEDIA_DOCUMENT_50 passed

= 1.1.51 =
* Put properties in commercial department accordingly from Dezrez when property type contains 'Commercial'
* Updated how VECO format obtains data by using wp_remote_get() instead of cURL.

= 1.1.50 =
* Updated how Dezrez JSON format obtains data by using wp_remote_post() instead of cURL. Also don't put downloaded data into a file removing issues with permissions etc

= 1.1.49 =
* Added separate commercial property type mapping to Vebra format
* Added ability to assign properties to Agent/Branch during a CSV import if the Property Portal Add On is active
* Declare support for WordPress version 5.3.2

= 1.1.48 =
* Updated BLM format so Geocoding requests aren't continuously performed if previously failed due to REQUEST_DENIED being returned
* Added new 'propertyhive_expertagent_departments_to_import' filter to ExpertAgent format so departments imported can be overwritten
* Updated setting of featured properties in Veco format to use update_post_meta instead of add_post_meta
* Declare support for WordPress version 5.3.1

= 1.1.47 =
* Added option to only import updated properties in the Veco format based on the 'UpdatedDate' provided
* Updated the Veco format so images/media are re-imported if 'UpdatedDate' on the property differs from last time they were imported. The URL's don't change so have no other way to determine whether they should be re-imported or not
* Ensure office is at least set to the primary in Kyero format
* Set frontend submission user ID in CSV import if match found and add on is active

= 1.1.46 =
* Use displayAddress field from Loop if present, without trying to construct it ourselves
* Corrected issue with features in Domus format not importing
* Corrected link to docs

= 1.1.45 =
* Import negotiator from JET/ReapIT format if user with same name exists
* Added new filter to allow JET SOAP Client options to be modified
* Continue to import property even if no units in Arthur format
* Set availability to Sold if soldDetails node exists in REAXML format
* Declare support for WordPress version 5.3

= 1.1.44 =
* Added new options to draft or delete property when taken off of the market. Note this will result in 404 errors as the proeprty URL is no longer accessible
* Added ability to set negotiator when importing properties via CSV
* Set reference number to 'my_unique_id' field in SME Professional format

= 1.1.43 =
* Removed previous department mapping Gnomen as noticed the 'transaction' field so use that

= 1.1.42 =
* Made department/category a mappable field in Gnomen format as it can differ per client

= 1.1.41 =
* Ensure mapping for parking and outside space is saved if exists
* Corrected removal of properties in ReaXML format

= 1.1.40 =
* Corrected field for property type in Gnomen format
* Corrected field for available date in Utili format
* Added filter to ignore whether portal add on active when removing properties
* Removed properties from Acquaint format with status 'ERROR'
* Declare support for WordPress version 5.2.4

= 1.1.39 =
* Activate new Geocoding API key setting under 'Property Hive > Settings > General > Map'. Used for when the main API key entered has a referer restriction applied and separate key required just for Geocoding requests.
* Use new Geocoding API key in requests if present when trying to get lat/lng from address, else fallback to original
* Override default limit of 20 records in Arthur requests

= 1.1.38 =
* Added room dimensions to JET format
* Added more default property type mapping in Utili format

= 1.1.37 =
* Corrected issue with property type not importing in Utili format
* Trimmed additional space from Vebra details entered as this sometimes caused support queries when extra spaces had been copied and pasted
* Imported correct unit value for price, rent and floor area when importing commercial properties via CSV
* Switched to new Loop API
* Changed Loop API to use wp_remote_get() instead of bespoke cURL request
* Added 'Sale Agreed' status to default list of availabilities in Utili format
* Stored HTTPS version of media when storing media as URLs in Jupix format
* Added support for taxonomies that support multiple values during CSV validation such as commercial property type

= 1.1.36 =
* Updated Arthur format to import floorplans and EPCs
* Updated SuperControl format to use GET instead of POST when requesting properties
* Updated SuperControl format to use propertyname as Display Address instead of propertytown
* Updated SuperControl format to import booked dates, allowing date/availability filter on frontend
* Corrected refreshing of Arthur token wiping out existing imports
* Declare support for WordPress version 5.2.3

= 1.1.35 =
* Jet/ReapIT format to look for 'Bedrooms' field if 'TotalBedrooms' field doesn't exist
* Juvo image index to start at 0 instead of 1

= 1.1.34 =
* Added prelimenary support for SuperControl API

= 1.1.33 =
* Updated Arthur format to import deposit, rent frequency and descriptions
* Updated Arthur format to always give images an extension
* Use ID as reference number in Acquaint format
* Import fees as a room in Acquaint format
* Import brochures from Acquaint format
* Use wp_remote_get() when trying to get feeds in Kyero format instead of adding our own fallbacks

= 1.1.32 =
* Updated Vebra format to cater for firmid when assigning properties to offices. The mapping can now be entered as {firmid}-{branchid} if same branchid shared across multiple firms within the same XML

= 1.1.31 =
* Corrected issue with Acquaint format importing and replacing the existing media every time it ran
* Updated Dezrez JSON format to import EPCs if sent in the 'Documents' field instead of the 'EPC' field

= 1.1.30 =
* Added support for Arthur format ready for initial testing. First release which requires the new Rooms and Student Accommodation add on
* Added warning to property record if property was imported by an import that no longer exists to aid debugging

= 1.1.29 =
* Added new option to only import updated properties in Jupix format
* Added new option to only import updated properties in Thesaurus format
* Removed 'Featured' and 'PriceOnApplication' as availability mappings in Dezrez JSON format as these were conflicting with Sold STC and other statuses
* Updated Vebra format to import commercial property full descriptions into correct description fields instead of rooms

= 1.1.28 =
* Write to log if status sent that's not mapped in WebEDGE format
* Improved price qualifier mapping in Dezrez JSON format
* Use RoleID as reference number in Dezrez JSON import
* Use wp_remote_get() to obtain remote files in Domus format
* Don't import properties with sale_stage sold, let or sold_or_let in Realla

= 1.1.27 =
* Ensure currency is stored when importing properties across all formats
* Look for office name or ID when deciding which office to assign properties to in Dezrez JSON format. Previously it would look at just name

= 1.1.26 =
* Added support for Utili API
* Few amendments to Juvo XML format based on responses from their developers

= 1.1.25 =
* Added ability for new formats to be added by third parties through use of new filters and actions
* Declare support for WordPress version 5.2.2

= 1.1.24 =
* Import Address4 field if present as county in ReapIT/JET format
* Try to auto assign properties to location in ReapIT/JET format
* Add Address4 field to geocoding request in ReapIT/JET format
* Cater for floorplans being sent as documents in Realla format

= 1.1.23 =
* Added support for remote BLM whereby BLM's are retrieved via URL instead of being sent via FTP
* Corrected POA and rent frequency in Agency Pilot JSON format
* Corrected wrong field name being used in EstatesIT setup
* Corrected potential parse error in BLM format after recent geocoding amend
* Corrected log regarding number of virtual tours imported in 10ninety and BLM formats

= 1.1.22 =
* Added support for Juvo XML
* Don't perform Google Geocoding request if no API key present and write to log
* Corrected featured properties not being set in Domus format

= 1.1.21 =
* Corrected wrong URL being used for new ReSales Online format

= 1.1.20 =
* Added support for ReSales Online XML
* Added 'Categories' to list of data retrieved from Agency Pilot API
* Declare support for WordPress version 5.2.1

= 1.1.19 =
* Correct format of available date imported in WebEDGE format
* Commercial properties imported via Jupix can now get assigned multiple property types

= 1.1.18 =
* Import reception rooms in SME format
* Re-download images and other media in Acquaint format if 'updateddate' field has changed
* Catered for rent being sent as 0 in Jupix format for commercial properties
* Don't process BLM if missing #DATA# or #END# tags
* Added new filter 'propertyhive_expertagent_unique_identifier_field' to change field used as unique ID in Expert Agent format

= 1.1.17 =
* Added support for Estates IT XML
* Added warning on JET / ReapIT format if SOAP not enabled
* Catered for price being sent as 0 in Jupix format for commercial properties
* Added support for 'sale by' for commercial properties in Jupix format
* Improved way in which Jupix XML is obtained and output response if failed
* Don't import on hold, withdrawn or draft properties in WebEDGE format
* Cater for querystring or no link direct to PDF in brochure URL in BLM
* Import 'big' image from Loop
* Removed duplicate </table> from CSV mapping stage
* Don't import room dimensions from WebEDGE if 0' 0"
* Added price qualifier support for PropertyADD format
* Corrected placeholder on PropertyADD URL input
* Declare support for WordPress version 5.2

= 1.1.16 =
* Added support for Eurolink Veco API format
* Added support for Loop API format
* Import available date from JET / ReapIT
* Only run DezrezOne XML imports if both sales and lettings parsed correctly

= 1.1.15 =
* Added support for Kyero XML format
* Completed integration testing for WebEDGE / Propertynews.com format

= 1.1.14 =
* Added support for SME Professional XML
* Corrected issue with brochures imported using Agency Pilot JSON format not importing correctly when URL's contain a query string
* Call update_property_price_actual() after importing commercial properties in BLM and CSV formats so prices and currencies get set accordingly which are then later used for ordering
* Added preliminary support for Gnomen (pending testing)
* Added preliminary support for WebEDGE / Propertynews.com (pending testing)
* Declare support for WordPress version 5.1.1

= 1.1.13 =
* Added office and negotiator mapping to Agency Pilot REST API format. Office mapping can be controlled when setting up the import and entering the ID of the office from Agency Pilot. When mapping the negotiator Property Hive will look for a WP user with the same name, otherwise will default to the current user.

= 1.1.12 =
* Catered for new media storage settings across all formats allowing media to be saved as URL's instead of actually downloaded
* Fixed Domus feed falling over when a property is missing a description
* Empty ph_featured_properties transient after import has completed

= 1.1.11 =
* Clean up media no longer used from all formats to assist with disk space growing over time storing old, unused media
* Import district and county in Agency Pilot REST API format
* Correct features not being imported correctly in Agency Pilot REST API format

= 1.1.10 =
* UTF8 encode room names and room descriptions from Thesaurus format

= 1.1.9 =
* Fixed commercial properties coming through as residential in ExpertAgent format when department was set to 'Commercial Sales' or 'Commercial Lettings'
* Swap Agency Pilot REST API over to using the new OAuth2 authentication
* Cater for ampersands being present in ExpertAgent branch names
* Declare support for WordPress version 5.0.3

= 1.1.8 =
* Revised way in which checking if import already running is done
* Try and auto-assign properties to location in PropertyADD format
* Changed logic of Expert Agent room imports in event rooms no longer exist
* Corrected available date imported from Acquaint
* Added price qualifier mapping to Acquaint format
* Added new filter (propertyhive_{format}_properties_due_import) to filter properties pre import
* Log post ID of property being removed
* Remove properties correctly in Vebra format where action is 'deleted'
* Declare support for WordPress version 5.0.1

= 1.1.7 =
* Limited log entries to 255 characters to prevent them exceeding DB limit and not getting logged
* Added support for commercial properties in ExpertAgent format
* Corrected HTML tags in PropertyADD descriptions coming through encoded

= 1.1.6 =
* Added support for PropertyADD XML format

= 1.1.5 =
* Added support for new Agency Pilot REST API format

= 1.1.4 =
* Corrected issue with Geocoding requests failing due to &amp; in URL instead of &
* Corrected issue with Geocoding request failures being logged due to length of error message
* Added setting link to main plugins page
* After importing media in Jupix format remove files that are not referenced

= 1.1.3 =
* Change geocoding requests so they're made over HTTPS to prevent failure

= 1.1.2 =
* Use uploaded date from Vebra as post date
* Added commercial tenure mapping to Reapit / JET format

= 1.1.1 =
* Improvements to Reapit / JET format to reduce chance of it timing out and removing all properties
* Added support for featured properties in Reapit / JET format
* Corrected default status for commercial properties in Reapit / JET format
* Corrected issue with mappings being duplicated in dropdown during mapping step of setting up new import

= 1.1.0 =
* Corrected issue with new 'Only import updated properties' option in Reapit / JET format
* Added link to logs to download processed BLM files to assist with support

= 1.0.99 =
* Added new option to Reapit / JET format to only import updated properties
* Added support for commercial properties to Reapit / JET format
* Added support for multiple residential property types in Reapit / JET format
* Catch invalid SOAP calls in Reapit / JET feed which would cause fatal error
* Fixed availability mapping in Agency Pilot format
* Added 'OfferAccepted' to default list of availability mappings in Dezrez JSON format
* Append floors and tenancy schedule to full description in Realla format
* Correct commercial rent frequency in Vebra format. Don't just default to pa
* Declare support for WordPress version 4.9.8

= 1.0.98 =
* Added additional warning when deleting import that has on market properties. Was causing support when people created a copy of an existing import and wondered why the old properties weren't removed from the market.
* Take into account MarketedOnInternet field in ReapIT/JET format when deciding if property should be on the market or not
* Updated Dezrez JSON format to include support for specific Branch IDs and/or Tags
* Corrected property ID field name in Realla format when comparing meta/terms and logging changes

= 1.0.97 =
* Added support for commercial tenure to BLM format
* Added new message promoting new Jupix Enquiries add on if a Jupix import is setup
* Removed line breaks from full description when importing from Jupix as they include both HTML <br>'s and line breaks which resulted in double spacing on front end.
* Tweaked Reapit / JET remove functionaliyy including new filter and improved log
* Declare support for WordPress version 4.9.7

= 1.0.96 =
* Renamed 'Jet' to 'Reapit / Jet' as they use the same API
* Remove unnecessary logs that offered no benefits. Should reduce log entries by 50%
* Do comparison of meta data and taxonomies/terms before and after importing properties, then compare and display any differences in the logs
* When logging how many photos, floorplans etc have been imported, display how many are new vs existing
* Added support for commercial properties to CSV format
* Display warning next to 'Import Frequency' setting about a high frequency and getting IP blocked

= 1.0.95 =
* Added support for Agency Pilot format
* Added new 'Email Reports' features allowing log to be emailed to specified recipient after an import completes
* Added warning and details on property record if viewing a property record that came from an automatic import

= 1.0.94 =
* Set POA correctly in JET format when applicable (i.e. when PriceQualifier field is 'PA')
* Added support to Jupix XML and Expert Agent XML formats for when the property portal add on is active to assign properties to agents and branches

= 1.0.93 =
* Added support for wp-cli. Now manually execute import by running 'wp import-properties'

= 1.0.92 =
* Cater for BST timezone when requesting properties from Vebra who seem to want dates in GMT/UTC (unsure as no mention in their docs relating to timezones)
* Declare support for WordPress version 4.9.6

= 1.0.91 =
* Don't set negotiator ID if it's been set manually or already exists
* Don't import off market or withdrawn properties in Realla format

= 1.0.90 =
* Updated JET format after restrictions added their end which caused imports to fail. The change is to not get all properties in one go now but obtain them in batches and paginate through them.

= 1.0.89 =
* Added support for Realla JSON API

= 1.0.88 =
* Add cURL fallback when obtaining property details in Dezrez XML format

= 1.0.87 =
* Write full description for commercial properties to correct field when importing them from Jupix
* Cater for translations when outputting field names in CSV mapping process
* Don't continue to import properties from ExpertAgent or CityLets when XML can't be parsed. Previously could've meant all properties were removed from market if invalid XML provided
* Add log when availability in Vebra format not mapped
* Look at SEARCHABLE_AREAS field when automatically mapping location in 10ninety
* Change re-run limit from 12 to 6 hours if nothing has happened
* Declare support for WordPress version 4.9.5

= 1.0.86 =
* Attempt to automatically assign properties to locations in Dezrez formats
* Added ability to import parking from CSV
* Corrected typo in Dezrez XML import which could cause 'Only Import Updated Properties' feature to not work

= 1.0.85 =
* Added support for Domus XML API
* If no regions mapped in Jupix format then try to automatically assign prooerties to a location by looking at the address

= 1.0.84 =
* Cater for importing new homes when using the Vebra API format
* Corrected wrong variable names being used in Jupix format which sometimes caused imports to not process
* Declare support for WordPress version 4.9.4

= 1.0.83 =
* Added support for agricultural properties to Jupix XML format. Will import them into sales with the property type set as 'Land' (if it finds a type of that name)
* Improved support for commercial availability in Jupix XML format

= 1.0.82 =
* New format, 10ninety, added to list of supported formats. This is an XML which they provide a URL to.

= 1.0.81 =
* Added support for marketing flags to CSV format
* Import available date from Thesaurus
* Corrected name of reception rooms field in JET format

= 1.0.80 =
* Added new 'chunk' advanced settings for processing records in, well, chunks. Good for reducing server load and preparation for potential process forking in future to get around timeout issues
* Added seconds to log output
* Added support for custom fields added using the Template Assistant add on in CSV format
* Don't continue processing Jupix XML if XML can't be parsed. In the past we've seen it where Jupix would be down or provide a blank XML file meaning all properties would be removed until the next import ran
* Do addslashes() when storing media URLs from ExpertAgent format. For some reason some media URLs contain backslashes which would be removed by WP.
* Added support for BLM and RTDF portals in CSV format
* Save virtual tours in CSV format. Previously you could select the field but they weren't actually saved
* Corrected use of incorrect hook name in CSV import
* Declare support for WordPress version 4.9.2

= 1.0.79 =
* Cater for EPC/HIP documents sent in columns MEDIA_DOCUMENT_{51-55}. Previous we only checked MEDIA_DOCUMENT_50
* Replace 'ftp://' protocol if entered as part of the host for formats that use FTP
* Declare support for WordPress version 4.9.1

= 1.0.78 =
* Cater for both price qualifier fields in Thesaurus format
* Declare support for WordPress version 4.9

= 1.0.77 =
* Refinement to how we determine if a property is on or off market in Dezrez JSON format
* Declare support for WordPress version 4.8.3

= 1.0.76 =
* Various updates to the REAMXML format, including adding support for virtual tours and currency
* When 'Only Import Updated Properties' is selected in BLM format, take into account that the UPDATE_DATE might be blank, in which case import the property
* Cater for invalid or empty BLMs bu ignoring them. Prevents a case where an empty BLM is sent and all properties are removed
* Declare support for WordPress version 4.8.2

= 1.0.75 =
* Added support for virtual tours to JET format
* Updated REAMXML format to cater for images sent in different ways. Some send it in <images> node, other send it in <objects> node
* Added check to automatically restart import scheduled task if for some reason it doesn't exist

= 1.0.74 =
* Added new option to BLM format to only import updated properties. Uses the UPDATE_DATE field, if provided, to determine if a property has changed and needs updating
* Declare support for WordPress version 4.8.1

= 1.0.73 =
* Added support for importing features in JET format

= 1.0.72 =
* Use Google API key when making geocoding requests for lat/lng
* Added more debugging when trying to obtain lat/lng and log errors when lat/lng can't be obtained
* In ExpertAgent format cater for when department names have been customised. Previously we would check for 'Residential Sales' for example but turns out these can be customised by the client to be just 'Sales'.

= 1.0.71 =
* Don't unlink/delete received images in BLM format until updated database in case it times out mid-import

= 1.0.70 =
* Added support for commercial properties to Jupix format

= 1.0.69 =
* Import room dimensions in Thesaurus import

= 1.0.68 =
* Prevent manual execution of import when there has been any activity in the past 5 minutes. This indicates an import might already be running and therefore could result in duplicates and other issues.

= 1.0.67 =
* Try and auto-assign property to location in Thesaurus format based on address provided
* Write log to entry when an import is executed manually, including who executed it, to assist with debugging

= 1.0.66 =
* Rooms now imported when using Thesaurus format
* Added warning when setting up Vebra import if cURL isn't enabled

= 1.0.65 =
* Automatically perform mapping where possible when creating a new import
* Redirect user to main 'Import Properties' screen when plugin is activated for the first time

= 1.0.64 =
* Added new import frequency of 'Every 15 Minutes'
* Corrections regarding dates and times output. Now save everything to the DB in GMT/UTC and then output based on timezone in 'Settngs > General'
* Added 'Exchanged' to list of default availability mappings in ExpertAgent format
* Added 'LongDesc' to list of fields to obtain when importing from JET and use this field as the description
* Added commercial support to Vebra format
* Improvements to Rentman format including fixing availability, adding more property types to default mapping and importing available date
* Include wp-admin/includes/plugin.php in cron. This might've caused issues with import not running for some hosts

= 1.0.63 =
* Output error if local directory doesn't exist or not readable for formats trying to parse files locally
* Declare support for WordPress version 4.8

= 1.0.62 =
* Added support for Acquaint
* Added to new filters to the JET format to override the criteria used in API requests for properties
* When importing properties using the BLM format, allow for assignment to multiple locations if more than one match found
* Declare support for WordPress version 4.7.5

= 1.0.61 =
* Added new remove action to choose whether or not to delete media when properties are taken off market
* When removing properties only query properties already on market. Improves the efficiency for imports that have been running a while
* Corrected issue with imports failing to run in multisite environment
* Changed terminology of 'Running' to 'Active' on screen to avoid confusion
* Corrected a few field names and now import brochures/EPCs in JET format
* Declare support for WordPress version 4.7.4

= 1.0.60 =
* Automatically assign properties to locations imported from ExpertAgent
* Added filter to override fields requested from JET

= 1.0.59 =
* Import virtual tours from BLM files if present

= 1.0.58 =
* Fixed a couple of issues with CSV import regarding department and currency
* Added additional default mapping values to JET format

= 1.0.57 =
* Brochures now imported in Dezrez XML format

= 1.0.56 =
* Added support for the REAXML format; a common format used in the Australian real estate industry

= 1.0.55 =
* Correct usage of summary and full description in JET format

= 1.0.54 =
* Added support for Rentman XML format

= 1.0.53 =
* Add filters to dezrez feeds to allow overwriting of imported media widths

= 1.0.52 =
* Cater for when image is replaced but retains same URL in Jupix feed by checking and comparing modified date
* Updated Vebra format regarding types and qualifiers to not use rm_* fields but instead designated fields in XML
* Declare support for WordPress version 4.7.3

= 1.0.51 =
* Added support for JET software format

= 1.0.50 =
* Added ability to specify which inparticular branches properties should be imported in Dezrez format
* Output error if PHP ZipArchive class doesn't exist and trying to process ZIP files containing BLMs
* Added support to Dezrez XML format for when the property portal add on is active to assign properties to agents and branches

= 1.0.49 =
* Fixed type and price qualifier mapping for Vebra format
* Try and assign properties a location taxonomy based on address provided by Vebra
* Improvements to setting of default country across all formats

= 1.0.48 =
* Import EPC chart from Thesaurus using dynamic EPC generator and passing in EER and EIR numbers (Note: requires define('ALLOW_UNFILTERED_UPLOADS', true); in wp-config.php)
* Add link to documentation in availability mapping section to assist with this

= 1.0.47 =
* Add location mapping to Jupix format
* Try and auto-assign property to location in BLM format based on address provided

= 1.0.46 =
* Added additional logging when we receive a type that isn't mapped
* Added message when it looks like an import has fell over (i.e. not done anything in 30 minutes) suggesting the next steps to take
* Removed chance of import being ran from front end by public by using 'admin_init' action instead of 'init'

= 1.0.45 =
* Corrected CSV import failing when rows exceeded 1000 characters
* Added support for currency to CSV import, and use correct default if not provided
* Added new 'propertyhive_csv_fields' filter to allow custom fields to be added to CSV import
* Declare support for WordPress version 4.7.2

= 1.0.44 =
* Import full descriptions for commercial properties correctly

= 1.0.43 =
* Improvements to Thesaurus format after it being used in a real-world scenario
* Set the correct rental frequency in Dezrez XML format
* Add more rules to Dezrez JSON feed regarding property types to increase the chance of them being imported correctly
* Check for valid [license key](https://wp-property-hive.com/product/12-month-license-key/) before performing future updates
* Declare support for WordPress version 4.7.1

= 1.0.42 =
* Add new option to Vebra API XML format to specify whether only updated properties are imported. Good for doing a full refresh of property data
* Tweaks to 'Do Not Remove' setting when receiving a delete action from Vebra
* Make sure Vebra import uses the correct property ID when logging and deciding which properties to take off the market

= 1.0.41 =
* 'Next Due To Run At' column now a true representation of when you can expect the next import to run
* Improvements to Jupix XML format. If no lat/lng in XML, attempt to get it ourselves using the Google Geocoding API

= 1.0.40 =
* New format, Thesaurus, added to list of supported formats

= 1.0.39 =
* Added support for brochures, EPCs and virtual tours to Dezrez JSON format
* Corrections to CSV import including supporting CSVs using invalid line endings and more

= 1.0.38 =
* Tweak to Dezrez XML format to ensure property photos and floorplans updated in the future are updated correctly

= 1.0.37 =
* Cater for Sold STC status received in Dezrez XML format
* Declare support for WordPress version 4.7

= 1.0.36 =
* Add new option to Dezrez XML format to specify whether only updated properties are imported. Good for doing a full refresh of property data
* Add EPCs and virtual tours to Dezrez XML import format

= 1.0.35 =
* Check that finfo class exists before trying to validate file type.

= 1.0.34 =
* Prevent media being duplicated when ExpertAgent alternate their media URLs

= 1.0.33 =
* Add support for manual CSV upload
* Take into account 'Do Not Remove' setting when receiving a delete action from Vebra
* Added extra debugging and UTF8 encoding to BLM post inserts and updates
* Added missing save hooks and logs to end of most formats on each property iteration
* Automatically clear down old unused media when also clearing down old processed BLM files. For when we receive media for properties that we never end up importing

= 1.0.32 =
* Add support for Citylets XML format

= 1.0.31 =
* Fix issue which prevented property type from importing in Jupix feed

= 1.0.30 =
* Fix issue with importing full descriptions from BLMs

= 1.0.29 =
* Fix typo which prevented virtual tours from importing in Jupix feed

= 1.0.28 =
* Attempt to fix issue with EPC PDFs from Jupix not importing due to missing extension
* Fix typos in error messages

= 1.0.27 =
* Add support for Dezrez Rezi JSON API format
* Add ability to delete paused imports

= 1.0.26 =
* Correction to recent commercial BLM import to fix commercial properties not showing on frontend

= 1.0.25 =
* Add support for importing commercial properties from BLM format when commercial department is active

= 1.0.24 =
* Only remove properties if one or more properties we processed in DezRez and Vebra formats. Stops all properties being removed if issue with API request.
* Corrected issue with DezRez XML feed regarding it taking properties off the market that haven't been updated
* Corrected issue with DezRez XML feed regarding downloading Metropix floorplans

= 1.0.23 =
* Add a new FTP passive option to Expert Agent import options
* Consider 'propertyoftheweek' XML node in Expert Agent XML when setting featured properties
* Updated price calculation when receiving the rent frequency as PPPW in BLM files
* Attempt to solve an encoding issue when inserting the full description
* Declare support for WordPress version 4.6.1

= 1.0.22 =
* Fallback to use cURL if allow_url_open is disabled when trying to obtain URL contents
* Add warnings to setup wizard if both allow_url_open and cURL are disabled

= 1.0.21 =
* Keep BLM files for 7 days before automatically deleting them
* Add extra error logging around media uploading
* Fix a couple of errors around overwriting existing brochures and EPCs

= 1.0.20 =
* Added Vebra as a supported import format. Uses the V9 Client Feed API

= 1.0.19 =
* Fixed issue with each import not being considered independently when working out if it's ok run.
* Make checking for ExpertAgent featured property not case-sensitive to improve reliability
* ExpertAgent import to cross check both country names and country codes when trying to set the property country.
* Fixed issue with 'Last Ran Date' not showing if start and end date are the same
* Declare support for WordPress version 4.5.3

= 1.0.18 =
* Added new actions pre and post import for each format
* Corrected issue with Dezrez XML import not importing Sold STC properties
* Corrected issue with ExpertAgent XML import to cater for media URL's containing spaces

= 1.0.17 =
* Added integration support for when the property portal add on is active to assign properties to agents and branches

= 1.0.16 =
* Improve checking of annual rent frequency in ExpertAgent import

= 1.0.15 =
* Added support for Dezrez XML

= 1.0.14 =
* Fixed typo in BLM import
* Obtain lat/lng for properties sent in BLM using Google Geocoding service as we don't get that info

= 1.0.13 =
* Corrected incorrect calculation when normalising rents to monthly amounts

= 1.0.12 =
* Add Available to Let to list of Expert Agent availabilities

= 1.0.11 =
* Add Let STC to list of Expert Agent availabilities

= 1.0.10 =
* New option to disable auto-removal of properties when they're not included in imported files
* Add log entry when a property is automatically removed

= 1.0.9 =
* Added actions to execute custom code on each property imported via the various formats

= 1.0.8 =
* Added support for countries in all import formats

= 1.0.7 =
* Corrections to ensure media descriptions are corect in EA and BLM imports

= 1.0.6 =
* Fixed issue with mappings not getting set correctly
* Improvements to ExpertAgent import, including now importing price qualifier, type, POA, rent frequency, brochures, EPCs and virtual tours

= 1.0.5 =
* Huge improvements to logging
* Add fallback for when title and excerpt might go in blank due to encoding

= 1.0.4 =
* Added Jupix XML to list of supported automatic formats
* Tweaked code in various places to prevent PHP warnings

= 1.0.3 =
* Added support for one or more automatic BLM imports
* Improve cleaning up of files once they're finished with
* Small improvements to recently released ExpertAgent XML support

= 1.0.2 =
* Added support for multiple automatic feeds

= 1.0.1 =
* Large overhaul of addon to allow for automatic add ons
* Added ExpertAgent XML to list of supported automatic formats

= 1.0.0 =
* First working release of the add on