Scripts for monitoring downloads
================================

These scripts are for monitoring daily snapshot downloads as well as the Composer and Docker packages.

Of particular note, the daily snapshot tool requires some extra Python package, BeautifulSoup4.

On Debian, this is probably available as ``python3-bs4``.

Aside from Debian, I suggest creating a venv for this. I did it with ``python3 -m venv daily_snapshot_venv`` then source that with
``source daily_snapshot_venv/bin/activate``.
Install 'beautifulsoup4' to the venv.

