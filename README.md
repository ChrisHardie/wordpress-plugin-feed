![Current release](https://img.shields.io/github/release/vaites/wordpress-plugin-feed.svg)
[![Build Status](https://travis-ci.org/vaites/wordpress-plugin-feed.svg?branch=master)](https://travis-ci.org/vaites/wordpress-plugin-feed)

WordPress Plugin Feed
=====================

![Example](https://raw.githubusercontent.com/vaites/wordpress-plugin-feed/master/example.png)

WordPress developers should be informed of the updates of the plugins that use. Automattic provides feeds that do not give the necessary information:

* [Akismet feed](https://wordpress.org/plugins/rss/topic/akismet)
* [Akismet development log](https://plugins.trac.wordpress.org/log/akismet?limit=100&mode=stop_on_copy&format=rss)

WordPress Feed Plugin provides detailed feeds for plugin releases, avoiding the need to review the WordPress control panel or visit the profile of each plugin.

Features:
* Full human readable changelog
* Accurate release date (based on Subversion commits)
* Link to Subversion commit list between releases
* Highlighted security updates
  * Link to known vulnerabilities on [CVE Details](http://www.cvedetails.com)
* [Semantic Versioning](http://semver.org/) syntax
* Support for (less accurate) changelog of proprietary plugins or with external changelog:
  * All-In-One SEO Pack
  * BuddyPress
  * Gravity Forms
  * Slider Revolution
  * The WordPress Multilingual Plugin (WPML)
  * UberMenu
  * Ultimade Addons for Visual Composer
  * Visual Composer
* Compatible with PHP 5.3 or greater

Because this tool parses HTML of different websites, result cannot be 100% accurate and can fail after a change in the code. So issues and pull requests are welcome...

Usage
-----

Just download the release package, place the code on any web server that supports PHP and add to your favorite feed reader:

    http://your/web/server/wordpress-plugin-feed/index.php?plugin=PLUGIN

Replace *PLUGIN* with plugin name, the same as WordPress uses in plugin URL (like *better-wp-security* for [iThemes Security](https://wordpress.org/plugins/better-wp-security))

You can also clone the repository and run `composer update`:

    git clone https://github.com/vaites/wordpress-plugin-feed
    cd wordpress-plugin-feed
    composer update

Configuration
-------------

[PHP Dotenv](https://github.com/vlucas/phpdotenv) or URL parameters is used to define configuration:
* **OUTPUT_LIMIT**: number of releases on output (default 25)
* **OUTPUT_FORMAT**: output format (atom or rss), available as URL parameter (format)
* **RELEASE_STABILITY**: one o more stability options (any, stable, alpha, beta, rc) separated by commas, also availabla as URL parameter (stability)

There's an *.env.example* file that you can copy to *.env*.