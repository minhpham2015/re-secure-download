# R2 Secure Download WordPress Plugin

A WordPress plugin for secure large file downloads from Cloudflare R2 with license authentication, chunked downloads, resume functionality, and progress tracking.

## 📋 Table of Contents

- [Prerequisites](#prerequisites)
- [Step 1: Set Up Node.js API](#step-1-set-up-nodejs-api)
- [Step 2: Install WordPress Plugin](#step-2-install-wordpress-plugin)
- [Step 3: Configure Plugin Settings](#step-3-configure-plugin-settings)
- [Step 4: Usage](#step-4-usage)
- [Features](#features)
- [API Endpoints](#api-endpoints)
- [Troubleshooting](#troubleshooting)
- [License](#license)

## Prerequisites

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Node.js 16 or higher
- npm (comes with Node.js)
- Cloudflare R2 account and bucket
- R2 API tokens with read access

## Step 1: Set Up Node.js API

### 1.1 Clone the Repository

```bash
git clone https://github.com/minhpham2015/r2-cloudflare-node-api.git
cd r2-cloudflare-node-api
```

### 1.2 Install Dependencies

```bash
npm install
```

### 1.3 Configure Environment Variables

```bash
# Copy the example environment file
cp .env-exam .env

# Edit .env with your actual values
nano .env
```

Update your `.env` file with the following variables:

```env
PORT=3000

# Cloudflare R2 Configuration
R2_ACCOUNT_ID=your_account_id_here
R2_ACCESS_KEY_ID=your_access_key_id_here
R2_SECRET_ACCESS_KEY=your_secret_access_key_here
R2_BUCKET=your_bucket_name
R2_ENDPOINT=https://your_account_id.r2.cloudflarestorage.com
```

### 1.4 Start the API Server

For development:
```bash
npm run dev
```

For production:
```bash
npm start
```

The API will be available at `http://localhost:3000` (or your configured port).

### 1.5 Test the API

Test that your API is working by making a POST request:

```bash
curl -X POST http://localhost:3000/download \
  -H "Content-Type: application/json" \
  -d '{"license":"test","domain":"localhost","file":"test.zip"}'
```

You should receive a response with a signed download URL.

## Step 2: Install WordPress Plugin

### 2.1 Download the Plugin

1. Download the `r2-secure-download.php` file
2. Create a folder named `r2-secure-download` in your WordPress plugins directory
3. Place the PHP file in the plugin folder

### 2.2 Alternative: Upload via WordPress Admin

1. Go to **WordPress Admin** → **Plugins** → **Add New**
2. Click **Upload Plugin**
3. Choose the plugin ZIP file (if you create one) or upload the PHP file
4. Click **Install Now**

### 2.3 Activate the Plugin

1. Go to **WordPress Admin** → **Plugins**
2. Find "R2 Secure Download + Chunk Resume"
3. Click **Activate**

## Step 3: Configure Plugin Settings

### 3.1 Access Plugin Settings

1. Go to **WordPress Admin** → **Settings** → **R2 Download**

### 3.2 Configure Settings

**License Key:**
- Enter your product license key (provided by your software vendor)
- Example: `ABC-123-XYZ`

**API Endpoint:**
- Enter your Node.js API endpoint URL
- Default: `http://localhost:3000/download`
- For production, use your actual server URL (e.g., `https://api.yoursite.com/download`)

### 3.3 Clear Cache (Optional)

If you've previously configured the plugin or changed settings:
1. Scroll down to "Cache Management"
2. Click **"Clear File Size Cache"**

## Step 4: Usage

### 4.1 Add Download Shortcode

Add the download interface to any page or post:

```php
[r2_download]
```

### 4.2 User Experience

1. **User visits the page** with the shortcode
2. **Login required** - Users must be logged in to download
3. **License validation** - Plugin checks license with your Node.js API
4. **Download interface** shows:
   - File information
   - Progress bar
   - Download button
   - Pause/Resume functionality

### 4.3 Download Process

1. Click **"Start Download"**
2. Files download in 10MB chunks
3. Progress bar updates in real-time
4. Downloads can be paused and resumed
5. Completion shows download time

### 4.4 File Location

Downloaded files are saved to:
```
wp-content/uploads/r2-downloads/
```

## Features

### ✅ Core Features
- **Secure Downloads**: License and domain validation
- **Large File Support**: Handles files of any size
- **Chunked Downloads**: Downloads in 10MB chunks
- **Resume/Pause**: Can pause and resume downloads
- **Progress Tracking**: Real-time progress updates
- **Error Handling**: Comprehensive error messages

### ✅ Advanced Features
- **Cache Management**: Clears cached file sizes
- **Admin Settings**: Easy configuration
- **WordPress Integration**: Native WordPress shortcode
- **Security**: Nonce verification and input sanitization
- **Performance**: Optimized chunked transfers

## API Endpoints

### POST `/download` (Node.js API)

Validates license and returns signed R2 URL:

**Request:**
```json
{
  "license": "ABC-123-XYZ",
  "domain": "yoursite.com",
  "file": "package-install__woozio-main.zip"
}
```

**Success Response:**
```json
{
  "success": true,
  "message": "License & domain valid",
  "data": {
    "url": "https://bucket.r2.cloudflarestorage.com/file.zip?signature..."
  }
}
```

**Error Response:**
```json
{
  "success": false,
  "error": "License not found"
}
```

### AJAX Endpoints (WordPress Plugin)

- `wp_ajax_r2_download_progress` - Gets download progress
- `wp_ajax_r2_trigger_download` - Starts chunk downloads
- `wp_ajax_r2_delete_file` - Deletes downloaded files

## Troubleshooting

### Common Issues

**"API endpoint not responding"**
- Check that your Node.js API is running
- Verify the API endpoint URL in plugin settings
- Check server logs for API errors

**"License validation failed"**
- Verify license key is correct
- Check API server logs for validation errors
- Ensure domain is authorized for the license

**"Download stuck at X%"**
- Check browser console for JavaScript errors
- Clear file size cache in plugin settings
- Check server logs for chunk download errors

**"File not downloading"**
- Verify R2 bucket permissions
- Check signed URL generation
- Ensure file exists in R2 bucket

### Debug Mode

Enable WordPress debug logging:

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Check logs at: `wp-content/debug.log`

### Cache Issues

If experiencing caching problems:
1. Go to **Settings** → **R2 Download**
2. Click **"Clear File Size Cache"**
3. Try downloading again

## Security Considerations

- **HTTPS Required**: Use HTTPS in production
- **API Security**: Keep API keys secure
- **License Validation**: All downloads require valid licenses
- **Domain Restrictions**: Licenses are domain-specific
- **File Access**: Only authorized files can be downloaded

## License

This plugin is licensed under the MIT License.

## Support

For support and questions:
- Check the troubleshooting section
- Review server logs for errors
- Verify API and R2 configurations

---

**Note**: This plugin requires a compatible Node.js API server running the [r2-cloudflare-node-api](https://github.com/minhpham2015/r2-cloudflare-node-api) service.
