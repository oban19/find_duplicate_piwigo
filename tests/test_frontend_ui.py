"""Frontend UI regression tests for admin.tpl using Playwright.

Tests critical interactive flows (toggle_favorite, set_group_favorite, delete_image)
directly against admin.tpl to catch JS scoping/ReferenceError bugs across <script> IIFEs.
"""

import base64
import json
import re
from pathlib import Path
from playwright.sync_api import sync_playwright

TINY_PNG = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII="
)

ROOT_DIR = Path(__file__).resolve().parent.parent
ADMIN_TPL_PATH = ROOT_DIR / "admin.tpl"

SAMPLE_IMAGES = [
    {
        "ID": 101,
        "PATH": "upload/2026/01/img101.jpg",
        "FULL_URL": "http://localhost/upload/2026/01/img101.jpg",
        "FILE": "img101.jpg",
        "NAME": "Image 101",
        "URL": "http://localhost/admin.php?page=photo-101",
        "PHASH": "01010101",
        "DATE_CREATION": "2026-01-01 12:00:00",
        "FILE_SIZE": "1.50 MB",
        "FILE_SIZE_BYTES": 1572864,
        "HAS_GPS": 0,
        "HAS_MOTION": 0,
        "IS_FAVORITE": 0,
        "ALBUM_COUNT": 1,
        "ALBUMS": {"1": "Album A"},
    },
    {
        "ID": 102,
        "PATH": "upload/2026/01/img102.jpg",
        "FULL_URL": "http://localhost/upload/2026/01/img102.jpg",
        "FILE": "img102.jpg",
        "NAME": "Image 102",
        "URL": "http://localhost/admin.php?page=photo-102",
        "PHASH": "01010101",
        "DATE_CREATION": "2026-01-01 12:00:01",
        "FILE_SIZE": "1.20 MB",
        "FILE_SIZE_BYTES": 1258291,
        "HAS_GPS": 0,
        "HAS_MOTION": 0,
        "IS_FAVORITE": 0,
        "ALBUM_COUNT": 1,
        "ALBUMS": {"1": "Album A"},
    },
]


def render_admin_tpl() -> str:
    """Render admin.tpl with mock Smarty values for browser execution."""
    tpl = ADMIN_TPL_PATH.read_text(encoding="utf-8")

    # Replace Smarty {literal}...{/literal} tags
    tpl = tpl.replace("{literal}", "").replace("{/literal}", "")

    # Replace translation tags: {'text'|translate...}
    tpl = re.sub(
        r"\{'([^']*)'\|translate[^}]*\}",
        lambda m: m.group(1).replace("'", "\\'"),
        tpl,
    )

    # Replace simple variable placeholders used in JS/HTML
    replacements = {
        "{$FD_ACTION_URL|escape:'javascript'}": "/admin.php?page=plugin-find_duplicates",
        "{$FD_ACTION_URL}": "/admin.php?page=plugin-find_duplicates",
        "{$PWG_TOKEN|escape:'javascript'}": "test-token-123",
        "{$PWG_TOKEN}": "test-token-123",
        "{$FD_VERSION|default:'1.0.0'|escape:'html'}": "1.1.10",
        "{$FD_TOTAL_SAVED_SPACE|default:'0 B'|escape:'html'}": "0 B",
        "{$FD_HASHED_IMAGES}": "100",
        "{$FD_TOTAL_IMAGES|default:0}": "100",
        "{$FD_TOTAL_IMAGES}": "100",
        "{$FD_MODE|default:'visual'|escape:'javascript'}": "visual",
        "{$FD_MODE|default:'visual'|escape:'html'}": "visual",
        "{$FD_MODE}": "visual",
        "{$FD_SIMILARITY|default:100|escape:'javascript'}": "100",
        "{$FD_SIMILARITY|default:100}": "100",
        "{$FD_SAME_ALBUM|default:0|escape:'javascript'}": "0",
        "{$FD_SHOW_HIDDEN|default:0|escape:'javascript'}": "0",
        "{$FD_SEARCH_GROUP|default:''|escape:'url'}": "",
        "{$FD_SEARCH_GROUP|default:''|escape:'html'}": "",
        "{$FD_TIME_INTERVAL|default:15|escape:'javascript'}": "15",
        "{$FD_SORT|default:'default'|escape:'javascript'}": "default",
    }
    for key, val in replacements.items():
        tpl = tpl.replace(key, val)

    # Inject two concrete .fd-group elements into .fd-duplicates-list
    # (2 groups prevents auto-reload when the first group is resolved)
    group2_images = [
        dict(SAMPLE_IMAGES[0], ID=201, FILE="img201.jpg", FULL_URL="http://localhost/upload/2026/01/img201.jpg"),
        dict(SAMPLE_IMAGES[1], ID=202, FILE="img202.jpg", FULL_URL="http://localhost/upload/2026/01/img202.jpg"),
    ]
    group_html = (
        f'<div class="fd-group" id="group-1" data-phash="01010101" data-title="Album A 1" '
        f"data-images='{json.dumps(SAMPLE_IMAGES)}'>"
        f'<h4><span>Album A 1</span>'
        f'<button type="button" class="fd-favorite-all-btn fd-btn-sm fd-group-fav-btn" data-target-status="1">Mark all</button>'
        f'<button type="button" class="fd-btn fd-btn-sm fd-open-group-btn">Open group</button></h4>'
        f'<div class="fd-img-wrap" data-id="101"><img src="http://localhost/img101.jpg"></div>'
        f'<div class="fd-img-wrap" data-id="102"><img src="http://localhost/img102.jpg"></div>'
        f"</div>"
        f'<div class="fd-group" id="group-2" data-phash="10101010" data-title="Album B 1" '
        f"data-images='{json.dumps(group2_images)}'>"
        f'<h4><span>Album B 1</span>'
        f'<button type="button" class="fd-favorite-all-btn fd-btn-sm fd-group-fav-btn" data-target-status="1">Mark all</button>'
        f'<button type="button" class="fd-btn fd-btn-sm fd-open-group-btn">Open group</button></h4>'
        f'<div class="fd-img-wrap" data-id="201"><img src="http://localhost/img201.jpg"></div>'
        f'<div class="fd-img-wrap" data-id="202"><img src="http://localhost/img202.jpg"></div>'
        f"</div>"
    )

    # Strip remaining Smarty control blocks and inject our test group
    tpl = re.sub(r"\{foreach\b.*?\{/foreach\}", "", tpl, flags=re.DOTALL)
    tpl = re.sub(r"\{for\b.*?\{/for\}", "", tpl, flags=re.DOTALL)
    tpl = re.sub(r"\{/?(?:if|else|elseif)[^}]*\}", "", tpl)
    tpl = re.sub(r"\{\$[^}]+\}", "", tpl)

    tpl = tpl.replace(
        '<div class="fd-duplicates-list">',
        f'<div class="fd-duplicates-list">{group_html}',
        1,
    )

    return f"<!DOCTYPE html><html><head><meta charset='utf-8'></head><body>{tpl}</body></html>"


