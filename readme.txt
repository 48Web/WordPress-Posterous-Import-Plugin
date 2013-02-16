=== Plugin Name ===
Contributors: abrudtkuhl
Tags: importer, posterous, WordPress.com
Requires at least: 2.9
Tested up to: 3.5.1
Stable tag: 0.20
License: GPL v2 - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Import posts, comments, tags, and attachments from a Posterous.com blog with correct links

== Description ==

Import posts, comments, tags, and attachments from a Posterous.com blog.

== Installation ==

1. Upload the `posterous-importer` folder to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Go to the Tools -> Import screen, Click on Posterous

== Changelog ==

= 0.20 - 16th Feb 2012
* fixing 403 issue
* added link fix from Bradd Gessler gist https://gist.github.com/bradgessler/3185320

= 0.10 - 26th Mar 2012
* Add hook to handle non-200 responses from Posterous.
* Sleep for about one second between calls to the Posterous API to avoid failing thanks to rate limiting.
* Set a flag and return rather than exiting when we get malformed XML back from the API.
* Check for audio/video files in process_posts() and add their data as postmeta for use later.
* In process_attachments(), fetch any media from postmeta and pass along to the media extraction function.
* In process_attachment(), fetch media from postmeta and add to the list of images to be imported.
* Add a hook to fire after importing an attachment so that additional things can be done afterard (e.g. updating post content with shortcodes to display media).
* Add hooks to fire at the beginning of dispatch() and just before footer display after import so that we can do other fun things in these spots if needed.
* Update the front end to change the "Username" field to "Email Address" and to do light email address validation before submitting to Posterous.

= 0.9 - 12th Jan 2012 =
* Fix a call to wp_enqueue_script that was too early 
* Fix an XSS issue

= 0.8 =
* Fixing a "could not serialize simplexml-string" error. Kudos to Florian Hassler.

= 0.1 =
* Initial release

=== Original Plugin Details ===
Contributors: automattic, briancolinger, westi, dllh
Tags: importer, posterous, WordPress.com
Requires at least: 2.9
Tested up to: 3.3
Stable tag: 0.10
License: GPL v2 - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Import posts, comments, tags, and attachments from a Posterous.com blog.
