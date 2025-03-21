#!/bin/bash

# Directory of this script
DIR="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
PLG_FILE="$DIR/par2protect.plg"
TODAY_VERSION=$(date +"%Y.%m.%d")

# Step 1: Run the package creation script
echo "Running package creation script..."
"$DIR/makepkg.sh"

# Step 2: Extract the newly created package name from the output
LATEST_PACKAGE=$(ls -t "$DIR/packages" | grep -E '^par2protect-.*-x86_64\.txz$' | head -1)
if [ -z "$LATEST_PACKAGE" ]; then
    echo "Error: Could not find the newly created package."
    exit 1
fi

# Extract the version from the package name
NEW_VERSION=$(echo "$LATEST_PACKAGE" | sed -E 's/par2protect-([0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?)-x86_64\.txz/\1/')
echo "New version: $NEW_VERSION"

# Step 3: Update the .plg file
echo "Updating .plg file with version $NEW_VERSION..."
if [ -f "$PLG_FILE" ]; then
    # Update the version in the plg file - fixed sed command
    sed -i "s/<!ENTITY version \"[0-9]\{4\}\.[0-9]\{2\}\.[0-9]\{2\}[a-z]\?\">/<!ENTITY version \"$NEW_VERSION\">/g" "$PLG_FILE"
    
    echo "PLG file updated successfully."
else
    echo "Error: PLG file not found at $PLG_FILE"
    exit 1
fi

echo "Process completed successfully."
echo "Package: $LATEST_PACKAGE"
echo "Version updated in .plg file: $NEW_VERSION"