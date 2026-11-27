"""Frontend UI regression tests for admin.tpl using Playwright.

Tests interactive UI flows directly against admin.tpl to catch JS scoping,
event handling, navigation/token, theme, and DOM regression bugs.
"""

import json
import re
import struct
import zlib
from pathlib import Path
from playwright.sync_api import sync_playwright


def make_png(width: int = 200, height: int = 200) -> bytes:
    """Create a minimal valid RGB PNG of given dimensions using stdlib only."""
    raw = b"".join(b"\x00" + b"\x80\x80\x80" * width for _ in range(height))
    def chunk(tag: bytes, data: bytes) -> bytes:
        return struct.pack("!I", len(data)) + tag + data + struct.pack("!I", zlib.crc32(tag + data) & 0xFFFFFFFF)
    ihdr = struct.pack("!IIBBBBB", width, height, 8, 2, 0, 0, 0)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", ihdr) + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b"")


TEST_PNG = make_png(200, 200)

ROOT_DIR = Path(__file__).resolve().parent.parent
ADMIN_TPL_PATH = ROOT_DIR / "admin.tpl"
BASE_URL = "http://localhost/admin.php?page=plugin-find_duplicates"

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

    tpl = tpl.replace("{literal}", "").replace("{/literal}", "")
    tpl = re.sub(
        r"\{'([^']*)'\|translate[^}]*\}",
        lambda m: m.group(1).replace("'", "\\'"),
        tpl,
    )

    replacements = {
        "{$FD_ACTION_URL|escape:'javascript'}": "/admin.php?page=plugin-find_duplicates",
        "{$FD_ACTION_URL}": "/admin.php?page=plugin-find_duplicates",
        "{$PWG_TOKEN|escape:'javascript'}": "test-token-123",
        "{$PWG_TOKEN}": "test-token-123",
        "{$FD_VERSION|default:'1.0.0'|escape:'html'}": "1.1.11",
        "{$FD_TOTAL_SAVED_SPACE|default:'0 B'|escape:'html'}": "0 B",
        "{$FD_HASHED_IMAGES}": "50",
        "{$FD_TOTAL_IMAGES|default:0}": "100",
        "{$FD_TOTAL_IMAGES}": "100",
        "{$FD_MODE|default:'visual'|escape:'javascript'}": "visual",
        "{$FD_MODE|default:'visual'|escape:'html'}": "visual",
        "{$FD_MODE}": "visual",
        "{$FD_SIMILARITY|default:100|escape:'javascript'}": "100",
        "{$FD_SIMILARITY|default:100}": "100",
        "{$FD_SAME_ALBUM|default:0|escape:'javascript'}": "0",
        "{$FD_SAME_ALBUM|default:0}": "0",
        "{$FD_SHOW_HIDDEN|default:0|escape:'javascript'}": "0",
        "{$FD_SEARCH_GROUP|default:''|escape:'url'}": "",
        "{$FD_SEARCH_GROUP|default:''|escape:'html'}": "",
        "{$FD_TIME_INTERVAL|default:15|escape:'javascript'}": "15",
        "{$FD_SORT|default:'default'|escape:'javascript'}": "default",
        "{if $FD_SAME_ALBUM}checked{/if}": "",
        "{if $FD_SHOW_HIDDEN}checked{/if}": "",
        "{if $FD_MODE == 'time'}style=\"display:none;\"{/if}": "",
        "{if ($FD_HASHED_IMAGES + $FD_FAILED_IMAGES) >= $FD_TOTAL_IMAGES && $FD_TOTAL_IMAGES > 0}disabled{/if}": "",
    }
    for key, val in replacements.items():
        tpl = tpl.replace(key, val)

    group2_images = [
        dict(SAMPLE_IMAGES[0], ID=201, FILE="img201.jpg", FULL_URL="http://localhost/upload/2026/01/img201.jpg"),
        dict(SAMPLE_IMAGES[1], ID=202, FILE="img202.jpg", FULL_URL="http://localhost/upload/2026/01/img202.jpg"),
    ]
    group3_images = [
        dict(SAMPLE_IMAGES[0], ID=301, FILE="img301.jpg", FULL_URL="http://localhost/upload/2026/01/img301.jpg"),
        dict(SAMPLE_IMAGES[1], ID=302, FILE="img302.jpg", FULL_URL="http://localhost/upload/2026/01/img302.jpg"),
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
        f'<div class="fd-group" id="group-3" data-phash="11110000" data-title="Album C 1" '
        f"data-images='{json.dumps(group3_images)}'>"
        f'<h4><span>Album C 1</span>'
        f'<button type="button" class="fd-favorite-all-btn fd-btn-sm fd-group-fav-btn" data-target-status="1">Mark all</button>'
        f'<button type="button" class="fd-btn fd-btn-sm fd-open-group-btn">Open group</button></h4>'
        f'<div class="fd-img-wrap" data-id="301"><img src="http://localhost/img301.jpg"></div>'
        f'<div class="fd-img-wrap" data-id="302"><img src="http://localhost/img302.jpg"></div>'
        f"</div>"
    )

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
    api_calls: list[dict[str, str]] = []
    nav_urls: list[str] = []
    page_errors: list[str] = []
    scan_step = {"count": 0}

    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page()
        page.on("pageerror", lambda err: page_errors.append(str(err)))
        page.on("dialog", lambda dialog: dialog.accept())

        def handle_route(route):
            url = route.request.url
            method = route.request.method
            body = route.request.post_data or ""
            if url.startswith("http://localhost/admin.php") and "action=" not in url:
                if url != BASE_URL:
                    nav_urls.append(url)
                route.fulfill(status=200, content_type="text/html", body=html)
                return
            if "action=scan" in url:
                api_calls.append({"method": method, "url": url, "body": body})
                scan_step["count"] += 1
                done = scan_step["count"] >= 2
                route.fulfill(
                    status=200,
                    content_type="application/json",
                    body=json.dumps({
                        "hashed": 75 if not done else 100,
                        "total": 100,
                        "done": done,
                    }),
                )
                return
            if "action=retry_failed" in url:
                api_calls.append({"method": method, "url": url, "body": body})
                route.fulfill(status=200, content_type="application/json", body=json.dumps({"status": "ok"}))
                return
            if "action=toggle_favorite" in url:
                api_calls.append({"method": method, "url": url, "body": body})
                route.fulfill(status=200, content_type="application/json", body=json.dumps({"status": "ok", "is_favorite": 1}))
                return
            if "action=set_group_favorite" in url:
                api_calls.append({"method": method, "url": url, "body": body})
                route.fulfill(status=200, content_type="application/json", body=json.dumps({"status": "ok", "is_favorite": 1, "updated_ids": [101, 102]}))
                return
            if "action=delete_image" in url:
                api_calls.append({"method": method, "url": url, "body": body})
                route.fulfill(
                    status=200,
                    content_type="application/json",
                    body=json.dumps({"status": "ok", "deleted_bytes": 1572864, "total_saved_bytes": 1572864}),
                )
                return
            if url.endswith(".jpg"):
                route.fulfill(status=200, content_type="image/png", body=TEST_PNG)
                return
            route.fulfill(status=200, content_type="text/plain", body="")

        page.route("**/*", handle_route)

        # 1. Theme detection (light and dark)
        page.goto(BASE_URL)
        page.evaluate("document.body.style.backgroundColor = 'rgb(255, 255, 255)'; window.applyFdTheme();")
        assert "fd-theme-light" in page.locator("#fd-main").get_attribute("class")
        assert "fd-theme-light" in page.locator("#fd-modal").get_attribute("class")

        page.evaluate("document.body.style.backgroundColor = 'rgb(20, 20, 20)'; window.applyFdTheme();")
        assert "fd-theme-dark" in page.locator("#fd-main").get_attribute("class")
        assert "fd-theme-dark" in page.locator("#fd-modal").get_attribute("class")

        # 2. Tab switching (.fd-tab) preserves params and includes pwg_token
        with page.expect_navigation():
            page.click('.fd-tab[data-mode="time"]')
        assert any(
            "fd_mode=time" in u and "pwg_token=test-token-123" in u
            for u in nav_urls
        ), f"Expected tab navigation with pwg_token, got: {nav_urls}"

        # 3. Filters: warning message only for visual + similarity < 100 + !same_album, and submitFilters() preserving sort + pwg_token
        page.goto(BASE_URL)
        assert not page.locator("#fd-warning-msg").is_visible()  # similarity=100 uses O(N) fast-path

        page.locator("#fd-similarity-range").fill("92")
        page.locator("#fd-similarity-range").dispatch_event("input")
        assert page.locator("#fd-sim-val").inner_text() == "92%"
        assert page.locator("#fd-warning-msg").is_visible()  # visual + sim<100 + same_album unchecked

        page.check("#fd-same-album-chk")
        assert not page.locator("#fd-warning-msg").is_visible()
        page.uncheck("#fd-same-album-chk")
        assert page.locator("#fd-warning-msg").is_visible()

        with page.expect_navigation():
            page.select_option("#fd-sort-select", "size_desc")
        assert any(
            "sort=size_desc" in u and "pwg_token=test-token-123" in u
            for u in nav_urls
        ), f"Expected sort navigation with pwg_token, got: {nav_urls}"

        nav_urls.clear()
        page.goto(BASE_URL)
        page.evaluate("document.getElementById('fd-sort-select').value = 'count_desc';")
        with page.expect_navigation():
            page.click('#fd-filter-form button[type="submit"]')
        assert any(
            "sort=count_desc" in u and "pwg_token=test-token-123" in u
            for u in nav_urls
        ), f"Expected submitFilters() to preserve sort and pwg_token, got: {nav_urls}"

        # 4. Scan button (#fd-scan-btn) multi-chunk progress & retry button (#fd-retry-btn) via POST
        page.goto(BASE_URL)
        page.click("#fd-scan-btn")
        page.wait_for_timeout(200)
        assert sum(
            1 for c in api_calls
            if c["method"] == "POST" and "action=scan" in c["url"] and "pwg_token=test-token-123" in c["body"] and "pwg_token=" not in c["url"]
        ) == 2

        page.goto(BASE_URL)
        page.click("#fd-retry-btn")
        page.wait_for_timeout(150)
        assert any(
            c["method"] == "POST" and "action=retry_failed" in c["url"] and "pwg_token=test-token-123" in c["body"] and "pwg_token=" not in c["url"]
            for c in api_calls
        )

        # 5. Modal open/close (Escape, backdrop, close button) & zoom controls
        page.goto(BASE_URL)
        page.click("#group-1 .fd-open-group-btn")
        page.wait_for_selector("#fd-modal.fd-modal-show")

        page.keyboard.press("Escape")
        page.wait_for_selector("#fd-modal", state="hidden", timeout=5000)

        page.click("#group-1 .fd-open-group-btn")
        page.wait_for_selector("#fd-modal.fd-modal-show")
        page.click("#fd-modal", position={"x": 5, "y": 5})
        page.wait_for_selector("#fd-modal", state="hidden", timeout=5000)

        page.click("#group-1 .fd-open-group-btn")
        page.wait_for_selector("#fd-modal.fd-modal-show")

        # Sync zoom and per-image zoom lens
        page.click(".fd-sync-zoom-btn")
        assert "active" in page.locator(".fd-sync-zoom-btn").get_attribute("class")
        first_link = page.locator("#fd-modal-images .fd-modal-img-wrap a").first
        box = first_link.bounding_box()
        assert box is not None
        page.mouse.move(box["x"] + box["width"] / 2, box["y"] + 20)
        assert page.locator("#fd-modal-images .fd-zoom-lens").first.evaluate("el => el.style.opacity") == "1"

        page.locator("#fd-modal-images .fd-zoom-toggle-btn").first.click(force=True)
        assert "active" in page.locator("#fd-modal-images .fd-zoom-toggle-btn").first.get_attribute("class")

        # 6. Modal toggle_favorite on first image via POST
        first_link.hover(force=True)
        page.locator("#fd-modal-images .fd-favorite-btn").first.click(force=True)
        page.wait_for_timeout(150)
        assert any(
            c["method"] == "POST" and "action=toggle_favorite" in c["url"] and "image_id=101" in c["body"] and "pwg_token=test-token-123" in c["body"] and "pwg_token=" not in c["url"]
            for c in api_calls
        )
        assert page.locator("#fd-modal-images .fd-favorite-btn").first.inner_text() == "★"
        star_loc = page.locator("#group-1 .fd-img-wrap[data-id='101'] .fd-thumb-fav-star")
        assert star_loc.count() == 1
        assert star_loc.evaluate("el => parseInt(getComputedStyle(el).zIndex, 10) >= 3")

        # 7. Modal delete_image on first image via POST (auto-closes modal when group-1 drops to 1 image)
        first_link.hover(force=True)
        page.locator("#fd-modal-images .fd-btn-danger").first.click(force=True)
        page.wait_for_timeout(150)
        assert any(
            c["method"] == "POST" and "action=delete_image" in c["url"] and "image_id=101" in c["body"] and "pwg_token=test-token-123" in c["body"] and "pwg_token=" not in c["url"]
            for c in api_calls
        )
        assert "1.50 MB" in page.locator("#fd-top-saved-space").inner_text()
        page.wait_for_selector("#fd-modal", state="hidden", timeout=5000)

        # 8. Modal header group favorite button (favAllBtn) on group-2 via POST
        page.click("#group-2 .fd-open-group-btn")
        page.wait_for_selector("#fd-modal.fd-modal-show")
        page.click("#fd-modal-title .fd-favorite-all-btn")
        page.wait_for_selector("#fd-modal", state="hidden", timeout=5000)
        assert any(
            c["method"] == "POST" and "action=set_group_favorite" in c["url"] and "201" in c["body"] and "202" in c["body"] and "pwg_token=test-token-123" in c["body"] and "pwg_token=" not in c["url"]
            for c in api_calls
        )

        # 9. Group header set_group_favorite button on group-3 via POST (remains disabled while fading out)
        page.click("#group-3 .fd-group-fav-btn")
        page.wait_for_timeout(150)
        assert any(
            c["method"] == "POST" and "action=set_group_favorite" in c["url"] and "301" in c["body"] and "302" in c["body"] and "pwg_token=test-token-123" in c["body"] and "pwg_token=" not in c["url"]
            for c in api_calls
        )
        assert page.locator("#group-3 .fd-group-fav-btn").is_disabled()

        assert not page_errors, f"Uncaught JS errors detected: {page_errors}"
        browser.close()

    print("Frontend UI tests passed (9/9 scenarios, 0 JS errors).")


if __name__ == "__main__":
    run_tests()
