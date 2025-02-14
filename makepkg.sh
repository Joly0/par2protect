#!/bin/bash
DIR="$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"
tmpdir="/tmp/tmp.$((RANDOM * 19318203981230))"
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
# Change to source directory before copying
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
echo "Package created: packages/$archive.txz"
echo "MD5 file created: packages/$archive.md5"