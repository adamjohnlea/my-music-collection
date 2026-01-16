# Web Console Commands

This feature allows you to run console commands from the web interface instead of the terminal.

## Accessing the Tools Page

Navigate to `/tools` in your web browser, or click the "Tools" link in the navigation menu.

## Available Commands

The tools page provides buttons for running these console commands:

### 1. Initial Sync
- **Command**: `sync:initial`
- **Description**: Imports your entire Discogs collection into the local database
- **Options**:
  - Force checkbox: Override safety check if DB already has data
- **Use when**: First-time setup

### 2. Refresh
- **Command**: `sync:refresh`
- **Description**: Incremental refresh of newly added/changed items
- **Options**:
  - Pages to scan (default: 5, range: 1-50)
- **Use when**: Regular updates to sync new additions

### 3. Enrich
- **Command**: `sync:enrich`
- **Description**: Fetch full release details (tracklist, credits, etc.)
- **Options**:
  - Release ID (optional): Target a specific release
  - Limit (default: 100): Number of releases to enrich when no ID specified
- **Use when**: Getting detailed information for releases

### 4. Backfill Images
- **Command**: `images:backfill`
- **Description**: Downloads missing cover images
- **Options**:
  - Limit (default: 60): Number of images to download
- **Use when**: Populating local image cache
- **Note**: Web runs default to 60 images (~60 seconds) due to rate limiting

### 5. Rebuild Search Index
- **Command**: `search:rebuild`
- **Description**: Repairs or rebuilds the full-text search index
- **Use when**: Search results seem incorrect or after major updates

### 6. Push Changes to Discogs
- **Command**: `sync:push`
- **Description**: Pushes queued changes back to Discogs
- **Use when**: You've made changes locally that need to sync to Discogs

### 7. Export Static Site
- **Command**: `export:static`
- **Description**: Generates a static HTML site for offline hosting
- **Options**:
  - Output Directory (default: dist)
  - Base URL (default: /)
  - Chunk Size (default: 0 - no splitting)
  - Copy images checkbox: Copy cached images to output
- **Use when**: Creating a deployable static version

## How It Works

### Real-Time Progress Display

When you click a button to run a command:

1. The command starts executing in the background
2. A progress panel appears showing:
   - Current status (Running/Completed/Error)
   - Real-time output from the command
   - Line count
3. Output updates automatically every 500ms
4. The panel shows completion status when done

### Background Execution

Commands run in separate PHP processes, so:
- Long-running commands won't timeout the web request
- You can see progress as it happens
- Multiple commands can run sequentially (buttons disabled during execution)

### Progress Storage

Progress is tracked in `var/progress/` directory:
- Each job gets a unique ID
- Progress stored as JSON files
- Output logs stored separately
- Files can be cleaned up manually if needed

## Technical Details

### Architecture

- **Controller**: `src/Http/Controllers/ToolsController.php`
- **Routes**:
  - `GET /tools` - Display the tools page
  - `POST /tools/run` - Execute a command
  - `GET /tools/progress/{jobId}` - Poll for progress updates
- **Template**: `templates/tools.html.twig`

### Security

- CSRF token validation on all command submissions
- Only predefined commands can be executed
- Parameters are validated and sanitized
- Suitable for local-only use (as intended)

### Command Execution

Commands are executed via PHP's `proc_open()` function:
- Captures both stdout and stderr
- Real-time output streaming
- Exit code tracking
- Proper cleanup on completion

## Troubleshooting

### Command Not Starting
- Check that `bin/console` exists and is executable
- Verify PHP can execute background processes
- Check `var/progress/` directory is writable

### Progress Not Updating
- Check browser console for JavaScript errors
- Verify the job ID exists in `var/progress/`
- Check file permissions on progress files

### Command Fails
- Check the output panel for error messages
- Review the full log file in `var/progress/{jobId}.log`
- Verify Discogs API credentials are configured

## Cleanup

Progress files accumulate in `var/progress/`. To clean up old files:

```bash
# Remove progress files older than 7 days
find var/progress -type f -mtime +7 -delete
```

Consider adding this to a cron job for automatic cleanup.
