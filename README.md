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

## Important Notes

- PAR2 file creation can be CPU intensive, especially for large files or directories
- Additional storage space is required for PAR2 files (based on chosen redundancy level)
- The plugin uses par2cmdline-turbo for optimal performance

## Architecture

PAR2Protect uses a modular architecture with the following components:

### Core Components

- **Config**: Centralized configuration management
- **Logger**: Comprehensive logging system
- **Database**: SQLite database for storing protection information
- **API**: RESTful API for frontend communication

### Services

- **Protection**: Handles file protection operations
- **Verification**: Handles file verification and repair
- **Queue**: Manages background processing of operations

## API

PAR2Protect provides a RESTful API that can be used to integrate with other applications:

```
GET /plugins/par2protect/api/v1/protection
GET /plugins/par2protect/api/v1/protection/:path
POST /plugins/par2protect/api/v1/protection
DELETE /plugins/par2protect/api/v1/protection/:path

GET /plugins/par2protect/api/v1/verification/:path
POST /plugins/par2protect/api/v1/verification
POST /plugins/par2protect/api/v1/verification/repair

GET /plugins/par2protect/api/v1/queue
GET /plugins/par2protect/api/v1/queue/active
GET /plugins/par2protect/api/v1/queue/:id
POST /plugins/par2protect/api/v1/queue
DELETE /plugins/par2protect/api/v1/queue/:id

GET /plugins/par2protect/api/v1/status

GET /plugins/par2protect/api/v1/settings
PUT /plugins/par2protect/api/v1/settings
POST /plugins/par2protect/api/v1/settings/reset

GET /plugins/par2protect/api/v1/logs/activity
GET /plugins/par2protect/api/v1/logs/entries
POST /plugins/par2protect/api/v1/logs/clear
GET /plugins/par2protect/api/v1/logs/download
```

### Logs

Logs are stored at `/boot/config/plugins/par2protect/par2protect.log` and can be saved from the settings page.

## Support

For support, visit the [Unraid Community Forums](https://forums.unraid.net/topic/YOURTHREAD)

## Credits

- Uses [par2cmdline-turbo](https://github.com/animetosho/par2cmdline-turbo) by animetosho
- Unraid: https://unraid.net/
- Developed by [Joly0]

## License

This project is licensed under the [GNU Affero General Public License v3.0](LICENSE).