README.txt for base-alpine 5.0 and later
=========================================

[To see how it started, scroll down to 'base-alpine 4.0 and later'.]

After overhauling and modernizing BlueOnyx in 2014 (introduction of the 
'Adminica'-GUI) and again in 2022 (newer CodeIgniter and many internal 
improvments) it was time again in 2023 to modernize the 'look' and 'feel'
of BlueOnyx.

That's why we integrated the 'Elmer' GUI theme.

Elmer looks clean, crisp and snappy. It also works out of the box on all
screen sizes from large curved ultra-HD displays down to rinky-dinky small
phone displays. There is no funky code or invisible theme/template switching
needed for that. It's plain HTML and CSS with just a manageable amount of
jQuery, JavaScript and a single Ajax script.

In fact: Compared to 'Adminica' it is surprising how lightly 'Elmer' is
burdened by scripts, as jQuery and Boostrap have come a long way.

The integration of Elmer was a plus three month all out effort: Seven days
a week with many long days and short nights.

The full list of code changes can be seen here:

https://devel.blueonyx.it/trac/changeset?reponame=&new=5111%40BlueOnyx%2F5311R&old=4800%40BlueOnyx%2F5311R

It's way too many to list, but I should point out a few important architectural
changes:


UIFC, UIFC1, UIFC2:
====================

Previously the PHP Classes for GUI elements such as buttons, form fields 
and such were stored in ...

/usr/sausalito/ui/chorizo/ci4/app/Libraries/uifc/

To retain the 'Adminica' theme and to add the 'Elmer' theme as a new default
we created two new directories:

/usr/sausalito/ui/chorizo/ci4/app/Libraries/uifc1/	<- Adminica
/usr/sausalito/ui/chorizo/ci4/app/Libraries/uifc2/	<- Elmer

Other GUI classes in Libraries, the BaseController and Helpers now check
$BX_SESSION['gui_theme'] to see if 'adminica' or 'elmer' is wanted by the
user who uses the GUI and then load the respective UIFC classes selectively.

ServerScriptHelper.php is execute before BaseController is instanciated, so
that one instead has to check the Cookie $_COOKIE['gui_theme'] in order to
be able to decide if UIFC1 or UIFC2 needs to be used.


i18n (Internationalization):
=============================

That's used to display the GUI texts in various languages. Once upon a time 
(until 5208R) we used a PHP Zend API module for this, which was incredibly fast. 
But we were unable to port this to newer PHP versions, so it got replaced with 
native PHP code (slow and ugly).

At the earliest stage of the development of the new GUI I tackled i18n again and 
trimmed a whole heap of fat from it to make it lean, mean and fast. This chafed 
around 50-80% off from the page load times of most GUI pages. For some larger 
pages this even trims the load time down from 2.9 seconds to 0.9 seconds. 


Buttons:
=========

The old 'Adminica' GUI pages prior to this release used straight up HTML to render
some of the more specialized buttons. This was suboptimal for the implementation
of another theme. Therefore the Button.php Class for UIFC1 and UIFC2 has been extended
to render ALL buttons we need and also to be flexible enough to go beyond that for
Buttons styles that we're not yet having on our radar.


GUI Icons ("Adminica" and "Elmer"):
====================================

"Elmer" uses fonts (mostly Fontawesome, but also ZDMI and GlypIcons) for GUI icons. 
"Adminica" used three different methods for icons: Fonts, Graphics and vectors cut 
out of larger graphics. 

While redesigning the Button.php Class for both "Adminica" and "Elmer" I switched 
"Adminica" to use the same buttons (fonts) as "Elmer". This means that in some 
places in "Adminica" you will now see slightly different Icons than before. In most 
cases these changes are subtle.


Two factor authentication (2FA):
=================================

You can now protect the GUI with 2FA. Means: Aside from just entering username and 
password a 2FA token (or a one-time-key) can be required for users to login.

This is turned OFF by default and must be enabled manually.

You can enable "GUI-Login: 2FA required" in "Sever Management " / "Maintenance" / 
"Server Desktop" and can choose wo MUST use 2FA in order to login to the GUI:

- Nobody (if "GUI-Login: 2FA required" is disabled)
- All users with enabled 2FA (per Vsite & per User setting)
- All Server administrators with enabled 2FA
- All Server and Vsite administrators with enabled 2FA

While integrating the 2FA GUI login mechanism I was messing with our holy cow: 
The security mechanisms of Sausalito. After entering username and password the GUI 
authenticates you against CCEd and you get a sessionId. With a valid sessionId you 
ARE logged in and can usually see whatever pages you have privileges for. 2FA 
enabled or not.

Tacking on 2FA to this mechanism was like throwing the key to the cistern away 
after the child had already fallen into the well. So I revised and extended the 
mechanism that we used to auto-login into additional services such as phpMyAdmin, 
GoAccess, Monitorix or the Radicale CalDAV/CardDAV GUI.

Aside from a valid sessionId a GUI user now also needs a security token in the 
session data in order to login to the GUI and to be able to see any privileged 
pages. 

This security token is auto-generated with OpenSSL during the login - even if 2FA 
is disabled. The encryption key for this is randomly generated for each BlueOnyx 
server and usually only valid for a given session - until logout or session expiry 
due to inactivity.

This new security token gets set in the stage where we determine if 2FA is enabled 
for the user or not. If 2FA is enabled and the user doesn't provide a 2FA token 
or one-time-key? Then he doesn't get past the login page - valid sessionId or not. 
Upon entering an incorrect 2FA token the sessionId gets revoked and the GUI's 
"Brute Force Detector" (see: "Sever Management " / "Maintenance" / "Server Desktop")
will also count this as a failed login attempt and may block further access for 
the offending IP if the number of failed attempts exceeds the configured values 
in the given time frame.

Likewise: phpMyAdmin, GoAccess, Monitorix and the Radicale CalDAV/CardDAV GUI 
now use this mechanism to generate secure tokens via OpenSSL for auto-logins to 
the respective services (if the user is privileged to use them).

Other than that: The changes and improvements are way too many to list.

---
With best regards,

Michael Stauber
mstauber@blueonyx.it
19th Februrary 2024



README.txt for base-alpine 4.0 and later
=========================================

This is a Chorizo'fied base-alpine and therefore has a lot more *meat*
than the original base-alpine of BlueOnyx 510XR.

Actually this module contains enough changes to warrant a complete 
name change. I resisted that urge for a couple of reasons.

Mainly I wanted "all eggs in one basket" and didn't want to spread
CodeIgniter, configs, UIFC Classes, Helpers, Libraries and essential
CodeIgniter mods through various modules and separate RPMs. 

Keeping them in one single RPM makes maintenance much easier. It will
mean "bigger updates" (a fatter RPM) on YUM updates, but that's a 
good compromise.

So this new base-alpine contains the following:

- Anything the old base-alpine had.
- Minus the horribly outdated PDF manuals.
- Minus the /web/error/* pages for AdmServ
- Minus the old /web pages for AdmServ

On top of that it inherited:

- The 'ci' directory that contains our preconfigured CodeIgniter.
- The web/.htaccess that's needed to route all traffic through CI
- The web/index.php of CodeIgniter that handles ALL transactions.
- The /web/.adm/ folder with all the visible baggage of the Adminica
  theme which must be publically accessible.


Notes for code-maintainers:
============================

BlueOnyx 5212R uses the CodeIgniter (https://codeigniter.com/) framework. We
specifically use CodeIgniter 4 for 5212R, where 5210R was still using CI 3.

Our CodeIgniter 4 was installed via Composer and requires PHP-8.3. 

PHP-8.3 on 5212R:
==================

Our PHP-8.3 is installed under /home/solarspeed/admserv-php/ and you find the
composer binary at /home/solarspeed/admserv-php/bin/composer

CodeIgniter 4 installation path:
=================================

Our CodeIgniter 4 is installed in /usr/sausalito/ui/chorizo/ci4 and it can be 
upgraded this way:

cd /usr/sausalito/ui/chorizo/ci4
/home/solarspeed/admserv-php/bin/composer upgrade
./bx_ci4_fixer.sh

The script bx_ci4_fixer.sh replaces upgraded CI4 files with those again that we
had modified in order to get our custom routing and CSRF handling working for
5212R.

/usr/sausalito/ui/chorizo/ci/system/
------------------------------------
The CodeIgniter directory /usr/sausalito/ui/chorizo/ci/system/ should be 
hands off. Unless you upgrade CodeIgniter. DO NOT MODIFY stuff in there.
Because otherwise it'll bite you in the ass during the next CodeIgniter
update.

CodeIgniter 4 mMain config file:
---------------------------------

/usr/sausalito/ui/chorizo/ci4/.env

Of note is: 

'app.baseURL': This MUST be set to match the hostname of the server. 
'CI_ENVIRONMENT' [development|production] allows enabling debug bar

/usr/sausalito/ui/chorizo/ci4/app/
------------------------------------------
That is free for grabs and can be modified at will.

/usr/sausalito/ui/chorizo/ci4/app/Config/
-------------------------------------------------
Config directory of this CodeIgniter instance.

/usr/sausalito/ui/chorizo/ci4/app/Helpers/
--------------------------------------------------
Directory for helper scripts.

/usr/sausalito/ui/chorizo/ci4/app/Libraries/
---------------------------------------------------
The real deal. That is where the Chorizified versions of the Sausalito 
PHP Classes and UIFC Classes reside.

/usr/sausalito/ui/chorizo/ci4/app/Libraries/uifc/
---------------------------------------------------------
These *are* the droids that you are looking for.

/usr/sausalito/ui/chorizo/ci4/Modules/
--------------------------------------------------
That is where all the modules go. No exceptions.


Notes on CodeIgniter:
======================

RTFM: https://codeigniter.com/user_guide/intro/index.html

Seriously. Read it. It is the indispensible documentation of CodeIgniter.
There is no excuse not having it open in at least one tab while you're 
working on this code.

Click on the black "Table of Contents" tab at the top right as well. It is
a timesaver.

Now this CodeIgniter instance is modified. Check the script 
/usr/sausalito/ui/chorizo/ci4/bx_ci4_fixer.sh to see what we modified.

The main modifications revolve around us modifying how Routing works. 
Means: Which URI calls which Module Class. CI4 does have pretty flexible
means for this already, but they were still too unflexible to allow us to
use different Vendor directories in /usr/sausalito/ui/chorizo/ci4/Modules/
such as these:

#> ls -k1 /usr/sausalito/ui/chorizo/ci4/Modules/
Base
Bluapp
Compass
Other
Solarspeed

Additionally we modified the AutoLoader to supplement CI4's CSRF.php with one
modified version of our own to be able to exclude API URLs (and the password
checker) from CSRF protection.

Lastly the HTML template that (in 'production' environment) shows a generic
'Ooops! Something went wrong!' error message was replaced with one that is
themed in BlueOnyx style.


The MVC-Model:
==============

People not familliar with MVC models need a small crash course. So let's have it:

M = Model
V = View
C = Controller

Controller: The Controller contains the actualy code.
View:		The View contains the presentation of the output and is populated
			  by the controller.
Model:	This is typically used to model the database storage and to shove
			  data into MySQL. As we use CCE and CODB as backend, we usually do
			  not use Models in our CodeIgniter classes. Instead the data storage
			  is managed inside the Controller.

Routing:
========

The only publically accessible part of the GUI is a single PHP file:

/usr/sausalito/ui/web/index.php

A .htaccess (located at: /usr/sausalito/ui/web/.htaccess) handles anything that
doesn't hit the index.php or is caught by other "fluff" directly. Such as images,
stylesheets, jQuery scripts and therelike. If the called URL doesn't terminate in
an existing file or folder, CodeIgniter will handle it. One way or the other.

Now if you call a page such as http://<IP>:81/vsite/vsiteAdd (for example), then
you are really hitting the index.php instead. Based on the requested URL our
CodeIgniter then looks at the Routing-table to see if any PHP Class of it is 
mapped to that URL segment.

You can run the shell script "/usr/sausalito/ui/chorizo/ci4/spark routes" to see
which routes currently exist on your server.

Every module (like "base-vsite" for example) must have its own Route.php file.

Example: /usr/sausalito/ui/chorizo/ci4/Modules/Base/Vsite/Config/Routes.php

There you can map which URI loads which GUI Class and references which function in
it. Example forh ttp://<IP>:81/vsite/vsiteAdd:

$routes->add('vsite/vsiteAdd', 'Vsite\Controllers\VsiteAdd::index');

CI4 then knows that it has to load the following GUI class in order to serve
the page:

/usr/sausalito/ui/chorizo/ci4/Modules/Base/Vsite/Controllers/VsiteAdd.php

Hence that class is called and presents the matching GUI page.

So if you have paid attention, then you will already have drawn *two* conclusions:

a.) All custom modules MUST have at the bare minimum:

	- A Config/Routes.php with the mapping for URL -> Class
	- A Controller/ClassName.php

b.) Class-Names MUST be unique. No two Classes may have the same name.

c.) Class-Names MUST start with a capitalized character.

Please keep that in mind.

If your custom module should display within the framework of the BlueOnyx Chorizo
GUI and doesn't need a custom "dresssing", then you do NOT need to provide your own
"view". So this is a "clothes optional" party.


BlueOnyx Menus:
===============

Basically the menus work as before. However, as our URLs have lost the *.php extension, 
we need separate Menu files for the Chorizo GUI. This also makes it easier to have 
certain menu items only visible in the Chorizo GUI, but not the old GUI. This is useful
for modules that are very generic. Such as base-compass.mod or base-webapp.mod, which
ship with both the old and new GUI to be able to install it on all boxes from 5106R up to
5209R.

The Chorizo Menu's therefore reside here: /usr/sausalito/ui/chorizo/menu/

A typical toplevel Menu entry looks like this:

<item 
    id="base_controlpanel" 
    label="[[base-alpine.controlpanel]]" 
    description="[[base-alpine.controlpanelDescription]]"
    icon="download_to_computer"
    requiresChildren="1"
>
    <parent id="base_sysmanage" order="20"/>
</item>

So the format hasn't really changed. Just the "icon" is a new feature and allows to specify
an image to be shown left of the menu text.

Here is another example of a menu XML file at the end of the menu tree:

<item 
  id="base_personalEmail" 
  label="[[base-user.personalEmail]]" 
  description="[[base-user.personalEmail_help]]" 
  url="/user/personalEmail" 
  icon="v_card_2"
  module="user">
  <parent id="base_personalProfile" order="20"/>
</item>

Things you need to know about menus:

a.) IDs must be unique.
b.) Children of the same toplevel menu must not have identical numerical "order".
c.) We only support three levels of menus.

So:

Menu ID AAAA may be parent. It may have BBBB, CCCC and DDDD as children. Each of those Children
may have Child menu entries that lead to actual pages. But the Children may not have more nested
menus that reach any deeper than that. In practiacal terms as ID "root" is the toplevel Menu entry,
you end up with two useable menu levels. Use them wisely.

Class BxPage:
=============

You need to start somewhere to familliarize yourself with the new Chorizo GUI. You can do that
in two ways:

a.) Look at a certain (simple) GUI page and examine the contoller for it to see how it works.
This GUI has a fair share of good and bad examples. A REALLY bad example is this:

URL: 	/user/personalAccount
File:	/usr/sausalito/ui/chorizo/ci4/Modules/Base/Users/Controllers/PersonalAccount.php

DO NOT USE THAT AS AN EXAMPLE! It sucks. It works, but it ain't pretty and it's not proper UFIC code.

Good example:

URL:	/apache/apache
File:	/usr/sausalito/ui/chorizo/ci4/Modules/Base/Apache/Controllers/Apache.php

Why is that a good example? Because the code is very clean and 100% in UIFC. On the other hand the
bad example personalAccount isn't. It uses non-UIFC classes and that should be avoided like the plague.

The Class Apache is lean and mean and well structured. It starts with "lining up the ducks" and 
initializing the needed libraries. It then checks the ACL's to see if the logged in user has the
rights to view the page.

Then comes the data handling segment that parses the Form and POST data (if there is any) and 
performs the CODB transactions if any need to be done.

After that comes the error handling to check if the transaction raised any errors.

Lastly there is the part where the presentation is done and the actual GUI page and the formfields
are shown.

This last part (the formfields) follows the old UIFC format as closely as possible. But there are
subtle differences, new UIFC classes and slightly changed behaviors all around.

Noteable changes:

 - addBXDivider() replaces the old addDivider(), which had serious setbacks. Do not use addDivider()
   anymore. Instead use addBXDivider() instead.

 - getTextField() is a hell of a lot more flexible these days. We do have UIFC classes for all kinds
   of shit. Such as getDomainName(), getBoolean(), getEmailAddress(), getInteger() and many more.
   At the end of the day these are <INPUT> fields that often just differ in the kind of data they
   accept as valid. 

   So if you wanted an <INPUT> field that allows to enter an IP-Address, you could do this:

	$ipaddrField = $factory->getIpAddress("ipaddr", $my_ip, 'rw');
	$block->addFormField(
		$ipaddrField,
		$factory->getLabel("ipaddr", false)
	);

	But you could also do this instead and the result will be the same:

	$ipaddrField = $factory->getTextField("ipaddr", $my_ip, 'rw');
	$ipaddrField->setType("ipaddr");
	$block->addFormField(
		$ipaddrField,
		$factory->getLabel("ipaddr", false)
	);	

	Because you can use setType() to specify a different validator for the input to define which
	kind of data is accepted. As before the supported data types are defined via the Schema files.
	It's just that the more specialized UIFC classes such as getIpAddress() have the data type
	hardwired, while getTextField() is more generic and flexible.

	Additionally there are other "switches" that can be used to change the behaviour of some UIFC
	classes. These vary from Class to Class. Like changing if a label is shown. And if so, if the
	label is on the left (default) or on top. Or if no label is shown. Size and width or length of
	the input field can also be adjusted.

Best idea is: When you want to create a new page, look at an existing page that uses the element you
want and "borrow" that code.

But I said there were TWO ways of understanding the new Code. So here is the SECOND way to do it:


Understanding the Chorizo GUI from the top down:
=================================================

b.) In that case you want to start here:

/usr/sausalito/ui/chorizo/ci4/app/Controllers/BaseController.php

CodeIgniter 4 introduced a "BaseController", which is executed during every call. We can use this
to "line up our ducks", handle ACLs, do caching, session management and a lot of other useful
stuff. All GUI Classes have access to the data and functions within BaseController via the $CI 
variable.

BaseController also pre-loads most of the libraries and helpers and internal CI4 functions that we
need for displaying typical GUI pages.

c.) /usr/sausalito/ui/chorizo/ci/application/libraries/BxPage.php

THAT is the big deal. The main course of the dinner, the 25 year old Whisky, the Cohiba cigar or the 
21 year old chick with pink hair, too much makeup and a daddy complex. Depending on what greases your 
gears.

There are only a handfull of pages that do NOT use BxPage for processing. That would be the Login page,
the error pages and some peripheral "fluff" that goes into the header of most GUI pages. Everything else
uses BxPage.

You could ask "What does BxPage do?" Let me answer with a rethorical question: "What doesn't it do?" It's
our swiss army knife and does:

- Loading of all essential libraries, classes and helpers.
- Localization
- Error handling and display
- Parsing and presentation of the Menus (based on the ACL's)
- Scratchpad (temporary storage) for Label Objects
- Presents headers, page body and footer
- Presents Active Monitor Alerts and Warnings
- Actually checks the RAID status, too (although in an ideal world it wouldn't need to)

Couple of other things, too. A lot of the functionality in BxPage relies on Helper functions that have
been offloaded into these two helpers:

/usr/sausalito/ui/chorizo/ci4/app/Helpers/blueonyx_helper.php
/usr/sausalito/ui/chorizo/ci4/app/Helpers/uifc_ng_helper.php

When you understand BxPage, you'll have mastered Chorizo.

When you understand the Controllers and know the most common UIFC classes, then it'll be "good enough" 
to create your own GUI pages.

That's basically Chorizo in a nutshell. It's not perfect. It has its kinks and quirks and glitches and
a lot of room for optimizations and tweaks. We'll get to that one thing after another.

In the longer run the BlueOnyx WIKI will have more information about how to build custom modules for
BlueOnyx. So please check http://wiki.blueonyx.it every now and then.

In closing:
===========

Let me wrap this documentation up with a few words of gratitude and a big thanks to the whole BlueOnyx 
user base and BlueOnyx community:

The initial version of the Chorizo GUI? The one that used CodeIgniter 3 and was used for 5207R/5208R?

That took 1 year, 9 months and 21 days of development by a single person (659 days).

In that time I moved twice. Once from Germany to Colombia and once in Colombia from one apartment block
to another. I learned another language. Watched several seasons of TV-Shows while coding and went through
my MP3 playlist hundreds of times. "Map of the Problematics" (Muse), "Clocks" (Coldplay) and especially
"Dreamscape" (See: https://www.youtube.com/watch?v=2WPCLda_erI), "Breaking the Habit" (Linkin Park) and
others provided the beats to keep the code flowing.

Chorizo itself builds on a foundation that has been laid over the last 15 years by the guys of Cobalt 
Networks Inc. (most noteably Kevin Chiu!), BlueQuartz (Hisao Shibuya) and others who have carried the 
torch in the last one and a half decade and/or contributed bits and pieces here and there. Too many
to mention, really.

Then there is this newest iteration of the CodeIgniter 4 Chorizo GUI for 5212R. This was a complete
rewrite as CI3 cannot be upgraded to CI4. So I had to start with a clean slate (a naked CI4 install)
and then one by one merge our BlueOnyx related "stuff" back in. While figuring out (on the go) what
all the differences between CI3 and CI4 were. And there were plenty. So a few times I had to go back
and re-do things "the right way" that I already had implemented in "the wrong way". 

This process took from 18th August to 16th November 2022 (90 days) to just get the GUI working (again).
Full time, working seven days a week and often working 10, 12 or even 14 hours a day.

The result is well worth it, as the new CI4 Chorizo GUI for BlueOnyx 5212R now uses caching for 
frequently used CODB Objects, which cuts down on uneccessary CCEd transactions and speeds up
things a lot. We also use PHP Sessions via CI4 functions, which the older Chorizo GUI didn't.
And there was plenty of old code to clean up with better prodecures, which slimmed things down
a bit and made the GUI much more responsive.

Lastly: BaseController allowed us to trim a lot of redundant "fat" from all GUI pages. Especially in the
first third of a typical GUI page where we "line up the ducks", as BaseController already had done that
for us.

And Chorizo wouldn't be Chorizo without the Adminica Theme, which was created by Tricicle Labs:

Product Page:		http://themeforest.net/item/adminica-the-professional-admin-template/160638
Adminica Live Preview: 	http://templates.tricyclelabs.com/adminica/
Bootstrap Live Preview: http://templates.tricyclelabs.com/adminica-bootstrap/

While this theme is now showing its age, it IS so complex and so complete that despite my best
efforts I have been unable to replace it. I simply couldn't find anything else that was better
than what Tricicle Labs had rolled up. It is *that* good and we can be glad to have it.

Lastly, Chorizo most defenitely wouldn't be Chorizo if Dirk Estenfeld Black Point Arts Internet 
Solutions GmbH hadn't sprung the idea, the inspiration and (together with the BlueOnyx community) 
provided the much needed funding to help me pull this one off. 

And there are my good friends Chris Gebhardt from Virtbiz.com and Uwe Stache from BB-One.net, who 
for so many years have provided the infrastructure, hardware and bandwith to support the BlueOnyx 
Project with rock solid and top notch hosting. If you need hosting, then these are the places you 
want to go to. 

Then there are the BlueOnyx users, who have supported this project through many ups and downs and
who - with great compassion - contributed ideas, time and money to the project whenever needed. 
People like Meaulnes Legler, who (almost singlehandedly) translated the GUI into French. Which was
a real effort considering the amount of text. There are just too many to mention. So what else
can I say, but this:

Thanks a million to ALL BlueOnyx users! You are the greatest!


With best regards,

Michael Stauber
mstauber@blueonyx.it
30h November 2024
