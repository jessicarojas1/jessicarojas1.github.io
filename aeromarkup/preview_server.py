#!/usr/bin/env python3
"""Local static preview server for AeroMarkup (dev only)."""
import functools
import http.server
import os

DIRECTORY = os.path.join(os.path.dirname(os.path.abspath(__file__)), "static")
Handler = functools.partial(http.server.SimpleHTTPRequestHandler, directory=DIRECTORY)
http.server.HTTPServer(("127.0.0.1", 4173), Handler).serve_forever()
