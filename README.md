# Termi-Story: An Interactive Terminal Story Engine

Termi-Story is a web-based application that simulates a command-line terminal, designed to be a powerful and flexible engine for creating interactive fiction, text-based adventure games, and narrative puzzles.

It provides a complete toolkit for story creators, including a secure admin panel to build a virtual filesystem, write scripts, and manage players, while offering players an immersive, retro-style terminal experience.


# What is Termi-Story?

At its core, Termi-Story is a **game engine** disguised as a terminal. Instead of navigating a world with graphics, players explore a virtual filesystem, reading text files for clues, and running simple script files (.app) to solve puzzles and advance the story.

It's built with PHP and a MySQL database on the back-end, with a dynamic, vanilla JavaScript front-end that brings the terminal to life.

**Suggested Uses**

- **Text-Based Adventure Games:** Create classic "Zork"-style games where players explore directories as rooms and interact with files as objects.
- **Alternate Reality Games (ARGs):** Build a component of an ARG where players must "hack" into a system to find clues.
- **Interactive Resumes/Portfolios:** Create a unique and memorable way for visitors to explore your projects and skills.
- **Educational Tools:** Teach basic command-line concepts in a fun, sandboxed environment.
- **CTF (Capture The Flag) Challenges:** Design puzzles where players must navigate the filesystem and run scripts to find hidden flags.

# Features

**Player Terminal**

- **Immersive Login:** A secure, in-terminal login process.
- **Full Command Set:** ls, cat, cd, run, unlock, reset, help, and more.
- **Advanced Scripting Engine:** Run .app files with variables, conditional logic (IF/ELSE, AND/OR), math (CALC), and atmospheric delays (WAIT).
- **Quality of Life:** Command history (arrow keys) and tab autocompletion.
- **Animations:** Typewriter and "buffering" effects for an authentic feel.

**Admin Panel**

- **Secure Logins:** Separate, secure logins for the administrator and players.
- **Full Filesystem Manager:** A web UI to create, edit, and delete all files and directories.
- **User Permissions:** Set access restrictions on files and directories. Assign ownership to a specific player or make it available to "All Users". Files restricted to other users will return "Access Denied" if an unauthorized user attempts to access them.
- **Locks:** Give any file, directory or app a lock password (with an optional hint). Players must `unlock` it before they can read a file, `cd` into a directory, or `run` an app. Changing the password locks it again for players who had already unlocked it.
- **Naming:** Players type names as single words, so item names can't contain spaces or slashes.
- **Full User Manager:** An interface to add, edit, and delete player accounts.
- **Branding & Theming:** A theme manager to customize the terminal's title, greetings, MOTD, and color scheme.


**Story Backup (Admin)**

- The **Story** tab in the admin panel can export the full virtual filesystem (and theme) to a JSON file, and import it back in **merge** or **replace** mode.
- File lock passwords are exported as hashes (not plaintext). Owners are matched by username on import.
- Text and script content is exported as stored, which is HTML-encoded once (e.g. `&quot;` for `"`). If you hand-edit an export, keep that encoding.
- A command-line exporter still lives at `config/export_story.php` for text dumps; the admin JSON format is preferred for round-trips between installs.

**File Structure**

The project is organized into four main directories to separate concerns.

/termi-story/  
|  
├── admin/ # Admin panel UI and logic.  
├── config/ # Settings loader, local credentials and CLI maintenance scripts.  
├── public/ # All publicly accessible files (the user terminal).  
└── src/ # Core back-end PHP classes (the engine).

