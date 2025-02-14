# PAR2Protect Plugin for Unraid

PAR2Protect is an Unraid plugin that provides automated PAR2 file creation and verification for data protection. It helps protect your data against bit rot, file corruption, and other forms of data degradation by creating and maintaining PAR2 parity files.

## Features (Work in Progress)

- **Automated Protection**: Automatically creates PAR2 files for specified directories
- **Manual Control**: Create PAR2 files manually for specific files or file types
- **Scheduled Verification**: Regular integrity checks of your protected files
- **Configurable Redundancy**: Set custom redundancy levels for different protection needs
- **Flexible Path Selection**: Choose which directories and files to protect

## Installation

1. Navigate to the Unraid Community Applications (CA)
2. Search for "PAR2Protect"
3. Click "Install"

Or install manually by adding this URL in the Unraid plugin section:

    https://raw.githubusercontent.com/Joly0/par2protect/main/par2protect.plg

## Configuration

### Basic Settings

- **Default Redundancy Level**: Set the percentage of redundancy for PAR2 files (1-20%)
- **Verification Schedule**: Choose how often to verify PAR2 files (daily/weekly/monthly)
- **Protected Paths**: Specify which directories to monitor and protect

## Important Notes

- PAR2 file creation can be CPU intensive, especially for large files or directories
- Additional storage space is required for PAR2 files (based on chosen redundancy level)
- The plugin uses par2cmdline-turbo for optimal performance

## Current Status

⚠️ **This plugin is currently under development** ⚠️

While the basic framework is in place, some features are still being implemented. Please check back regularly for updates.

## Planned Features

- Automatic PAR2 file creation for monitored directories
- Scheduled verification of existing PAR2 files
- Manual PAR2 creation tools
- Detailed logging and notification system

## Support

For support, visit the [Unraid Community Forums](https://forums.unraid.net/topic/YOURTHREAD)

## Credits

- Uses [par2cmdline-turbo](https://github.com/animetosho/par2cmdline-turbo) by animetosho
- Developed by [Joly0]

## License

This project is licensed under the [GNU Affero General Public License v3.0](LICENSE).