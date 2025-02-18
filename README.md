# Glance

Web-based media viewer

## Project Plan

* Filesystem-driven structure
* Per-directory-tree modular render classes
  * For structure:
    * Standard - filesystem hierarchy
    * Tumblr - post JSON
  * For rendering:
    * Grouped by type
    * Chronological (for Telegram)
  * Extensions to client-side JS
* Inline README rendering
* Pagination would be nice to control HTML size
* Background queue tasks:
  * Thumbnail generation
  * Timeline preview generation
  * `whos-that-bean` facial recognition
* Real-time streaming
  * Static file streaming
  * Fallback to conversion
* User authentication
* Directory tree authorization
* Static files must be authorized too (with Nginx)
* Public link generation - directory or file