- **/admin/**: Contains all files for the web-based administrator panel, including the UI for managing the filesystem, users, and themes.
- **/config/**: `database.php` loads your settings from `config.local.php` (your credentials; not tracked in git) or from environment variables. It also holds the command-line maintenance scripts.
- **/public/**: This is the only directory that should be accessible to the public. It contains the player-facing terminal (index.php), its assets (.js, .css), and the API files it communicates with.
- **/src/**: Contains all the core PHP classes that power the application, including the terminal command engine, the scripting engine, and the admin logic.

# Server Setup & Security

This section covers the requirements for hosting your Termi-Story application and the best practices for keeping it secure.

**Server Requirements**

To run this application, your web server must have the following:

- **Web Server:** Apache or Nginx is recommended.
- **PHP:** Version 8.0 or higher, with Argon2 password hashing available (`PASSWORD_ARGON2ID`, included in standard builds).
- **MySQL Database:** A database to store the filesystem, users, and theme settings.
- **PHP Extension:** The pdo_mysql extension must be enabled in your php.ini file.

**Configuration**

Credentials are never stored in tracked files. Set them up like this:

1. Copy `config/config.local.example.php` to `config/config.local.php`. This file is gitignored.
2. Fill in your database settings (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, and optionally `DB_PORT` for a non-standard port).
3. Generate an admin password hash with `php config/hash_password.php 'your-long-unique-password'` and paste it into `ADMIN_PASSWORD_HASH`. Keep the single quotes, because the hash contains `$` characters. **Admin login stays disabled until a hash is set.**

Alternatively, set the environment variables `TERMI_DB_HOST`, `TERMI_DB_PORT`, `TERMI_DB_USER`, `TERMI_DB_PASS`, `TERMI_DB_NAME`, `TERMI_ADMIN_USERNAME` and `TERMI_ADMIN_PASSWORD_HASH`. Values in `config.local.php` take precedence over environment variables.

> **Upgrading from an older version:** earlier versions kept credentials in `config/database.php` and the admin hash in `admin/auth.php`. Move those values into `config/config.local.php`, then discard your local edits to those two files before pulling.

> **Repairing text from older versions:** earlier versions of the admin editor re-encoded text every time a file was saved, so characters like `'`, `"` and `&` could gradually turn into `&#039;`, `&amp;quot;` and so on. Text files and scripts are now encoded exactly once when saved, decoded when shown in the editor or to players, and decoded before a script runs. To clean up content that was already affected, preview the changes with `php config/repair_double_encoding.php`, then run it again with `--apply`.

**Securing the Document Root**

The most effective security measure is to set your web server's **document root** (or "webroot") to the /public directory inside your project folder.

- **Correct Setup:** <https://yourdomain.com/> points to the /termi-story/public/ directory.
- **Incorrect Setup:** <https://yourdomain.com/> points to the /termi-story/ directory.

By doing this, the public can **only** access files inside /public. All of your sensitive core logic (/src), configuration files (/config), and the entire admin panel (/admin) will be located outside the webroot, making them completely inaccessible from a web browser.

There are also fallbacks in case the document root is set up incorrectly:

- The maintenance scripts in `/config` (`update_*.php`, `hash_password.php`, `export_story.php`) refuse to run outside the command line.
- **Apache:** `.htaccess` files deny web access to `/config` and `/src`. The project-root `.htaccess` also hides `.git` and other dotfiles, `/verification`, `dbsetup.txt`, `README.md` and `LICENSE`.
- **Nginx** ignores `.htaccess`. If you can't point the root at `/public`, add an equivalent rule:

  ```nginx
  location ~ (^|/)\.(?!well-known/) { return 404; }
  location ~ ^/(config|src|verification)(/|$) { return 404; }
  location ~ ^/(dbsetup\.txt|README\.md|LICENSE)$ { return 404; }
  ```

**Securing the Admin Panel**

1. **Use a Strong Admin Password:** The admin password (whose hash goes in `config/config.local.php`) should be long, complex, and unique.
2. **Rename the Admin Directory (Recommended):** After you have finished creating your story's content, consider renaming the /admin directory to something random and hard to guess (e.g., /adm_panel_a7b3c9). Or, you can also just delete it entirely as the public and backend has no dependencies from the admin panel.
3. **Server-Level Protection (Advanced):** Use an .htaccess file (on Apache) or similar configurations on Nginx to password-protect the admin directory at the server level.

**Built-in Protections**

- **Sessions:** Player and admin sessions use separate cookies (`termistory` and `termistory_admin`) flagged `HttpOnly` and `SameSite=Lax`, plus `Secure` when served over HTTPS. The session ID is replaced on every login, and PHP's strict session mode rejects session IDs the server didn't issue.
- **CSRF:** The admin API only accepts JSON `POST` requests that carry the session's CSRF token in an `X-CSRF-Token` header. The admin login and logout forms also require the token. The player API only accepts JSON `POST` requests.
- **Rate limiting:** Failed password attempts are throttled:
  - Player login: 5 failures per username per IP address, and 50 per IP address, in 15 minutes.
  - Admin login: 5 failures per IP address in 15 minutes.
  - `unlock`: 20 wrong passwords per player in 5 minutes.

  Attempt counts are kept as small files in the system temp directory, so no database changes are needed. To store them elsewhere, add `define('RATE_LIMIT_DIR', '/path/to/dir');` to `config/config.local.php`. Limits are keyed on `REMOTE_ADDR`, so if you run behind a reverse proxy, configure it to pass the real client address to PHP (for example, `mod_remoteip` or the nginx `real_ip` module). Otherwise, all players share one IP-based limit.

# Creator's Guide: The Scripting Engine

The heart of your interactive story is the scripting engine. This guide explains how to write scripts to create dynamic experiences.

**Scripting Commands**

- **ECHO \[text to display\]**: Displays text to the player.
- **SET \[variable_name\] = \[value\]**: Creates or modifies a player variable.
- **CALC \[variable_name\] = \[value1\] \[operator\] \[value2\]**: Performs a math operation (+, -, \*, /).
- **WAIT \[milliseconds\]**: Pauses the script (up to 30000 ms per WAIT).
- **IF \[condition\] / ELSE / ENDIF**: Controls the flow of your script.
- **FETCH \[variable_name\] = \[URL\]**: Fetches data from a fixed URL endpoint. The endpoint must return JSON containing a "value" field. The value is sanitized to only allow alphanumeric characters and then stored in the specified variable. On error (connection issue, missing "value" field, etc.), the variable is set to an empty string ("").

> **Note on FETCH:** You can also use `FETCH` to ping a URL (e.g., to count how many times a script has run). The server should still return JSON with a `value` field (like `{"value": "OK"}`) for the script to successfully proceed. You can then optionally reset the variable on the next line using `SET [variable_name] = ""`.

> **FETCH restrictions:** For safety, `FETCH` only supports `http://` and `https://` URLs, does not follow redirects (use the final URL), reads at most 64 KB, and times out after 10 seconds. URLs whose host resolves to a private, loopback, link-local or otherwise non-public address (e.g. `localhost`, `192.168.x.x`, `169.254.169.254`) are refused. If your endpoint runs on the same machine or your LAN, add `define('FETCH_ALLOW_PRIVATE_HOSTS', true);` to `config/config.local.php`. Only do this if you trust every script on the server, including any you import.

**Variables Explained**

- **Player Variables:** Created with SET or CALC. They are unique to each player and are saved automatically.
- **System Variables (Read-Only):**
  - $USERNAME: The current player's username.
  - $SESS_RUN_INPUT: The argument a player provides with the run command.
- **Unset Variables:** Treated as an empty string ("") in IF statements and as the number 0 in CALC operations. Otherwise, it is treated as empty in cases like when checking for user input in RUN commands.

**Writing Conditional Logic**

The IF command supports multiple conditions and operators.

- **Operators:** ==, !=, >, &lt;, &gt;=, <=
- **Combiners:** AND, OR
- **Evaluation Order:** The engine evaluates conditions in a specific order. It first checks all AND conditions together as a group, and then it uses OR to separate these groups. You cannot use parentheses () to change this order.
- **Example:** IF $HAS_KEY == 1 AND $POWER_ON == 1 OR $USERNAME == admin This condition is true if ($HAS_KEY is 1 **AND** $POWER_ON is 1) **OR** if ($USERNAME is "admin").

**Security, Limitations, and Best Practices**

- **Security:** The engine is secure. Player input from the rum command is **automatically sanitized to be alphanumeric, no symbols accepted**. The engine **cannot execute PHP or JavaScript**.
- **Initialization (init.app):** An init.app file in the root (/) directory will run automatically for new players or after a reset. Use this to set starting variables. **Crucially, this script must end with _SET SESS_INIT = 1_ to prevent it from running on every login.**
- **Clarity is Key:** Use comments (#) to document your scripts.
- **Multi-Step Calculations:** The CALC command only handles one operation at a time. To perform more complex math, use a temporary variable to store the intermediate result. **Remember to clear your temporary variables at the end of the script** by setting them to an empty value.

**Example:**

\# Calculate a bonus score  
CALC TEMP_SCORE = $BASE_SCORE \* 2  
CALC FINAL_SCORE = $TEMP_SCORE + 100  
<br/>\# Clean up the temporary variable  
SET TEMP_SCORE = ""

- **Limitations:** The engine does not support nested IF/ELSE blocks. All IF statements must be top-level.

# Player's Guide: Interacting with the Terminal

As a player, your goal is to explore the pseudo-filesystem and uncover secrets.

**Player Commands**

- **ls**: Lists files and directories.
- **cd \[directory\]**: Changes your directory.
- **cat \[filename\]**: Displays a text file's contents.
- **run \[appname.app\]**: Executes a script aka ‘program’.
- **unlock \[name\] \[password\]**: Unlocks a protected file, directory or app.
- **view \[filename\]**: Views an image file.
- **reset**: Resets all your progress.
- **help**: Displays available commands.
- **clear**: Clears the screen.
- **logout**: Logs you out.

**Quality of Life Features**

- **Command History**: Use the **Up** and **Down** arrow keys.
- **Tab Autocomplete**: Press the **Tab** key to complete commands and filenames. A triple comma (,,,) can also be used for auto complete on devices with no tab key.

**Important Note on Progress**

Your progress (variables set by scripts, unlocked files) is tied to your current **browser session**. It does not follow your user account. If you close your browser, switch to a different computer, run `logout`, or your session expires, your progress will be reset. If a different player logs in on the same browser, they start fresh rather than inheriting the previous player's progress.
