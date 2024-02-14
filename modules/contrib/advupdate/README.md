# Update Manager Advanced

Module modifies the Drupal "`Available updates`" email report to include
the information normally shown at `/admin/reports/updates/update`, 
with links to the module updates and their release notes.

The module very similar to 
"[Update Status Detailed Email]
(https://www.drupal.org/project/update_detailed_email)",
however, implemented in another way.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/advupdate).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/advupdate).


## Requirements

This module does not require any dependency. But if you want to receive emails
with the design of your theme, you can additionally install and configure
the module, for example,
[Swift Mailer](https://www.drupal.org/project/swiftmailer)
or any another which you prefer, globally for the site for all emails.

 
## Installation

You can install the module by Composer (look more 
[using Composer to manage Drupal site dependencies]
(https://www.drupal.org/node/2718229)).
Once you have setup building your code base using composer, 
require the module via:

   ```$ composer require drupal/advupdate```

then enable the module as usual OR install manually as usual.


## Configuration

1. By default nothing to do. However if you wish to disable the
   functionality of this module 
   without un-installing the module, you can do the following:

2. Go to "`Home > Administration > Reports > Available updates`"
   and disable the checkbox at "`Expand the report using 
   "`Update Manager Advanced`" module`".


## Maintainers

- Ruslan Piskarov - [Ruslan Piskarov](https://www.drupal.org/u/ruslan-piskarov)
