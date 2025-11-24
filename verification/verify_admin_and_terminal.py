from playwright.sync_api import sync_playwright, expect

def verify_changes():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context()
        page = context.new_page()

        # 1. Admin Login
        print("Navigating to Admin Login...")
        page.goto("http://localhost:8080/admin/login.php")
        page.fill("#username", "storymanager")
        page.fill("#password", "admin123")
        page.click("button[type='submit']")

        # Verify we are on the admin index
        print("Checking Admin Dashboard...")
        expect(page).to_have_title("Admin Panel - Filesystem")

        # 2. Verify Tabs Navigation
        print("Testing Tabs...")
        # Default: Filesystem
        expect(page.locator("#tab-content-filesystem")).to_be_visible()

        # Click Users Tab
        page.click("button[data-tab='users']")
        expect(page.locator("#tab-content-users")).to_be_visible()
        expect(page.locator("#tab-content-filesystem")).not_to_be_visible()
        # Verify Users Table (empty but headers exist)
        expect(page.locator("#users-table")).to_be_visible()

        # Click Theme Tab
        page.click("button[data-tab='theme']")
        expect(page.locator("#tab-content-theme")).to_be_visible()
        # Verify Theme Form Data loaded (Title should be Termi-Story)
        expect(page.locator("#theme_terminal_title")).to_have_value("Termi-Story")

        # Take Admin Screenshot
        page.screenshot(path="verification/admin_panel.png", full_page=True)
        print("Admin Panel screenshot saved.")

        # 3. Verify Public Terminal Mobile View
        print("Testing Mobile Terminal...")
        # Create a mobile context
        iphone_13 = p.devices['iPhone 13']
        mobile_context = browser.new_context(**iphone_13)
        mobile_page = mobile_context.new_page()

        mobile_page.goto("http://localhost:8080/public/index.php")

        # Verify Login Prompt
        expect(mobile_page.locator("#prompt-label")).to_contain_text("Username:")

        # Type something to check input
        mobile_page.fill("#terminal-input", "testuser")

        # Take Terminal Screenshot
        mobile_page.screenshot(path="verification/mobile_terminal.png")
        print("Mobile Terminal screenshot saved.")

        browser.close()

if __name__ == "__main__":
    verify_changes()