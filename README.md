=== EduAdmin Course Import ===
Contributors: aksell
Tags: eduadmin, courses, import, acf, cron, ajax
Requires at least: 6.0
Tested up to: 6.8.2
Requires PHP: 8.2
Stable tag: 1.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Author: Aksell and OpenAI

Imports courses from the EduAdmin API into a custom post type in WordPress.
Supports scheduled cron imports, manual import via AJAX, and custom ACF fields.

== Description ==

EduAdmin Course Import is a utility plugin that synchronizes courses from the EduAdmin API into WordPress.  
It creates and updates a custom post type (`course`) with fields like events, prices, and custom fields using ACF.

Key features:

* Scheduled cron import every 6 hours (upcoming events only).
* Manual import button in the WP dashboard (AJAX, no page reload).
* Handles course updates and prevents duplicates using `coursetemplateid`.
* Imports featured image, categories, custom fields, and repeater fields (Events, PriceNames, CustomFields).
* Calculates course duration (days or hours) from event start/end dates.
* Provides course/event statistics and the next scheduled import in the dashboard widget.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install directly via the WordPress admin.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. A new **EduAdmin Course Import** widget will appear on your Dashboard.

== Usage ==

* Courses are imported automatically every 6 hours.
* You can also trigger a manual import from the dashboard widget.
* By default, only upcoming events are imported.  
  (Full import is possible via code but not recommended for production.)

== Changelog ==

= 1.10 =
* Major performance improvement in course import process
* Added automatic fallback for Location based on latest or upcoming event
* Limited past event fetch to last 12 months to reduce load time
* Optimized ACF field updates — only changed values are written
* Improved image handling to avoid duplicate uploads (reuses existing attachments)
* Added lightweight throttling to prevent database overload
* Cleanup script prepared for duplicate image removal and event pruning


= 1.9.3 = 
* Updated status widget to show only real updated fields, and reduced database load.

= 1.9.2 =
* Fixed date time parsing for Created, Last Application Date, Application Open Date, and Modified

= 1.9.1 =
* Fixed deprecation error for strip_tags for CourseDescriptionShort

= 1.9 =
* Added support for EduAdmin custom field Language (ID8166) mapping to ACF field “Language”

= 1.8.1 =
* Fixed an array_key error for status widget for removed events
* Stripped tags from CourseDescriptionShort to remove unneccesary HTML tags in Excerpt

= 1.8 =
* Added clean up of events older than 6 months

= 1.7 =
* Restructured import to a 2-phase model: fetch only upcoming events first, then fetch corresponding course templates
* Reduced number of API requests and optimized performance
* Ensured all event fields (including DiplomaReportId, MaxParticipants etc.) are written to ACF repeater


= 1.6 =
* Changed duration from a calculated field to a fixed Custom Field (ID 8110) from EduAdmin

= 1.5.2 =
* Added function name to cronjob
* Fixed: Correct timezone for Next scheduled import

= 1.5.1 =
* Fixed: Added missing media includes (`media.php`, `file.php`, `image.php`) so `media_sideload_image()` works reliably in cron/AJAX imports.

= 1.5 =
* Added dashboard widget with live AJAX manual import.
* Improved logging and status output.
* Cleaned up and consolidated code structure.

= 1.4 =
* Added admin widget showing last import status.
* Option to run manual or full import.
* Better error handling and nonce validation.

= 1.3 =
* Improved import logic for events and categories.
* Added support for merging Events, PriceNames, CustomFields.

= 1.2 =
* Added calculation of course duration.
* Better handling of featured images and taxonomies.

= 1.1 =
* Added cron import every 6 hours.
* First ACF integration.

= 1.0 =
* Initial release. Basic import of courses and events.

== Frequently Asked Questions ==

= Does this plugin require ACF? =
Yes. It uses ACF Pro repeater fields for Events, PriceNames, and CustomFields.

= Does it import past events? =
No, only upcoming events are imported (to prevent bloating and nonce errors).  
Old events can be cleaned manually or with the included one-time cleanup script.

= Can it run a full import? =
Yes, but it is not enabled in the dashboard by default.  
Full imports are only recommended for development/testing.

== Screenshots ==

1. Dashboard widget showing import status and manual import button.
2. Example of course cards with badges and booking button.
3. Course table list view with status and booking logic.

== Upgrade Notice ==

= 1.5 =
Major update with AJAX-powered dashboard import and consolidated code. Recommended upgrade.
