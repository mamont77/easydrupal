Unicana Redirect

Detect links on the website and add tracking codes. For site different rules may apply.
Some sites may just add an extension at the end of the url, others may use a redirection page.

INSTALLING
----------

1. Install the module as normal, see link for instructions.
   Link: https://www.drupal.org/documentation/install/modules-themes/modules-8.
2. Configure permissions on /admin/people/permissions#module-unicana_redirect.
3. Enable "Unicana Redirect Filter" e.g. on /admin/config/content/formats/manage/full_html
   and make sure the filter placed after "Convert URLs into links" if enabled.
4. Add some redirects on /admin/config/content/unicana-redirect. Don't use protocol or WWW prefix for a domain.
   Just first level of a domain, like: example.com.
5. Clear All Caches on /admin/config/development/performance.
