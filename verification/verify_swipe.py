from playwright.sync_api import sync_playwright, expect

def verify_swipe():
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        iphone_13 = p.devices['iPhone 13']
        context = browser.new_context(**iphone_13)
        page = context.new_page()

        print("Navigating to Public Terminal...")
        page.goto("http://localhost:8080/public/index.php")

        print("Logging in...")
        page.fill("#terminal-input", "testuser")
        page.press("#terminal-input", "Enter")
        page.wait_for_timeout(500) # Wait for prompt update

        # Debug: Print current prompt
        prompt = page.inner_text("#prompt-label")
        print(f"Prompt after username: {prompt}")

        page.fill("#terminal-input", "password")
        page.press("#terminal-input", "Enter")

        page.wait_for_timeout(1000) # Wait for login response

        # Debug: Check output
        output = page.inner_text("#terminal-output")
        print(f"Terminal Output:\n{output}")

        # Check if login succeeded
        prompt = page.inner_text("#prompt-label")
        print(f"Prompt after password: {prompt}")

        if "@term" not in prompt:
            print("Login failed. Exiting.")
            browser.close()
            return

        print("Typing partial command 'he'...")
        page.fill("#terminal-input", "he")

        # Simulate Swipe Right via JS Event Dispatch
        print("Dispatching swipe events...")
        page.evaluate("""
            const input = document.getElementById('terminal-input');

            const touchStart = new TouchEvent('touchstart', {
                bubbles: true,
                cancelable: true,
                changedTouches: [new Touch({identifier: 0, target: input, screenX: 100, screenY: 100})]
            });
            input.dispatchEvent(touchStart);

            const touchEnd = new TouchEvent('touchend', {
                bubbles: true,
                cancelable: true,
                changedTouches: [new Touch({identifier: 0, target: input, screenX: 200, screenY: 100})]
            });
            input.dispatchEvent(touchEnd);
        """)

        page.wait_for_timeout(1000)

        val = page.input_value("#terminal-input")
        print(f"Input value after swipe: '{val}'")

        if val == "help":
            print("SUCCESS: Autocomplete triggered!")
        else:
            print("FAILURE: Autocomplete did not trigger.")

        browser.close()

if __name__ == "__main__":
    verify_swipe()