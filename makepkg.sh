#!/bin/bash
DIR="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
tmpdir="/tmp/tmp.$((RANDOM * 19318203981230))"
par2tmpdir="/tmp/tmp.par2.$((RANDOM * 19318203981230))"
plugin=$(basename "$DIR")
base_version=$(date +"%Y.%m.%d")
packages_dir="$DIR/packages"

# Find an available version suffix
version=$base_version
archive="par2protect-$version-x86_64"
if [ -f "$packages_dir/$archive.txz" ] || [ -f "$packages_dir/$archive.md5" ]; then
    # Start with "a" as the first suffix
    char_code=97  # ASCII code for 'a'
    while true; do
        suffix=$(printf "\\$(printf '%03o' $char_code)")
        archive="par2protect-$base_version$suffix-x86_64"
        if [ ! -f "$packages_dir/$archive.txz" ] && [ ! -f "$packages_dir/$archive.md5" ]; then
            break
        fi
        char_code=$((char_code + 1))
        # Just in case we go beyond 'z'
        if [ $char_code -gt 122 ]; then
            echo "Error: Too many versions for today, please clean up packages directory."
            exit 1
        fi
    done
fi

echo "Creating package $archive..."

# Create temporary and packages directories
mkdir -p "$tmpdir"
mkdir -p "$packages_dir"

# Get the latest par2cmdline-turbo version information
echo "Checking for latest par2cmdline-turbo..."
PAR2_LATEST_URL=$(curl -s https://api.github.com/repos/animetosho/par2cmdline-turbo/releases/latest | grep "browser_download_url.*linux-amd64.xz" | cut -d '"' -f 4)
PAR2_VERSION=$(echo "$PAR2_LATEST_URL" | grep -oP 'v\d+\.\d+\.\d+' | head -1)
PAR2_ARCHIVE="par2cmdline-turbo-${PAR2_VERSION}-x86_64"

if [ -z "$PAR2_LATEST_URL" ]; then
    echo "Error: Could not determine latest par2cmdline-turbo URL."
    exit 1
fi

# Check if we already have this version packaged
PAR2_NEEDS_PACKAGING=true
if [ -f "$packages_dir/$PAR2_ARCHIVE.txz" ] && [ -f "$packages_dir/$PAR2_ARCHIVE.md5" ]; then
    echo "par2cmdline-turbo $PAR2_VERSION is already packaged."
    PAR2_NEEDS_PACKAGING=false
else
    echo "New version of par2cmdline-turbo detected: $PAR2_VERSION"
    # Check if any older versions exist
    EXISTING_PAR2_PACKAGES=$(find "$packages_dir" -name "par2cmdline-turbo-*.txz" | wc -l)
    if [ "$EXISTING_PAR2_PACKAGES" -gt 0 ]; then
        echo "Replacing older par2cmdline-turbo package(s)."
        # Optionally, remove old packages here if needed
    fi
fi

# Download and package par2cmdline-turbo if needed
if [ "$PAR2_NEEDS_PACKAGING" = true ]; then
    mkdir -p "$par2tmpdir"
    echo "Downloading par2cmdline-turbo $PAR2_VERSION..."
    wget -q -O "$par2tmpdir/par2cmdline-turbo.xz" "$PAR2_LATEST_URL"
    
    if [ $? -ne 0 ]; then
        echo "Error: Failed to download par2cmdline-turbo."
        rm -rf "$par2tmpdir"
        exit 1
    fi
    
    echo "Extracting par2cmdline-turbo..."
    xz -d "$par2tmpdir/par2cmdline-turbo.xz"
    
    if [ ! -f "$par2tmpdir/par2cmdline-turbo" ]; then
        echo "Error: Failed to extract par2cmdline-turbo."
        rm -rf "$par2tmpdir"
        exit 1
    fi
    
    # Create par2cmdline-turbo package
    mkdir -p "$par2tmpdir/usr/local/bin"
    mv "$par2tmpdir/par2cmdline-turbo" "$par2tmpdir/usr/local/bin/par2"
    chmod +x "$par2tmpdir/usr/local/bin/par2"
    
    # Package par2cmdline-turbo
    cd "$par2tmpdir" || exit 1
    tar -cJf "$packages_dir/$PAR2_ARCHIVE.txz" .
    cd "$packages_dir" || exit 1
    md5sum "$PAR2_ARCHIVE.txz" > "$PAR2_ARCHIVE.md5"
    echo "par2cmdline-turbo package created: packages/$PAR2_ARCHIVE.txz"
    
    # Cleanup par2 temp directory
    rm -rf "$par2tmpdir"
else
    echo "Using existing par2cmdline-turbo package."
fi

# Now create the main plugin package
cd "$DIR/source" || exit 1
# Copy all files (now relative to source/)
cp --parents $(find . -type f ! -iname "makepkg" ! -iname ".*") "$tmpdir/"

# Create package
cd "$tmpdir" || exit 1
tar -cJf "$packages_dir/$archive.txz" .
# Create MD5 file
cd "$packages_dir" || exit 1
md5sum "$archive.txz" > "$archive.md5"

# Cleanup
rm -rf "$tmpdir"

# Update the PLG file with the par2cmdline-turbo version
PLG_FILE="$DIR/par2protect.plg"
if [ -f "$PLG_FILE" ]; then
    # Get current version in PLG file
    CURRENT_PLG_VERSION=$(grep -oP '<!ENTITY par2VER "v\d+\.\d+\.\d+">' "$PLG_FILE" | grep -oP 'v\d+\.\d+\.\d+')
    
    if [ "$CURRENT_PLG_VERSION" != "$PAR2_VERSION" ]; then
        # Create a backup of the original plg file
        cp "$PLG_FILE" "$PLG_FILE.bak"
        
        # Update the par2 version in the plg file
        sed -i "s/<!ENTITY par2VER \"v[0-9]\+\.[0-9]\+\.[0-9]\+\">/<!ENTITY par2VER \"$PAR2_VERSION\">/g" "$PLG_FILE"
        
        echo "PLG file updated with par2cmdline-turbo version: $PAR2_VERSION (was $CURRENT_PLG_VERSION)"
    else
        echo "PLG file already contains the correct par2cmdline-turbo version: $PAR2_VERSION"
    fi
else
    echo "Warning: PLG file not found at $PLG_FILE"
fi

echo "Package created: packages/$archive.txz"
echo "MD5 file created: packages/$archive.md5"
echo "par2cmdline-turbo package: packages/$PAR2_ARCHIVE.txz"
echo "par2cmdline-turbo MD5: packages/$PAR2_ARCHIVE.md5"