# Termi-Story: An Interactive Terminal Story Engine

Termi-Story is a web-based application that simulates a command-line terminal, designed to be a powerful and flexible engine for creating interactive fiction, text-based adventure games, and narrative puzzles.

It provides a complete toolkit for story creators, including a secure admin panel to build a virtual filesystem, write scripts, and manage players, while offering players an immersive, retro-style terminal experience.

Player DEMO: [https://remote-terminal.lainsy.net](https://remote-terminal.lainsy.net)

DEMO login: kshaw

DEMO player password: imnotfromfailtech

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
- **Full User Manager:** An interface to add, edit, and delete player accounts.
- **Branding & Theming:** A theme manager to customize the terminal's title, greetings, MOTD, and color scheme.

**File Structure**

The project is organized into four main directories to separate concerns.

/termi-story/  
|  
├── admin/ # Admin panel UI and logic.  
├── config/ # Database connection details.  
├── public/ # All publicly accessible files (the user terminal).  
└── src/ # Core back-end PHP classes (the engine).

- **/admin/**: Contains all files for the web-based administrator panel, including the UI for managing the filesystem, users, and themes.
- **/config/**: Holds the database.php file for your database credentials.
- **/public/**: This is the only directory that should be accessible to the public. It contains the player-facing terminal (index.php), its assets (.js, .css), and the API files it communicates with.
- **/src/**: Contains all the core PHP classes that power the application, including the terminal command engine, the scripting engine, and the admin logic.

# Server Setup & Security

This section covers the requirements for hosting your Termi-Story application and the best practices for keeping it secure.

**Server Requirements**

To run this application, your web server must have the following:

- **Web Server:** Apache or Nginx is recommended.
- **PHP:** Version 7.4 or higher.
- **MySQL Database:** A database to store the filesystem, users, and theme settings.
- **PHP Extension:** The pdo_mysql extension must be enabled in your php.ini file.

**Securing the Document Root**

The most effective security measure is to set your web server's **document root** (or "webroot") to the /public directory inside your project folder.

- **Correct Setup:** <https://yourdomain.com/> points to the /termi-story/public/ directory.
- **Incorrect Setup:** <https://yourdomain.com/> points to the /termi-story/ directory.

By doing this, the public can **only** access files inside /public. All of your sensitive core logic (/src), configuration files (/config), and the entire admin panel (/admin) will be located outside the webroot, making them completely inaccessible from a web browser.

**Securing the Admin Panel**

1. **Use a Strong Admin Password:** The admin password in admin/auth.php should be long, complex, and unique.
2. **Rename the Admin Directory (Recommended):** After you have finished creating your story's content, consider renaming the /admin directory to something random and hard to guess (e.g., /adm_panel_a7b3c9). Or, you can also just delete it entirely as the public and backend has no dependencies from the admin panel.
3. **Server-Level Protection (Advanced):** Use an .htaccess file (on Apache) or similar configurations on Nginx to password-protect the admin directory at the server level.

# Creator's Guide: The Scripting Engine

The heart of your interactive story is the scripting engine. This guide explains how to write scripts to create dynamic experiences.

**Scripting Commands**

- **ECHO \[text to display\]**: Displays text to the player.
- **SET \[variable_name\] = \[value\]**: Creates or modifies a player variable.
- **CALC \[variable_name\] = \[value1\] \[operator\] \[value2\]**: Performs a math operation (+, -, \*, /).
- **WAIT \[milliseconds\]**: Pauses the script.
- **IF \[condition\] / ELSE / ENDIF**: Controls the flow of your script.

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
- **unlock \[filename\] \[password\]**: Unlocks a protected file.
- **reset**: Resets all your progress.
- **help**: Displays available commands.
- **clear**: Clears the screen.
- **logout**: Logs you out.

**Quality of Life Features**

- **Command History**: Use the **Up** and **Down** arrow keys.
- **Tab Autocomplete**: Press the **Tab** key to complete commands and filenames.

**Important Note on Progress**

Your progress (variables set by scripts, unlocked files) is tied to your current **browser session**. It does not follow your user account. If you close your browser, switch to a different computer, or your session expires, your progress will be reset.
