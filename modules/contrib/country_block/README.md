This module allows site administrators to block access to their Drupal site based on the user's country of origin. It uses the Smart IP module to determine the user's location from their IP address. If a user is visiting from a country on the blocklist, they will be denied access and shown a configurable message.

<h3 id="module-project--features">Features</h3>
This module provides a simple yet effective way to control access to your site based on geography.

*   **Block by Country:** Easily block entire countries using a simple list of country codes.
*   **Configurable Blocklist:** An administrator can add or remove countries from the blocklist through a user-friendly interface.
*   **Customizable Message:** Set a custom message that will be displayed to users who are denied access.
*   **Permission-based Access:** Configuration is protected by a dedicated permission, ensuring only authorized users can change the settings.

This module is ideal for sites that need to restrict content based on regional licensing, comply with legal requirements, or reduce malicious traffic from specific parts of the world.

<h3 id="module-project--post-installation">Post-Installation</h3>
Once installed, follow these steps to configure the module:

1.  **Set Permissions:** Navigate to the permissions page (`/admin/people/permissions`) and grant the "Administer Country Block" permission to the appropriate user roles (e.g., Administrator).
2.  **Configure Settings:** Go to the module's configuration page at `/admin/config/system/country-block`.
3.  **Add Blocked Countries:** In the "Blocked Countries" text area, enter the two-letter country codes (ISO 3166-1 alpha-2) for the countries you wish to block. Each code should be on a new line. For your convenience, the form includes a link to a list of country codes.
4.  **Customize the Message:** In the "Blocked Message" field, you can edit the default message that will be shown to users from a blocked country.
5.  **Save:** Click "Save configuration". The country block is now active.

<h3 id="module-project--additional-requirements">Additional Requirements</h3>
This module requires the following:

*   **Smart IP (`smart_ip`) module:** This module is essential for identifying the user's country based on their IP address. The Smart IP module itself requires a configured data source (e.g., a GeoIP database) to function correctly. Please follow the installation and configuration instructions for the Smart IP module.

<h3 id="module-project--recommended-libraries">Recommended modules/libraries</h3>
There are no other recommended modules for this project. However, it is highly recommended to keep the GeoIP database used by the Smart IP module up-to-date to ensure accurate country detection.

<h3 id="module-project--similar-projects">Similar projects</h3>
While other modules may provide geographic tools, Country Block is focused on simplicity and ease of use for one specific task: blocking countries. Modules like **IP-based Determination of Country, City, and Language** offer broader location data but do not provide a direct blocking mechanism. Country Block is a lightweight solution for those who need a simple "on/off" switch for country access.
