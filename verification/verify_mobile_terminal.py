from playwright.sync_api import sync_playwright, expect

def verify_mobile_changes():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        # iPhone 13 Pro Max viewport
        iphone_13 = p.devices['iPhone 13 Pro Max']
        context = browser.new_context(**iphone_13)
        page = context.new_page()

        print("Navigating to Public Terminal...")
        page.goto("http://localhost:8080/public/index.php")

        # Verify text size is 16px
        print("Checking font size...")
        font_size = page.eval_on_selector("body", "el => window.getComputedStyle(el).fontSize")
        print(f"Font size: {font_size}")
        if font_size != "16px":
            print("WARNING: Font size is not 16px!")

        # Simulate Keyboard Open (Resize Viewport)
        # Standard iPhone 13 height is ~844px. Keyboard takes ~300px.
        # Visual Viewport height becomes ~544px.
        print("Simulating Keyboard Open (Resizing viewport)...")

        # Playwright doesn't allow setting visualViewport directly easily in the same way a real browser does via protocols,
        # but we can resize the page viewport which triggers the same layout response if we were using 'vh' units.
        # However, our JS listens to 'visualViewport' resize.
        # In headless/desktop browsers, visualViewport matches layout viewport mostly.
        # We will try to resize the window size.

        page.set_viewport_size({"width": 390, "height": 450})
        # Wait for the resize event to fire and JS to adjust height
        page.wait_for_timeout(500)

        # Check if container height was adjusted.
        # The script sets terminalContainer.style.height = visualViewport.height + 'px'
        container_height = page.eval_on_selector("#terminal-container", "el => el.style.height")
        print(f"Container inline height: {container_height}")

        # Verify input is visible
        expect(page.locator("#terminal-input")).to_be_visible()

        # Screenshot
        page.screenshot(path="verification/mobile_terminal_keyboard_sim.png")
        print("Screenshot saved.")

        browser.close()

if __name__ == "__main__":
    verify_mobile_changes()