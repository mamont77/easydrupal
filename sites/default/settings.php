<?php

/**
 * Load services definition file.
 */
$settings['container_yamls'][] = __DIR__ . '/services.yml';

/**
 * Fast 404 settings:
 *
 * Fast 404 will do two separate types of 404 checking.
 *
 * The first is to check for URLs which appear to be files or images. If Drupal
 * is handling these items, then they were not found in the file system and are
 * a 404.
 *
 * The second is to check whether or not the URL exists in Drupal by checking
 * with the menu router, aliases and redirects. If the page does not exist, we
 * will server a fast 404 error and exit.
 */

// @TODO anything still using $conf hasn't yet been implemented within the module.

# Disallowed extensions. Any extension in here will not be served by Drupal and
# will get a fast 404. This will not affect actual files on the filesystem as
# requests hit them before defaulting to a Drupal request.
# Default extension list, this is considered safe and is even in queue for
# Drupal 8 (see: http://drupal.org/node/76824).
$settings['fast404_exts'] = '/^(?!drupals).*\.(txt|png|gif|jpe?g|css|js|ico|swf|flv|cgi|bat|pl|dll|exe|asp)$/i';

# If you use a private file system use the conf variable below and change the
# 'sites/default/private' to your actual private files path
# $settings['fast404_exts'] = '/^(?!drupals)^(?!sites/default/private).*\.(txt|png|gif|jpe?g|css|js|ico|swf|flv|cgi|bat|pl|dll|exe|asp)$/i';

# If you would prefer a stronger version of NO then return a 410 instead of a
# 404. This informs clients that not only is the resource currently not present
# but that it is not coming back and kindly do not ask again for it.
# Reference: http://en.wikipedia.org/wiki/List_of_HTTP_status_codes
# $conf['fast_404_return_gone'] = TRUE;

# Allow anonymous users to hit URLs containing 'imagecache' even if the file
# does not exist. TRUE is default behavior. If you know all imagecache
# variations are already made set this to FALSE.
$settings['fast404_allow_anon_imagecache'] = TRUE;

# If you use FastCGI, uncomment this line to send the type of header it needs.
# Reference: http://php.net/manual/en/function.header.php
# $conf['fast_404_HTTP_status_method'] = 'FastCGI';

# BE CAREFUL with this setting as some modules
# use their own php files and you need to be certain they do not bootstrap
# Drupal. If they do, you will need to whitelist them too.
$conf['fast404_url_whitelisting'] = FALSE;

# Array of whitelisted files/urls. Used if whitelisting is set to TRUE.
$settings['fast404_whitelist'] = array(
  'index.php',
  'rss.xml',
  'install.php',
  'cron.php',
  'update.php',
  'xmlrpc.php',
);

# Array of whitelisted URL fragment strings that conflict with fast404.
$settings['fast404_string_whitelisting'] = array('cdn/farfuture', '/advagg_');

# By default we will show a super plain 404, because usually errors like this are shown to browsers who only look at the headers.
# However, some cases (usually when checking paths for Drupal pages) you may want to show a regular 404 error. In this case you can
# specify a URL to another page and it will be read and displayed (it can't be redirected to because we have to give a 30x header to
# do that. This page needs to be in your docroot.
$conf['fast404_HTML_error_page'] = './404.html';

# Path checking. USE AT YOUR OWN RISK.
# Path checking at this phase is more dangerous, but faster. Normally
# Fast404 will check paths during Drupal bootstrap via an early Event.
# While this setting finds 404s faster, it adds a bit more load time to
# regular pages, so only use if you are spending too much CPU/Memory/DB on
# 404s and the trade-off is worth it.
# This setting will deliver 404s with less than 2MB of RAM.
//$settings['fast404_path_check'] = TRUE;

# Default fast 404 error message.
$settings['fast404_html'] = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML+RDFa 1.0//EN" "http://www.w3.org/MarkUp/DTD/xhtml-rdfa-1.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head><title>404 Not Found | MakeDrupalEasy</title>
  <style type="text/css">
    body {
      background: #fff;
      font: 14px Arial, Helvetica, sans-serif;
      color: #666;
      margin: 0;
      padding: 0;
      text-align: center;
    }

    p {
      margin: 0;
    }

    .wrapper {
      width: 50%;
      margin: 10% auto;
    }

    .error-number {
      font-size: 82px;
      color: #81A5BF;
      font-weight: bold;
      margin-bottom: 15px;
    }

    .error-text {
      font-size: 27px;
      margin-bottom: 15px;
    }

    .logo {
      font-size: 32px;
      color: #74B74A;
      text-decoration: none;
      font-weight: bold;
      margin-bottom: 15px;
    }

    .go-home a {
      margin-top: 12px;
      display: inline-block;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      font-weight: 500;
      background-color: #74B74A;
      padding: 15px 20px;
      color: #fff;
      text-decoration: none;
    }

    .drupal-logo {
      max-width: 296px;
      width: 100%;
      height: auto;
      margin-bottom: 15px;
    }

    @media (min-width: 992px) {
      .left {
        width: 49%;
        float: left;
      }

      .right {
        width: 49%;
        float: right;
      }

      .error-number {
        font-size: 164px;
      }
    }
  </style>
</head>
<body>
<div class="wrapper">
  <div class="left">
    <img class="drupal-logo" src="/themes/custom/easydrupal/logo.svg"/>
  </div>
  <div class="right">
    <a class="logo" href="/">MakeDrupalEasy</a>
    <p class="error-number">404</p>
    <p class="error-text">… Oops! Something is missing</p>
    <p class="go-home"><a href="/">Home</a></p>
  </div>
</div>
</body>
</html>
';

/**
 * Include the Pantheon-specific settings file.
 *
 * n.b. The settings.pantheon.php file makes some changes
 *      that affect all envrionments that this site
 *      exists in.  Always include this file, even in
 *      a local development environment, to insure that
 *      the site settings remain consistent.
 */
include __DIR__ . "/settings.pantheon.php";

/**
 * If there is a local settings file, then include it
 */
$local_settings = __DIR__ . "/settings.local.php";
if (file_exists($local_settings)) {
  include $local_settings;
}
