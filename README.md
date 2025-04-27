# Glance

Web-based media viewer

## Project Plan

* [x] Filesystem-driven structure
* [ ] Per-directory-tree modular render classes
  * For structure:
    * [x] Standard - filesystem hierarchy
    * [ ] Tumblr - post JSON
  * For rendering:
    * [x] Grouped by type
    * [ ] Chronological (for Telegram)
  * [ ] Extensions to client-side JS
* [x] Inline README rendering
* [ ] Pagination would be nice to control HTML size
* [ ] In-database additional metadata storage
  * [ ] Indexed on file path
  * [ ] Indexed on file OsHash to handle file moves
* Background queue tasks:
  * [ ] Thumbnail generation
  * [ ] Timeline preview generation
  * [ ] Text indexing for images
  * [ ] Transcript generation for audio
  * [ ] `whos-that-bean` facial recognition
  * [x] `phash` generation
  * [x] OsHash generation
* [ ] `phash` lookup in StashDB
* [x] Real-time streaming
  * [x] Static file streaming
  * [x] Fallback to conversion
* [x] User authentication
* [x] Directory tree authorization
  * [x] UI for managing authorization rules
* [x] Static files must be authorized too (with Nginx)
* [ ] Public link generation - directory or file
