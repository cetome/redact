"""Pictures of the home page tour (public/images/tour, see the README there).

Local only. Signs in as the invented tour account (database/seeders/TourDemoSeeder.php, product
"Smart door lock (demo)", on the content pack the local app runs with) through
`php artisan redact:dev-login` (APP_ENV=local, REDACT_DEV_LOGIN=true), opens each workflow step
(Assessment::ORDER, then Reports) at 1440 x 900 CSS pixels and a device scale of 2, in light and
dark, and writes for each step and theme:

    public/images/tour/<step>-<theme>.webp      1440 x 900  (1x)
    public/images/tour/<step>-<theme>@2x.webp   2880 x 1800 (2x, retina screens)

    php artisan db:seed --class=TourDemoSeeder
    php artisan serve --port=8765
    python scripts/tour-shots.py --base http://127.0.0.1:8765

Needs Microsoft Edge (or Chrome: --browser), and the Python packages websockets and Pillow.
"""
import argparse
import asyncio
import base64
import io
import json
import pathlib
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request

import websockets
from PIL import Image

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / 'public' / 'images' / 'tour'
EMAIL = 'tour-demo@example.com'
PRODUCT = 'Smart door lock (demo)'

# Asks the app itself for the URL of each step (Assessment::stepUrl), so new steps come along.
URLS_PHP = r'''
$v = App\Models\ProductVersion::whereHas("product", fn ($q) => $q->where("name", "%s"))->latest("created_at")->firstOrFail();
$a = App\Assessment\Assessment::for($v);
$urls = [];
foreach (App\Assessment\Assessment::ORDER as $k) { $urls[$k] = $a->stepUrl($k); }
$urls["reports"] = route("reports.index", $v);
echo json_encode($urls);
'''


def artisan(*args: str) -> str:
    return subprocess.run(['php', 'artisan', *args], cwd=ROOT, capture_output=True, text=True, check=True).stdout


def login_path() -> str:
    return next(word for word in artisan('redact:dev-login', EMAIL).split() if word.startswith('/_dev/'))


def step_paths() -> dict:
    out = artisan('tinker', '--execute', URLS_PHP % PRODUCT)
    urls = json.loads(out[out.index('{'):])
    return {step: urllib.parse.urlsplit(url).path for step, url in urls.items()}


async def shoot(args) -> None:
    profile = tempfile.mkdtemp(prefix='redact-tour-')
    browser = subprocess.Popen([args.browser, '--headless=new', '--disable-gpu', '--hide-scrollbars',
                                f'--remote-debugging-port={args.port}', f'--user-data-dir={profile}',
                                '--window-size=1440,900', 'about:blank'],
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        page = None
        for _ in range(150):
            try:
                targets = json.load(urllib.request.urlopen(f'http://127.0.0.1:{args.port}/json'))
                page = next(t for t in targets if t['type'] == 'page')
                break
            except Exception:
                time.sleep(0.2)
        async with websockets.connect(page['webSocketDebuggerUrl'], max_size=400 * 1024 * 1024) as ws:
            counter = 0

            async def call(method, params=None, wait_event=None):
                nonlocal counter
                counter += 1
                mine = counter
                await ws.send(json.dumps({'id': mine, 'method': method, 'params': params or {}}))
                result = None
                while True:
                    msg = json.loads(await ws.recv())
                    if msg.get('id') == mine:
                        result = msg.get('result', {})
                        if not wait_event:
                            return result
                    if wait_event and msg.get('method') == wait_event:
                        return result

            paths = step_paths()
            await call('Page.enable')
            await call('Emulation.setDeviceMetricsOverride', {'width': 1440, 'height': 900, 'deviceScaleFactor': 2, 'mobile': False})
            await call('Page.navigate', {'url': args.base + login_path()}, wait_event='Page.loadEventFired')
            OUT.mkdir(parents=True, exist_ok=True)
            for theme in ('light', 'dark'):
                await call('Emulation.setEmulatedMedia', {'features': [{'name': 'prefers-color-scheme', 'value': theme}]})
                for step, path in paths.items():
                    await call('Page.navigate', {'url': args.base + path}, wait_event='Page.loadEventFired')
                    await asyncio.sleep(1.2)
                    shot = await call('Page.captureScreenshot', {'format': 'png'})
                    image = Image.open(io.BytesIO(base64.b64decode(shot['data']))).convert('RGB')
                    for suffix, size, quality in (('@2x', (2880, 1800), args.quality), ('', (1440, 900), args.quality + 8)):
                        target = OUT / f'{step}-{theme}{suffix}.webp'
                        image.resize(size, Image.LANCZOS).save(target, 'WEBP', quality=quality, method=6)
                        print(f'{target.relative_to(ROOT)}  {target.stat().st_size // 1024} KB')
    finally:
        browser.terminate()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--base', default='http://127.0.0.1:8765')
    parser.add_argument('--browser', default=r'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe')
    parser.add_argument('--port', type=int, default=9361)
    parser.add_argument('--quality', type=int, default=74)
    asyncio.run(shoot(parser.parse_args()))
