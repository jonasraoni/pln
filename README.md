```
================================================================
=== PKP Preservation Network Plugin for OJS
=== Version: (see version.xml)
=== Author: Chris MacDonald <chris@fcts.ca>
=== Author: Michael Joyce <ubermichael@gmail.com>
=== Author: Dimitris Efstathiou <defstat@gmail.com>
================================================================
```

## About

This plugin provides a means for OJS to preserve content in the PKP
Preservation Network (PKP PN). The plugin checks for new and modified content
and provided the PN's terms of use are met, will communicate with the PN's
staging server to preserve your published content automatically.

## License

This plugin is licensed under the GNU General Public License v3. See the
file LICENSE for the complete terms of this license.

## System Requirements

- OJS 3.3.0-x.
- CURL support for PHP.
- ZipArchive support for PHP.

## Note

The primary difference between this plugin and the existing LOCKSS preservation
mechanism present in OJS is the PN requires no registration or involvement with
the network - as long as you agree with the network's terms of use, you can
preserve your journal's content.

## Contact/Support

If you have issues, please use the PKP support forum (https://forum.pkp.sfu.ca/c/questions/5),
the issues tracker (https://github.com/pkp/pln/issues) is reserved for triaged issues.

## Setting up the deposit server

By default, the plugin deposits to https://pkp-pn.lib.sfu.ca. Journal
managers can change the URL on the plugin settings page. The default URL can
also be set in the OJS `config.inc.php` file by adding this configuration:

```
; Change the default Preservation Network URL
[lockss]
pln_url = https://example.com
```

You will need to clear the data caches after adding or changing this setting.
There is a link to clear the caches at
`Site Administration` > `Administration`

## Installation Instructions

We recommend installing this plugin using the Plugin Gallery within OJS. Log in
with administrator privileges, navigate to `Settings` > `Website` > `Plugins`, and
choose the Plugin Gallery. Find the `PN Plugin` there and follow the
instructions to install it.

## Build Instructions

(These instructions are only necessary if you are working with the plugin
manually. If you are installing the plugin using the Plugin Gallery, they are
not necessary.)

- Clone the repository containing the code.
- Run OJS's `php tools/upgrade.php upgrade`
- Execute `composer install` from console, being in the cloned `pln` folder.
  (This process is going to produce a `vendor` folder containing the depending
  library.)
- Enable Acron plugin and change `config.inc.php` variable `scheduled_tasks = On`
- Enable the PN plugin

## Other useful hints / Troubleshooting hints

- The plugin depends on 2 database tables: `pln_deposits` and `pln_deposit_objects`.
  If those tables are not present in your database, it means the plugin wasn't
  installed properly, refer to the previous sections for help.

- Ensure the plugin is creating daily log files at the `scheduledTaskLogs` folder within
  the OJS files directory. Files named as `PKPPLNDepositorTask-*id*-*datestamp*` should
  be present. If absent, the task is probably not being executed daily or
  there might be permission issues to create them.

- The `plugins.generic.pln.classes.tasks.Depositor` task must be present in the
  `scheduled_tasks` table. If it's not, try to reload the scheduled tasks at the
  Acron plugin (the option `Reload Scheduled Tasks` at the plugin settings).

- Every log file should end with an entry like `[*date time*] [Notice] Task process stopped.`.
  If absent, it means the process has been halted unexpectedly due to errors, check
  the server/PHP error log for more information.

- If an issue fails to be packaged, try to export it through the Native XML plugin at the
  from the native import/export plugin. Possible export problems may cause the
  PLN Plugin to fail to send the failed content, and the native import/export
  `Tools` > `Import/Export`, which is supposed to display some hints about what went wrong.

- Whenever something doesn't work as expected, always check the error log for clues.
  If nothing helps, report your problem in the forum.