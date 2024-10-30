=== Country Caching Extension ===
Contributors: wrigs1
Donate link: http://means.us.com/
Tags: comet, cache, caching, country, geoip, Comet-Cache, geo-location, Comet Cache, location
Requires at least: 3.3
Tested up to: 4.9.6
Requires PHP: 5.4
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Enables Comet Cache to cache by page/visitor country instead of just page. Solves "wrong country content" Geo-Location issues.

== Description ==

DUE TO PERSONAL CIRCUMSTANCES I AM NO LONGER ABLE TO DEVELOP OR SUPPORT THIS PLUGIN. IF YOU ARE INTERESTED IN ADOPTING THIS PLUGIN SEE https://developer.wordpress.org/plugins/wordpress-org/take-over-an-existing-plugin/

Solves [wrong country content for visitor Geo-Location](http://wptest.means.us.com/caching-and-geoLocation/) issues. Enables Comet Cache to display the correct page and widget content for a visitor's country.

**Bonus** also makes Cookie Notice work correctly with Comet Cache (whether using country/EU geolocation or not). 

If you need country caching with other caching plugins then see "Advice" below.

This plugin adds an extension to Comet Cache enabling it to create separate snapshots (cache) for each page based on country location.

Extra caching **can be restricted to specific countries and/or a group of countries**.  E.g. if you are based in the US but show different content to Canadian,Mexican & EU country visitors, you can set separate caching for CA & MX visitors +  single group cache for EU visitors; the remainder of your visitors will standard cache ("US") content.

It works on normal Wordpress and on Multisite (see FAQ).

**Comet Cache** is designed to work with add-on scripts and should work seamlessly with this plugin.

**Identification of visitor country for caching**

Via Cloudflare or Maxmind. (this product includes GeoLite2 data created by MaxMind, available from http://www.maxmind.com ) It is also possible to connect a different GeoLocation sytem of your choice (see documentation).

If you use Cloudflare and have "switched on" their GeoLocation option ( see [Cloudflare's  instructions](https://support.cloudflare.com/hc/en-us/articles/200168236-What-does-CloudFlare-IP-Geolocation-do- ) ) then it will be used to identify visitor country.  If not, then the Maxmind Country Database will be used.


**Updating** (If not using Cloudflare for country) The installed Maxmind Country/IP data file will lose accuracy over time.  To automate a monthly update of this file, install and enable the [Category Country Aware (CCA) plugin](https://wordpress.org/plugins/category-country-aware/ ) (Country Caching and the Cataegory Country Aware (CCA) plugins use the same Maxmind data file in the same folder and the CCA plugin includes code for its update). The CCA plugin has many other features and functionality you may find useful. Alternatively you can manually update (FAQ below).

**Additional Info and support**

**ADVICE:**

Don't use ANY Caching plugin UNLESS you know how to use an FTP program (e.g. Filezilla).

Support forums show that Caching plugins including Comet Cache can result in "white screen" problems for some users; the only solution may be to delete files using FTP/Cpanel or OS command line.

The Country Caching plugin deletes files on deactivation/delete, but in "white screen" situations you may have to resort to "manual" deletion - see FAQ for instructions.

**WP Super Cache:** is also designed to work with "add-ons" and an equivalent of this plugin is available in the Wordpress repository.

**W3 Total Cache** does not *currently* provide a suitable hook for plugin country caching. Others have [requested this facility](https://wordpress.org/support/topic/request-add-hook-to-allow-modification-of-the-cache-key ).


== Installation ==

The easiest way is direct from your WP Dashboard like any other widget:

Once installed go to: "Dashboard->Country Caching". Check the "*Enable CC Country Caching add-on*" box, and save settings.

If you want automatic "3 weekly" update of *Maxmind Country->IP range data* then also install the [Category Country Aware plugin (here on Wordpress.Org)](https://wordpress.org/plugins/category-country-aware/ ).


== Frequently Asked Questions ==

= Where can I find support/additional documentation =

Support questions should be posted on Wordpress.Org<br />
Additional documentation is provided at http://wptest.means.us.com/quick-cache-and-geoip-enable-caching-by-pagevisitor-country-instead-of-just-page/


= How do I know its working =

See [these checks](http://wptest.means.us.com/quick-cache-and-geoip-enable-caching-by-pagevisitor-country-instead-of-just-page/ ).

= How do I keep the Maxmind country/IP range data up to date =

Install the [Category Country Aware plugin](https://wordpress.org/plugins/category-country-aware/ ) from Wordpress.Org; it will update Maxmind data every 3 weeks.

= How do I keep the Maxmind country/IP range data up to date =

Automatically: install the [Category Country Aware plugin](https://wordpress.org/plugins/category-country-aware/ ) from Wordpress.Org and enable its settings; it will update your Maxmind data every "month".

Manually: monthly/whatever; download "GeoLite2-Country.tar.gz" from [Maxmind](https://dev.maxmind.com/geoip/geoip2/geolite2/ ) and extract the file "GeoLite2-Country.mmdb" and upload it to your servers "/wp-content/cca_maxmind_data/" directory.


= Will it work on Multisites =

Yes, it will be the same for all blogs (you can't have it on for Blog A, and off for Blog B).

On MultiSites, the Country Caching settings menu will be visible on the Network Admin Dashboard (only).


= How do I stop/remove Country Caching =

Deactivating the plugin will remove the Caching Extension. Then clear Comet's cache (Dashboard->Comet Cache->Clear)

If all else fails:

1.  Log into your site via FTP; e.g. with CoreFTP or FileZilla.
2.  Delete this file: /wp-content/ac-plugins/cca_qc_geoip_plugin.php
3.  Delete this directory: /wp-content/plugins/country-caching-extension/
4.  Then via your Wordpress Admin: Dashboard->QuickCache->Clear


== Screenshots ==

1. Simple set up. Dashboard->Settings->Country Caching
2. Configuration and Server Info


== Changelog ==

= 1.2.0 = Fix for Cookie Notice, to make it work correctly with Comet Cache (whether or not you are using geolocation or CCA to restrict CN to EU visitors)

= 1.1.0 =  (if your site is not using Cloudflare) Maxmind GeoLite2 will be used for country look-up instead of Maxmind Legacy (no longer updated by Maxmind).
Connector also provided to allow use other GeoIP look-up system in place of CF/Maxmind country lite.

= 1.0.0 =  Added checking of additional server variables for visitor's IP Address.
Fixed display bug on "Support Tab" - extension info will now display even if Caching has not been enabled in Comet Cache.
Support Tabs "Display GeoIP data" now displays values of the server's visitor IP variables

= 0.7.2 =  Maxmind data files are now auto installed (when you first enable Country Caching)  in a shared directory for use by other plugins.
The data files are provided by Maxmind under Creative Commons license, but the Wordpress.org repository requires all files stored there should be licensed under GPL. The Plugin has been altered to comply. 


== Upgrade Notice ==

= 1.2.0 = Fix for Cookie Notice, to make it work correctly with Comet Cache (whether or not you are using geolocation or CCA to restrict CN to EU visitors)

= 1.1.0 =  (if your site is not using Cloudflare) Maxmind GeoLite2 will be used for country look-up instead of Maxmind Legacy (no longer updated by Maxmind).
Connector also provided to allow use other GeoIP look-up system in place of CF/Maxmind country lite.

== License ==

This program is free software licensed under the terms of the [GNU General Public License version 2](http://www.gnu.org/licenses/old-licenses/gpl-2.0.html) as published by the Free Software Foundation.

In particular please note the following:

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.