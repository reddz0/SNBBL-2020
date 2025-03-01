<?php

/*****************
 * Enable/disable modules
 *****************/
// Change value from true to false if you wish to disable a module.
$settings['modules_enabled'] = array(
    'IndcPage'           => true,  # Inducements try-out
    'PDFroster'          => true,  # Team PDF roster
    'RSSfeed'            => false,  # Site RSS feed
    'SGraph'             => false,  # Graphical statistics
    'Memmatches'         => true,  # Memorable matches viewer
    'Wanted'             => true,  # Wanted list
    'HOF'                => true,  # Hall of fame
    'Prize'              => true,  # Tournament prizes list
    'Registration'       => false,  # Allows users to register on the site.
    'Search'             => true,  # Search for coaches and teams.
    'TeamCompare'        => true,  # Team strength compare
    'Cemetery'           => true,  # Team cemetery page
    'FamousTeams'        => true,  # Famous Teams page
    'PDFMatchReport'     => true,  # Generating PDF forms for tabletop match reports.
    'LeagueTables'       => true,  # Provides league table link on the main menu
    'Conference'         => true,  # Provides support for conferences within tournaments
    'Scheduler'          => false, # Alternative match scheduler
    'LeaguePref'         => true,  # Allows dynamic configuration of league preferences
    'TeamCreator'        => true,  # Allows coaches to create teams quickly
    // The below modules are not well maintained and are poorly supported!!
    'UPLOAD_BOTOCS'      => false, # Allow upload of a BOTOCS match
    'XML_BOTOCS'         => false, # BOTOCS XML export of team
	'Adverts'         	 => false, # Shows a BB banners across the top
);

/*****************
 * Module settings
 *****************/
/*
    Registration
*/
$settings['allow_registration'] = false; // Default is true.
$settings['registration_webmaster'] = "reddz@mts.net"; // Default is "webmaster@example.com".
$settings['lang'] = 'en-GB'; // Default language for registred user.

/*
    Leegmgr (now deprecated)
*/
$settings['leegmgr_enabled'] = false; // Enables upload of BOTOCS LRB5 application match reports.
/*
    Uploads report to a scheduled match.  The options are [false|true|"strict"]
    - false does not check for scheduled matches
    - true checks for scheduled matches and will create a match if not found
    - "strict" will allow only scheduled matches to be used
*/
$settings['leegmgr_schedule'] = false;
$settings['leegmgr_extrastats'] = false; // Enables the reporting of extra stats and the use of the alternate XSD file.
$settings['leegmgr_cyanide'] = false; // Setting to false here is preferred as this can be set to true in each specific league.
$settings['leegmgr_cyanide_edition'] = 2; // 1 = the first Cyanide edition, 2 = Legendary edition.
$settings['leegmgr_botocs'] = false; // Setting to false here is preferred as this can be set to true in each specific league.

/*
    PDF roster & PDF match report
*/
$settings['enable_pdf_logos'] = true;