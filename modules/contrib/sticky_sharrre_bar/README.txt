Sticky Sharrre Bar - module for Drupal based on
http://sharrre.com, http://imakewebthings.com/jquery-waypoints.
Provides sticky block for social network sharing.

Also this module uses icon pack http://sensationalfix.com/flat-social-icons-eps/.
The file icons.png is derived from work that is Copyright Jorge Calvo
(http://sensationalfix.com/).
Source: http://sensationalfix.com/flat-social-icons-eps/.
Used here under the Creative Commons Attribution-ShareAlike (CC BY-SA) license.

SUPPORTED PROVIDERS
-------------------
- Google Plus
- Facebook
- Twitter
- Digg
- Delicious
- StumbleUpon
- Linkedin
- Pinterest
- Reddit (TODO)
- Tumblr (TODO)

Author: Ruslan Piskarev <http://drupal.org/user/424444>.

REQUIREMENTS
----------
- jQuery Waypoints - library v4.0.0 or higher.
  Download link: http://imakewebthings.com/jquery-waypoints.
- jQuery Sharrre - library v1.3.5 only. The "Sharrre" v2.0.0 and higher doesn't work for now.
  Download link https://github.com/Julienh/Sharrre/archive/1.3.5.zip.

INSTALLING
----------

1. Install the module as normal, see link for instructions.
   Link: https://www.drupal.org/documentation/install/modules-themes/modules-8
2. Download and unzip the "Waypoints" library
  to "/libraries/jquery-waypoints" directory.
  Files structure:
    /libraries/jquery-waypoints/lib/jquery.waypoints.js
    /libraries/jquery-waypoints/lib/jquery.waypoints.min.js
    /libraries/jquery-waypoints/lib/shortcuts/sticky.js
    /libraries/jquery-waypoints/lib/shortcuts/sticky.min.js
    ...
3. Download and unzip the "Sharrre" library
  to "/libraries/sharrre" directory.
  Files structure:
    /libraries/sharrre/jquery.sharrre.min.js
    /libraries/sharrre/jquery.sharrre.js
    ...
  Warning! For security reasons you should delete the sharrre.php file
  from the "Sharrre" library folder.
  If you would like to use an alternate library location, you can add
    $conf['sticky_sharrre_bar.settings']['waypoints_library_path'] = PATH/TO/JQUERY-WAYPOINTS;
    $conf['sticky_sharrre_bar.settings']['sharrre_library_path']   = PATH/TO/SHARRRE;
  to your settings.php file.
  The module has own drupal controller "/sharrre" instead of the sharrre.php.
4. Go to "Home > Administration > Extend"
  and enable "Sticky Sharrre Bar" module.
5. Go to "Home > Administration > Structure > Block layout > Place block" and select "Sticky sharrre bar",
  set the necessary providers, regions, etc.
  The block is prefers the "header" region.
  You can unset "Use the css of module" and make your own style in your theme.

MORE INFORMATION
----------------
- To issue any bug reports, feature or support requests, see the module issue
  queue at https://drupal.org/project/issues/2268241.
