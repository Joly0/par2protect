# PAR2Protect API Testing with CSRF Token

This directory contains scripts to help test the PAR2Protect API with proper CSRF token handling.

## Background

Unraid's WebUI implements CSRF protection, which requires a valid CSRF token to be included with API requests. The CSRF token is stored in the file:

```
/var/local/emhttp/var.ini
```

The scripts in this directory read this token and include it in API requests to the PAR2Protect plugin.

## Important Notes

For the PAR2Protect API, there are specific requirements for CSRF token handling:

1. The API endpoints must be accessed using the format:
   ```
   http://localhost/plugins/par2protect/api/v1/index.php?endpoint=ENDPOINT_NAME&csrf_token=TOKEN
   ```

2. For GET requests, including the CSRF token in the URL is sufficient.

3. For POST/PUT requests:
   - The CSRF token must be included in the URL as a query parameter: `&csrf_token=TOKEN`
   - The CSRF token must be included in the form data as a field: `csrf_token=TOKEN`
   - The request must use `application/x-www-form-urlencoded` content type, not JSON

## Available Scripts

### 1. test_api_with_csrf.sh

A bash script that reads the CSRF token from var.ini and makes a POST request to the queue endpoint using form data.

**Usage:**
```bash
./test_api_with_csrf.sh
```

### 2. test_api_with_csrf.php

A PHP version of the script that reads the CSRF token from var.ini and makes a POST request to the queue endpoint using form data.

**Usage:**
```bash
php test_api_with_csrf.php
```

### 3. api_tester.php

A more flexible PHP script that allows testing different API endpoints with various HTTP methods and data.

**Usage:**
```bash
php api_tester.php [endpoint] [method] [data_json]
```

**Examples:**
```bash
# GET request to the queue endpoint
php api_tester.php queue GET

# POST request to add a protection operation
php api_tester.php queue POST '{"operation_type":"protect","parameters":{"path":"/mnt/cache/backup","redundancy":10}}'

# GET request to the settings endpoint
php api_tester.php settings GET
```

## How It Works

1. The scripts read the CSRF token from `/var/local/emhttp/var.ini`
2. They include the token in the API request URL as a query parameter
3. For POST/PUT requests, they also include the token in the form data
4. For POST/PUT requests, they use `application/x-www-form-urlencoded` content type

## Troubleshooting

If you encounter issues:

1. Make sure the `/var/local/emhttp/var.ini` file exists and contains a `csrf_token` entry
2. Check that you have the necessary permissions to read the var.ini file
3. Verify that PHP and curl are installed and working correctly
4. If you're still having issues, try running the scripts with sudo to ensure they have the necessary permissions