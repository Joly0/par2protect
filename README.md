# PAR2Protect Plugin for Unraid

PAR2Protect is an Unraid plugin that uses par2 commands to protect, verify, and repair files and folders. It leverages the par2cmdline-turbo executable to create parity files that can be used to detect and repair file corruption.

## Features

- **File Protection**: Create par2 parity files for individual files or entire directories
- **Verification**: Verify the integrity of protected files
- **Repair**: Automatically repair corrupted files using parity data
- **Queue System**: Background processing of protection, verification, and repair operations
- **Dashboard**: Monitor protection status and recent activity
- **Detailed Logging**: Comprehensive logging of all operations (disabled by default)

## Requirements

- Unraid 7.0.0 or later
- par2cmdline-turbo package installed (is auto-installed)

## Installation

1. Install the plugin from the Unraid Community Applications
2. Navigate to the PAR2Protect page in the Unraid webUI
3. Configure settings as needed

Or install manually by adding this URL in the Unraid plugin section:

    https://raw.githubusercontent.com/Joly0/par2protect/main/par2protect.plg

## Usage

### Protecting Files

1. From the Dashboard, click "Protect Files"
2. Select the files or directories you want to protect
3. Choose protection mode (individual files or entire directory)
4. Set redundancy level (higher = more protection but larger par2 files)
5. Click "Start Protection"

### Verifying Files

1. From the Dashboard, click "Verify All" to verify all protected files
2. Alternatively, go to the Protected Files list and click "Verify" for a specific item

### Repairing Files

1. If verification detects damaged files, a "Repair" button will appear
2. Click "Repair" to attempt to repair the damaged files
3. The system will use the par2 files to repair the damaged files if possible

### Re-Protecting Files

1. If verification detects damaged files, a "Re-Protect" button will appear. Otherwise you can select multiple items in the list for the "Re-Protect Selected" button to be enabled.
2. Click "Re-Protect/Re-Protect Selected" to remove the current protection and apply a new one with configurable settings for the protection level.

## Important Notes

- PAR2 file creation can be CPU intensive, especially for large files or directories
- Additional storage space is required for PAR2 files (based on chosen redundancy level)
- The plugin uses par2cmdline-turbo for optimal performance


Understood. Here is the updated API section content you can use for your README file, reflecting the endpoints found in `api/v1/routes.php`:


## API

PAR2Protect provides a RESTful API (v1) that can be used to integrate with other applications.

**Base URL:** `/plugins/par2protect/api/v1/`

**Authentication:** Most operations (POST, PUT, DELETE) require a valid CSRF token obtained from Unraid (e.g., from `/var/local/emhttp/var.ini` or via UI interactions) to be included in the request, typically as a parameter named `csrf_token` in the URL query string or request body (form-encoded).

**Endpoints:**

### Protection

*   `GET /protection`
    *   Retrieves status for all protected paths.
*   `GET /protection/:path`
    *   Retrieves protection status for a specific path. `:path` should be the URL-encoded full path.
*   `POST /protection`
    *   Initiates protection for a path (likely requires path and settings in the request body).
*   `DELETE /protection/:path`
    *   Removes protection information for a specific path. `:path` should be the URL-encoded full path.
*   `GET /protection/:path/redundancy`
    *   Gets the calculated or configured redundancy level for a specific path. `:path` should be the URL-encoded full path.
*   `POST /protection/redundancy`
    *   Gets redundancy levels for multiple paths (likely requires paths in the request body).
*   `POST /protection/reprotect`
    *   Initiates a re-protection task for a path (likely requires path in the request body).
*   `POST /protection/files`
    *   Retrieves a list of files associated with protected paths (likely requires paths or criteria in the request body).

### Verification

*   `GET /verification/:path`
    *   Retrieves verification status for a specific path. `:path` should be the URL-encoded full path.
*   `POST /verification`
    *   Initiates verification for a path (likely requires path and settings in the request body).
*   `POST /verification/repair`
    *   Initiates repair for a path (likely requires path and settings in the request body).

### Queue

*   `GET /queue`
    *   Retrieves all items currently in the processing queue.
*   `GET /queue/active`
    *   Retrieves the currently active task from the queue.
*   `GET /queue/:id`
    *   Retrieves the status of a specific queue item by its ID.
*   `POST /queue`
    *   Adds a new task (protect, verify, repair) to the queue (requires operation details in the request body).
*   `DELETE /queue/:id`
    *   Cancels/removes a specific item from the queue by its ID.
*   `POST /queue/cleanup`
    *   Initiates a cleanup of completed or old queue tasks.
*   `POST /queue/kill`
    *   Attempts to kill stuck or unresponsive queue processes.

### Status

*   `GET /status`
    *   Retrieves the overall status of the PAR2Protect plugin and related services.

### Settings

*   `GET /settings`
    *   Retrieves the current plugin settings.
*   `PUT /settings`
    *   Updates plugin settings (requires settings data in the request body).
*   `POST /settings/reset`
    *   Resets plugin settings to their default values.

### Logs

*   `GET /logs/activity`
    *   Retrieves recent activity log entries.
*   `GET /logs/entries`
    *   Retrieves detailed log entries (potentially with filtering options).
*   `POST /logs/clear`
    *   Clears the activity/system logs.
*   `GET /logs/download`
    *   Provides a downloadable file containing the logs.

### Events (Server-Sent Events)

*   `GET /events`
    *   Establishes an SSE connection for real-time updates (e.g., queue progress).

### Debug (Note: May be temporary)

*   `GET /debug/services`
    *   Retrieves information about internal services for debugging purposes.

**Note:** Replace `:path` with the URL-encoded full path to the file or directory (e.g., `/mnt/user/movies/My%20Movie%20(2024)`). Replace `:id` with the specific queue item ID. Request bodies for POST/PUT are typically sent as `application/x-www-form-urlencoded`. Refer to the `scripts/tests/api_tester.php` script aswell as the `scripts/tests/api_testing_readme.md` for examples of CSRF handling and request formatting.


### Logs

Logs are stored at `/tmp/par2protect/logs/par2protect.log` and regularly backed up to `/boot/config/plugins/par2protect/logs/`.
You can download the latest logs through the settings page (not yet implemented)

## Support

For support, visit the [Unraid Community Forums](https://forums.unraid.net/topic/YOURTHREAD)

## Credits

- Uses [par2cmdline-turbo](https://github.com/animetosho/par2cmdline-turbo) by animetosho
- Unraid: https://unraid.net/
- Developed by [Joly0]

## License

This project is licensed under the [GNU Affero General Public License v3.0](LICENSE).
