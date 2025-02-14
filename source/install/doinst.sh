#!/bin/bash
PLUGIN="par2protect"
PLUGIN_DIR="/usr/local/emhttp/plugins/$PLUGIN"
CONFIG_DIR="/boot/config/plugins/$PLUGIN"

echo "Installing par2protect plugin..."

# Create required directories
mkdir -p "$PLUGIN_DIR"
mkdir -p "$CONFIG_DIR"
mkdir -p "/mnt/user/appdata/$PLUGIN/"{parity,logs,status}

# Move plugin files to the correct location
mv -v usr/local/emhttp/plugins/par2protect/* "$PLUGIN_DIR/"

# Set execute permissions for scripts
chmod +x "$PLUGIN_DIR/scripts/rc.par2protect"
chmod +x "$PLUGIN_DIR/scripts/monitor.php"
chmod +x "$PLUGIN_DIR/scripts/tasks/"*.php

# Create default config if it doesn't exist
if [ ! -f "$CONFIG_DIR/$PLUGIN.cfg" ]; then
    echo "Creating default configuration..."
    cp "$PLUGIN_DIR/config/default.cfg" "$CONFIG_DIR/$PLUGIN.cfg"
fi

echo "Installation complete."