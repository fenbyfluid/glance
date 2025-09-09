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
* [x] In-database additional metadata storage
  * [x] Indexed on file path
  * [ ] Indexed on file OsHash to handle file moves
* Background queue tasks:
  * [x] Thumbnail generation
  * [x] Timeline preview generation
  * [x] Text indexing for images
  * [ ] Transcript generation for audio
  * [ ] `whos-that-bean` facial recognition
  * [x] `phash` generation
  * [x] OsHash generation
* [x] `phash` lookup in StashDB
* [x] Real-time streaming
  * [x] Static file streaming
  * [x] Fallback to conversion
* [x] User authentication
* [x] Directory tree authorization
  * [x] UI for managing authorization rules
* [x] Static files must be authorized too (with Nginx)
* [ ] Public link generation - directory or file
* [x] Search
  * [x] Ngram search for file/directory names
  * [x] Full text search on "descriptions"
    * [x] Image text OCR
    * [x] Text file contents
    * [x] HTML file contents
    * [ ] Audio transcripts
    * [x] Scene synopsis
    * [ ] Tumblr posts
  * [x] Attribute-based filtering
  * [x] Optional filtering to current / child directories
* [ ] File upload
* [ ] File deletion and renaming, maybe ?

## Database Caching Plan

1. User visits index for a path using a Directory source  
   (!) watch out where we put this logic, as e.g. it's quite likely we'll want real cached file entries to back the media
       used by the "virtual" tumblr posts (which will be cached off in their own bespoke table)
2. Directory entries are loaded from the DB for the dir and direct children (as a path match plus a self join on the parent_id)
3. If the dir doesn't exist in the DB, or the mtime or inode are different, then
   1. A background job is scheduled to update the direct children
   2. The user is presented with a placeholder page that will refresh once the job is complete  
      (!) we need to implement some server->client notification for this (or a lock and poll)
   3. The background job gets the real directory contents, and direct child files from the db, plus the dir children loaded earlier, and
      1. updates/creates the main directory entry  
         (!) check if this is a directory rename and we just need to juggle the children around in the DB
      2. soft-deletes any children that don't exist anymore  
         these tables will hold complex metadata we don't want to accidentally destroy without explicit intention
      3. updates any children that have a different mtime/inode  
         (?) what do we do about the extra metadata in these cases? probably anything generated needs regeneration at least
      4. inserts new entries for any new children  
         (!) check if there are any entries elsewhere that need moving (inc trashed), watch out for ordering of operations  
         (!) both these rename handling bits are fairly complex, and we can probably do without them until we have
             human-provided additional metadata  
         (?) do we want to inline all metadata into this table, or just what's needed for rendering? how extensible do we want it?
      5. schedules another directory update job for any changed/new directories
      6. schedules a (low priority) phash generation job for any changed/new files
4. Otherwise, the direct child file entries are loaded from the DB also
5. The combined children are sorted and limited according to the rules for the directory
6. (?) Check for mtime/inode changes for any files after filtering (keeps the per-request work bounded)  
       File change is expected to be rare, so we may just want to handle this via a maintenance task that fires the directory update job
7. The paginated contents are returned for rendering, along with info about total count  
   (?) consider if we want an AJAX req to load the directory contents page in general

## Metadata Storage / Searching Ideas

We need quite a bit of metadata to support the functionality we'd like, and it's not obvious if we want to:
1. Inline it all into the main Files table
   1. As discrete columns, or
   2. As a JSON blob (possibly with generated columns for indexing)
2. Have related records for e.g. "Scenes" off in a separate table
3. Implement a KV storage that could contain anything, with one table per type of data, indexed on
   * (file, key) for lookup, and
   * (key, value) for filtering

Laravel Scout with the TNTSearch driver is probably the most promising FT search option.

TNTSearch is a little clunky with the SQLite engine, and the MySQL engine is not properly supported in the Scout adapter.
Typesense looks like it's worth an investigation, but needs an external server binary running, and doesn't do n-gram indexing currently.
Efficiently filtering user access permissions with TNTSearch seems a little painful, we appear to need materialized user
access per document, which it doesn't offer any way of storing within the index itself. Our query overheating method
borrowed from Phabricator seems to work for now.
Looks like we're currently missing stopword handling, which is messing up our search results a little. This may also
be an issue with our trigram creation, we maybe should be tokenizing before trigram creation rather than embedding spaces.

For audio transcription, whisper-cpp is a promising option. We could shell out to the binary, but a convenient option
appears to be to use the shared library packaged by Fedora (`whisper-cpp` and `whisper-cpp-devel`). We'll just need to
download and cache the model into the storage directory and then invoke it via FFI after decoding the audio with FFmpeg.

Alternatively, if we did switch to Typesense for searching, it has native Whisper, CLIP, and other ML model integration.

We currently store the OsHash and PHash in the main files table, we think we might also want to store the duration for
audio and videos. There are some proposed changes in Stash to the PHash algorithm necessitating a "v2", and also adding
an audio "AHash" algorithm (for video audio, so they'd have both, but we'd also like it for audio files). These might
motivate moving the fingerprints out of the main files table, and possibly as far as the general KV store.