def run_tests() -> None:
    html = render_admin_tpl()
    api_calls: list[str] = []
    page_errors: list[str] = []

    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page()
        page.on("pageerror", lambda err: page_errors.append(str(err)))
        page.on("dialog", lambda dialog: dialog.accept())

        def handle_route(route):
            url = route.request.url
            if url == "http://localhost/admin.php?page=plugin-find_duplicates":
                route.fulfill(status=200, content_type="text/html", body=html)
                return
            if "action=toggle_favorite" in url:
                api_calls.append(url)
                route.fulfill(
                    status=200,
                    content_type="application/json",
                    body=json.dumps({"status": "ok", "is_favorite": 1}),
                )
                return
            if "action=set_group_favorite" in url:
                api_calls.append(url)
                route.fulfill(
                    status=200,
                    content_type="application/json",
                    body=json.dumps({"status": "ok", "is_favorite": 1, "updated_ids": [101, 102]}),
                )
                return
            if "action=delete_image" in url:
                api_calls.append(url)
                route.fulfill(
                    status=200,
                    content_type="application/json",
                    body=json.dumps({
                        "status": "ok",
                        "deleted_bytes": 1572864,
                        "total_saved_bytes": 1572864,
                    }),
                )
                return
            if url.endswith(".jpg"):
                route.fulfill(status=200, content_type="image/png", body=TINY_PNG)
                return
            # Stub other assets
            route.fulfill(status=200, content_type="text/plain", body="")

        page.route("**/*", handle_route)
        page.goto("http://localhost/admin.php?page=plugin-find_duplicates")

        # 1. Open modal, hover over image to reveal actions overlay, and test toggle_favorite
        page.click(".fd-open-group-btn")
        page.wait_for_selector("#fd-modal.fd-modal-show")
        page.locator("#fd-modal-images .fd-modal-img-wrap a").first.hover()
        page.locator("#fd-modal-images .fd-favorite-btn").first.click()
        page.wait_for_timeout(150)

        assert any(
            "action=toggle_favorite" in u and "image_id=101" in u and "pwg_token=test-token-123" in u
            for u in api_calls
        ), f"Expected toggle_favorite API call, got: {api_calls}"
        assert page.locator("#fd-modal-images .fd-favorite-btn").first.inner_text() == "★"
        assert page.locator(".fd-group .fd-img-wrap[data-id='101'] .fd-thumb-fav-star").count() == 1

        # 2. Test delete_image on first image
        first_link = page.locator("#fd-modal-images .fd-modal-img-wrap a").first
        first_link.hover(force=True)
        page.locator("#fd-modal-images .fd-btn-danger").first.click(force=True)
        page.wait_for_timeout(150)

        assert any(
            "action=delete_image" in u and "image_id=101" in u and "pwg_token=test-token-123" in u
            for u in api_calls
        ), f"Expected delete_image API call, got: {api_calls}"
        assert "1.50 MB" in page.locator("#fd-top-saved-space").inner_text()

        # Wait for modal to auto-close after group-1 is reduced to 1 image
        page.wait_for_selector("#fd-modal", state="hidden", timeout=5000)

        # 3. Test set_group_favorite button on group-2 header
        page.click("#group-2 .fd-group-fav-btn")
        page.wait_for_timeout(150)

        assert any(
            "action=set_group_favorite" in u and "201" in u and "202" in u and "pwg_token=test-token-123" in u
            for u in api_calls
        ), f"Expected set_group_favorite API call, got: {api_calls}"

        assert not page_errors, f"Uncaught JS errors detected: {page_errors}"
        browser.close()

    print("Frontend UI tests passed (3/3 scenarios, 0 JS errors).")


if __name__ == "__main__":
    run_tests()
